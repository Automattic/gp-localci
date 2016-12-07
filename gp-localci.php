<?php
/**
 *  LocalCI is a Github-oriented localization continuous integration
 *  add-on to GlotPress. LocalCI provides string coverage management
 *  and associated messaging coordination between Github and an external
 *  CI build system (eg, CircleCI, TravisCI).
 *
 *  Requires PHP 7.0.0 or greater.
 *
 *  Put this plugin in the folder: /glotpress/plugins/
 */

require __DIR__ . '/includes/ci-adapters.php';
require __DIR__ . '/includes/db-adapter.php';

define( 'LOCALCI_DESIRED_LOCALES', '' );
define( 'LOCALCI_DEBUG_EMAIL', '' );
define( 'LOCALCI_GITHUB_API_URL', 'https://api.github.com' );
define( 'LOCALCI_GITHUB_API_MANAGEMENT_TOKEN', '' );


class GP_Route_LocalCI extends GP_Route_Main {
	public function __construct() {
		$this->template_path = __DIR__ . '/templates/';
	}

	public function relay_new_strings_to_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$db        = $this->get_gp_db_adapter();
		$build_ci  = $this->get_ci_adapter( LOCALCI_BUILD_CI );
		$gh_data   = $build_ci->get_gh_data();

		if ( ! $this->is_github_data_valid( $gh_data ) ) {
			$this->die_with_error( "Invalid Github data.", 400 );
		}

		if ( 'master' == $gh_data->branch ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		if ( $this->is_locked( $gh_data->sha ) ) {
			$this->die_with_error( "Rate limit exceeded.", 429 );
		}

		// Temporary while we watch what comes through
		wp_mail( 'hew@automattic.com', 'LocalCI debug dump', print_r( $build_ci->get_payload(), true ) );
		$this->tmpl( 'status-ok' );
		exit;
		// Temporary while we watch what comes through

		$po          = $build_ci->get_new_strings_po( $gh_data->branch );
		$project_id  = $build_ci->get_gp_project_id();

		if ( empty( $po ) || ! is_numeric( $project_id ) || $project_id < 1 ) {
			$this->die_with_error( "Invalid GlotPress data.", 400 );
		}

		$coverage    = $db->get_string_coverage( $po, $project_id );
		$stats       = $db->generate_coverage_stats( $coverage );
		$suggestions = $db->generate_string_suggestions( $coverage );

		$response = $this->post_to_gh_status_api( $gh_data->owner, $gh_data->repo, $gh_data->sha, $stats );

		if ( is_wp_error( $response ) || 201 != $response['status_code'] ) {
			$this->die_with_error( "GH status update failed.", 400 );
		}

		$this->tmpl( 'status-ok' );
	}

	public function relay_string_freeze_from_gh() {
	}

	public function invoke_ci_build() {
	}

	public function post_to_gh_status_api( $owner, $repo, $sha, $stats ) {
		return wp_safe_remote_post( GITHUB_API_URL . "/repos/$owner/$repo/statuses/$sha", $stats );
	}



	/**
	 * The nitty gritty details
	 */
	private function get_gp_db_adapter() {
		return new GP_LocalCI_DB_Adapter();
	}

	private function get_ci_adapter( $ci ) {
		$ci_adapter = 'GP_LocalCI_' . $ci . '_Adapter';
		return new $ci_adapter;
	}

	private function is_github_data_valid( $data ) {
		if ( empty( $data->owner ) || empty( $data->repo )
			|| empty( $data->sha ) || empty( $data->branch ) ) {
			return false;
		}

		if ( ! is_string( $data->owner ) || ! is_string( $data->repo )
			|| ! is_string( $data->sha ) || ! is_string( $data->branch ) ) {
			return false;
		}

		if ( 40 !== strlen( $data->sha ) ) {
			return false;
		}

		return true;
	}

	private function is_locked( $sha ) {
		if ( get_transient( 'localci_sha_lock' ) === $sha ) {
			return true;
		}

		set_transient( 'localci_sha_lock', $sha, HOUR_IN_SECONDS );
		return false;
	}
}

class GP_LocalCI_API_Loader {
	function init() {
		$this->init_new_routes();
	}

	function init_new_routes() {
		GP::$router->add( '/localci/-relay-new-strings-to-gh', array( 'GP_Route_LocalCI', 'relay_new_strings_to_gh' ), 'post' );
	}
}

$gp_localci_api = new GP_LocalCI_API_Loader();
add_action( 'gp_init', array( $gp_localci_api, 'init' ) );
