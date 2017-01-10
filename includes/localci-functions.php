<?php
function localci_load_po( $po_obj_or_file ) {
	if ( $po_obj_or_file instanceof PO ) {
		return $po_obj_or_file;
	}

	$po = new PO();

	if ( is_string( $po_obj_or_file ) && file_exists( $po_obj_or_file ) ) {
		$po->import_from_file( $po_obj_or_file );
	} elseif ( is_string( $po_obj_or_file ) ) {
		$po->import_from_file( 'data://text/plain,' . urlencode( $po_obj_or_file ) );
	} else {
		return false;
	}

	return $po;
}

function localci_generate_coverage_stats( $po_obj_or_file, $coverage ) {
	$po = localci_load_po( $po_obj_or_file );
	$stats = array_fill_keys( LOCALCI_DESIRED_LOCALES, 0 );

	foreach ( $coverage['translations'] as $row ) {
		$stats[ $row->locale ]++;
	}

	$num_translated = array_sum( $stats );

	$stats['num_strings'] = count( $po->entries );
	$stats['new_strings'] = count( $coverage[ 'new_strings' ] );
	$stats['percent_translated'] = localci_generate_coverage_percent_translated( $stats['num_strings'], $num_translated );
	$stats['summary'] = localci_generate_coverage_summary( $stats['num_strings'], $stats['new_strings'], $stats['percent_translated'] );

	return $stats;
}

function localci_generate_coverage_percent_translated( $num_originals, $num_translated ) {
	if ( 0 === $num_originals ) {
		return 0;
	}

	return number_format( ( $num_translated / ( count( LOCALCI_DESIRED_LOCALES ) * $num_originals ) ) * 100 );
}

function localci_generate_coverage_summary( $num_strings, $new_strings = 0, $percent_translated ) {
	$warning_threshold = 3;

	if ( 0 !== $new_strings ) {
		$new_strings = ' ' . sprintf( _n( '(%d new)', '(%d new)', $new_strings, 'gp_localci' ), $new_strings );
	} else {
		$new_strings = '';
	}

	$summary = sprintf( _n( 'Total: %1$d string%2$s. ', 'Total: %1$d strings%2$s. ', $num_strings, 'gp_localci' ), $num_strings, $new_strings );

	switch ( true ) {
		case ( 0 === $num_strings ) :
			break;
		case '100' === $percent_translated :
			$summary .= 'Translations: 100% coverage.';
			break;
		case $percent_translated >= 75:
			$summary .= "Translations: {$percent_translated}% coverage.";
			break;
		case $percent_translated > 25:
			$summary .= "Translations: {$percent_translated}% coverage.";
			break;
		case $percent_translated <= 25:
			$prefix = $num_strings > $warning_threshold ? ' Only ' : ' Translations: ';
			$summary .= $prefix . "{$percent_translated}% translated.";
	}

	return $summary;
}

function localci_generate_string_suggestions() {
	// @TODO: likely not trivial
}
