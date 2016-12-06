<?php
class GP_LocalCI_CircleCI_Adapter implements GP_LocalCI_CI_Adapter {
	private $payload;

	function __construct() {
		$json = json_decode( file_get_contents( 'php://input' ) );
		$this->payload = $json->payload;
	}

	function get_build_owner() {
		return $this->payload->username;
	}

	function get_build_repo() {
		return $this->payload->reponame;
	}

	function get_build_sha() {
		return $this->payload->vcs_revision;
	}

	function get_new_strings_po() {
		$response = $this->safe_get( $this->payload->build_url );

		if ( empty( $response ) || is_wp_error( $response ) ) {
			$this->die_with_error( "Artifact pull failed.", 400 );
		}
	}

	function get_gp_project_id() {
	}
}

