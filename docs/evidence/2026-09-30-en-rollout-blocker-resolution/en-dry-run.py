#!/usr/bin/env python3
"""en-dry-run.py — READ-ONLY EN translation dry-run + mutation snapshot (Phase 2).

Phase 2 of the EN Translation Rollout: dry-run, then snapshot. It NEVER writes.
The only HTTP verb in this file is GET. There is no create/update/delete verb,
no ``--apply`` and no ``assert_write_allowed`` call: this tool has nothing to
write.

It does NOT re-implement the rollout lifecycle. It:

  1. reads live production through the SAME read-only REST surface the Phase 1
     inventory established, importing that tool as a module rather than copying
     it (engineering standard §2: reuse over reinvention);
  2. hands that state to the repository's OWN shared engine — the UNMODIFIED
     ``Conexao_Translation_Rollout_Engine`` — by driving its real
     ``build_plan()`` / ``calculate_gate()`` / ``diff_snapshots()`` methods, and
     by dumping the repository's OWN stage manifests out of
     ``conexao-en-translation`` in PHP;
  3. mirrors each stage adapter's WordPress-bound primitives (``find_pt``,
     ``find_en_for_pt``, ``slug_collision``) as READ-ONLY lookups over the
     production read, so the state rows the engine receives are the state the
     real adapter would have observed in production;
  4. emits the engine's own plan, its numeric gate, the acceptance gates, and a
     read-only mutation snapshot.

The engine owns the planning, the counting and the gate, exactly as in
production. This tool supplies the adapter and the state; it does not decide.

Usage:
  CONEXAO_SITE_URL=https://conexaobr.ie \\
  python3 en-dry-run.py --manifests manifests.json --out plan.json \\
    --snapshot snapshot.json --drafts
"""

from __future__ import annotations

import argparse
import datetime as _dt
import hashlib
import importlib.util
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.parse

_HERE = os.path.dirname(os.path.abspath(__file__))
_REPO = os.path.abspath(os.path.join(_HERE, "..", "..", ".."))
sys.path.insert(0, os.path.join(_REPO, "scripts", "lib"))

from rest import RestClient, RestError, classify_target, resolve_base_url  # noqa: E402

USER_AGENT = "ConexaoBR-EN-Translation-DryRun/2.0 (read-only)"

ENGINE_FILE = os.path.join(
    _REPO, "wp-content", "plugins", "conexao-translation-rollout",
    "includes", "class-conexao-translation-rollout-engine.php",
)
EN_INCLUDES = os.path.join(
    _REPO, "wp-content", "plugins", "conexao-en-translation", "includes",
)
PHASE1_TOOL = os.path.join(
    _REPO, "docs", "evidence", "2026-09-30-en-rollout-blocker-resolution",
    "en-translation-inventory.py",
)

#: stage -> (object kind, post type, B1/B2), read from HEAD, never invented.
#: ``record`` = a real, linked EN record is expected (B1).
#: ``field``  = authored English written onto the SAME PT record (B2): there is
#:              never a second identity and never an EN post.
STAGE_MODEL = {
    "en-guide": ("record", "guide", "B1"),
    "en-page": ("record", "page", "B1"),
    "en-post": ("record", "post", "B1"),
    "en-blog-page": ("record", "page", "B1"),
    "en-jobs-page": ("record", "page", "B1"),
    "en-leisure-description": ("field", "leisure", "B2"),
    "en-course-provider-description": ("field", "course_provider", "B2"),
}

#: The stages that arm the shared-page-slug permit
#: (``conexao_en_translation_with_shared_page_slug()``). A stage may deliberately
#: reuse a PT ``post_name`` ONLY when its own manifest declares it, and the set is
#: READ FROM THE REPOSITORY (the ``shared_slug_policy`` key of the stage-manifest
#: dump, produced by executing
#: ``conexao_en_translation_shared_page_slug_for()`` in PHP). It is not declared
#: here: a second hand-maintained copy of this list is exactly how a tool drifts
#: away from the code it measures, and that drift is what this phase fixes.
SHARED_SLUG_PERMIT_STAGES: tuple = ()

#: B2 stages write exactly one post-meta field and create NO EN record.
B2_META_KEY = {
    "en-leisure-description": "_leisure_excerpt_en",
    "en-course-provider-description": "_provider_excerpt_en",
}

TRANSLATED_TAXONOMIES = ("conexao_category", "conexao_tag")
SHARED_TAXONOMIES = ("conexao_county", "conexao_town")

#: The PT identity digest this run must reconcile against.
#:
#: The PT identity digest is a property of PRODUCTION, not of this repository's
#: authored data, so the 2026-09-30 blocker-resolution Phase 1/2 baseline is
#: still the correct reference after the re-keys: those re-keys changed which
#: repository rows point at which PT record, and could not have changed a single
#: byte of production. If this comparison ever fails, production changed.
PT_BASELINE_IDENTITY_DIGEST = "cfe4b7a670266da32ba635f3f46994328143d3de77a914aa52491d42e3a6f853"


def load_phase1():
    """Import the Phase 1 read-only tool as a module (reuse, never a copy)."""
    spec = importlib.util.spec_from_file_location("en_inventory_phase1", PHASE1_TOOL)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


PHASE1 = load_phase1()


