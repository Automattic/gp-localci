<?php

define( 'LOCALCI_CIRCLECI_API_URL', 'https://circleci.com/api/v1.1' );

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

	public function get_new_strings_pot() {
		if ( ! $this->payload->has_artifacts ) {
			return false;
		}

		$path  = "{$this->payload->vcs_type}/{$this->payload->username}/{$this->payload->reponame}/{$this->payload->build_num}";
		$token = GP_LocalCI_Config::get_value( $this->payload->username, $this->payload->reponame, 'build_ci_api_token' );
		$url   = LOCALCI_CIRCLECI_API_URL . "/project/{$path}/artifacts?circle-token={$token}";

		$response = wp_remote_get( esc_url_raw( $url ) );

		if ( empty( $response ) || is_wp_error( $response ) ) {
			return false;
		}

		$artifacts = json_decode( wp_remote_retrieve_body( $response ) );
		$new_strings_artifact = false;

		foreach ( $artifacts as $artifact ) {
			if ( '$CIRCLE_ARTIFACTS/translate/localci-new-strings.pot' == $artifact->pretty_path ) {
				$new_strings_artifact = $artifact;
				break;
			}
		}

		if ( empty( $new_strings_artifact ) ) {
			return false;
		}

		$response = wp_remote_get( esc_url_raw( $new_strings_artifact->url . "?circle-token={$token}" ) );

		return wp_remote_retrieve_body( $response );
	}
}
