<?php
/**
 *  LocalCI is a Github-oriented localization continuous integration
 *  add-on to GlotPress. LocalCI provides string coverage management
 *  and associated messaging coordination between Github and an external
 *  CI build system (eg, CircleCI, TravisCI).
 *
 *  Requires PHP7.0.0 or greater.
 *
 *  Put this file in the folder: /glotpress/plugins/
 */

require __DIR__ . '/includes/gp-localci-db-adapter.php';

define( 'LOCALCI_DESIRED_LOCALES', '' );
define( 'LOCALCI_DEBUG_EMAIL', '' );
define( 'LOCALCI_GITHUB_API_URL', 'https://api.github.com' );
define( 'LOCALCI_GITHUB_API_MANAGEMENT_TOKEN', '' );


class GP_Route_LocalCI extends GP_Route_Main {
	public function __construct() {
		$this->template_path = dirname( __FILE__ ) . '/templates/';
	}

	public function relay_new_strings_to_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$json = json_decode( file_get_contents( 'php://input' ) );

		$owner = $json->payload->username;
		$repo  = $json->payload->reponame;
		$sha   = $json->payload->vcs_revision;

		if ( $this->is_locked( $sha ) ) {
			$this->die_with_error( "Rate limit exceeded.", 429 );
		}

		$response = $this->safe_get( $json_payload->build_url );

		if ( empty( $response ) || is_wp_error( $response ) ) {
			$this->die_with_error( "Artifact pull failed.", 400 );
		}

		$db = new GP_LocalCI_DB_Adapter();

		$coverage    = $db->get_string_coverage( $po_file, $project_id );
		$stats       = $db->generate_coverage_stats( $coverage );
		$suggestions = $db->generate_string_suggestions( $coverage );

		$response = $this->post_to_gh_status_api( $owner, $repo, $sha, $stats );

		if ( is_wp_error( $response ) || 201 != $response['status_code'] ) {
			$this->die_with_error( "GH status update failed.", 400 );
		}

		$this->tmpl( 'localci-status-ok' );
	}

	public function relay_string_freeze_from_gh() {
	}

	public function invoke_ci_build() {
	}

	public function post_to_gh_status_api( $owner, $repo, $sha, $stats ) {
		return wp_safe_remote_post( GITHUB_API_URL . "/repos/$owner/$repo/statuses/$sha", $stats );
	}

	private function is_locked( $sha ) {
		if ( get_transient( 'localci_sha_lock') === $sha ) {
			return true;
		}

		set_transient( 'localci_sha_lock', $sha, HOUR_IN_SECONDS );
		return false;
	}

	private function safe_get( $url ) {
		$safe = false;
		$whitelisted_domains = array(
			'https://circleci.com'
		);

		foreach ( $whitelisted_domains as $domain ) {
			if ( 0 === strpos( $url, $domain ) ) {
				$safe = true;
				break;
			}
		}

		if ( ! $safe ) {
			return new WP_Error;
		}

		return wp_remote_get( $url, array() );
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
