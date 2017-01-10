<?php
class GP_LocalCI_DB_Adapter {
	public function get_string_coverage( $po_obj_or_file, $project_id  ) {
		$po = localci_load_po( $po_obj_or_file );
		return $this->get_cross_locale_translated_status( $po->entries, $project_id );
	}

	private function get_cross_locale_translated_status( $po_entries, $project_id ) {
		$translations = $existing_originals = $new_originals = array();

		foreach ( $po_entries as $entry ) {
			$original = GP::$original->by_project_id_and_entry( $project_id, $entry );
			if ( $original ) {
				$original_translations = $this->get_translations_for_original( $original->id );
				if ( empty( $original_translations ) && '-obsolete' === $original->status ) {
					$new_originals[] = $original->fields();
				} else {
					$original = $original->fields();
					$original['locales']  = array();
					foreach ( $original_translations as $translation ) {
						$translations[] = (object) array(
							'original_id'    => $original['id'],
							'context'        => $original['context'],
							'singular'       => $original['singular'],
							'plural'         => $original['plural'],
							'translation_id' => $translation->id,
							'locale'         => $translation->locale,
						);
						$original['locales'][] = $translation->locale;
					}
					$existing_originals[] = $original;
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
			'translations' => $this->filter_cross_locale_translated_status( $translations ),
		);

		return $coverage;
	}

	private function filter_cross_locale_translated_status( $rows ) {
		foreach ( $rows as $key => $row ) {
			if ( ! in_array( $row->locale, LOCALCI_DESIRED_LOCALES ) ) {
				unset( $rows[ $key ] );
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
