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

if ( ! defined( 'GP_LOCALCI_UNIT_TEST' ) || ! GP_LOCALCI_UNIT_TEST ) {
	require __DIR__ . '/config.php';
}

require __DIR__ . '/includes/ci-adapters.php';
require __DIR__ . '/includes/db-adapter.php';
require __DIR__ . '/includes/gh-adapter.php';
require __DIR__ . '/includes/localci-functions.php';


class GP_Route_LocalCI extends GP_Route_Main {
	public function __construct( $ci = null, $db = null, $gh = null ) {
		$this->ci = isset( $ci ) ? $ci : $this->get_ci_adapter( LOCALCI_BUILD_CI );
		$this->db = isset( $db ) ? $db : new GP_LocalCI_DB_Adapter();
		$this->gh = isset( $gh ) ? $gh : new GP_LocalCI_Github_Adapter();

		$this->template_path = __DIR__ . '/templates/';
	}

	public function relay_new_strings_to_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$gh_data = $this->ci->get_gh_data();

		if ( ! $this->gh->is_data_valid( $gh_data ) ) {
			$this->die_with_error( 'Invalid Github data.', 406 );
		}

		if ( 'master' == $gh_data->branch ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		if ( $this->is_locked( $gh_data->sha ) ) {
			$this->die_with_error( 'Rate limit exceeded.', 429 );
		}

		$this->set_lock( $gh_data->sha );

		$po_file     = $this->ci->get_new_strings_pot();
		$project_id  = GP_LocalCI_Config::get_value( $gh_data->owner, $gh_data->repo, 'gp_project_id' );

		if ( false === $po_file || false === $project_id ) {
			$this->die_with_error( 'Invalid GlotPress data.', 400 );
		}

		$po = localci_load_po( $po_file );

		if ( empty( $po->entries ) ) {
			$this->gh->post_to_status_api( $gh_data->owner, $gh_data->repo, $gh_data->sha, $gh_data->branch, '0 new strings. ¡Ándale!' );
			$this->tmpl( 'status-ok' );
			exit;
		}

		$coverage  = $this->db->get_string_coverage( $po, $project_id );
		$comments  = $this->gh->post_suggestions_comments( $gh_data, $coverage );

		$stats     = localci_generate_coverage_stats( $po, $coverage );

		$this->gh->post_to_status_api( $gh_data->owner, $gh_data->repo, $gh_data->sha, $gh_data->branch, $stats['summary'] );
		$this->tmpl( 'status-ok' );
	}

	public function relay_string_freeze_from_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		if ( ! $this->gh->is_valid_request( $this->gh->owner, $this->gh->repo ) ) {
			$this->die_with_error( 'Invalid request.', 401 );
		}

		if ( ! $this->gh->is_string_freeze_label_added_event() ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		// @todo: sleep?

		$most_recent_pot = $this->ci->get_most_recent_pot(
			$this->gh->owner,
			$this->gh->repo,
			$this->gh->branch
		);

		if ( false === $most_recent_pot ) {
			// @todo: alert someone; this is an error
		}

		if ( empty( $most_recent_pot ) ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		// @todo: figure out how to import into GP

		// @todo: report back to the GH PR confirmation (?)
	}

	public function status( $owner, $repo, $branch ) {
		$po_file    = $this->ci->get_most_recent_pot( $owner, $repo, $branch );
		$pull_request = $this->gh->get_pull_request( $owner, $repo, $branch );

		$status_gh_link_href = $pull_request ?
			"https://github.com/$owner/$repo/pull/$pull_request->number" :
			"https://github.com/$owner/$repo/tree/$branch/";

		$status_gh_link_text = $pull_request ?
			"$pull_request->title ($owner/$repo)" :
			"$repo/branch/$branch";

		$project_id = GP_LocalCI_Config::get_value( $owner, $repo, 'gp_project_id' );
		$project    = GP::$project->get( $project_id );
		$po         = localci_load_po( $po_file );
		$coverage   = $this->db->get_string_coverage( $po, $project_id );
		$stats      = localci_generate_coverage_stats( $po, $coverage );

		add_action( 'gp_head', array( $this, 'status_page_css' ) );
		$this->tmpl( 'status-details', get_defined_vars() );
	}

	public function status_page_css() {
		wp_register_style( 'gp-localci', plugins_url( 'css/gp-localci.css', __FILE__ ) );
		gp_enqueue_style( 'gp-localci' );
	}

	/**
	 * The nitty gritty details
	 */
	private function get_ci_adapter( $ci ) {
		$ci_adapter = 'GP_LocalCI_' . $ci . '_Adapter';
		return new $ci_adapter;
	}

	private function is_locked( $sha ) {
		$shas = get_transient( 'localci_sha_lock' );

		if ( ! isset( $shas[ $sha ] ) ) {
			return false;
		}

		if ( $this->has_lock_expired( $shas[ $sha ] ) ) {
			unset( $shas[ $sha ] );
			set_transient( 'localci_sha_lock', $shas, 5 * MINUTE_IN_SECONDS );
			return false;
		}

		return true;
	}

	private function set_lock( $sha ) {
		$shas = get_transient( 'localci_sha_lock' );

		if ( empty( $shas ) || ! is_array( $shas ) ) {
			$shas = array();
		}

		$shas[ $sha ] = time();
		set_transient( 'localci_sha_lock', $shas, 5 * MINUTE_IN_SECONDS );
	}

	private function has_lock_expired( $sha_lock_time ) {
		return time() > $sha_lock_time + HOUR_IN_SECONDS;
	}

	private function log( $type, $context, $message ) {
		GP_LocalCI::get_instance()->log( $type, $context, $message );
	}
}

class GP_LocalCI {
	private static $instance = null;
	public $id = 'gp_localci';

	public static function init() {
		self::get_instance();
	}

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	function __construct() {
		$this->init_new_routes();
		$this->debug = apply_filters( 'gp_localci_debug', false );
	}

	function log( $type, $context, $message ) {
		do_action( 'gp_localci_log', $type, $context, $message );
	}

	function init_new_routes() {
		$owner = $repo = '([0-9a-zA-Z_\-\.]+?)';
		$branch = '(.+?)';

		GP::$router->add( '/localci/-relay-new-strings-to-gh', array( 'GP_Route_LocalCI', 'relay_new_strings_to_gh' ), 'post' );
		GP::$router->add( '/localci/-relay-string-freeze-from-gh', array( 'GP_Route_LocalCI', 'relay_string_freeze_from_gh' ), 'post' );
		GP::$router->add( "/localci/status/$owner/$repo/$branch", array( 'GP_Route_LocalCI', 'status' ), 'get' );
	}
}

add_action( 'gp_init', array( 'GP_LocalCI', 'init' ) );
