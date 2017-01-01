<?php
class GP_LocalCI_Github_Adapter {
	public function __construct() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) || ! gp_startswith( $_SERVER['HTTP_USER_AGENT'], 'GitHub-Hookshot' ) ) {
			return false;
		}

		$this->headers = array(
			'X-GitHub-Event'  => $_SERVER['HTTP_X_GITHUB_EVENT'],
			'X-Hub-Signature' => $_SERVER['HTTP_X_HUB_SIGNATURE'],
		);

		if ( $_SERVER['HTTP_CONTENT_TYPE'] === 'application/x-www-form-urlencoded' ) {
			$this->raw_payload = $_POST['payload'];
		} else {
			$this->raw_payload = file_get_contents( 'php://input' );
		}

		$this->payload = json_decode( $this->raw_payload );

		$this->owner   = isset( $this->payload->repository->owner->login ) ? $this->payload->repository->owner->login : false;
		$this->repo    = isset( $this->payload->repository->name) ? $this->payload->repository->name : false;
		$this->branch  = isset( $this->payload->pull_request->head->ref ) ? $this->payload->pull_request->head->ref : false;
		$this->sha     = isset( $this->payload->pull_request->head->sha ) ? $this->payload->pull_request->head->sha : false;
	}

	public function generate_webhook_signature( $owner, $repo ) {
		return 'sha1=' . hash_hmac( 'sha1', $this->raw_payload, GP_LocalCI_Config::get_value( $owner, $repo, 'github_webhook_secret' ) );
	}

	public function is_valid_request( $owner, $repo ) {
		return hash_equals( $this->headers['X-Hub-Signature'], $this->generate_webhook_signature( $owner, $repo ) );
	}

	public function is_data_valid( $data ) {
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

	public function is_string_freeze_label_added_event() {
		if ( 'pull_request' !== $this->headers['X-GitHub-Event'] ) {
			return false;
		}

		if ( 'labeled' !== $this->payload->action ) {
			return false;
		}

		if ( LOCALCI_GITHUB_STRING_FREEZE_LABEL !== $this->payload->label->name ) {
			return false;
		}

		return true;
	}

	public function post_to_status_api( $owner, $repo, $sha, $localci_summary ) {
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
}
