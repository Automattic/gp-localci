<?php
class GP_LocalCI_DB_Adapter {

	use GP_Localci_Log;

	public function get_string_coverage( $po_obj_or_file, $project_id  ) {
		$po = localci_load_po( $po_obj_or_file );
		return $this->get_cross_locale_translated_status( $po->entries, $project_id );
	}

	private function get_cross_locale_translated_status( $po_entries, $project_id ) {
		$existing_originals = $new_originals = array();
		$string_freeze = false;

		foreach ( $po_entries as $entry ) {
			$original = GP::$original->by_project_id_and_entry( $project_id, $entry );
			if ( $original ) {
				$original_array = $original->fields();
				$original_translations = $this->get_translations_for_original( $original->id );
				$translation_locales = wp_list_pluck( $original_translations, 'locale' );
				$missing_locales = array_diff( LOCALCI_DESIRED_LOCALES, $translation_locales );
				$comments = explode( "\n", $original_array['comment'] );
				// Partially or fully untranslated, and has a string freeze comment:
				if ( ! empty( $missing_locales ) && in_array( 'status: string-freeze',  $comments, true ) ) {
					$string_freeze = true;
					$original_array['locales'] = $translation_locales;
					$new_originals[] = $original_array;
				} elseif ( empty( $original_translations ) && '-obsolete' === $original->status ) {
					$new_originals[] = $original_array;
				} else {
					$original_array['locales'] = $translation_locales;
					$existing_originals[] = $original_array;
				}
			} else {
				$data = array(
					'project_id' => $project_id,
					'context'    => $entry->context,
					'singular'   => $entry->singular,
					'plural'     => $entry->plural,
					'comment'    => $entry->extracted_comments,
					'references' => implode( ' ', $entry->references ),
					'context_message' => $this->context_checks( $entry, $project_id ),
				);

				$new_originals[] = $data;
			}
		}

		$coverage = array(
			'new_strings' => $new_originals,
			'existing_strings' => $existing_originals,
			'string_freeze' => $string_freeze,
			'translations' => $this->filter_cross_locale_translated_status( array_merge( $new_originals, $existing_originals ) ),
		);

		return $coverage;
	}

	private function context_checks( $entry, $project_id ) {
		if ( ! $entry->context ) {
			return false;
		}

		if ( str_word_count( $entry->singular ) > 4 ) {
			return "This string is probably long enough that it doesn't need a **context**. Are you sure you don't want to use a translator comment instead?";
		}

		if ( str_word_count( $entry->context ) > 3 ) {
			return "This **context** is really long. Are you sure you don't want to use a translator comment instead?";
		}

		$same_originals_excluding_context = GP::$original->find_many( array( 'singular' => $entry->singular, 'plural' => $entry->plural, 'status' => '+active', 'project_id' => $project_id ) );
		if ( empty( $same_originals_excluding_context ) ) {
			return "This string doesn't exist elsewhere without a **context** (or with a different one). You can probably drop the context. If needed, you can use a translator comment instead to clarify meaning.";
		}

		$contexts = array_unique( wp_list_pluck( $same_originals_excluding_context, 'context' ) );
		if ( 1 === count( $contexts ) && is_null( $contexts[0] ) ) {
			return 'This string already exists without a **context**. Only add a context if the meaning of the string is very specific.';
		}

		$context_message = 'This string already exists with the following **contexts**:';
		foreach ( $contexts as $context ) {
			$context_message .= is_null( $context ) ? "\n- `null` (no context)" : "\n- `$context`";
		}
		$context_message .= "\n\n Would it make sense to reuse one of the above?";
		return $context_message;
	}

	private function filter_cross_locale_translated_status( $strings ) {
		$rows = array();
		foreach ( $strings as $string ) {
			if ( ! isset( $string['locales'] ) ) {
				continue;
			}
			foreach ( $string['locales'] as $_locale ) {
				if ( in_array( $_locale, LOCALCI_DESIRED_LOCALES ) ) {
					$rows[ $_locale ] = $rows[ $_locale ] + 1;
				}
			}
		}
		return $rows;
	}

	private function get_translations_for_original( $original_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, tset.locale FROM gp_translations as t
				 JOIN gp_translation_sets as tset on t.translation_set_id = tset.id
				 WHERE t.original_id = %d AND t.status = 'current'", $original_id
			)
		);
	}
}
