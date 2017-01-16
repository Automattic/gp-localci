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

		if ( 'application/x-www-form-urlencoded' === $_SERVER['HTTP_CONTENT_TYPE'] ) {
			$this->raw_payload = $_POST['payload'];
		} else {
			$this->raw_payload = file_get_contents( 'php://input' );
		}

		$this->payload = json_decode( $this->raw_payload );

		$this->owner   = isset( $this->payload->repository->owner->login ) ? $this->payload->repository->owner->login : false;
		$this->repo    = isset( $this->payload->repository->name ) ? $this->payload->repository->name : false;
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

	public function get_pull_request( $owner, $repo, $branch ) {
		// TODO: auth
		$url = LOCALCI_GITHUB_API_URL . "/repos/$owner/$repo/pulls?sort=updated&direction=desc" ;
		$response = localci_cached_remote_get( add_query_arg( 'head', "$owner:$branch", $url ) );
		if ( $response ) {
			$pulls = json_decode( $response );
			return $pulls[0];
		}

		return false;
	}

	public function post_suggestions_comments( $gh_data, $coverage ) {

		$owner = $gh_data->owner;
		$repo = $gh_data->repo;
		$pr_number = $gh_data->pr_number;
		$sha = $gh_data->sha;
		$branch = $gh_data->branch;

		$url = "/repos/$owner/$repo/pulls/$pr_number/comments";
		$status_page_url = gp_url_public_root() . "localci/status/$owner/$repo/$branch";

		// Delete previous comments
		$current_pr_comments = localci_cached_remote_get( LOCALCI_GITHUB_API_URL . $url );
		if ( $current_pr_comments ) {
			$current_pr_comments = json_decode( $current_pr_comments );
			foreach ( $current_pr_comments as $comment ) {
				if ( 'a8ci18n' === $comment->user->login ) {
					$this->api_delete( "/repos/$owner/$repo/pulls/comments/{$comment->id}" );
				}
			}
		}

		$diff = $this->get_pull_request_diff( $owner, $repo, $pr_number );
		foreach ( $coverage['new_strings'] as $string ) {
			if ( $string['suggestions'] ) {
				list( $file, $line ) = array_pad( explode( ':',
					array_pop(
						preg_split( '/\s+/', $string['references'], -1, PREG_SPLIT_NO_EMPTY )
					)
				), 2, 0 );

				$re = '/' . preg_quote( $file, '/' ) . '\n@@.*\n([\s\S]*?)(diff|\Z)/m';
				preg_match_all( $re, $diff, $matches );

				if ( ! empty( $matches[1] ) ) {
					$message_body = $this->pr_suggestion_comment( $matches[1][0], $string, $string['suggestions'], $file, $sha, $status_page_url );
					if ( $message_body ) {
						$this->api_post( $url, $message_body );
					}
				}
			}
		}
	}

	private function pr_suggestion_comment( $diff, $string, $suggestions, $file, $sha, $status_page_url ) {
		// Find the line number to comment on
		$lines = explode( "\n", $diff );

		$current_translation_count = false;
		$message = '';

		$best_suggestion = array_shift( $suggestions );
		foreach ( $lines as $line_number => $line ) {
			if ( ! gp_startswith( $line, '+' ) || ! gp_in( $string['singular'], $line )  ) {
				continue;
			}

			// Is this a string change, and our best suggestion is the previous string?
			if ( gp_in( $best_suggestion['singular'], $lines[ $line_number - 1 ] ) ) {
				$current_translation_count = count( $best_suggestion['locales'] );
				$best_suggestion = false;
				if ( ! empty( $suggestions ) ) {
					$best_suggestion = array_shift( $suggestions );
				};
			}

			if ( $current_translation_count ) {
				$message .= "Warning: $current_translation_count translations will be lost with this change.\n";
			}

			if ( $best_suggestion ) {
				$message .= 'Alternate string suggestion: ' . $this->format_string_for_comment( $best_suggestion );
			}

			if ( '' !== $message ) {
				$message .= "\n Visit the [PR Translation status page]($status_page_url) for details.";
				$body = array(
					'body' => $message,
					'commit_id' => $sha,
					'path' => $file,
					'position' => $line_number + 1,
				);
				return $body;
			}
		}

		return false;
	}

	private function format_string_for_comment( $suggestion ) {
		$formatted = "```translate( '{$suggestion['singular']}'";
		if ( ! is_null( $suggestion['plural'] )  ) {
			$formatted .= ", '{$suggestion['plural']}'";
		}
		if ( ! is_null( $suggestion['context'] )  ) {
			$formatted .= ", { context: '{$suggestion['context']}'}";
		}
		$formatted .= ' )```';

		return $formatted;
	}

	private function get_pull_request_diff( $owner, $repo, $pr_number ) {
		// TODO: auth
		$url = LOCALCI_GITHUB_API_URL . "/repos/$owner/$repo/pulls/$pr_number" ;

		$args = array( 'headers' => array( 'Accept' => 'application/vnd.github.3.diff' ) );
		$diff = localci_cached_remote_get( $url, 5 * MINUTE_IN_SECONDS, $args );

		return $diff;
	}

	public function post_to_status_api( $owner, $repo, $sha, $branch, $localci_summary ) {
		$data = array(
			'state'       => 'success',
			'description' => $localci_summary,
			'context'     => 'ci/i18n',
			'target_url'  => gp_url_public_root() . "localci/status/$owner/$repo/$branch",
		);

		return $this->api_post( "/repos/$owner/$repo/statuses/$sha", $data );
	}

	private function api_post( $path, $body ) {
		$post_data = array(
			'headers' => array(
				'Authorization' => 'token ' . LOCALCI_GITHUB_API_MANAGEMENT_TOKEN,
			),
			'body' => wp_json_encode( $body ),
			'blocking' => false,
			'timeout' => 30,
			'user-agent' => 'LocalCI/GP v1.0',
		);

		$r = wp_safe_remote_post( LOCALCI_GITHUB_API_URL . $path, $post_data );
		return $r;
	}

	private function api_delete( $path ) {
		wp_remote_request(
			LOCALCI_GITHUB_API_URL . $path,
			array(
				'headers' => array(
					'Authorization' => 'token ' . LOCALCI_GITHUB_API_MANAGEMENT_TOKEN,
				),
				'timeout' => 30,
				'blocking' => true,
				'method' => 'DELETE',
			)
		);
	}

	private function api_get( $path, $params, $headers, $cache_time ) {

	}
}
