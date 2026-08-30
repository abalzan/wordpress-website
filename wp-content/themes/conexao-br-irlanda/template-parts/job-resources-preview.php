<?php
/**
 * Job Resources Preview — homepage "Onde procurar emprego" (compact).
 *
 * Renders a small, curated subset of external job-search websites as
 * compact text-only cards. There is NO second hard-coded list here: the
 * data comes from conexao_job_resources() — the exact same source of
 * truth the /empregos/ landing section uses (managed in wp-admin under
 * Empregos → "Onde procurar emprego", filterable via the
 * `conexao_job_resources` filter). Only the first few resources are
 * shown so the homepage stays visually clean; /empregos/ renders the
 * complete list.
 *
 * The cards reuse the Event card visual language (.event-card,
 * .event-card-body, .event-card-title, .event-card-excerpt,
 * .event-card-cta) without a banner image, so the section introduces no
 * image downloads, JavaScript, or external requests. All destinations
 * are external: links open in a new tab with rel="noopener noreferrer".
 *
 * @package Conexao_BR_Irlanda
 */

// Compact homepage preview: show only the first resources from the
// shared /empregos/ data source.
$job_resources_preview = array_slice( conexao_job_resources(), 0, 3 );

if ( ! empty( $job_resources_preview ) ) :
	?>
	<div class="events-grid jobs-home-grid">
		<?php foreach ( $job_resources_preview as $job_resource ) : ?>
			<?php
			if ( empty( $job_resource['title'] ) || empty( $job_resource['url'] ) ) {
				continue;
			}

			$resource_title = (string) $job_resource['title'];
			$resource_url   = esc_url( (string) $job_resource['url'] );
			$resource_desc  = isset( $job_resource['description'] ) ? (string) $job_resource['description'] : '';
			$external_attrs = ' target="_blank" rel="noopener noreferrer"';
			$new_tab_hint   = esc_attr__( '(abre em nova aba)', 'conexao-br-irlanda' );
			/* translators: %s: external job site name, e.g. "Jobs.ie". */
			$access_label   = esc_attr( sprintf( __( 'Acessar %s (abre em nova aba)', 'conexao-br-irlanda' ), $resource_title ) );
			?>
			<article class="event-card job-resource-card">
				<div class="event-card-body">
					<h3 class="event-card-title">
						<a href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $resource_title ); ?></a>
					</h3>

					<?php if ( $resource_desc ) : ?>
						<p class="event-card-excerpt"><?php echo esc_html( $resource_desc ); ?></p>
					<?php endif; ?>

					<a href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="event-card-cta" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-label="<?php echo $access_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php esc_html_e( 'Acessar', 'conexao-br-irlanda' ); ?>
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<line x1="5" y1="12" x2="19" y2="12"></line>
							<polyline points="12 5 19 12 12 19"></polyline>
						</svg>
					</a>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
	<?php
endif;