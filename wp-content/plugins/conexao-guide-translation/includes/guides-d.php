<?php
// Stage 9 map D: merges the final two batches.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_d(): array {
	return array_merge( conexao_guide_translation_manifest_d1(), conexao_guide_translation_manifest_d2() );
}