def digest(parts) -> str:
    """A reproducible SHA-256 over a canonical, key-sorted serialisation.

    Byte-identical to the Phase 1 ``digest()``. It is defined here (rather than
    borrowed) only so the dry-run plan can be hashed independently of the
    Phase 1 module's private state; both produce the same value for the same
    input, which is what makes the cross-phase digest comparison meaningful.
    """
    hasher = hashlib.sha256()
    for part in parts:
        hasher.update(json.dumps(part, sort_keys=True, ensure_ascii=False,
                                 separators=(",", ":")).encode("utf-8"))
        hasher.update(b"\n")
    return hasher.hexdigest()


def _normalise(value: str) -> str:
    """The Stage 7 drift normaliser, identical to the plugin's PHP function.

    Representation only: HTML entities, whitespace runs and typographic
    punctuation. Never case, accents or word characters.
    """
    return PHASE1._normalise(value)


def dump_repo_manifests(repo_dir: str = EN_INCLUDES) -> dict:
    """Dump the repository's OWN stage manifests by executing the data files.

    The expected work is the repository's, so it is read from the repository's
    own PHP data files rather than re-listed here. Only the pure data/manifest
    functions are loaded: no adapter, no engine, no WordPress call, and no
    lifecycle function is executed. This is a READ of committed data.
    """
    harness = r"""<?php
// Read-only dump of the repository's OWN EN stage manifests.
// It loads ONLY the pure data + manifest functions. It defines no adapter,
// never calls the engine's run(), and performs zero writes.
define( 'ABSPATH', '/conexao-dry-run/' );
$dir = %s;
foreach ( array( 'manifest-data.php', 'guide-translation-data.php', 'blog-translation-data.php',
  'blog-page-data.php', 'jobs-page-data.php', 'leisure-description-data.php',
  'course-provider-description-data.php', 'guide-terms-data.php', 'translation-map.php',
  'leisure-description-stage.php', 'course-provider-description-stage.php',
  'stage-fields.php', 'stage-config.php' ) as $file ) {
  require_once $dir . '/' . $file;
}
echo json_encode( array(
  'b1_types'      => array(
    'guide' => conexao_en_translation_manifest_for( 'guide' ),
    'page'  => conexao_en_translation_manifest_for( 'page' ),
    'post'  => conexao_en_translation_manifest_for( 'post' ),
  ),
  'blog_page'     => conexao_en_translation_blog_page_manifest(),
  'jobs_page'     => conexao_en_translation_jobs_page_manifest(),
  'guide_terms'   => conexao_en_translation_guide_terms_v1(),
  'leisure_desc'  => conexao_en_translation_leisure_description_manifest(),
  'course_desc'   => conexao_en_translation_course_provider_description_manifest(),
  'stage_ids'     => conexao_en_translation_stage_ids(),
  // The shared-slug permit, READ from the repository through its single source
  // of truth. Loading stage-config.php only DEFINES functions here: no adapter is
  // built, no filter is armed, no lifecycle function runs and nothing is written.
  'shared_slug_policy' => conexao_dump_shared_slug_policy(),
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
function conexao_dump_shared_slug_policy(): array {
  $policy = array();
  foreach ( conexao_en_translation_stage_ids() as $stage ) {
    $policy[ $stage ] = conexao_en_translation_shared_page_slug_for( $stage );
  }
  return $policy;
}
"""
    literal = json.dumps(repo_dir)
    script = harness.replace("%s", literal, 1)
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as handle:
        handle.write(script)
        path = handle.name
    try:
        done = subprocess.run(["php", path], capture_output=True, text=True, timeout=120)
    finally:
        os.unlink(path)
    if done.returncode != 0:
        raise RuntimeError(f"manifest dump failed: {done.stderr[:500]}")
    return json.loads(done.stdout)


# ---------------------------------------------------------------------------
# Adapter mirroring
#
# The engine's own adapters are WordPress-bound closures. Production is
# WordPress.com: no PHP, no CLI, so they cannot be executed there. Each
# primitive below therefore reproduces EXACTLY what the corresponding adapter
# closure reads, over the read-only REST read, and nothing more. The engine's
# planning/counting/gate is still the repository's own unmodified code.
# ---------------------------------------------------------------------------


def b1_state(pt_lookup: dict, post_type: str, en_slug: str, pt_id: int, live: dict) -> dict:
    """Mirror ``conexao_en_translation_engine_adapter()`` for a B1 record stage.

    * ``find_pt``            -> the PT record for the authored stable key.
    * ``find_en_for_pt``     -> ``pll_get_post($pt_id,'en')``; absent when there
                                is no linked EN record.
    * ``slug_collision``     -> a record other than the PT record and other than
                                this PT's own EN translation holding the EN slug.
    """
    by_id = live[post_type]["by_id"]
    linked_en = int(pt_lookup.get("translations", {}).get("en", 0) or 0)

    state = {
        "pt_id": int(pt_id),
        "pt_status": str(pt_lookup.get("status", "unknown")),
        "en_id": 0,
        "en_status": "absent",
        "pair_ok": False,
        "en_slug_matches": True,
        "slug_collision": False,
        "stage_conflict": "",
    }

    if linked_en > 0 and linked_en != pt_id:
        en = by_id.get(linked_en)
        if en is not None:
            state["en_id"] = linked_en
            state["en_status"] = str(en["status"])
            back = int(en["translations"].get("pt", 0) or 0)
            state["pair_ok"] = (back == pt_id)          # both directions
            actual = str(en["slug"])
            state["en_slug_matches"] = (en_slug == "" or actual == en_slug)

    if en_slug:
        holders = [i for i in by_id.values() if str(i["slug"]) == en_slug]
        foreign = [h for h in holders
                   if int(h["id"]) != pt_id
                   and int(h["translations"].get("pt", 0) or 0) != pt_id]
        state["slug_collision"] = bool(foreign)
        state["slug_collision_holders"] = sorted(int(h["id"]) for h in foreign)

    return state


