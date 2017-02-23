<?php
class GP_LocalCI_DB_Adapter {
	public function get_string_coverage( $po_obj_or_file, $project_id  ) {
		$po = localci_load_po( $po_obj_or_file );
		return $this->get_cross_locale_translated_status( $po->entries, $project_id );
	}

	private function get_cross_locale_translated_status( $po_entries, $project_id ) {
		$existing_originals = $new_originals = array();

		foreach ( $po_entries as $entry ) {
			$original = GP::$original->by_project_id_and_entry( $project_id, $entry );
			if ( $original ) {
				$original_array = $original->fields();
				$original_translations = $this->get_translations_for_original( $original->id );
				if ( empty( $original_translations ) && '-obsolete' === $original->status ) {
					$new_originals[] = $original_array;
				} else {
					$original_array['locales'] = wp_list_pluck( $original_translations, 'locale' );
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
				);
				$new_originals[] = $data;
			}
		}

		$coverage = array(
			'new_strings' => $new_originals,
			'existing_strings' => $existing_originals,
			'translations' => $this->filter_cross_locale_translated_status( array_merge( $new_originals, $existing_originals ) ),
		);

		return $coverage;
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
