<?php

define( 'LOCALCI_CIRCLECI_API_URL', 'https://circleci.com/api/v1.1' );

class GP_LocalCI_CircleCI_Adapter implements GP_LocalCI_CI_Adapter {

	use GP_LocalCI_Cached_Remote_Get;

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

		$pull_request = $this->payload->pull_requests[0]->url;
		$pull_request = substr( $pull_request, strrpos( $pull_request, '/' ) + 1 );

		return (object) array(
			'owner'     => mb_strtolower( $this->payload->username ),
			'repo'      => $this->payload->reponame,
			'sha'       => $this->payload->vcs_revision,
			'branch'    => $this->payload->branch,
			'pr_number' => $pull_request,
		);
	}

	public function get_most_recent_pot( $gh_data ) {
		return $this->get_new_strings_pot( array(
			'build_num' => 'latest',
			'branch'    => $gh_data->branch,
			'reponame'  => $gh_data->repo,
			'username'  => $gh_data->owner,
			'vcs_type'  => $gh_data->vcs_type,
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

		$response = $this->cached_get( esc_url_raw( $url ), 5 * MINUTE_IN_SECONDS );
		$artifacts = json_decode( $response );
		$new_strings_artifact = false;

		if ( ! $artifacts ) {
			 return false;
		}

		foreach ( $artifacts as $artifact ) {
			if ( '$CIRCLE_ARTIFACTS/translate/localci-new-strings.pot' === $artifact->pretty_path ) {
				$new_strings_artifact = $artifact;
				break;
			}
		}

		if ( empty( $new_strings_artifact ) ) {
			return false;
		}

		$artifact_file = $this->cached_get( esc_url_raw( $new_strings_artifact->url . "?circle-token={$token}" ) );

		return $artifact_file;
	}
}
