<?php
/**
 * Authored EN rows RETIRED because their PT source no longer exists.
 *
 * This file is the durable, machine-readable record of an EN row that was
 * deliberately removed from the authored dataset. A retired row is NOT the same
 * thing as a row that was never authored, and it is NOT the same thing as a row
 * that is merely unresolved: the decision itself has to survive, or the next
 * reader cannot tell an intentional retirement from an oversight.
 *
 * WHY A REGISTRY AT ALL
 * ---------------------
 * The shared engine already owns the vocabulary for a row that is PRESENT in a
 * manifest but whose PT record is absent — `build_plan()` emits
 * "PT record absent in this site (documented exclusion)" into its `skip` bucket.
 * That mechanism cannot express this case: the PT record here is not merely
 * absent on a target site, it was PERMANENTLY DELETED upstream, and the authored
 * English was retired with operator authorisation rather than left to be skipped
 * forever. The row is therefore absent from the manifest by decision, and this
 * registry is where that decision is written down. The engine is NOT modified
 * and this registry is NOT fed to it: the retired row must not reappear as a
 * plan object, and it does not.
 *
 * VOCABULARY
 * ----------
 * `classification` uses the term the EN rollout reports already use for an
 * authored row with no resolvable PT source: `NO_REAL_PT_SOURCE`. `reason` is
 * the operator-facing sentence. `retired_on` is the date of the operator
 * decision. `evidence` points at the committed investigation that established
 * the deletion, so the claim is checkable rather than asserted.
 *
 * NOTHING HERE INVENTS A PT IDENTITY. A retired row records the PT stable key it
 * was authored against — the slug, which is the only portable identity
 * (engineering standard 0.4) — and never a local post ID.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The retired authored EN rows, keyed by the PT stable key they were authored
 * against.
 *
 * Read-only: nothing in the rollout lifecycle consumes this array. It exists so
 * the retirement is explicit and auditable, and so a future contributor who
 * meets the missing row does not re-author it by accident.
 *
 * @return array<string,array<string,string>>
 */
function conexao_en_translation_exclusions_v1(): array {
	return array(
		'learner-permit-theory-test-irlanda-cnh-brasileira' => array(
			'classification' => 'NO_REAL_PT_SOURCE',
			'reason'         => 'PT source permanently deleted; operator-authorized exclusion',
			'stage'          => 'en-guide',
			'en_slug'        => 'brazilian-driving-licence-in-ireland-theory-test-and-learner-permit',
			'retired_on'     => '2026-09-30',
			'evidence'       => 'docs/reports/2026-09-30-en-pt-source-reconciliation.md; '
				. 'docs/evidence/2026-09-30-en-pt-source-reconciliation/08-row1-exhaustive.txt',
		),
	);
}