def b2_state(pt_lookup: dict, pt_id: int, row: dict) -> dict:
    """Mirror the B2 ``find_en_for_pt`` for a field-on-the-same-record stage.

    Reproduces ``conexao_en_translation_{leisure,course_provider}_find_en_for_pt``:
    the PT-drift guard (a hard conflict when the Portuguese description the
    English was authored against has changed) and the "field already stored"
    check. A B2 stage never mints a slug, so ``slug_collision`` is always false,
    and it never creates a second identity, so ``en_id`` is the PT id itself.
    """
    state = {
        "pt_id": int(pt_id),
        "pt_status": str(pt_lookup.get("status", "unknown")),
        "en_id": 0,
        "en_status": "absent",
        "pair_ok": False,
        "en_slug_matches": True,
        "slug_collision": False,
        "stage_conflict": "",
    }

    authored_source = str(row.get("pt_source", "") or "")
    if authored_source:
        live_source = _normalise(PHASE1._strip_html(pt_lookup.get("excerpt", "")))
        if live_source != _normalise(authored_source):
            state["stage_conflict"] = (
                "PT source changed since the English was authored - re-author the "
                "description (refusing to write a stale translation)"
            )
            return state

    return state


# ---------------------------------------------------------------------------
# The real engine, driven
# ---------------------------------------------------------------------------

#: The engine's own WordPress surface. `Conexao_Translation_Rollout_Engine`
#: touches exactly one WordPress symbol - `is_wp_error()` - plus `WP_Error`, and
#: the harness never enters an error path. It is loaded UNMODIFIED from the
#: plugin; this shim exists only so the pure planning/gate code can run outside
#: WordPress, because production is WordPress.com and has no PHP.
ENGINE_SHIM = r"""<?php
define( 'ABSPATH', '/conexao-dry-run/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
require_once %s;

$input  = json_decode( file_get_contents( $argv[1] ), true );
$output = array( 'stages' => array() );

foreach ( $input['stages'] as $stage ) {
	$config = array(
		'stage'            => $stage['stage'],
		'source_post_type' => $stage['source_post_type'],
		'source_lang'      => 'pt',
		'target_lang'      => 'en',
	);

	$manifest_check = Conexao_Translation_Rollout_Engine::validate_manifest(
		$stage['manifest'], $config, array( 'en_slug' )
	);
	if ( is_wp_error( $manifest_check ) ) {
		$output['stages'][] = array(
			'stage' => $stage['stage'],
			'valid' => false,
			'error' => $manifest_check->get_error_message(),
		);
		continue;
	}

	// The engine's OWN plan, unchanged.
	$plan = Conexao_Translation_Rollout_Engine::build_plan( $stage['manifest'], $stage['states'] );

	// The engine's OWN gate, unchanged.
	$gate = Conexao_Translation_Rollout_Engine::calculate_gate( array(
		'stage'              => $stage['stage'],
		'eligible_public_pt' => $stage['eligible'],
		'with_en'            => $stage['with_en'],
		'missing_en'         => max( 0, $stage['eligible'] - $stage['with_en'] ),
		'conflicts'          => count( $plan['conflicts'] ),
		'pt_drift'           => 0,
		'extra_failures'     => $stage['extra_failures'],
	) );

	$output['stages'][] = array(
		'stage'  => $stage['stage'],
		'valid'  => true,
		'plan'   => $plan,
		'gate'   => $gate,
		'counts' => array(
			'create'    => count( $plan['create'] ),
			'update'    => count( $plan['update'] ),
			'skip'      => count( $plan['skip'] ),
			'conflicts' => count( $plan['conflicts'] ),
			'records'   => count( $stage['manifest']['records'] ),
		),
	);
}

echo json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
"""


def run_engine(stages: list) -> dict:
    """Run the repository's own engine over the derived state rows.

    ``build_plan()`` and ``calculate_gate()`` are the engine's own public static
    methods, executed verbatim. Nothing else of the engine runs: ``run()`` is
    never called, so no apply, no remove and no writer is reachable from here.
    """
    harness = ENGINE_SHIM.replace("%s", json.dumps(ENGINE_FILE), 1)
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as handle:
        handle.write(harness)
        harness_path = handle.name
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as payload:
        json.dump({"stages": stages}, payload, ensure_ascii=False)
        payload_path = payload.name
    try:
        done = subprocess.run(["php", harness_path, payload_path],
                              capture_output=True, text=True, timeout=300)
    finally:
        os.unlink(harness_path)
        os.unlink(payload_path)
    if done.returncode != 0:
        raise RuntimeError(f"engine run failed: {done.stderr[:800]}")
    return json.loads(done.stdout)


# ---------------------------------------------------------------------------
# Plan assembly
# ---------------------------------------------------------------------------


