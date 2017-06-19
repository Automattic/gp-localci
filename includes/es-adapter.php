<?php

class GP_LocalCI_ES_Adapter {

	public $entry_missed_word_penalty = 2.5;
	public $suggestion_missed_word_penalty = 1.5;

	use GP_Localci_Log;

	public function get_suggestions( $new_strings ) {
		foreach ( $new_strings as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$entry = (object) $entry;
			}

			// Don't get suggestions for existing strings
			if ( isset( $entry->id ) && $entry->id ) {
				continue;
			}

			$suggested_replacements = $this->get_suggested_replacements( $entry );
			if ( $suggested_replacements ) {
				$new_strings[ $key ]['suggestions'] = $suggested_replacements;
			}
		}
		return $new_strings;
	}

	private function get_suggested_replacements( $entry ) {
		if ( ! function_exists( 'gp_es_find_similar' ) ) {
			return false;
		}

		$placeholders_re = apply_filters( 'gp_warning_placeholders_re', '%(\d+\$(?:\d+)?)?[bcdefgosuxEFGX]' );
		preg_match_all( "/$placeholders_re/", $entry->singular . $entry->plural, $matches );
		$entry_placeholders = count( $matches[0] );
		$entry_length = strlen( strip_tags( $entry->singular ) );

		$hits = gp_es_find_similar( $entry );
		if ( ! $hits ) {
			return false;
		}

		$suggestions = array();
		foreach ( $hits as $hit ) {
			$original['id'] = $hit['_source']['original_id'];
			$original = $hit['_source']['original'];

			// Discard suggestions where string length vary too much.
			$hit_length = strlen( strip_tags( $original['singular'] ) );
			if ( $hit_length < absint( ceil( 0.5 * $entry_length ) ) ||	$hit_length > absint( ceil( 2 * $entry_length ) ) ) {
				continue;
			}

			// Discard originals with different number of placeholders.
			preg_match_all( "/$placeholders_re/", $original['singular'] . $original['plural'], $matches );
			$hit_placeholders = count( $matches[0] );
			if ( $hit_placeholders !== $entry_placeholders ) {
				continue;
			}

			$original['score'] = $this->nlp_test( $entry->singular, $original['singular'], $hit['_score'] );

			// TODO: set up config for the minimum score
			if ( $original['score'] < 6 ) {
				continue;
			}

			$original['references'] = implode( ' ', $original['references'] );
			$original['locales'] = array_keys( $hit['_source']['translations'] );


			// Discard strings with no translations.
			if ( empty( $original['locales'] ) ) {
				continue;
			}

			$suggestions[] = $original;
		}

		usort( $suggestions, function( $a, $b ) {
			return $a['score'] < $b['score'];
		});

		return $suggestions;
	}

	private function nlp_test( $entry, $suggestion, $score ) {
		$original_entry = $entry;
		$original_suggestion = $suggestion;

		// Replace placeholders such as %s, %1$s, and %(purchaseName)s with 'Placeholder'
		$nouns_re = '(%(\d+\$(?:\d+)?)?[s]|%\([^)]+\)[s])';
		$entry = preg_replace( "/$nouns_re/", 'Placeholder', $entry );
		$suggestion = preg_replace( "/$nouns_re/", 'Placeholder', $suggestion );

		// Replace placeholders such as %d, %1$d, and %(count)d with '42'
		$numbers_re = '(%(\d+\$(?:\d+)?)?[d]|%\([^)]+\)[d])';
		$entry = preg_replace( "/$numbers_re/", '42', $entry );
		$suggestion = preg_replace( "/$numbers_re/", '42', $suggestion );

		// Replace placeholders such as {{a}} or {{/em}
		$tags_re = '{{[^}]+}}';
		$entry = preg_replace( "/$tags_re/", '', $entry );
		$suggestion = preg_replace( "/$tags_re/", '', $suggestion );

		// Remove end of sentence punctuation - it confuses the API
		$entry = rtrim( $entry, ',.!?-…' );
		$suggestion = rtrim( $suggestion, ',.!?-…' );

		$result_entry_suggestion = $this->nlp_test_one_way( $entry, $suggestion, $this->entry_missed_word_penalty );
		$result_suggestion_entry = $this->nlp_test_one_way( $suggestion, $entry, $this->suggestion_missed_word_penalty );

		$nscore = $result_entry_suggestion['nscore'] + $result_suggestion_entry['nscore'];
		if ( ! $nscore  ) {
			return $score;
		}

		$score = $score - $nscore;
		$mismatches = array();

		if ( ! empty( $result_entry_suggestion['misses'] ) ) {
			$mismatches[] = 'Missing from suggestion: ' . implode( ', ', $result_entry_suggestion['misses'] );
		}

		if ( ! empty( $result_suggestion_entry['misses'] ) ) {
			$mismatches[] = 'Missing from string: ' . implode( ', ', $result_suggestion_entry['misses'] );
		}

		if ( ! empty( $mismatches ) ) {
			$this->log( 'result', 'nlp-mismatch', array(
					'entry' => $original_entry,
					'suggestion' => $original_suggestion,
					'mismatch' => implode( "\n", $mismatches ),
					'nscore' => $nscore,
					'score' => $score,
				)
			);
		}

		return $score;
	}

	private function nlp_test_one_way( $basis, $target, $penalty ) {
		$nscore = 0;

		$misses = $basis_important_words = array();

		$lc_target = gp_strtolower( $target );

		// The API treats contractions as separate words.
		// Split the string into words, wish contractions being their own words.

		// 1. Generate a regex for contractions
		$contractions = array( 's', 've', 'll', 't', 'm', 'd' );
		$contractions_regex = implode( '|', array_map( function( $c ) {
			return '[\'`]' . $c;
		}, $contractions ) );

		// 2. Split by contractions, but keep them in the array. Example result: ['This is my dog', '\'s', 'bone']
		$contractions_split = preg_split( '/(' . $contractions_regex . ')/', $lc_target, -1, PREG_SPLIT_DELIM_CAPTURE );

		// 3. Combine the array into a string, then split it again into words. Example result: ['This', 'is', 'my', 'dog', '\'s', 'bone']
		$lc_target_array = preg_split( '/\W+/', implode( ' ', $contractions_split ), -1, PREG_SPLIT_NO_EMPTY );

		$basis_morphology = $this->rosette_api_morphology( $basis );
		$basis_syntax = $this->rosette_api_syntax_dependencies( $basis );

		if ( isset( $basis_morphology->tokens ) ) {
			foreach ( $basis_morphology->tokens as $token_id => $token ) {
				// Skip placeholders.
				if ( gp_startswith( $token, '%' ) ) {
					continue;
				}
				// We only care about verbs/nouns.
				if ( in_array( $basis_morphology->posTags[ $token_id ], array( 'VERB', 'NOUN' ), true ) ) {
					$basis_important_words[ gp_strtolower( $token ) ][] = $basis_morphology->posTags[ $token_id ];
				}
			}
		}

		if ( isset( $basis_syntax->sentences ) ) {
			foreach ( $basis_syntax->sentences as $sentence ) {
				foreach ( $sentence->dependencies as $dep ) {
					if ( $dep->dependencyType === 'compound' ) {
						$compound = gp_strtolower( $basis_syntax->tokens[ $dep->dependentTokenIndex ] . ' ' . $basis_syntax->tokens[ $dep->governorTokenIndex ] );
						$basis_important_words[ $compound ][] = 'compound' ;
					} else {
						// Skip placeholders.
						if ( gp_startswith( $basis_syntax->tokens[ $dep->dependentTokenIndex ], '%' ) ) {
							continue;
						}
						// Combine objects.
						$dep_type = in_array( $dep->dependencyType, array( 'dobj', 'iobj', 'pobj' ), true ) ? 'object' : $dep->dependencyType;
						// We only case about root/objects.
						if ( in_array( $dep_type, array( 'object', 'root' ), true ) ) {
							$basis_important_words[ gp_strtolower( $basis_syntax->tokens[ $dep->dependentTokenIndex ] ) ][] = $dep_type;
						}
					}
				}
			}
		}

		foreach ( $basis_important_words as $word => $types ) {
			$count = 0;
			$word_types = array();
			foreach ( $types as $type ) {
				if (
					('compound' === $type && ! gp_in( $word, $lc_target ) )
					|| ( 'compound' !== $type ) && ! in_array( $word, $lc_target_array, true )
				) {
					$word_types[] = $type;
					$count++;
				}
			}

			if ( ! $count ) {
				continue;
			}

			$misses[] = sprintf( '`%1$s` (as %2$s)', $word, implode( ', ', $word_types ) );

			// Small formula to lower the impact of a word if it is found more than once.
			// For example, if the penalty is 3 and the word was missed twice, the nscore will be 4.
			// If the word was missed 3 times, nscore will be 6, etc.
			$nscore += $penalty * ( $count - (  ( $count - 1 ) / 2 ) );
		}

		return array(
			'nscore' => $nscore,
			'misses' => $misses,
		);
	}

	private function rosette_api_morphology( $text ) {
		return $this->rosette_api_request( 'morphology/complete', $text );
	}

	private function rosette_api_syntax_dependencies( $text ) {
		return $this->rosette_api_request( 'syntax/dependencies', $text );
	}

	private function rosette_api_request( $path, $text ) {
		$cache_group = 'rosette-api';
		$cache_key   = md5( $path . $text );

		if ( false !== $cache = wp_cache_get( $cache_key, $cache_group ) ) {
			return $cache;
		}

		$post_data = array(
			'headers' => array( 'X-RosetteAPI-Key' => LOCALCI_ROSETTE_API_TOKEN, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array( 'content' => strip_tags( $text ), 'language' => 'eng' ) ),
			'user-agent' => 'LocalCI/GP v1.0',
		);

		$url = 'https://api.rosette.com/rest/v1/' . $path;

		$this->log( 'remote-request', 'cached', array(
			'url' => $url,
			'type' => 'POST',
			'args' => $post_data,
		) );

		$request = wp_remote_post( $url, $post_data );
		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) !== 200 ) {
			wp_cache_add( $cache_key, '', $cache_group, 5 * MINUTE_IN_SECONDS );
			return false;
		}

		$response = json_decode( wp_remote_retrieve_body( $request ) );
		wp_cache_add( $cache_key, $response, $cache_group );

		return $response;
	}
}