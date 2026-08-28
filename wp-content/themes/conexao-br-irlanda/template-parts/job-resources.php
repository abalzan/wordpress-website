<?php
/**
 * Job Resources Section — "Onde procurar emprego" (Empregos landing).
 *
 * Renders external job-search websites as cards that reuse the Event card
 * visual language (.event-card, .event-card-banner, .event-card-body,
 * .event-card-title, .event-card-excerpt, .event-card-cta) so the section
 * feels native to the site. Content comes from conexao_job_resources()
 * (see inc/job-resources.php).
 *
 * All destinations are external: links open in a new tab with
 * rel="noopener noreferrer". Cards without an image get a clean branded
 * fallback banner (site initial on the primary gradient) instead of a
 * broken image.
 *
 * @package Conexao_BR_Irlanda
 */

$job_resources = conexao_job_resources();

if ( ! empty( $job_resources ) ) :
	?>
	<section class="empregos-job-resources" aria-labelledby="empregos-job-resources-title">
		<h2 id="empregos-job-resources-title" class="empregos-job-resources-title">
			<?php esc_html_e( 'Onde procurar emprego', 'conexao-br-irlanda' ); ?>
		</h2>

		<div class="events-grid empregos-job-resources-grid">
			<?php foreach ( $job_resources as $job_resource ) : ?>
				<?php
				if ( empty( $job_resource['title'] ) || empty( $job_resource['url'] ) ) {
					continue;
				}

				$resource_title  = (string) $job_resource['title'];
				$resource_url    = esc_url( (string) $job_resource['url'] );
				$resource_desc   = isset( $job_resource['description'] ) ? (string) $job_resource['description'] : '';
				$resource_image  = conexao_job_resource_image( $job_resource );
				$external_attrs  = ' target="_blank" rel="noopener noreferrer"';
				$new_tab_hint    = esc_attr__( '(abre em nova aba)', 'conexao-br-irlanda' );
				?>
				<article class="event-card job-resource-card">
					<?php if ( $resource_image['attachment_id'] ) : ?>
						<a class="event-card-banner" href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Acessar %s', 'conexao-br-irlanda' ), $resource_title ) ); ?>"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<?php
							echo wp_get_attachment_image(
								$resource_image['attachment_id'],
								'conexao-event-banner',
								false,
								array(
									'class'   => 'event-card-banner-img',
									'loading' => 'lazy',
									'alt'     => esc_attr( $resource_image['alt'] ),
									// Same rendered-width rationale as the Event card banners
									// (see template-parts/event-card.php).
									'sizes'   => '(max-width: 768px) 85vw, 320px',
								)
							);
							?>
						</a>
					<?php elseif ( $resource_image['url'] ) : ?>
						<a class="event-card-banner" href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Acessar %s', 'conexao-br-irlanda' ), $resource_title ) ); ?>"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<img class="event-card-banner-img" src="<?php echo esc_url( $resource_image['url'] ); ?>" alt="<?php echo esc_attr( $resource_image['alt'] ); ?>" loading="lazy">
						</a>
					<?php else : ?>
						<a class="event-card-banner job-resource-banner-fallback" href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-hidden="true" tabindex="-1"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<span class="job-resource-banner-letter"><?php echo esc_html( mb_substr( $resource_title, 0, 1 ) ); ?></span>
						</a>
					<?php endif; ?>

					<div class="event-card-body">
						<h3 class="event-card-title">
							<a href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $resource_title ); ?></a>
						</h3>

						<?php if ( $resource_desc ) : ?>
							<p class="event-card-excerpt"><?php echo esc_html( $resource_desc ); ?></p>
						<?php endif; ?>

						<a href="<?php echo $resource_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="event-card-cta" title="<?php echo esc_attr( $new_tab_hint ); ?>"<?php echo $external_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<?php esc_html_e( 'Saiba mais', 'conexao-br-irlanda' ); ?>
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
								<line x1="5" y1="12" x2="19" y2="12"></line>
								<polyline points="12 5 19 12 12 19"></polyline>
							</svg>
							<span class="screen-reader-text"><?php esc_html_e( '(abre em nova aba)', 'conexao-br-irlanda' ); ?></span>
						</a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
endif;
