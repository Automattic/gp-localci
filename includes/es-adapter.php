<?php

class GP_LocalCI_ES_Adapter {
	public function get_suggestions( $new_strings ) {
		foreach ( $new_strings as $key => $entry ) {
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

		if ( is_array( $entry ) ) {
			$entry = (object) $entry;
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

			$original['references'] = implode( ' ', $original['references'] );
			$original['locales'] = array_keys( $hit['_source']['translations'] );
			$original['score'] = $hit['_score'];

			// Discard obsolete strings with no translations.
			if ( '-obsolete' === $original['status'] && empty( $original['locales'] ) ) {
				continue;
			}

			$suggestions[] = $original;
		}

		return $suggestions;
	}
}