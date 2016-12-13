<?php
function localci_load_po( $po_obj_or_file ) {
	if ( $po_obj_or_file instanceof PO ) {
		return $po_obj_or_file;
	}

	$po = new PO();

	if ( is_string( $po_obj_or_file ) && file_exists( $po_obj_or_file ) ) {
		$po->import_from_file( $po_obj_or_file );
	} else if ( is_string( $po_obj_or_file ) ) {
		$po->import_from_file( 'data://text/plain,' . urlencode( $po_obj_or_file ) );
	} else {
		return false;
	}

	return $po;
}

function localci_generate_coverage_stats( $po_obj_or_file, $coverage ) {
	$po = localci_load_po( $po_obj_or_file );

	$stats = array_fill_keys( LOCALCI_DESIRED_LOCALES, 0 );
	$stats['string_count'] = count( $po->entries );

	foreach ( $coverage as $row ) {
		$stats[$row->locale]++;
	}

	return $stats;
}


function localci_generate_string_suggestions( ) {
	// @TODO: likely not trivial
}
