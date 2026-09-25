<?php
// Stage 9 map B: placeholder (batch 2).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_b(): array {
	return array_merge(
		conexao_guide_translation_manifest_b1(),
		conexao_guide_translation_manifest_b2(),
		conexao_guide_translation_manifest_b3()
	);
}
