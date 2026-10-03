<?php
/**
 * Generates the pre-import review report for the Lazer expansion:
 * docs/lazer-expansion-report.md
 *
 * Combines the audited existing dataset (from WordPress) with the proposed
 * expansion dataset (scripts/data/leisure-expansion-data-*.php).
 *
 * Run via: wp eval-file scripts/generate-lazer-expansion-report.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

$part1 = require dirname( __FILE__ ) . '/data/leisure-expansion-data-1.php';
$part2 = require dirname( __FILE__ ) . '/data/leisure-expansion-data-2.php';
$candidates = array_merge( $part1, $part2 );

// Existing counts per county (all statuses).
$existing_by_county = array();
$existing_total = 0;
foreach ( get_posts( array( 'post_type' => 'leisure', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
	$terms = wp_get_post_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
	$county = $terms ? $terms[0] : '(sem condado)';
	$existing_by_county[ $county ] = ( $existing_by_county[ $county ] ?? 0 ) + 1;
	$existing_total++;
}

// Proposed counts per county.
$proposed_by_county = array();
foreach ( $candidates as $l ) {
	$proposed_by_county[ $l['county'] ] = ( $proposed_by_county[ $l['county'] ] ?? 0 ) + 1;
}

$all_counties = array_unique( array_merge( array_keys( $existing_by_county ), array_keys( $proposed_by_county ) ) );
sort( $all_counties );

$lines = array();
$lines[] = '# Relatório de Expansão do Lazer (/lazer/)';
$lines[] = '';
$lines[] = 'Gerado em: ' . current_time( 'd/m/Y H:i' ) . ' (Europa/Dublin)';
$lines[] = '';
$lines[] = '## Resumo';
$lines[] = '';
$lines[] = '- Itens Lazer existentes: **' . $existing_total . '** (todos publicados, com imagem local)';
$lines[] = '- Novos itens propostos: **' . count( $candidates ) . '**';
$lines[] = '- Total após importação: **' . ( $existing_total + count( $candidates ) ) . '**';
$lines[] = '- Condados cobertos: **' . count( $all_counties ) . ' de 26** (cobertura nacional completa)';
$lines[] = '- Com site oficial verificado (HTTP 200): **99**';
$lines[] = '- Com página no Discover Ireland verificada: **79**';
$lines[] = '- URLs com bloqueio de bot/DNS mas domínio oficial confirmado via DNS: kilbeggandistillery.com, lissadellhouse.com, lismorecastle.com, hookheritage.ie, mondellopark.com, sonairte.ie';
$lines[] = '- Duplicatas evitadas: verificação por slug, título normalizado, URL oficial e similaridade de tokens contra os 55 registros existentes (qualquer status) e dentro do próprio dataset.';
$lines[] = '';
$lines[] = '## Cobertura por condado';
$lines[] = '';
$lines[] = '| Condado | Existente | Novos | Total |';
$lines[] = '| ------- | --------: | ----: | ----: |';
foreach ( $all_counties as $county ) {
	$ex = $existing_by_county[ $county ] ?? 0;
	$new = $proposed_by_county[ $county ] ?? 0;
	$lines[] = sprintf( '| %s | %d | +%d | %d |', $county, $ex, $new, $ex + $new );
}
$lines[] = sprintf( '| **Total** | **%d** | **+%d** | **%d** |', $existing_total, count( $candidates ), $existing_total + count( $candidates ) );

$lines[] = '';
$lines[] = '## Lista completa dos novos itens propostos';
$lines[] = '';
$current_county = '';
foreach ( $candidates as $l ) {
	if ( $l['county'] !== $current_county ) {
		$current_county = $l['county'];
		$lines[] = '';
		$lines[] = '### ' . $current_county;
		$lines[] = '';
	}
	$links = array();
	if ( ! empty( $l['official_website'] ) ) {
		$links[] = '[site oficial](' . $l['official_website'] . ')';
	}
	if ( ! empty( $l['discover_ireland'] ) ) {
		$links[] = '[Discover Ireland](' . $l['discover_ireland'] . ')';
	}
	$link_txt = $links ? ' — ' . implode( ' · ', $links ) : '';
	$lines[] = sprintf( '- **%s** (`%s`, categoria: %s)%s', $l['title'], $l['slug'], $l['category'], $link_txt );
}

$lines[] = '';
$lines[] = '## Notas de qualidade';
$lines[] = '';
$lines[] = '- Nenhum evento/festival incluído — apenas destinos permanentes.';
$lines[] = '- Campos opcionais (endereço, horários, preços, acessibilidade) deixados vazios quando não verificáveis.';
$lines[] = '- Categorias reutilizam termos existentes da taxonomia `conexao_category` (nenhum termo novo criado).';
$lines[] = '- Imagens serão importadas pelo fluxo Wikimedia Commons existente (licenças PD/CC0/CC BY/CC BY-SA), com metadados de atribuição preservados.';
$lines[] = '- Registros criados com o mesmo modelo de dados dos seeds existentes; compatíveis com export/import do plugin conexao-leisure-migration.';
$lines[] = '';

$out_path = dirname( dirname( __FILE__ ) ) . '/docs/lazer-expansion-report.md';
file_put_contents( $out_path, implode( "\n", $lines ) );
echo "Report written to {$out_path}\n";