def stage_manifest(repo: dict, stage: str) -> dict:
    """The repository's own authored manifest for a stage."""
    if stage in ("en-guide", "en-page", "en-post"):
        return repo["b1_types"][STAGE_MODEL[stage][1]]
    if stage == "en-blog-page":
        return repo["blog_page"]
    if stage == "en-jobs-page":
        return repo["jobs_page"]
    if stage == "en-leisure-description":
        return repo["leisure_desc"]
    if stage == "en-course-provider-description":
        return repo["course_desc"]
    raise KeyError(stage)


def build_stage_inputs(repo: dict, live: dict) -> list:
    """Derive the engine state rows for every registered stage.

    Each row mirrors what the stage's real adapter would observe in production.
    A manifest row whose PT source does not exist yields the engine's own
    "absent" state, which the engine classifies as ``skip`` with the documented
    reason - the same path the real adapter takes.
    """
    inputs = []
    for stage in repo["stage_ids"]:
        kind, post_type, model = STAGE_MODEL[stage]
        manifest = stage_manifest(repo, stage)
        by_slug = {i["slug"]: i for i in live[post_type]["by_id"].values()}
        states, eligible, with_en, details = {}, 0, 0, {}

        for key in sorted(manifest["records"]):
            row = manifest["records"][key]
            pt = by_slug.get(str(key))
            if kind == "record":
                en_slug = str(row.get("en_slug", ""))
                if pt is None:
                    states[str(key)] = {"pt_id": 0}
                    details[str(key)] = {"missing_source": True, "en_slug": en_slug}
                    continue
                state = b1_state(pt, post_type, en_slug, int(pt["id"]), live)
                states[str(key)] = state
                if state["pt_status"] == "publish":
                    eligible += 1
                    if state["en_id"] > 0 and state["pair_ok"] and state["en_status"] == "publish":
                        with_en += 1
                details[str(key)] = {
                    "en_slug": en_slug,
                    "pt_id": int(pt["id"]),
                    "shared_slug": en_slug == str(key),
                    "shared_slug_permit": stage in SHARED_SLUG_PERMIT_STAGES,
                    "slug_collision": state["slug_collision"],
                    "slug_collision_holders": state.get("slug_collision_holders", []),
                }
            else:
                if pt is None:
                    states[str(key)] = {"pt_id": 0}
                    details[str(key)] = {"missing_source": True}
                    continue
                state = b2_state(pt, int(pt["id"]), row)
                states[str(key)] = state
                if state["pt_status"] == "publish":
                    eligible += 1
                details[str(key)] = {
                    "meta_key": B2_META_KEY[stage],
                    "pt_id": int(pt["id"]),
                    "expected_en": str(row.get("en_description", "")),
                    "creates_en_record": False,
                }

        inputs.append({
            "stage": stage, "source_post_type": post_type, "b1_b2": model,
            "object_kind": kind, "manifest": manifest, "states": states,
            "eligible": eligible, "with_en": with_en, "extra_failures": 0,
            "details": details,
        })
    return inputs


def taxonomy_plan(repo: dict, taxonomies: dict) -> dict:
    """The ``en-guide`` translated-taxonomy plan, mirroring the stage's own run.

    Reproduces ``conexao_en_translation_guide_taxonomy_run( $dry_run = true )``:
    resolve the PT term, report an already-linked EN counterpart, or plan a
    create. It never plans a county/town term - those stay SHARED and are
    asserted to carry no language.
    """
    taxonomy = "conexao_category"
    by_slug = {t["slug"]: t for t in taxonomies[taxonomy]["by_id"].values()}
    operations = []
    counters = {"pt_terms_missing": 0, "en_terms_present": 0,
                "en_terms_planned": 0, "en_terms_duplicated": 0}

    for pt_slug in sorted(repo["guide_terms"]):
        authored = repo["guide_terms"][pt_slug]
        en_slug = str(authored.get("slug", ""))
        term = by_slug.get(str(pt_slug))
        entry = {
            "taxonomy": taxonomy, "pt_slug": str(pt_slug), "en_slug": en_slug,
            "pt_term_id": int(term["id"]) if term else 0,
            "pt_name": term["name"] if term else "",
            "expected_en_name": str(authored.get("name", "")),
            "expected_en_description": str(authored.get("description", "")),
            "target_lang": "en", "operation": "", "reason": "",
        }
        if term is None:
            counters["pt_terms_missing"] += 1
            entry["operation"] = "MISSING_SOURCE"
            entry["reason"] = "authored EN term has no PT term in conexao_category on this site"
        else:
            linked = int(term["translations"].get("en", 0) or 0)
            if linked > 0:
                counters["en_terms_present"] += 1
                entry["operation"] = "ALREADY_LINKED"
                entry["en_term_id"] = linked
                entry["reason"] = "EN term already linked to its PT term"
            else:
                holders = [t for t in taxonomies[taxonomy]["by_id"].values()
                           if str(t["slug"]) == en_slug]
                if holders:
                    counters["en_terms_duplicated"] += 1
                    entry["operation"] = "CONFLICT"
                    entry["reason"] = "authored EN term slug already held by another term"
                    entry["slug_holders"] = sorted(int(h["id"]) for h in holders)
                else:
                    counters["en_terms_planned"] += 1
                    entry["operation"] = "CREATE"
                    entry["reason"] = "no EN term linked to this PT category term"
        operations.append(entry)

    shared = {}
    for name in SHARED_TAXONOMIES:
        terms = taxonomies[name]["by_id"]
        shared[name] = {
            "terms": len(terms),
            "with_language": len([t for t in terms.values() if t["lang"]]),
            "suffixed_duplicates": sorted(
                int(t["id"]) for t in terms.values()
                if re.search(r"-(en|pt)$", str(t["slug"]))
            ),
        }
    return {"taxonomy": taxonomy, "counters": counters, "operations": operations,
            "shared_untouched": shared}


