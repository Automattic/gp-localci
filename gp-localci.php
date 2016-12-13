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

require __DIR__ . '/config.php';
require __DIR__ . '/includes/ci-adapters.php';
require __DIR__ . '/includes/db-adapter.php';
require __DIR__ . '/includes/localci-functions.php';


class GP_Route_LocalCI extends GP_Route_Main {
	public function __construct() {
		$this->template_path = __DIR__ . '/templates/';
	}

	public function relay_new_strings_to_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$build_ci  = $this->get_ci_adapter( LOCALCI_BUILD_CI );
		$db        = $this->get_gp_db_adapter();
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

		$this->set_lock( $gh_data->sha );

		$po_file     = $build_ci->get_new_strings_pot();
		$project_id  = GP_LocalCI_Config::get_value( $gh_data->owner, $gh_data->repo, 'gp_project_id' );

		if ( false === $po_file || false === $project_id ) {
			$this->die_with_error( "Invalid GlotPress data.", 400 );
		}

		if ( empty( $po_file ) ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		$po          = localci_load_po( $po_file );
		$coverage    = $db->get_string_coverage( $po, $project_id );
		$stats       = localci_generate_coverage_stats( $po, $coverage );


		$response = $this->post_to_gh_status_api( $gh_data->owner, $gh_data->repo, $gh_data->sha, $stats['summary'] );

		$this->tmpl( 'status-ok' );
	}

	public function relay_string_freeze_from_gh() {
		// @TODO
	}

	public function post_to_gh_status_api( $owner, $repo, $sha, $localci_summary ) {
		return wp_safe_remote_post( LOCALCI_GITHUB_API_URL . "/repos/$owner/$repo/statuses/$sha", array(
			'headers' => array(
				'Authorization' => 'token ' . LOCALCI_GITHUB_API_MANAGEMENT_TOKEN,
			),
			'body' => json_encode( array(
				'state' => 'success',
				'description' => $localci_summary,
				'context' => 'ci/i18n'
			) ),
			'blocking' => false,
			'timeout' => 30,
			'user-agent' => 'LocalCI/GP v1.0'
		) );
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
		$shas = get_transient( 'localci_sha_lock' );

		if ( ! isset( $shas[$sha] ) ) {
			return false;
		}

		if ( $this->has_lock_expired( $shas[$sha] ) ) {
			unset( $shas[$sha] );
			set_transient( 'localci_sha_lock', $shas, HOUR_IN_SECONDS );
			return false;
		}

		return true;
	}

	private function set_lock( $sha ) {
		$shas = get_transient( 'localci_sha_lock' );

		if ( empty( $shas ) || ! is_array( $shas ) ) {
			$shas = array();
		}

		$shas[$sha] = time();
		set_transient( 'localci_sha_lock', $shas, HOUR_IN_SECONDS );
	}

	private function has_lock_expired( $sha_lock_time ) {
		return $sha_lock_time + HOUR_IN_SECONDS < time();
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
