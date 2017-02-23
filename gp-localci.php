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

require __DIR__ . '/includes/localci-traits.php';
require __DIR__ . '/includes/ci-adapters.php';
require __DIR__ . '/includes/db-adapter.php';
require __DIR__ . '/includes/gh-adapter.php';
require __DIR__ . '/includes/es-adapter.php';
require __DIR__ . '/includes/localci-functions.php';

class GP_Route_LocalCI extends GP_Route_Main {

	use GP_Localci_Log;

	public function __construct( $ci = null, $db = null, $gh = null ) {
		$this->ci = isset( $ci ) ? $ci : $this->get_ci_adapter( LOCALCI_BUILD_CI );
		$this->db = isset( $db ) ? $db : new GP_LocalCI_DB_Adapter();
		$this->gh = isset( $gh ) ? $gh : new GP_LocalCI_Github_Adapter();
		$this->es = isset( $es ) ? $es : new GP_LocalCI_ES_Adapter();

		$this->template_path = __DIR__ . '/templates/';
	}

	public function relay_new_strings_to_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$gh_data = $this->ci->get_gh_data();
		if ( ! $this->gh->set_gh_data( $gh_data ) ) {
			$this->log( 'error', 'invalid-gh-data-from-ci', $gh_data );
			$this->die_with_error( 'Invalid Github data from CI.', 406 );
		}

		if ( 'master' === $gh_data->branch ) {
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
		$this->gh->post_to_status_api( 'Processing...', 'pending' );

		if ( '' === $po_file ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		$po = localci_load_po( $po_file );
		if ( empty( $po->entries ) ) {
			$this->gh->post_to_status_api( '0 new strings. ¡Ándale!', 'success' );
			$this->tmpl( 'status-ok' );
			exit;
		}

		$coverage  = $this->db->get_string_coverage( $po, $project_id );
		$stats     = localci_generate_coverage_stats( $po, $coverage );

		$pr_state = $this->pr_status_state( $stats, $this->gh->is_pr_in_string_freeze() );

		$this->gh->post_to_status_api( $stats['summary'], $pr_state );

		$new_strings_suggestions = $this->es->get_suggestions( $coverage['new_strings'] );
		$comments = $this->gh->post_suggestions_comments( $new_strings_suggestions );

		$this->log( 'result', 'relay-new-strings-to-gh-result', array( 'comments' => $comments, 'gh_data' => $gh_data, 'stats' => $stats, 'coverage' => $coverage ) );

		$this->tmpl( 'status-ok' );
	}

	public function relay_string_freeze_change_from_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		if ( ! $this->gh->parse_incoming_request() ) {
			$this->die_with_error( 'Invalid request.', 401 );
		}

		$gh_data = $this->gh->get_gh_data();

		if ( ! $this->gh->is_string_freeze_label_changed_event() ) {
			$this->tmpl( 'status-ok' );
			exit;
		}

		$po_file = $this->ci->get_most_recent_pot( $gh_data );
		if ( ! $po_file ) {
			$this->log( 'error', 'ci-strings-file-not-found', func_get_args() );
			$this->die_with_error( 'Unable to retrieve strings file from CI.', 400 );
		}

		$project_id  = GP_LocalCI_Config::get_value( $gh_data->owner, $gh_data->repo, 'gp_project_id' );
		$po         = localci_load_po( $po_file );
		$coverage   = $this->db->get_string_coverage( $po, $project_id );
		$stats      = localci_generate_coverage_stats( $po, $coverage );

		$pr_state = $this->pr_status_state( $stats, $this->gh->is_pr_in_string_freeze() );
		$this->gh->post_to_status_api( $stats['summary'], $pr_state );

		$this->tmpl( 'status-ok' );
	}

	public function status( $owner, $repo, $branch ) {
		$this->gh->set_gh_data(
			(object) array(
				'owner' => $owner,
				'repo' => $repo,
				'branch' => $branch,
			)
		);

		$po_file = $this->ci->get_most_recent_pot( $this->gh->get_gh_data() );

		if ( ! $po_file ) {
			$this->log( 'error', 'ci-strings-file-not-found', func_get_args() );
			$this->die_with_error( 'Unable to retrieve strings file from CI for this branch. This usually happens when the latest build failed.', 400 );
		}

		$pull_request = $this->gh->get_pull_request();

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

		$coverage['new_strings'] = $this->es->get_suggestions( $coverage['new_strings'] );

		add_action( 'gp_head', array( $this, 'status_page_css' ) );
		$this->tmpl( 'status-details', get_defined_vars() );
	}

