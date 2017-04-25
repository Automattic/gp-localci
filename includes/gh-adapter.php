<?php
class GP_LocalCI_Github_Adapter {

	use GP_Localci_Log, GP_LocalCI_Cached_Remote_Get {
		GP_Localci_Log::log insteadof GP_LocalCI_Cached_Remote_Get;
	}

	private $data;

	private $payload;
	private $raw_payload;
	private $headers;

	public function parse_incoming_request() {

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

		$data = (object) array(
			'owner'     => isset( $this->payload->repository->owner->login ) ? mb_strtolower( $this->payload->repository->owner->login ) : false,
			'repo'      => isset( $this->payload->repository->name ) ? $this->payload->repository->name : false,
			'branch'       => isset( $this->payload->pull_request->head->ref ) ? $this->payload->pull_request->head->ref : false,
			'sha'    => isset( $this->payload->pull_request->head->sha ) ? $this->payload->pull_request->head->sha : false,
			'pr_number' => isset( $this->payload->number ) ? $this->payload->number  : false,
		);

		if ( ! $this->is_valid_request( $data->owner, $data->repo ) ) {
			return false;
		}

		return $this->set_gh_data( $data );
	}

	public function set_gh_data( $data ) {
		if ( ! $this->is_data_valid( $data ) ) {
			return false;
		}

		$data->vcs_type = 'github';
		$this->data = $data;
		return true;
	}

	public function get_gh_data() {
		return $this->data;
	}

	public function __get( $key ) {
		if ( isset( $this->data->$key ) ) {
			return $this->data->$key;
		}
		$this->log( 'property-not-set', 'gh-adapter', array(
			'key' => $key,
			'data' => $this->data,
		) );
	}

	public function generate_webhook_signature( $owner, $repo ) {
		return 'sha1=' . hash_hmac( 'sha1', $this->raw_payload, GP_LocalCI_Config::get_value( $owner, $repo, 'github_webhook_secret' ) );
	}

	private function is_valid_request( $owner, $repo ) {
		return hash_equals( $this->headers['X-Hub-Signature'], $this->generate_webhook_signature( $owner, $repo ) );
	}

	private function is_data_valid( $data ) {
		if ( empty( $data->owner ) || empty( $data->repo ) ) {
			return false;
		}

		if ( ! is_string( $data->owner ) || ! is_string( $data->repo ) ) {
			return false;
		}

		if ( ! empty( $data->sha ) && ( ! is_string( $data->sha ) ||  40 !== strlen( $data->sha ) ) ) {
			return false;
		}

		if ( isset( $data->brach ) && ( ! is_string( $data->branch ) ) ) {
			return false;
		}

		return true;
	}

