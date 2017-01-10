<?php
gp_title( sprintf( __( 'Localci Status &lt; %s &lt; GlotPress' ), esc_html( $repo . '/' . $branch ) ) );
gp_tmpl_header();
?>
	<h2>LocalCI Status: <?php echo esc_html( esc_html( $owner . '/' . $repo . '/' . $branch ) ); ?><a href="<?php echo esc_url( "https://github.com/$owner/$repo/tree/$branch/" ) ?>">&nearr;</a></h2>
	<div id="status-">
		<h3>Summary</h3>
		<div>
			<?php echo esc_html( $stats['summary'] ); ?>
		</div>
		<?php if ( ! empty( $coverage['new_strings'] ) && ! empty( $coverage['existing_strings'] ) ) : ?>
		<h3>Details</h3>
		<div>
			<?php if ( ! empty( $coverage['new_strings'] ) ) : ?>
			<h5>New and untranslated strings</h5>
			<ul>
			<?php
			foreach ( $coverage['new_strings'] as $new_string ) {
				$new_string['references'] = preg_split( '/\s+/', $new_string['references'], -1, PREG_SPLIT_NO_EMPTY );
				echo '<li>';
					echo '<code>' . esc_html( $new_string['singular'] ) . '</code><br/>';
					$reference = array_pop( $new_string['references'] );
					list( $file, $line ) = array_pad( explode( ':', $reference ), 2, 0 );
					echo '<a href="' . esc_url( "https://github.com/$owner/$repo/blob/$branch/" . addslashes( $file ) . '#L' . intval( $line ) ) . '">View in source</a>';
				echo '</li>';
			}
			?>
			</ul>
			<?php endif;?>
			<?php if ( ! empty( $coverage['existing_strings'] ) ) : ?>
			<h5>Existing translations</h5>
			<ul>
				<?php
				foreach ( $coverage['existing_strings'] as $original ) {
					$original['references'] = preg_split( '/\s+/', $original['references'], -1, PREG_SPLIT_NO_EMPTY );
					echo '<li>';
					echo '<code>' . esc_html( $original['singular'] ) . '</code><br/>';
					$reference = array_pop( $original['references'] );
					list( $file, $line ) = array_pad( explode( ':', $reference ), 2, 0 );
					echo ' <a href="' . esc_url( "https://github.com/$owner/$repo/blob/$branch/" . addslashes( $file ) . '#L' . intval( $line ) ) . '">View in source</a>';
					$all_translations_link = gp_url_project( $project, '-all-translated/' . $original['id'] );
					echo ' | <a href="' . esc_url( $all_translations_link ) . '">' . count( $original['locales'] ) . ' translations</a>';
					echo '</li>';
				}
				?>
			</ul>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

<?php gp_tmpl_footer();
