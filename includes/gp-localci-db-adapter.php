<?php
class GP_LocalCI_DB_Adapter {
	public function get_string_coverage( $po_obj_or_file, $project_id ) {
		$po = new PO();

		if ( is_string( $po_obj_or_file ) && file_exists( $po_obj_or_file ) ) {
			$po->import_from_file( $po_obj_or_file );
		} else if ( is_string( $po_obj_or_file ) ) {
			$po->import_from_file( 'data://text/plain,' . urlencode( $po_obj_or_file ) );
		} else {
			return false;
		}

		$result = $this->get_cross_locale_translated_status( $po->entries, $project_id );
		$coverage = $this->filter_cross_locale_translated_status( $result );

		return $coverage;
	}

	public function generate_coverage_stats( $coverage ) {
	}

	public function generate_string_suggestions( $coverage ) {
	}

	private function get_cross_locale_translated_status( $po_entries, $project_id ) {
		global $wpdb;

		$where         = array();
		$entries_where = array();

		foreach ( $po_entries as $entry ) {
			$entry_and   = array();
			$entry_and[] = $wpdb->prepare( '`singular` = BINARY %s', $entry->singular );
			$entry_and[] = is_null( $entry->plural ) ? '`plural` IS NULL' : $wpdb->prepare( '`plural` = BINARY %s', $entry->plural );
			$entry_and[] = is_null( $entry->context ) ? '`context` IS NULL' : $wpdb->prepare( '`context` = BINARY %s', $entry->context );
			$entries_where[] = '(' . implode( ' AND ', $entry_and ) . ')';
		}

		if ( empty( $entries_where ) ) {
			return false;
		}

		$where[] = '( ' . implode( ' OR ', $entries_where ) . ' )';
		$where[] = $wpdb->prepare( '`gp_originals`.`project_id` = %d', $project_id );
		$where = implode( ' AND ', $where );

		return $wpdb->get_results(
           "SELECT `gp_originals`.`id` as `original_id`, `context`, `singular`, `plural`, `gp_translations`.`id` as `translation_id`, `locale`
            FROM `gp_originals`
             JOIN `gp_translations` ON ( `gp_originals`.`id` = `gp_translations`.`original_id` )
             JOIN `gp_translation_sets` ON ( `gp_translations`.`translation_set_id` = `gp_translation_sets`.`id` )
            WHERE $where AND `gp_originals`.`status` = '+active' AND `gp_translations`.`status` = 'current'"
		 );
	}

	private function filter_cross_locale_translated_status( $rows ) {
		foreach ( $rows as $key => $row ) {
			if ( ! in_array( $row->locale, LOCALCI_DESIRED_LOCALES ) ) {
				unset( $rows[$key] );
			}
		}
		return $rows;
	}
}