	public function is_string_freeze_label_changed_event() {
		if ( 'pull_request' !== $this->headers['X-GitHub-Event'] ) {
			return false;
		}

		if ( 'labeled' !== $this->payload->action && 'unlabeled' !== $this->payload->action ) {
			return false;
		}

		if ( LOCALCI_GITHUB_STRING_FREEZE_LABEL !== $this->payload->label->name ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the latest github pull request based on a specific branch.
	 *
	 * @return bool|object Github pull request object, or false if failed.
	 */
	public function get_pull_request() {
		// TODO: probably doesn't work with forks prs.
		$api_path = "/repos/{$this->data->owner}/{$this->data->repo}/pulls?sort=updated&direction=desc&head={$this->data->owner}:{$this->data->branch}" ;
		$response = $this->api_get( $api_path );
		if ( $response ) {
			$pulls = json_decode( $response );
			return $pulls[0];
		}

		return false;
	}

	/**
	 * Post string suggestions comments on Github PR.
	 *
	 * @param array  $new_strings List of new strings and their suggestions
	 *
	 * @return array Array containing numbers of new, edited, and existing comments on PR.
	 */
	public function post_suggestions_comments( $new_strings ) {
		$owner_repo = $this->owner . '/' . $this->repo;

		$existing_comments = $edited_comments = $new_comments = 0;
		$api_path = "/repos/$owner_repo/pulls/{$this->pr_number}/comments";
		$status_page_url = gp_url_public_root() . "localci/status/$owner_repo/{$this->branch}";

		// Parse previous comments by our bot.
		$current_pr_comments = $this->api_get( $api_path, array(), 30 );
		$previous_comments = array();
		if ( $current_pr_comments ) {
			$current_pr_comments = json_decode( $current_pr_comments );
			foreach ( $current_pr_comments as $comment ) {
				if ( LOCALCI_GITHUB_USER_NAME === $comment->user->login ) {
					$previous_comments[ $comment->path ][ $comment->position ] = $comment;
				}
			}
		}

		$diff = $this->get_pull_request_diff();
		foreach ( $new_strings as $string ) {
			if ( isset( $string['suggestions'] ) || isset( $string['context_message'] ) ) {
				// Get the filename where this string was added
				list( $file, $line ) = array_pad( explode( ':',
					array_pop(
						preg_split( '/\s+/', $string['references'], -1, PREG_SPLIT_NO_EMPTY )
					)
				), 2, 0 );

				// Get the diff hunk for the current file
				$re = '/' . preg_quote( $file, '/' ) . '\n@@.*\n([\s\S]*?)(^diff|\Z)/m';
				preg_match_all( $re, $diff, $matches );
				if ( empty( $matches[1] ) ) {
					continue;
				}

				// Build the comment
				$comment = $this->pr_suggestion_comment( $matches[1][0], $string, $file, $status_page_url );
				if ( ! $comment ) {
					continue;
				}

				if ( ! isset( $previous_comments[ $file ][ $comment['position'] ] ) ) {
					// New comment
					if ( $this->api_post( $api_path, $comment ) ) {
						$new_comments++;
					};
				} else {
					// We've already commented on this line.
					$previous_comment = $previous_comments[ $file ][ $comment['position'] ];

					// With the same text? no need to post again.
					if ( $comment['body'] === $previous_comment->body	) {
						$existing_comments++;
						continue;
					}

					// Different text? let's update our original comment.
					if ( $this->api_patch( "/repos/$owner_repo/pulls/comments/{$previous_comment->id}", $comment ) ) {
						$edited_comments++;
					}
				}
			}
		}

		return array( 'new' => $new_comments, 'edited' => $edited_comments, 'existing' => $existing_comments );
	}

	/**
	 * Composes a suggestion comment for a PR.
	 *
	 * @param string $diff             Diff hunk for specific file.
	 * @param array  $string           String that was added in the PR.
	 * @param string $file             Filename where the string was added.
	 * @param string $status_page_url  Url of the GlotPress status page for that PR.
	 *
	 * @return array|bool  Array containing data to be sent to Github api, or false if no suggestions.
	 */
	private function pr_suggestion_comment( $diff, $string, $file, $status_page_url ) {
		// Find the line number to comment on
		$lines = explode( "\n", $diff );

		// Set up some vars
		$string_change = $best_suggestion = $current_translation_count = false;
		$message = '';
		if ( is_array( $string['suggestions'] ) ) {
			$suggestions = $string['suggestions'];
			$best_suggestion = array_shift( $suggestions );
		}

		// Since strings are sometimes joined over multiple lines, we don't want to look just at a single line
		// Let's look into as many lines as it would take to fit 40 chars per line.
		$max_number_of_lines = ceil( strlen( $string['singular'] ) / 40 );

		// first words:
		$words_in_string = explode( ' ', $string['singular'] );
		$start_of_string = join( ' ', array_slice( $words_in_string, 0, 3 ) );

		foreach ( $lines as $line_number => $line ) {
			// Looking for additions only
			if ( ! gp_in( $start_of_string, $line ) || gp_startswith( $line, '-' ) ) {
				continue;
			}

			// Since strings are sometimes joined over multiple lines, we don't want to look just at a single line
			// Let's look into as many lines as it would take to fit 40 chars per line.
			$search_lines = array();

			$i = 0;
			while ( ( $line_number + $i <= count( $lines ) ) && ( count( $search_lines ) < $max_number_of_lines ) ) {
				// We only search for additions or existing code
				// Existing: because it's possible that only part of a multi-line string has changed
				if ( ! gp_startswith( $lines[ $line_number + $i ], '-' ) ) {
					$search_lines[] = $lines[ $line_number + $i ];
				}
				$i++;
			}

			// To simplify our regex, we use a custom character combination instead of new lines.
			$search_in = implode( '_N_', $search_lines );
			$search_in = preg_replace( '/([\'|"]\s?\+_N_\+?\s+[\'|"])/', '', wp_unslash( $search_in ) );

			if ( ! preg_match( '/[\'"]' . preg_quote( $string['singular'], '/' ) . '[\'"]/', $search_in ) ) {
				continue;
			}

			// Is this a string change, and our best suggestion is the previous string?
			// Check by trying to match our best suggestion in the previous (max) 5 lines of deleted lines the diff hunk.
			$start_at_line     = max( $line_number - 5, 0 );
			$number_of_lines   = min( 5, $line_number );
			$lines_range       = array_slice( $lines, $start_at_line, $number_of_lines );
			$lines_range = array_filter( $lines_range, function( $line ){
				return wp_startswith( $line, '-' );
			});

			if ( $best_suggestion && $this->string_in_array( $best_suggestion['singular'], $lines_range ) ) {
				$string_change = true;
				// Will GlotPress discard the translations with this change?
				$current_translation_count = count( $best_suggestion['locales'] );
				if ( $this->translations_will_be_discarded( $string, $best_suggestion ) ) {
					$best_suggestion = false;
					$message .= ":disappointed: $current_translation_count existing translations will be lost with this change.\n";
					if ( ! empty( $suggestions ) ) {
						$best_suggestion = array_shift( $suggestions );
					};
				} else {
					$best_suggestion = false;
					$message .= ":ok: This change is safe, we'll keep the existing $current_translation_count translations.\n";
				}
			}

			// Not a string change, or we have an additional suggestion
			if ( $best_suggestion ) {
				$score = isset( $best_suggestion['score'] ) ? ' *ES Score: ' . number_format( $best_suggestion['score'], 0 ) . '*' : '';
				$translation_count_times = count( $best_suggestion['locales'] ) > 1 ? 'times' : 'time';
				if ( $current_translation_count ) {
					$message .= 'If the string must change, the following option has already been translated **' . count( $best_suggestion['locales'] ) . "** {$translation_count_times}:\n";
				} else {
					$message .= 'Hi! I\'ve found a possible matching string that has already been translated **' . count( $best_suggestion['locales'] ) . "** {$translation_count_times}:\n";
				}
				$message .= $this->format_string_for_comment( $best_suggestion ) . $score . "\n";
				if ( count( $suggestions ) ) {
					$suggestions_count = count( $suggestions ) > 1 ? 'suggestions' : 'suggestion';
					$message .= sprintf( "See %d additional %s in the [PR translation status page](%s)\n", count( $suggestions ), $suggestions_count, $status_page_url );
				}

				$message .= "\nHelp me improve these suggestions: react with :thumbsdown: if the suggestion doesn't make any sense, or with :thumbsup: if it's a particularly good one (even if not implemented).";
			}

			if ( $string['context_message'] && ! $string_change ) {
				$message .= "\n\n:information_source: " . $string['context_message'];
			}

			if ( '' !== $message ) {
				$body = array(
					'body' => $message,
					'commit_id' => $this->sha,
					'path' => $file,
					'position' => $line_number + 1,
				);
				return $body;
			}
		}

		return false;
	}

	/**
	 * Formats a suggested string for the PR comment, using i18n-calypso format.
	 *
	 * @param array $suggestion Suggested string.
	 *
	 * @return string The formatted suggestion.
	 */
	private function format_string_for_comment( $suggestion ) {
		// TODO: add filter for other formatting options.
		$formatted = "`translate( '{$suggestion['singular']}'";
		if ( ! is_null( $suggestion['plural'] )  ) {
			$formatted .= ", '{$suggestion['plural']}'";
		}
		if ( ! is_null( $suggestion['context'] )  ) {
			$formatted .= ", { context: '{$suggestion['context']}'}";
		}
		$formatted .= ' )`';

		return $formatted;
	}

	/**
	 * Fetches the pull request diff from Github, based on PR number.
	 *
	 * @return bool|string Diff string, or false if unsuccessful.
	 */
	private function get_pull_request_diff() {
		$api_path = "/repos/{$this->owner}/{$this->repo}/pulls/{$this->pr_number}" ;

		$args = array( 'headers' => array( 'Accept' => 'application/vnd.github.3.diff' ) );
		$diff = $this->api_get( $api_path, $args, 5 * MINUTE_IN_SECONDS );

		return $diff;
	}

	public function is_pr_in_string_freeze() {
		$api_path = "/repos/{$this->owner}/{$this->repo}/issues/{$this->pr_number}/labels" ;
		$labels = json_decode( $this->api_get( $api_path, array(),  MINUTE_IN_SECONDS ) );
		return in_array( LOCALCI_GITHUB_STRING_FREEZE_LABEL, wp_list_pluck( $labels, 'name' ), true );
	}

	public function get_string_freeze_prs() {
		$api_path = "/repos/{$this->owner}/{$this->repo}/issues?state=open&labels=" . urlencode( LOCALCI_GITHUB_STRING_FREEZE_LABEL );
		$prs = json_decode( $this->api_get( $api_path, array(),  30 * MINUTE_IN_SECONDS ) );
		return wp_list_pluck( wp_list_filter( $prs, array( 'pull_request' => null ), 'NOT' ), 'number' );
	}

	public function get_pull_request_branch( $pr_number ) {
		$api_path = "/repos/{$this->owner}/{$this->repo}/pulls/{$pr_number}";
		$pr = json_decode( $this->api_get( $api_path ) );

		$this->data->branch = $pr->head->ref;
		$this->data->sha = $pr->head->sha;

		return $this->data->branch;
	}

	/**
	 * Posts to github status api on a commit
	 *
	 * @param string $description  Localci summary data.
	 * @param string $state        Localci status state.
	 *
	 * @return array|bool|WP_Error
	 */
	public function post_to_status_api( $description, $state  ) {
		$owner_repo = $this->owner . '/' . $this->repo;

		$data = array(
			'state'       => $state,
			'description' => $description,
			'context'     => 'ci/i18n',
			'target_url'  => gp_url_public_root() . "localci/status/$owner_repo/{$this->branch}",
		);

		return $this->api_post( "/repos/$owner_repo/statuses/{$this->sha}", $data );
	}

	/**
	 * @param string $path       Github api path.
	 * @param array  $args       Additional HTTP api args.
	 * @param int    $cache_time How many seconds to cache a successful request result.
	 *
	 * @return bool|string  Result of request, or false if unsuccessful.
	 */
	private function api_get( $path, $args = array(), $cache_time = 0 ) {
		$args = wp_parse_args( $args, array( 'headers' => $this->api_auth_header() ) );
		return $this->cached_get( LOCALCI_GITHUB_API_URL . $path, $cache_time, $args );
	}

	/**
	 * Post to Github API.
	 *
	 * @param string $path  Github api path.
	 * @param mixed   $body  Body of request.
	 *
	 * @return array|bool|WP_Error
	 */
	private function api_post( $path, $body ) {
		$this->log( 'remote-request', 'github', array(
			'url' => LOCALCI_GITHUB_API_URL . $path,
			'type' => 'POST',
			'body' => $body,
		) );

		// TODO: handle errors.
		$post_data = array(
			'headers' => $this->api_auth_header(),
			'body' => wp_json_encode( $body ),
			'blocking' => false,
			'timeout' => 30,
			'user-agent' => 'LocalCI/GP v1.0',
		);

		$r = wp_safe_remote_post( LOCALCI_GITHUB_API_URL . $path, $post_data );
		return $r;
	}

	/**
	 * Delete request to Github API.
	 *
	 * @param string $path Path to send to.
	 */
	private function api_delete( $path ) {
		$this->log( 'remote-request', 'github', array(
			'url' => LOCALCI_GITHUB_API_URL . $path,
			'type' => 'DELETE',
		) );

		// TODO: return, handle errors.
		wp_safe_remote_request(
			LOCALCI_GITHUB_API_URL . $path,
			array(
				'headers' => $this->api_auth_header(),
				'timeout' => 30,
				'blocking' => true,
				'method' => 'DELETE',
			)
		);
	}

	/**
	 * Patch request to Github API.
	 *
	 * @param string $path Path to send to.
	 * @param mixed  $body  Body of request.
	 *
	 * @return array|WP_Error Array containing 'headers', 'body', 'response', 'cookies', 'filename'.
	 *                        A WP_Error instance upon error.
	 */
	private function api_patch( $path, $body ) {
		$this->log( 'remote-request', 'github', array(
			'url' => LOCALCI_GITHUB_API_URL . $path,
			'type' => 'PATCH',
			'body' => $body,
		) );

		// TODO: return, handle errors.
		return wp_safe_remote_request(
			LOCALCI_GITHUB_API_URL . $path,
			array(
				'headers' => $this->api_auth_header(),
				'body' => wp_json_encode( $body ),
				'timeout' => 30,
				'blocking' => true,
				'method' => 'PATCH',
			)
		);
	}

	/**
	 * Generates the Authorization header for the Github api.
	 *
	 * @return array Headers array.
	 */
	private function api_auth_header() {
		return array(
			'Authorization' => 'token ' . LOCALCI_GITHUB_API_MANAGEMENT_TOKEN,
		);
	}

	/**
	 * Compares two strings, and returns whether GlotPress will fuzzy the translations on import.
	 * @param object|array $new_string
	 * @param object|array $existing_string
	 *
	 * @return bool
	 */
	private function translations_will_be_discarded( $new_string, $existing_string ) {
		// If the existing string exists in more than one file, it will be kept,
		// And we'll end up with a new string
		if ( count( preg_split( '/\s+/', $existing_string['references'], -1, PREG_SPLIT_NO_EMPTY ) ) > 1 ) {
			return true;
		}

		$min_score = apply_filters( 'gp_original_import_min_similarity_diff', 0.8 );
		$string_similarity = gp_string_similarity(
			$this->entry_key( $new_string ),
			$this->entry_key( $existing_string )
		);

		return $string_similarity < $min_score;
	}

	/**
	 * Generates a PO entry key.
	 * The entry key is what's used for string comparisons in GP.
	 *
	 * @param object|array $entry
	 *
	 * @return bool|string
	 */
	private function entry_key( $entry ) {
		if ( is_array( $entry ) ) {
			$entry = (object) $entry;
		}

		if ( null === $entry->singular || '' === $entry->singular ) {
			return false;
		};

		// Prepend context and EOT, like in MO files
		$key = ! $entry->context ? $entry->singular : $entry->context . chr( 4 ) . $entry->singular;
		// Standardize on \n line endings
		$key = str_replace( array( "\r\n", "\r" ), "\n", $key );

		return $key;
	}

	/**
	 * Find a string in an array of strings.
	 * @param string $needle The string to look for.
	 * @param array $lines Array of strings.
	 *
	 * @return bool The string is in the array or not.
	 */
	private function string_in_array( $needle, $lines ) {
		foreach ( $lines as $line ) {
			if ( gp_in( $needle, $line ) ) {
				return true;
			}
		}

		return false;
	}

}
