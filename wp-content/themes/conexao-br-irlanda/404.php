<?php get_header(); ?>
<div class="site-container">
	<main id="primary" class="content-area">
		<section class="error-404">
			<h1 class="error-code">404</h1>
			<h2><?php esc_html_e( 'Ops! Página não encontrada.', 'conexao-br-irlanda' ); ?></h2>
			<p><?php esc_html_e( 'A página que você procura pode ter sido movida ou removida.', 'conexao-br-irlanda' ); ?></p>

			<div class="error-404-search">
				<h3><?php esc_html_e( 'Tente buscar pelo que precisa:', 'conexao-br-irlanda' ); ?></h3>
				<?php get_search_form(); ?>
			</div>

			<div class="error-404-links">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn-green"><?php esc_html_e( 'Voltar para o início', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/guias/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Guias Práticos', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Eventos', 'conexao-br-irlanda' ); ?></a>
			</div>

			<div class="error-404-popular">
				<h3><?php esc_html_e( 'Guias Populares', 'conexao-br-irlanda' ); ?></h3>
				<ul class="error-404-list">
					<?php
					// Single query for popular guides, cached in transient (5 min).
					$popular_guides = get_transient( 'conexao_404_guides' );
					if ( false === $popular_guides ) {
						$guide_query = new WP_Query( array(
							'post_type'      => 'guide',
							'posts_per_page' => 5,
							'no_found_rows'  => true,
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
						) );
						$popular_guides = array();
						if ( $guide_query->have_posts() ) {
							while ( $guide_query->have_posts() ) : $guide_query->the_post();
								$popular_guides[] = array( 'title' => get_the_title(), 'url' => get_permalink() );
							endwhile;
						}
						wp_reset_postdata();
						set_transient( 'conexao_404_guides', $popular_guides, 300 );
					}
					if ( ! empty( $popular_guides ) ) :
						foreach ( $popular_guides as $guide ) : ?>
							<li><a href="<?php echo esc_url( $guide['url'] ); ?>"><?php echo esc_html( $guide['title'] ); ?></a></li>
						<?php endforeach;
					else : ?>
						<li><a href="<?php echo esc_url( home_url( '/guias/pps-number/' ) ); ?>"><?php esc_html_e( 'PPS Number', 'conexao-br-irlanda' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/guias/medical-card/' ) ); ?>"><?php esc_html_e( 'Medical Card', 'conexao-br-irlanda' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/guias/abrir-conta-bancaria/' ) ); ?>"><?php esc_html_e( 'Abrir Conta Bancária', 'conexao-br-irlanda' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/guias/alugar-casa/' ) ); ?>"><?php esc_html_e( 'Alugar Casa', 'conexao-br-irlanda' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/guias/carteira-de-motorista/' ) ); ?>"><?php esc_html_e( 'Carteira de Motorista', 'conexao-br-irlanda' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>

			<div class="error-404-events">
				<h3><?php esc_html_e( 'Próximos Eventos', 'conexao-br-irlanda' ); ?></h3>
				<ul class="error-404-list">
					<?php
					// Single query for upcoming events, cached in transient (5 min).
					$upcoming_events = get_transient( 'conexao_404_events' );
					if ( false === $upcoming_events ) {
						// Recurrence: consume the shared ordered upcoming-event ID
						// list (Conexao_Event_Query) when the event runtime is
						// active; legacy date-meta query otherwise.
						$upcoming_ids = conexao_event_upcoming_ids();

						$events_args = array(
							'post_type'              => 'event',
							'post_status'            => 'publish',
							'posts_per_page'         => 3,
							'no_found_rows'          => true,
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
						);

						if ( is_array( $upcoming_ids ) ) {
							$events_args['post__in'] = empty( $upcoming_ids ) ? array( 0 ) : $upcoming_ids;
							$events_args['orderby']  = 'post__in';
							$events_args['order']    = 'ASC';
						} else {
							$events_args['meta_key']     = '_event_date';
							$events_args['meta_value']   = current_time( 'Y-m-d' );
							$events_args['meta_compare'] = '>=';
							$events_args['meta_type']    = 'DATE';
							$events_args['orderby']      = 'meta_value';
							$events_args['order']        = 'ASC';
						}

						$events_query = new WP_Query( $events_args );
						$upcoming_events = array();
						if ( $events_query->have_posts() ) {
							while ( $events_query->have_posts() ) : $events_query->the_post();
								$upcoming_events[] = array( 'title' => get_the_title(), 'url' => get_permalink() );
							endwhile;
						}
						wp_reset_postdata();
						set_transient( 'conexao_404_events', $upcoming_events, 300 );
					}
					if ( ! empty( $upcoming_events ) ) :
						foreach ( $upcoming_events as $event_item ) : ?>
							<li><a href="<?php echo esc_url( $event_item['url'] ); ?>"><?php echo esc_html( $event_item['title'] ); ?></a></li>
						<?php endforeach;
					else : ?>
						<li><?php esc_html_e( 'Nenhum evento próximo no momento.', 'conexao-br-irlanda' ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
		</section>
	</main>
</div>
<?php get_footer();