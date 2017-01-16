<?php
gp_title( sprintf( __( 'Localci Status &lt; %s &lt; GlotPress' ), esc_html( $repo . '/' . $branch ) ) );
gp_tmpl_header();
?>
	<h2>LocalCI Status &mdash; <a href="<?php echo esc_url( $status_gh_link_href ) ?>"><?php echo esc_html( $status_gh_link_text ); ?><span class="genericon genericon-external"></span></a></h2>
	<div id="status-">
		<h3>Summary</h3>
		<div>
			<?php echo esc_html( $stats['summary'] ); ?>
		</div>
		<?php if ( ! empty( $coverage['new_strings'] ) || ! empty( $coverage['existing_strings'] ) ) : ?>
		<h3>Details</h3><div>
			<?php if ( ! empty( $coverage['new_strings'] ) ) : ?>
			<h5>New and untranslated strings</h5>
				<ul class="strings new-strings">
			<?php
			foreach ( $coverage['new_strings'] as $new_string ) {
				localci_translation_item( $project, $new_string, $owner, $repo, $branch );
			}
			?>
				</ul>
			<?php endif;?>
			<?php if ( ! empty( $coverage['existing_strings'] ) ) : ?>
			<h5>Existing translations</h5>
				<ul class="strings existing-strings">
			<?php
			foreach ( $coverage['existing_strings'] as $original ) {
				localci_translation_item( $project, $original, $owner, $repo, $branch );
			}
			?>
				</ul>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

<?php gp_tmpl_footer();
