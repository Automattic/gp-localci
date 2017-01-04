<?php

define( 'LOCALCI_CIRCLECI_API_URL', 'https://circleci.com/api/v1.1' );

class GP_LocalCI_CircleCI_Adapter implements GP_LocalCI_CI_Adapter {
	private $payload;

	public function __construct() {
		$json = json_decode( file_get_contents( 'php://input' ) );
		$this->payload = is_object( $json ) && isset( $json->payload ) ? $json->payload : null;
	}

	public function get_payload() {
		return $this->payload;
	}

	public function get_gh_data() {
		if ( ! is_object( $this->payload ) ) {
			return false;
		}

		return (object) array(
			'owner'  => $this->payload->username,
			'repo'   => $this->payload->reponame,
			'sha'    => $this->payload->vcs_revision,
			'branch' => $this->payload->branch,
		);
	}

	public function get_most_recent_pot( $username, $reponame, $branch, $vcs_type = 'github' ) {
		return $this->get_new_strings_pot( array(
			'build_num' => 'latest',
			'branch'    => $branch,
			'reponame'  => $reponame,
			'username'  => $username,
			'vcs_type'  => $vcs_type,
		) );
	}

	public function get_new_strings_pot( $args = array() ) {
		if ( is_object( $this->payload ) ) {
			$default_args = array(
				'build_num'  => $this->payload->build_num,
				'reponame'   => $this->payload->reponame,
				'username'   => $this->payload->username,
				'vcs_type'   => $this->payload->vcs_type,
			);
		} else {
			$default_args = array();
		}

		$args = wp_parse_args( $args, $default_args );

		$path  = "{$args['vcs_type']}/{$args['username']}/{$args['reponame']}/{$args['build_num']}";
		$token = GP_LocalCI_Config::get_value( $args['username'], $args['reponame'], 'build_ci_api_token' );
		$url   = LOCALCI_CIRCLECI_API_URL . "/project/{$path}/artifacts?circle-token={$token}";

		if ( ! empty( $args['branch'] ) ) {
			$url = add_query_arg( array(
				'branch' => $args['branch'],
				'filter' => 'successful',
			), $url );
		}

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
