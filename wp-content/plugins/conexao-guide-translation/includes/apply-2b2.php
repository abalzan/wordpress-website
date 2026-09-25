<?php
// Stage 9 apply part 2b-2: main run loop.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_run( array $args = array() ): array {
	$dry_run = ! empty( $args['dry_run'] );
	$manifest = conexao_guide_translation_manifest();
	$summary = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'terms_created' => 0, 'terms_linked' => 0, 'links_localized' => 0, 'pt_changed' => 0 );
	$rows = array();
	if ( ! function_exists( 'pll_get_post' ) ) {
		return array( 'summary' => array_merge( $summary, array( 'errors' => 1 ) ), 'rows' => array( array( 'pt_slug' => '', 'pt_id' => 0, 'en_id' => 0, 'en_url' => '', 'action' => 'error', 'message' => 'Polylang missing' ) ), 'excluded' => array(), 'audit' => conexao_guide_translation_audit() );
	}
	if ( ! $dry_run ) {
		$t = conexao_guide_translation_ensure_terms( false );
		$summary['terms_created'] = $t['created'];
		$summary['terms_linked'] = $t['linked'];
	}
	$pt_before = array();
	foreach ( $manifest as $pt_slug => $en ) {
		$pt_slug = (string) $pt_slug;
		$row = array( 'pt_slug' => $pt_slug, 'pt_id' => 0, 'en_id' => 0, 'en_url' => '', 'action' => 'skipped', 'message' => '' );
		$pt_post = get_page_by_path( $pt_slug, OBJECT, 'guide' );
		if ( ! $pt_post instanceof WP_Post ) {
			$row['action'] = 'excluded'; $row['message'] = 'PT guide not present';
			++$summary['skipped']; $rows[] = $row; continue;
		}
		$pt_id = (int) $pt_post->ID;
		if ( 'publish' !== $pt_post->post_status ) {
			$row['pt_id'] = $pt_id; $row['action'] = 'excluded'; $row['message'] = 'PT not published';
			++$summary['skipped']; $rows[] = $row; continue;
		}
		$row['pt_id'] = $pt_id;
		$pt_before[ $pt_slug ] = conexao_guide_translation_snapshot_guide( $pt_id );
		$en_id = (int) pll_get_post( $pt_id, 'en' );
		if ( $en_id > 0 && $en_id !== $pt_id && 'publish' === get_post_status( $en_id ) ) {
			$row['en_id'] = $en_id;
			if ( ! conexao_guide_translation_pair_ok( $pt_id, $en_id ) ) {
				$row['action'] = 'error'; $row['message'] = 'pair link broken';
				++$summary['errors']; $rows[] = $row; continue;
			}
			if ( ! $dry_run ) { conexao_guide_translation_refresh_en( $pt_id, $en_id, $en, $summary ); }
			$row['action'] = 'exists'; $row['message'] = 'EN verified'; $row['en_url'] = (string) get_permalink( $en_id );
			++$summary['skipped']; $rows[] = $row; continue;
		}
		$collision = get_page_by_path( (string) $en['en_slug'], OBJECT, 'guide' );
		if ( $collision instanceof WP_Post && (int) $collision->ID !== $pt_id ) {
			$row['action'] = 'error'; $row['message'] = 'EN slug collision';
			++$summary['errors']; $rows[] = $row; continue;
		}
		if ( $dry_run ) {
			$row['action'] = 'would-create'; $row['message'] = 'would create EN';
			++$summary['created']; $rows[] = $row; continue;
		}
		list( $new_id, $err ) = conexao_guide_translation_create_en( $pt_post, $en );
		if ( '' !== $err ) {
			$row['en_id'] = $new_id; $row['action'] = 'error'; $row['message'] = $err;
			++$summary['errors']; $rows[] = $row; continue;
		}
		$row['en_id'] = $new_id; $row['en_url'] = (string) get_permalink( $new_id ); $row['action'] = 'created'; $row['message'] = 'EN created+linked';
		++$summary['created']; $rows[] = $row;
	}
	$gate = conexao_guide_translation_check_pt( $pt_before );
	$summary['pt_changed'] = $gate['changed'];
	$summary['errors'] += $gate['errors'];
	$rows = array_merge( $rows, $gate['rows'] );
	if ( ! $dry_run ) {
		if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) ) { PLL()->model->clean_languages_cache(); pll_languages_list(); }
		flush_rewrite_rules( false );
	}
	return array( 'summary' => $summary, 'rows' => $rows, 'excluded' => array(), 'audit' => conexao_guide_translation_audit() );
}
function conexao_guide_translation_refresh_en( int $pt_id, int $en_id, array $en, array &$summary ): void {
	$en_post = get_post( $en_id );
	$updates = array( 'ID' => $en_id );

	if ( $en_post instanceof WP_Post ) {
		if ( $en_post->post_title !== (string) $en['en_title'] ) { $updates['post_title'] = (string) $en['en_title']; }
		if ( $en_post->post_content !== g_content( (string) $en['en_content'] ) ) { $updates['post_content'] = g_content( (string) $en['en_content'] ); }
		if ( $en_post->post_name !== (string) $en['en_slug'] ) { $updates['post_name'] = (string) $en['en_slug']; }
		if ( (string) $en_post->post_excerpt !== (string) $en['en_excerpt'] ) { $updates['post_excerpt'] = (string) $en['en_excerpt']; }
	}

	// Only write when something actually differs: an unconditional
	// wp_update_post() on every run would keep bumping post_modified for no
	// reason and would make a no-op re-run look like a change.
	if ( count( $updates ) > 1 ) {
		wp_update_post( $updates );
		++$summary['updated'];
	}

	conexao_guide_translation_copy_fields( $pt_id, $en_id, $en );
	update_post_meta( $en_id, 'conexao_meta_description', (string) $en['en_meta_description'] );
}
function conexao_guide_translation_check_pt( array $pt_before ): array {
	$changed = 0; $errors = 0; $rows = array();
	foreach ( $pt_before as $pt_slug => $before ) {
		$pt_post = get_page_by_path( (string) $pt_slug, OBJECT, 'guide' );
		if ( ! $pt_post instanceof WP_Post ) {
			++$changed; ++$errors;
			$rows[] = array( 'pt_slug' => (string) $pt_slug, 'action' => 'error', 'message' => 'PT MISSING AFTER RUN', 'pt_id' => 0, 'en_id' => 0, 'en_url' => '' );
			continue;
		}
		$after = conexao_guide_translation_snapshot_guide( (int) $pt_post->ID );
		if ( '' === $before['language'] ) { $before['language'] = $after['language']; }
		if ( $after !== $before ) {
			++$changed; ++$errors;
			$rows[] = array( 'pt_slug' => (string) $pt_slug, 'action' => 'error', 'message' => 'PT CHANGED', 'pt_id' => (int) $pt_post->ID, 'en_id' => 0, 'en_url' => '' );
		}
	}
	return array( 'changed' => $changed, 'errors' => $errors, 'rows' => $rows );
}