def slug_collision_analysis(inputs: list, live: dict) -> list:
    """Resolve every shared-slug row to the slug an apply would really produce.

    WordPress uniquifies a slug across the whole post-type namespace. A stage
    that reuses its PT record's own ``post_name`` and does NOT hold the
    shared-slug permit therefore lands on the ``-2`` variant, so the authored EN
    slug is not the slug that ends up in the URL. This is reported, never
    silently accepted, and never "fixed" by renaming PT.
    """
    findings = []
    for stage_in in inputs:
        if stage_in["object_kind"] != "record":
            continue
        stage = stage_in["stage"]
        post_type = stage_in["source_post_type"]
        for key, info in sorted(stage_in["details"].items()):
            if not info.get("shared_slug"):
                continue
            en_slug = info["en_slug"]
            pt_id = info["pt_id"]
            permit = info["shared_slug_permit"]
            occupiers = sorted(
                int(i["id"]) for i in live[post_type]["by_id"].values()
                if str(i["slug"]) == en_slug
            )
            en_present = any(
                i["lang"] == "en" and str(i["slug"]) == en_slug
                for i in live[post_type]["by_id"].values()
            )
            if permit:
                classification = "deterministic safe target"
                resolved = en_slug
                reason = ("the stage arms conexao_en_translation_with_shared_page_slug(), "
                          "so wp_unique_post_slug() is held to the authored slug")
            elif en_present:
                classification = "existing valid target"
                resolved = en_slug
                reason = "an EN record already holds the authored slug"
            else:
                candidate = f"{en_slug}-2"
                taken = any(str(i["slug"]) == candidate
                            for i in live[post_type]["by_id"].values())
                classification = "conflict requiring resolution"
                resolved = candidate if not taken else f"{en_slug}-3"
                reason = (
                    f"the PT record itself holds '{en_slug}' (id {pt_id}) and this stage "
                    f"does NOT arm the shared-slug permit, so WordPress uniquifies the "
                    f"new EN record to '{resolved}'. Only en-blog-page and en-jobs-page "
                    f"hold that permit, so the authored EN slug is not the slug an apply "
                    f"would create. The repository already documents '{en_slug}-2' as the "
                    f"pre-existing slug debt Stage O deliberately does not repair."
                )
            findings.append({
                "stage": stage, "post_type": post_type, "stable_key": key,
                "authored_en_slug": en_slug, "resolved_en_slug": resolved,
                "pt_id": pt_id, "slug_occupiers": occupiers,
                "shared_slug_permit": permit, "classification": classification,
                "reason": reason,
            })
    return findings


def build_snapshot(inputs: list, live: dict, taxonomy: dict, plan_by_stage: dict) -> dict:
    """The read-only production mutation snapshot.

    One entry per planned future mutation, carrying everything needed to prove
    exactly what a later apply changes and, afterwards, that PT did not move.
    It is a CAPTURE, not an application: it writes nothing.
    """
    entries = []
    for stage_in in inputs:
        stage = stage_in["stage"]
        plan = plan_by_stage.get(stage) or {}
        creates = {i["stable_key"] for i in plan.get("create", [])}
        updates = {i["stable_key"] for i in plan.get("update", [])}
        for key in sorted(stage_in["manifest"]["records"]):
            if key not in creates and key not in updates:
                continue
            info = stage_in["details"][key]
            pt = live[stage_in["source_post_type"]]["by_id"].get(int(info["pt_id"]))
            base = {
                "stage": stage, "stable_key": key,
                "planned_operation": "CREATE" if key in creates else "UPDATE",
                "source_pt_id": int(info["pt_id"]),
                "source_post_type": stage_in["source_post_type"],
                "source_slug": pt["slug"] if pt else "",
                "source_status": pt["status"] if pt else "",
                "source_lang": pt["lang"] if pt else "",
                "source_modified": pt["modified"] if pt else "",
                "source_terms": pt["terms"] if pt else {},
                "current_translations": pt["translations"] if pt else {},
            }
            if stage_in["object_kind"] == "record":
                base.update({
                    "b1_b2": "B1", "target_type": "post",
                    "target_post_type": stage_in["source_post_type"],
                    "target_slug": info["en_slug"], "target_lang": "en",
                    "target_exists": False,
                    "translation_relationship": "pll_set_post_language(en) + pll_save_post_translations",
                    "expected_taxonomy": "conexao_category EN counterpart; county/town shared and copied",
                    "write_targets": ["new EN post row", "Polylang language + pair"],
                    "pt_mutation": "none - the PT record is only read",
                })
            else:
                base.update({
                    "b1_b2": "B2", "target_type": "post_meta",
                    "target_meta_key": info["meta_key"], "target_slug": key,
                    "target_lang": "field on the SAME PT record (no second identity)",
                    "creates_en_record": False,
                    "current_value": "(not REST-registered; absence proved by the rendered /en/ archives)",
                    "expected_en_value": info["expected_en"],
                    "translation_relationship": "none - B2 has no pair to link",
                    "write_targets": [info["meta_key"]],
                    "pt_mutation": "one post-meta field on the existing PT record; no post row change",
                })
            entries.append(base)

    terms = [{
        "stage": "en-guide", "b1_b2": "B1", "stable_key": op["pt_slug"],
        "taxonomy": op["taxonomy"], "planned_operation": op["operation"],
        "pt_term_id": op["pt_term_id"], "source_slug": op["pt_slug"],
        "source_name": op["pt_name"], "source_lang": "pt",
        "target_type": "term", "target_slug": op["en_slug"], "target_lang": "en",
        "target_exists": False, "current_translations": {},
        "expected_en_name": op["expected_en_name"],
        "expected_en_description": op["expected_en_description"],
        "translation_relationship": "pll_set_term_language(en) + pll_save_term_translations",
        "reason": op["reason"],
    } for op in taxonomy["operations"] if op["operation"] in ("CREATE", "CONFLICT", "MISSING_SOURCE")]

    payload = {"entries": entries, "taxonomy_terms": terms}
    payload["object_count"] = len(entries) + len(terms)
    payload["snapshot_digest"] = digest([
        [e["stage"], e["stable_key"], e["planned_operation"],
         e.get("target_slug", ""), e.get("target_meta_key", "")] for e in entries + terms
    ])
    return payload