	public function status_page_css() {
		wp_register_style( 'gp-localci', plugins_url( 'css/gp-localci.css', __FILE__ ) );
		gp_enqueue_style( 'gp-localci' );
	}

	public function string_freeze_pot( $owner, $repo ) {
		if ( ! $this->is_authorized_remote_action() ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$gh_data = (object) array(
			'owner' => $owner,
			'repo' => $repo,
		);

		$this->gh->set_gh_data( $gh_data );

		$po = new PO();
		$prs = $this->gh->get_string_freeze_prs();
		foreach ( $prs as $pr_number ) {
			$gh_data->branch = $this->gh->get_pull_request_branch( $pr_number );
			$po_file_for_branch = $this->ci->get_most_recent_pot( $gh_data );
			if ( $po_file_for_branch ) {
				$po->import_from_file( 'data://text/plain,' . urlencode( $po_file_for_branch ) );
			}
		}

		if ( ! empty( $po->entries ) ) {
			$this->headers_for_download( sanitize_file_name( $repo . '-string-freeze.pot' ) );
			echo $po->export(); //WPCS: XSS OK
		} else {
			$this->die_with_error( 'No strings found', 404 );
		}
	}

	public function update_string_freeze_prs_status( $owner, $repo ) {
		if ( ! $this->is_authorized_remote_action() ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$gh_data = (object) array(
			'owner' => $owner,
			'repo' => $repo,
		);

		$this->gh->set_gh_data( $gh_data );

		$project_id  = GP_LocalCI_Config::get_value( $gh_data->owner, $gh_data->repo, 'gp_project_id' );
		$prs = $this->gh->get_string_freeze_prs();

		foreach ( $prs as $pr_number ) {
			$gh_data->branch = $this->gh->get_pull_request_branch( $pr_number );
			$po_file_for_branch = $this->ci->get_most_recent_pot( $gh_data );
			$po         = localci_load_po( $po_file_for_branch );
			$coverage   = $this->db->get_string_coverage( $po, $project_id );
			$stats      = localci_generate_coverage_stats( $po, $coverage );

			$pr_state = $this->pr_status_state( $stats, true );
			$this->gh->post_to_status_api( $stats['summary'], $pr_state );
		}
	}

	/**
	 * The nitty gritty details
	 */
	private function get_ci_adapter( $ci ) {
		$ci_adapter = 'GP_LocalCI_' . $ci . '_Adapter';
		return new $ci_adapter;
	}

	private function is_locked( $sha ) {
		if ( GP_LocalCI::get_instance()->debug ) {
			return false;
		}

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

	private function pr_status_state( $stats, $string_freeze = false ) {
		if ( ! $string_freeze ) {
			return 'success';
		}

		$state = ( 100 === absint( $stats['percent_translated'] ) ) ? 'success' : 'failure';

		return $state;
	}

	private function is_authorized_remote_action() {
		return defined( 'LOCALCI_REMOTE_ACTION_TOKEN' ) && LOCALCI_REMOTE_ACTION_TOKEN === $_REQUEST['token'];
	}
}

class GP_LocalCI {
	private static $instance = null;
	public $id = 'gp_localci';
	public $debug;

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

	function init_new_routes() {
		$owner = $repo = '([0-9a-zA-Z_\-\.]+?)';
		$branch = '(.+?)';

		if ( class_exists( 'GP' ) ) {
			GP::$router->add( '/localci/-relay-new-strings-to-gh', array( 'GP_Route_LocalCI', 'relay_new_strings_to_gh' ), 'post' );
			GP::$router->add( '/localci/-relay-string-freeze-change-from-gh', array( 'GP_Route_LocalCI', 'relay_string_freeze_change_from_gh' ), 'post' );
			GP::$router->add( "/localci/status/$owner/$repo/$branch", array( 'GP_Route_LocalCI', 'status' ), 'get' );
			GP::$router->add( "/localci/string-freeze-pot/$owner/$repo", array( 'GP_Route_LocalCI', 'string_freeze_pot' ), 'get' );
			GP::$router->add( "/localci/update-string-freeze-status/$owner/$repo", array( 'GP_Route_LocalCI', 'update_string_freeze_prs_status' ), 'get' );
		}
	}
}

add_action( 'gp_init', array( 'GP_LocalCI', 'init' ) );
