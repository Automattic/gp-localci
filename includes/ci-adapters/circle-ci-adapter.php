<?php
class GP_LocalCI_CircleCI_Adapter implements GP_LocalCI_CI_Adapter {
	private $payload;

	public function __construct() {
		$json = json_decode( file_get_contents( 'php://input' ) );
		$this->payload = $json->payload;
	}

	public function get_payload() {
		return $this->payload;
	}

	public function get_gh_data() {
		return (object) array(
			'owner'  => $this->payload->username,
			'repo'   => $this->payload->reponame,
			'sha'    => $this->payload->vcs_revision,
			'branch' => $this->payload->branch,
		);
	}

	public function get_new_strings_po() {
		$response = $this->safe_get( $this->payload->build_url );

		if ( empty( $response ) || is_wp_error( $response ) ) {
			$this->die_with_error( "Artifact pull failed.", 400 );
		}
	}

	public function get_gp_project_id() {
	}

	public function safe_get( $url ) {
		if ( ! gp_startswith( $url, 'https://circleci.com' ) ) {
			return new WP_Error;
		}

		return wp_remote_get( $url, array() );
	}
}

