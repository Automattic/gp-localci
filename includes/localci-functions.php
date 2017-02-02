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

	$num_translated = array_sum( $coverage['translations'] );

	$stats['num_strings'] = count( $po->entries );
	$stats['new_strings'] = count( $coverage['new_strings'] );
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
			$summary .= 'Everything already translated!';
			break;
		case $percent_translated > 25:
			$summary .= "{$percent_translated}% already translated.";
			break;
		case $percent_translated <= 25 && $num_strings > $warning_threshold:
			$summary .= "Only {$percent_translated}% already translated.";
			break;
		case $percent_translated <= 25:
			$summary .= "{$percent_translated}% already translated.";
			break;
		case $percent_translated === '0':
			$summary .= "Nothing translated yet.";
	}

	return $summary;
}

// Returns a Gitnub url based on the entry references array
function localci_get_source_url( $project, $references, $owner, $repo, $branch  ) {
	list( $file, $line ) = array_pad( explode( ':',
		array_pop(
			preg_split( '/\s+/', $references, -1, PREG_SPLIT_NO_EMPTY )
		)
	), 2, 0 );

	if ( 'master' === $branch ) {
		$url = $project->source_url( $file, $line );
	} else {
		$url = "https://github.com/$owner/$repo/blob/$branch/" . addslashes( $file ) . '#L' . intval( $line );
	}

	return $url;
}

function localci_translation_item_context( $context, $previous_context ) {
	if ( ! $context && ! $previous_context ) {
		return;
	}
	$value = $context ? 'context: <em>' . esc_html( $context ) . '</em>' : '(No context)';
	return '<div title="' . esc_attr( $context ) . '" class="context">' . $value . '</div>';
}

function localci_translation_item_diff( $previous_text, $text ) {
	$diff = new Text_Diff( 'auto', array( array( $previous_text ), array( $text ) ) );
	$renderer  = new WP_Text_Diff_Renderer_inline();
	return $renderer->render( $diff );
}

function localci_translation_item( $project, $entry, $owner, $repo, $branch, $previous_entry = null, $echo = true ) {
	$item = '<li class="localci-translation-item">';

	foreach ( array( 'singular', 'plural' ) as $field ) {
		if ( ! empty( $entry[ $field ] ) || ! empty( $previous_entry[ $field ] ) ) {
			if ( $previous_entry ) {
				$value = localci_translation_item_diff( $previous_entry[ $field ], $entry[ $field ] );
				if ( empty( $value ) ) {
					$value = $previous_entry[ $field ];
				}
				$title = esc_attr( $entry[ $field ] );
			} else {
				$value = esc_html( $entry[ $field ] );
				$title = esc_attr( $field );
			}
			$item .= "<span title='$title' class='field $field'>" . $value . '</span>';
		}
	}

	$previous_context = isset( $previous_entry['context'] ) ? $previous_entry['context'] : null;
	$item .= localci_translation_item_context( $entry['context'], $previous_context );

	$item .= '<div class="meta">';
	$item .= '<span class="item-link source-link"><a title="View in source" href="' . esc_url( localci_get_source_url( $project, $entry['references'], $owner, $repo, $branch ) ) . '">source</a></span>';
	if ( ! empty( $entry['locales'] ) ) {
		$all_translations_link = gp_url_project( $project, '-all-translated/' . $entry['id'] );
		$item .= '<span class="item-link translations-link"><a href="' . esc_url( $all_translations_link ) . '">' . sprintf( _n( '%d translation', '%d translations', count( $entry['locales'] ) ), count( $entry['locales'] ) ) . '</a></span>';
	}
	if ( ! empty( $entry['score'] ) ) {
		$item .= '<span class="item-link score">ES score: ' . number_format( $entry['score'], 2 ) . '</span>';
	}
	$item .= '</div>';

	if ( isset( $entry['suggestions'] ) ) {
		$item .= '<ul class="suggestions">';
		foreach ( $entry['suggestions'] as $suggestion ) {
			$item .= localci_translation_item( $project, $suggestion, $owner, $repo, 'master', $entry, false );
		}
		$item .= '</ul>';
	}

	$item .= '</li>';

	if ( $echo ) {
		echo $item; // WPCS: XSS OK
	} else {
		return $item;
	}
}