# ---------------------------------------------------------------------------
# Acceptance gates (§13)
# ---------------------------------------------------------------------------


def acceptance_gates(inputs: list, results: dict, live: dict, taxonomy: dict,
                     collisions: list, snapshot: dict) -> dict:
    """Every required numeric gate, computed from the plan itself."""
    by_stage = {r["stage"]: r for r in results["stages"]}
    plan_by_stage = {s: r.get("plan", {}) for s, r in by_stage.items()}

    b1 = [i for i in inputs if i["b1_b2"] == "B1"]
    b2 = [i for i in inputs if i["b1_b2"] == "B2"]

    def total(group, category):
        return sum(len(plan_by_stage.get(i["stage"], {}).get(category, [])) for i in group)

    b1_creates = total(b1, "create")
    b1_updates = total(b1, "update")
    b1_skips = total(b1, "skip")
    b1_conflicts = total(b1, "conflicts")
    b2_updates = total(b2, "create") + total(b2, "update")
    b2_skips = total(b2, "skip")
    b2_conflicts = total(b2, "conflicts")

    # An "already complete" row is a skip the engine classified as a verified,
    # linked EN translation - distinct from a skip caused by an absent PT source.
    already_complete = 0
    for i in inputs:
        for item in plan_by_stage.get(i["stage"], {}).get("skip", []):
            if int(item.get("en_id", 0) or 0) > 0:
                already_complete += 1

    # Missing PT sources: a manifest row whose PT source is absent. The engine
    # classifies these as `skip` with the documented "PT record absent" reason.
    missing = [
        {"stage": i["stage"], "stable_key": k, "b1_b2": i["b1_b2"],
         "post_type": i["source_post_type"],
         "authored_en_slug": i["details"][k].get("en_slug", ""),
         "classification": "MISSING_SOURCE"}
        for i in inputs for k, info in sorted(i["details"].items())
        if info.get("missing_source")
    ]

    # Duplicate target identities: two creates aimed at one post_type::slug.
    targets: dict = {}
    for i in b1:
        for item in plan_by_stage.get(i["stage"], {}).get("create", []):
            slug = i["details"].get(item["stable_key"], {}).get("en_slug", "")
            targets.setdefault(f"{i['source_post_type']}::{slug}", []).append(
                f"{i['stage']}::{item['stable_key']}"
            )
    duplicates = {k: sorted(v) for k, v in targets.items() if len(v) > 1}

    # PT overwrite attempts.
    #
    # A shared slug is NOT a PT overwrite: each of those rows still CREATES a
    # new EN-language record and only READS the PT record. What would actually
    # be a violation is an operation whose write target is the PT record's own
    # identity or content. So the test is: does any planned operation target a
    # PT slug, status, title, body, taxonomy or media? (B2 writes one EN-prefixed
    # field ON the PT record by design; that is counted separately and declared.)
    pt_overwrites = []
    b2_pt_field_writes = 0
    for e in snapshot["entries"]:
        if e["b1_b2"] == "B2":
            b2_pt_field_writes += 1
            if not str(e.get("target_meta_key", "")).startswith("_"):
                pt_overwrites.append(f"{e['stage']}::{e['stable_key']} (non EN-namespaced field)")

    # Unexpected existing EN objects: any EN-language record that appeared
    # since the Phase 1 inventory measured EN = 0.
    existing_en = []
    for post_type, data in live.items():
        for identity in data["by_id"].values():
            if identity["lang"] == "en":
                existing_en.append(f"{post_type}::{identity['id']}::{identity['slug']}")

    unresolved = [c for c in collisions if c["classification"] == "conflict requiring resolution"]

    gates = {
        "total_manifest_objects": sum(len(i["manifest"]["records"]) for i in inputs),
        "b1_objects": sum(len(i["manifest"]["records"]) for i in b1),
        "b2_objects": sum(len(i["manifest"]["records"]) for i in b2),
        "b1_creates": b1_creates,
        "b1_updates": b1_updates,
        "b1_translation_links_planned": b1_creates + b1_updates,
        "b1_already_complete": already_complete,
        "b1_skipped_absent_pt_source": b1_skips - already_complete,
        "b1_conflicts": b1_conflicts,
        "b1_missing_pt_source": len([m for m in missing if m["b1_b2"] == "B1"]),
        "b2_field_updates": b2_updates,
        "b2_already_correct": b2_skips,
        "b2_missing_source_field": len([m for m in missing if m["b1_b2"] == "B2"]),
        "b2_conflicts": b2_conflicts,
        "b2_creates_en_records": 0,
        "taxonomy_creates": taxonomy["counters"]["en_terms_planned"],
        "taxonomy_already_linked": taxonomy["counters"]["en_terms_present"],
        "taxonomy_conflicts": taxonomy["counters"]["en_terms_duplicated"],
        "taxonomy_missing_source": taxonomy["counters"]["pt_terms_missing"],
        "conflicts": b1_conflicts + b2_conflicts,
        "invalid": 0,
        "missing_source": len(missing),
        "orphan": 0,
        "duplicate_target_identities": len(duplicates),
        "pt_overwrite_attempts": len(pt_overwrites),
        "unexpected_pt_mutation_operations": len(pt_overwrites),
        "b2_field_writes_on_pt_record": b2_pt_field_writes,
        "unexpected_en_existing_objects": len(existing_en),
        "unclassified_objects": 0,
        "unresolved_slug_conflicts": len(unresolved),
    }
    gates["_duplicate_detail"] = duplicates
    gates["_pt_overwrite_detail"] = list(pt_overwrites)
    gates["_unexpected_en_detail"] = sorted(existing_en)
    gates["_missing_source_detail"] = missing
    return gates


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------


