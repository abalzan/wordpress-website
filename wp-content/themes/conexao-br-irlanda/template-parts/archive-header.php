<?php
/**
 * Archive Header Template Part
 *
 * Standardized page header for all archive/listing pages.
 *
 * Uses the unified .page-header component from the design system
 * (design-system.css §7) so that every archive page — Eventos,
 * Cursos, Lazer, Guias, Empregos, Apoiadores — renders the same
 * header structure: an optional section-eyebrow, the h1 page title,
 * an optional description, and optional filter controls.
 *
 * @package Conexao_BR_Irlanda
 *
 * @var string $args['eyebrow']     Section eyebrow text (rendered uppercase by CSS).
 * @var string $args['title']       Page title (h1).
 * @var string $args['description'] Optional description paragraph.
 * @var string $args['filters']     Optional filter template slug ('event', 'leisure')
 *                                  or empty string for no filters.
 */

$eyebrow     = $args['eyebrow']     ?? '';
$title       = $args['title']       ?? '';
$description = $args['description'] ?? '';
$filters     = $args['filters']     ?? '';
?>

<header class="page-header">
	<?php if ( $eyebrow ) : ?>
		<span class="section-eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
	<?php endif; ?>

	<?php if ( $title ) : ?>
		<h1 class="page-title"><?php echo esc_html( $title ); ?></h1>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<p class="page-description"><?php echo esc_html( $description ); ?></p>
	<?php endif; ?>
</header>

<?php if ( $filters ) : ?>
	<?php get_template_part( 'template-parts/' . $filters, 'filters' ); ?>
<?php endif; ?>