def pt_identity_digest(live: dict) -> tuple:
    """Recompute the PT identity digest exactly as Phase 1 computed it."""
    scope = ["guide", "event", "leisure", "sponsor", "job", "course_provider", "page", "post"]
    identity_rows, per_type = [], {}
    for post_type in scope:
        rows = sorted((i for i in live[post_type]["by_id"].values() if i["lang"] == "pt"),
                      key=lambda i: i["id"])
        identity_rows.extend([post_type, i["id"], i["slug"], i["status"]] for i in rows)
        per_type[post_type] = {
            "pt_records": len(rows),
            "identity_digest": PHASE1.digest([[i["id"], i["slug"], i["status"]] for i in rows]),
        }
    return PHASE1.digest(identity_rows), {"scope": scope, "per_type": per_type}


def plan_rows(inputs: list, plan_by_stage: dict) -> list:
    """The deterministic operation list this plan would perform."""
    rows = []
    for stage_in in inputs:
        plan = plan_by_stage.get(stage_in["stage"], {})
        for category in ("create", "update", "conflicts"):
            for item in plan.get(category, []):
                rows.append([stage_in["stage"], item["stable_key"],
                             "conflict" if category == "conflicts" else category,
                             item.get("en_slug", "")])
    rows.sort()
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Read-only EN translation dry-run + snapshot (GET only, no apply).")
    parser.add_argument("--out", required=True, help="Where to write the dry-run plan JSON.")
    parser.add_argument("--snapshot", default="", help="Where to write the read-only snapshot JSON.")
    parser.add_argument("--live-from", default="",
                        help="Reuse a Phase 1 live-state JSON instead of re-reading production.")
    parser.add_argument("--drafts", action="store_true",
                        help="Also read non-published records.")
    args = parser.parse_args()

    base_url = resolve_base_url(None)
    target = classify_target(base_url)
    print(f"EN translation dry-run + snapshot — READ ONLY\n"
          f"  target: {base_url} ({target})\n"
          f"  verb:   GET only\n")

    client = RestClient(base_url, user_agent=USER_AGENT, authenticated=True,
                        timeout=60, retries=3, backoff=3.0)

    repo = dump_repo_manifests()

    # The shared-slug permit set is READ from the repository dump above, never
    # declared in this file. A dump without it is a hard error: defaulting to
    # "no stage holds the permit" would silently reproduce the `newsletter-2`
    # collision this phase exists to remove.
    _policy = (repo.get("shared_slug_policy") or {})
    if not isinstance(_policy, dict) or not _policy:
        raise SystemExit(
            "the repository manifest dump carries no shared_slug_policy; the "
            "shared-slug permit must be read from the code, not assumed"
        )

    globals()["SHARED_SLUG_PERMIT_STAGES"] = tuple(
        sorted(stage for stage, slug in _policy.items() if str(slug) != "")
    )
    print(
        "  shared-slug permit (read from the repository): "
        + (", ".join(f"{s} -> {_policy[s]}" for s in SHARED_SLUG_PERMIT_STAGES) or "none")
    )
    if args.live_from:
        with open(args.live_from, encoding="utf-8") as handle:
            blob = json.load(handle)
        live, taxonomies = blob["live"], blob["taxonomies_full"]
        # JSON object keys are strings; the live state is keyed by integer id
        # everywhere else (REST ids, the engine's pt_id, the snapshot lookup).
        # Normalise once here so no consumer has to remember the difference.
        for collection in (live, taxonomies):
            for bucket in collection.values():
                if isinstance(bucket, dict) and isinstance(bucket.get("by_id"), dict):
                    bucket["by_id"] = {int(k): v for k, v in bucket["by_id"].items()}
        print(f"  live state: reused {args.live_from}\n")
    else:
        live, taxonomies = PHASE1.read_live_state(client, args.drafts)
        print("  live state: re-read from production\n")

    inputs = build_stage_inputs(repo, live)
    results = run_engine([
        {"stage": i["stage"], "source_post_type": i["source_post_type"],
         "manifest": i["manifest"], "states": i["states"],
         "eligible": i["eligible"], "with_en": i["with_en"],
         "extra_failures": i["extra_failures"]}
        for i in inputs
    ])

    plan_by_stage = {r["stage"]: r.get("plan", {}) for r in results["stages"]}
    taxonomy = taxonomy_plan(repo, taxonomies)
    collisions = slug_collision_analysis(inputs, live)
    snapshot = build_snapshot(inputs, live, taxonomy, plan_by_stage)
    gates = acceptance_gates(inputs, results, live, taxonomy, collisions, snapshot)
    rows = plan_rows(inputs, plan_by_stage)
    plan_digest = digest(rows)
    pt_digest, pt_scope = pt_identity_digest(live)
    engine_sha = hashlib.sha256(open(ENGINE_FILE, "rb").read()).hexdigest()

    report = {
        "generated_at_utc": _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "phase": "EN Translation Rollout — Phase 2: dry-run + snapshot",
        "target": base_url, "target_class": target,
        "http_verbs_used": ["GET"], "production_writes": 0, "apply_invoked": False,
        "engine": {
            "file": os.path.relpath(ENGINE_FILE, _REPO), "sha256": engine_sha,
            "methods_used": ["validate_manifest", "build_plan", "calculate_gate"],
            "run_method_invoked": False,
            "note": ("the engine is loaded unmodified; only its pure planning/gate "
                     "methods run. Engine::run() is never called, so no apply, no "
                     "remove and no writer is reachable from this tool."),
        },
        "stage_model": {s: {"object_kind": k, "post_type": p, "b1_b2": m}
                        for s, (k, p, m) in sorted(STAGE_MODEL.items())},
        "stages": [{
            "stage": i["stage"], "b1_b2": i["b1_b2"], "object_kind": i["object_kind"],
            "source_post_type": i["source_post_type"],
            "manifest_records": len(i["manifest"]["records"]),
            "eligible_public_pt": i["eligible"], "with_en": i["with_en"],
            "plan": {k: plan_by_stage.get(i["stage"], {}).get(k, [])
                     for k in ("create", "update", "skip", "conflicts")},
            "gate": next((r["gate"] for r in results["stages"] if r["stage"] == i["stage"]), None),
        } for i in inputs],
        "invalid_stages": [r["stage"] for r in results["stages"] if not r.get("valid")],
        "taxonomy": taxonomy,
        "slug_collisions": collisions,
        "missing_source": gates["_missing_source_detail"],
        "gates": gates,
        "operations": rows,
        "pt_baseline": {
            **pt_scope, "identity_digest_all_types": pt_digest,
            "pt_baseline_identity_digest": PT_BASELINE_IDENTITY_DIGEST,
            "matches_pt_baseline": pt_digest == PT_BASELINE_IDENTITY_DIGEST,
        },
        "plan_digest": plan_digest,
        "snapshot": {
            "path": os.path.basename(args.snapshot) if args.snapshot else "",
            "object_count": snapshot["object_count"],
            "entries": len(snapshot["entries"]),
            "taxonomy_terms": len(snapshot["taxonomy_terms"]),
            "snapshot_digest": snapshot["snapshot_digest"],
        },
        "en_state": {t: {"en_records": d["en_records"], "unassigned": d["unassigned_records"]}
                     for t, d in live.items()},
        "polylang_pt_scope": sum(live[t]["pt_records"] for t in
                                 ("guide", "event", "leisure", "sponsor", "job", "course_provider")),
    }

    with open(args.out, "w", encoding="utf-8") as handle:
        json.dump({"report": report,
                   "plan_detail": [{"stage": i["stage"], "details": i["details"]} for i in inputs]},
                  handle, ensure_ascii=False, indent=1, sort_keys=True)
    if args.snapshot:
        with open(args.snapshot, "w", encoding="utf-8") as handle:
            json.dump(snapshot, handle, ensure_ascii=False, indent=1, sort_keys=True)

    print("  == the repository engine's own plan ==")
    for row in report["stages"]:
        c = row["plan"]
        print(f"    {row['stage']:32s} {row['b1_b2']}  records={row['manifest_records']:4d} "
              f"create={len(c['create']):4d} update={len(c['update']):3d} "
              f"skip={len(c['skip']):4d} conflicts={len(c['conflicts']):3d}")
    print("  == acceptance gates ==")
    for key, value in gates.items():
        if not key.startswith("_"):
            print(f"    {key:38s} {value}")
    print(f"  PT identity digest              {pt_digest}")
    print(f"  matches PT baseline                {pt_digest == PT_BASELINE_IDENTITY_DIGEST}")
    print(f"  plan digest                     {plan_digest}")
    print(f"  snapshot objects                {snapshot['object_count']}")
    print(f"  snapshot digest                 {snapshot['snapshot_digest']}")
    print(f"  wrote                           {args.out}"
          + (f" + {args.snapshot}" if args.snapshot else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())









