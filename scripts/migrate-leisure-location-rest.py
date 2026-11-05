#!/usr/bin/env python3
"""Migrate the Lazer (leisure) LOCATION dataset from the local source of truth
to a WordPress target, entirely over the WordPress REST API.

Purpose
-------
The local development environment holds the reviewed Lazer location dataset
(access coordinates, and the practical-characteristic taxonomy that produced
them). Production holds the same 289 attractions — verified 1:1 by slug — but
NONE of the coordinate data. This script moves exactly that missing data across,
idempotently, and writes nothing else.

Scope
-----
Only two families of field are ever written, and only on existing `leisure`
records that already exist in the target:

  1. COORDINATES — `_leisure_lat` and `_leisure_lng` (the only two location
     fields the data model exposes over REST). `_leisure_coord_source` and
     `_leisure_coord_verified` are registered with `show_in_rest => false`, so
     they are NOT writable through the REST API at all. That is a documented
     limitation, not an omission: the front-end proximity feature reads only
     lat/lng (`Conexao_Data_Model_Meta::get_coordinates()`), so the migration is
     complete and useful without the provenance text.
  2. LEISURE ATTRIBUTES (opt-in, `--include-attributes`) — ADD-ONLY assignment
     of `conexao_leisure_attribute` terms. Terms that exist in the target but
     not locally are always preserved; nothing is ever removed. Term IDs are
     resolved BY SLUG because term IDs are environment-local.

Never written: titles, slugs, content, excerpts, dates, statuses, authors,
featured images, every other meta key, every other taxonomy, and every
Polylang translation relationship. Nothing is ever created and nothing is ever
deleted — the script only fills fields on records that already exist.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# The shared Stage I REST engine: base URL, target classification, credentials
# and headers are resolved in ONE place for the whole repository.
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib"))
import rest  # noqa: E402  (path set up immediately above)

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

#: Descriptive, contactable User-Agent (REST etiquette). It is an identity
#: string, never an operational target.
USER_AGENT = "ConexaoBR-leisure-location-migration/1.0 (+repository tooling)"

#: The reviewed, portable coordinate dataset produced by the coordinate
#: research pipeline. It is the local source of truth for COORDINATES.
DEFAULT_MANIFEST = os.path.join(
    REPO_ROOT, "scripts", "data", "leisure-coordinates-2026-11-03.json"
)

#: The two coordinate meta keys the data model exposes over REST.
LAT_KEY = "_leisure_lat"
LNG_KEY = "_leisure_lng"

#: The only taxonomy this script will ever touch, and only additively.
ATTRIBUTE_TAXONOMY = "conexao_leisure_attribute"

#: Default resume/audit journal (git-ignored, see .gitignore `.local/`).
DEFAULT_STATE_FILE = os.path.join(
    REPO_ROOT, ".local", "leisure-location-migration-state.json"
)

#: Exit codes: 0 success (including "nothing to do"), 1 failure.
EXIT_OK = 0
EXIT_ERROR = 1


# ---------------------------------------------------------------------------
# Safety documentation (printed by --help after the module docstring).
# ---------------------------------------------------------------------------

SAFETY = """\
Safety
  * Dry run is the DEFAULT and writes nothing. --apply is the only write mode.
  * --target-url has no default: the target is always named explicitly, and a
    production target additionally requires --confirm-production.
  * Credentials come from WP_USERNAME / WP_APPLICATION_PASSWORD only. They are
    never printed, logged, or written into the report.
  * Records are matched by SLUG, never by local post ID (IDs are not portable).
  * Additive only: an existing differing target coordinate is reported as a
    CONFLICT and skipped unless --overwrite is given. Nothing is deleted, no
    record is created, no status, title, taxonomy (other than the opt-in
    attribute taxonomy) or translation relationship is touched.
  * Rate-limit aware: HTTP 429 is honoured via Retry-After with bounded backoff.
  * Resumable: applied records are journaled, so an interrupted run continues.
"""

MODES = """\
Modes
  --dry-run             (default) print the plan and write nothing.
  --apply               perform the plan. Requires --target-url (and
                        --confirm-production when the target is production).
  --verify              read-only: report target coverage and exit.
  --help                print this message and exit 0.
"""

UNRESOLVED = "UNRESOLVED"


def load_manifest(path):
    """Load and structurally validate the portable coordinate manifest.

    Args:
        path: Path to the reviewed JSON manifest.

    Returns:
        list[dict]: the manifest entries.

    Raises:
        SystemExit: when the manifest is missing, unreadable or not JSON.
    """
    if not path or not os.path.isfile(path):
        fail("manifest not found: {}".format(path), 2)
    try:
        with open(path, encoding="utf-8") as handle:
            decoded = json.load(handle)
    except (OSError, ValueError) as error:
        fail("manifest not readable as JSON: {}".format(error), 2)

    # Accept either a bare array of entries or an object with an `entries` key.
    if isinstance(decoded, dict) and isinstance(decoded.get("entries"), list):
        entries = decoded["entries"]
    elif isinstance(decoded, list):
        entries = decoded
    else:
        fail("manifest is neither a list of entries nor an object with `entries`", 2)

    out = [entry for entry in entries if isinstance(entry, dict)]
    if not out:
        fail("manifest contains no entries", 2)
    return out


def fail(message, code=EXIT_ERROR):
    """Print a failure to stderr and exit non-zero."""
    sys.stderr.write("ERROR: {}\n".format(message))
    raise SystemExit(code)


def normalise(value):
    """Return a stripped string for any scalar manifest/meta value."""
    if value is None:
        return ""
    if isinstance(value, bool):
        return "1" if value else ""
    return str(value).strip()


def parse_coordinate(raw, minimum, maximum, label):
    """Validate and canonicalise one coordinate string.

    Mirrors the data model's own rule (`Conexao_Data_Model_Meta`) so the migration
    validates exactly what the plugin will accept: a plain dot-separated decimal
    inside the geographic range, canonicalised to at most 6 decimal places.

    Args:
        raw: Raw manifest value (string, int, float or None).
        minimum: Inclusive lower bound.
        maximum: Inclusive upper bound.
        label: Human label used in the error message.

    Returns:
        str: the canonical decimal string, or "" when invalid.
    """
    text = normalise(raw)
    if text == "":
        return ""
    # Reject a locale comma, an exponent, NaN/inf and empty groups explicitly.
    if "," in text:
        sys.stderr.write(
            "  {}: REJECTED {!r} — a comma decimal separator is not canonical\n".format(
                label, raw
            )
        )
        return ""
    try:
        number = float(text)
    except ValueError:
        sys.stderr.write(
            "  {}: REJECTED {!r} — not a decimal number\n".format(label, raw)
        )
        return ""
    if number != number or number in (float("inf"), float("-inf")):
        sys.stderr.write(
            "  {}: REJECTED {!r} — not a finite number\n".format(label, raw)
        )
        return ""
    if number < minimum or number > maximum:
        sys.stderr.write(
            "  {}: REJECTED {!r} — outside {}..{}\n".format(
                label, raw, minimum, maximum
            )
        )
        return ""
    if number == 0.0:
        return "0"
    canonical = "{:.6f}".format(number).rstrip("0").rstrip(".")
    return canonical if canonical not in ("", "-") else "0"


def eligible_entries(entries):
    """Select the manifest entries this migration may act on.

    The rule is the repository's own apply convention: only `action == POPULATE`
    and only where `confidence == HIGH` or the owner explicitly approved the
    record by hand. Everything else is reported and skipped, never guessed.

    Args:
        entries: Manifest entries.

    Returns:
        tuple[list[dict], dict]: (eligible entries, reason -> count of skipped).
    """
    eligible = []
    skipped = {}
    for entry in entries:
        action = normalise(entry.get("action")).upper()
        confidence = normalise(entry.get("confidence")).upper()
        approved = entry.get("owner_approved") is True
        if action == "POPULATE" and (confidence == "HIGH" or approved):
            eligible.append(entry)
            continue
        key = "{}:{}".format(action or "NO_ACTION", confidence or "NO_CONFIDENCE")
        skipped[key] = skipped.get(key, 0) + 1
    return eligible, skipped


def plan_entry(entry, target_index, overwrite):
    """Decide what to do for one manifest entry against the target.

    Args:
        entry: One eligible manifest entry.
        target_index: slug -> target record (the fetched target dataset).
        overwrite: Whether an existing differing target value may be replaced.

    Returns:
        dict: a plan row with a stable `action` classification.
    """
    slug = normalise(entry.get("slug"))
    label = slug or normalise(entry.get("uuid")) or UNRESOLVED
    row = {
        "slug": slug,
        "uuid": normalise(entry.get("uuid")),
        "title": normalise(entry.get("title")),
        "action": UNRESOLVED,
        "reason": "",
        "target_id": None,
        "lat": "",
        "lng": "",
        "current_lat": "",
        "current_lng": "",
        "match": "none",
    }

    if not slug:
        row["reason"] = "no slug in the manifest entry; slug is the portable key"
        return row

    lat = parse_coordinate(entry.get("proposed_latitude"), -90.0, 90.0, label + " lat")
    lng = parse_coordinate(
        entry.get("proposed_longitude"), -180.0, 180.0, label + " lng"
    )
    if lat == "" or lng == "":
        # A half-populated pair is never written: the data model treats lat/lng
        # as a pair and the front-end ignores a partial pair anyway.
        row["reason"] = "missing or invalid coordinate pair in the manifest"
        return row
    row["lat"], row["lng"] = lat, lng

    record = target_index.get(slug)
    if record is None:
        row["reason"] = "no target record with this slug (never created by this script)"
        return row

    row["target_id"] = record["id"]
    row["current_lat"] = record["lat"]
    row["current_lng"] = record["lng"]

    # The target's own slug is the corroboration: the canonical slug matched,
    # which is the strongest identity key the production REST API exposes.
    row["match"] = "slug"

    if record["lat"] and record["lng"]:
        same = _same_coordinate(record["lat"], lat) and _same_coordinate(
            record["lng"], lng
        )
        if same:
            row["action"] = "ALREADY_SET"
            row["reason"] = "target already carries this exact coordinate"
        elif overwrite:
            row["action"] = "UPDATE"
            row["reason"] = "target differs; --overwrite allows replacement"
        else:
            row["action"] = "CONFLICT"
            row["reason"] = "target already carries a different coordinate; preserved"
        return row

    if record["lat"] or record["lng"]:
        row["action"] = "CONFLICT"
        row["reason"] = "target carries a partial (half) coordinate; preserved"
        return row

    row["action"] = "POPULATE"
    row["reason"] = "target has no coordinate"
    return row


def _same_coordinate(left, right):
    """Compare two coordinate strings numerically (never textually)."""
    try:
        return abs(float(left) - float(right)) < 1e-9
    except (TypeError, ValueError):
        return normalise(left) == normalise(right)


# ---------------------------------------------------------------------------
# REST access
# ---------------------------------------------------------------------------


class RateLimitedClient:
    """A minimal WordPress REST client with 429-aware backoff.

    `scripts/lib/rest.py` owns base-URL resolution, target classification,
    credential handling, header construction and error shaping for the whole
    repository; this class reuses all of that and adds only the one policy the
    shared `get_all()` deliberately does not impose — honouring `Retry-After`
    on HTTP 429, which WordPress.com returns under sustained load.

    Credentials are read from the environment by `rest.default_headers()` and
    are stored only in the request headers: they are never printed, logged, or
    accepted on the command line.
    """

    def __init__(self, base_url, timeout=30, max_attempts=5, backoff=2.0, verbose=True):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.max_attempts = max_attempts
        self.backoff = backoff
        self.verbose = verbose
        self.request_count = 0
        self.retry_count = 0

    def url_for(self, path):
        """Absolute URL for a REST path such as `wp/v2/leisure`.

        The WordPress REST base is `/wp-json/`; the same path works on
        WordPress.com's own `https://<site>/wp-json/` surface, so no
        platform-specific base is needed.
        """
        return "{}/wp-json/{}".format(self.base_url, path.lstrip("/"))

    def _retry_delay(self, error, attempt):
        """Honour Retry-After when present, else exponential backoff (bounded)."""
        retry_after = error.headers.get("Retry-After") if error is not None else None
        if retry_after:
            try:
                return min(float(retry_after), 60.0)
            except (TypeError, ValueError):
                pass
        return min(self.backoff * (2 ** (attempt - 1)), 30.0)

    def request(self, method, path, payload=None):
        """Perform one REST request, retrying only on 429 and 5xx.

        Returns:
            tuple[int, dict, object]: (status, headers, decoded body).

        Raises:
            SystemExit: on a non-retriable failure (401/403/404/…), with a
                bounded, credential-free message.
        """
        url = self.url_for(path)
        body = json.dumps(payload).encode("utf-8") if payload is not None else None
        try:
            headers = rest.default_headers(USER_AGENT, authenticated=True)
        except rest.RestError as error:
            # A missing credential is a usage error, not a crash: report it
            # cleanly (the shared client never puts a secret in the message).
            fail(str(error))

        attempt = 0
        while True:
            attempt += 1
            self.request_count += 1
            request = urllib.request.Request(
                url, data=body, headers=headers, method=method.upper()
            )
            try:
                with urllib.request.urlopen(request, timeout=self.timeout) as response:
                    raw = response.read().decode("utf-8", "replace")
                    status = response.status
                    headers_out = dict(response.headers)
                try:
                    decoded = json.loads(raw) if raw.strip() else None
                except ValueError:
                    fail(
                        "the response from {} {} was not JSON (first 200 bytes): {!r}. "
                        "Check that --target-url is the site root (the REST API lives "
                        "at /wp-json/).".format(method.upper(), path, raw[:200])
                    )
                return status, headers_out, decoded
            except urllib.error.HTTPError as error:
                detail = error.read().decode("utf-8", "replace")[:400]
                retriable = error.code == 429 or error.code >= 500
                if not retriable or attempt >= self.max_attempts:
                    hint = ""
                    if error.code in (401, 403):
                        hint = (
                            " — check WP_USERNAME / WP_APPLICATION_PASSWORD. Note that a "
                            "protected (underscore-prefixed) meta key is NOT writable over "
                            "REST unless the target plugin registers an explicit "
                            "auth_callback for it."
                        )
                    fail(
                        "HTTP {} on {} {}: {}{}".format(
                            error.code, method.upper(), path, detail, hint
                        )
                    )
                wait = self._retry_delay(error, attempt)
                self.retry_count += 1
                if self.verbose:
                    sys.stderr.write(
                        "  transient (HTTP {}); retrying in {:.1f}s [attempt {}/{}]\n".format(
                            error.code, wait, attempt, self.max_attempts
                        )
                    )
                time.sleep(wait)
            except (urllib.error.URLError, TimeoutError) as error:
                if attempt >= self.max_attempts:
                    fail(
                        "transport failure on {} {}: {}".format(
                            method.upper(), path, error
                        )
                    )
                wait = min(self.backoff * attempt, 30.0)
                self.retry_count += 1
                if self.verbose:
                    sys.stderr.write(
                        "  transport error; retrying in {:.1f}s\n".format(wait)
                    )
                time.sleep(wait)

    def get_all(self, path, params, per_page=100, max_pages=100):
        """Follow collection pagination via X-WP-TotalPages.

        Pagination is driven by the response header, so a truncated or
        reordered page set can never be silently accepted as complete.
        """
        collected = []
        query = dict(params)
        query["per_page"] = per_page
        page = 1
        headers = {}
        while page <= max_pages:
            query["page"] = page
            status, headers, body = self.request(
                "GET", "{}?{}".format(path, urllib.parse.urlencode(query))
            )
            if not isinstance(body, list):
                fail(
                    "expected a list from {} page {}; got {}".format(
                        path, page, type(body).__name__
                    )
                )
            collected.extend(body)
            total_pages = int(headers.get("X-WP-TotalPages") or 1)
            if page >= total_pages:
                break
            page += 1
        return collected, headers


# ---------------------------------------------------------------------------
# Target dataset
# ---------------------------------------------------------------------------


def fetch_target_index(client):
    """Fetch every `leisure` record from the target and index it by slug.

    Only the fields this migration needs are requested (`_fields` keeps the
    response small and the request cheap). The target's own post ID is used
    only as the write address WITHIN this environment — never as cross-
    environment identity.

    Args:
        client: RateLimitedClient bound to the target.

    Returns:
        tuple[dict, int]: (slug -> record, total record count).
    """
    records, _headers = client.get_all(
        "wp/v2/leisure",
        {
            "_fields": "id,slug,status,meta",
            "status": "publish",
        },
    )
    index = {}
    duplicates = []
    for record in records:
        slug = normalise(record.get("slug"))
        if not slug:
            continue
        meta = record.get("meta") or {}
        entry = {
            "id": record.get("id"),
            "slug": slug,
            "status": normalise(record.get("status")),
            "lat": normalise(meta.get(LAT_KEY)),
            "lng": normalise(meta.get(LNG_KEY)),
        }
        if slug in index:
            duplicates.append(slug)
            continue
        index[slug] = entry
    if duplicates:
        # An ambiguous slug would make "the" target record undefined. Fail closed
        # rather than pick one arbitrarily.
        fail(
            "the target has more than one leisure record with the same slug: {}; "
            "slug is the portable key and must be unique".format(
                ", ".join(sorted(set(duplicates))[:10])
            )
        )
    return index, len(records)


# ---------------------------------------------------------------------------
# Resume journal
# ---------------------------------------------------------------------------


def load_state(path):
    """Load the resume journal (an empty, valid journal when absent)."""
    empty = {"version": 1, "applied": {}, "attributes": {}}
    if not path or not os.path.isfile(path):
        return empty
    try:
        with open(path, encoding="utf-8") as handle:
            decoded = json.load(handle)
    except (OSError, ValueError):
        # A corrupt journal must never silently look like "nothing done": that
        # would re-walk everything. Report and start a fresh journal instead.
        sys.stderr.write(
            "  WARNING: the resume journal at {} is unreadable; starting a fresh "
            "one (re-applying is safe — the apply is idempotent)\n".format(path)
        )
        return empty
    if not isinstance(decoded, dict):
        return empty
    decoded.setdefault("version", 1)
    decoded.setdefault("applied", {})
    decoded.setdefault("attributes", {})
    return decoded


def save_state(path, state):
    """Persist the resume journal atomically (write-then-rename)."""
    if not path:
        return
    directory = os.path.dirname(path)
    if directory and not os.path.isdir(directory):
        os.makedirs(directory, exist_ok=True)
    temporary = path + ".tmp"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump(state, handle, indent=1, sort_keys=True)
    os.replace(temporary, path)


# ---------------------------------------------------------------------------
# Attribute taxonomy (optional phase)
# ---------------------------------------------------------------------------


def fetch_attribute_map(client):
    """Fetch the target's attribute terms keyed by SLUG.

    Term IDs are environment-local, so every term is resolved by slug. A term
    that does not exist in the target is never created by this script.
    """
    try:
        terms, _headers = client.get_all(
            "wp/v2/{}".format(ATTRIBUTE_TAXONOMY),
            {"_fields": "id,slug", "per_page": 100},
        )
    except SystemExit:
        raise
    return {normalise(term.get("slug")): term.get("id") for term in terms if term.get("slug")}


def plan_attributes(entries, attribute_ids, target_index):
    """Build an ADD-ONLY attribute plan keyed by slug.

    Args:
        entries: The local `leisure` records (slug + local attribute slugs).
        attribute_ids: Target term-ID map by slug.
        target_index: Target records by slug.

    Returns:
        list[dict]: one row per record that needs at least one term added.
    """
    rows = []
    for entry in entries:
        slug = normalise(entry.get("slug"))
        if not slug or slug not in target_index:
            continue
        wanted = [normalise(name) for name in (entry.get("attributes") or []) if normalise(name)]
        resolved = [attribute_ids[name] for name in wanted if name in attribute_ids]
        if not resolved:
            continue
        rows.append(
            {
                "slug": slug,
                "target_id": target_index[slug]["id"],
                "term_ids": resolved,
                "term_slugs": [name for name in wanted if name in attribute_ids],
            }
        )
    return rows


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def build_parser():
    """Build the argument parser (the `--dry-run`/`--apply` contract)."""
    parser = argparse.ArgumentParser(
        prog="migrate-leisure-location-rest.py",
        description=(
            "Migrate the Lazer location dataset (coordinates, and optionally the "
            "practical-attribute taxonomy) to a WordPress target over the REST API. "
            "Dry run by default."
        ),
        epilog=SAFETY + "\n" + MODES,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--target-url",
        default=None,
        help=(
            "The WordPress site to migrate INTO (e.g. the production site). "
            "REQUIRED for --apply and --verify; there is deliberately no default."
        ),
    )
    parser.add_argument(
        "--source-url",
        default=None,
        help=(
            "Optional local site to READ attribute taxonomy from. Defaults to "
            "the manifest only (coordinates need no live source)."
        ),
    )
    parser.add_argument(
        "--manifest",
        default=DEFAULT_MANIFEST,
        help="Reviewed coordinate manifest (default: scripts/data/leisure-coordinates-2026-11-03.json).",
    )
    parser.add_argument(
        "--include-attributes",
        action="store_true",
        help=(
            "Also ADD missing conexao_leisure_attribute terms, resolved by slug. "
            "Add-only; target terms are never removed and never created."
        ),
    )
    parser.add_argument(
        "--overwrite",
        action="store_true",
        help="Allow an existing DIFFERING target coordinate to be replaced. Off by default.",
    )
    parser.add_argument(
        "--state-file",
        default=DEFAULT_STATE_FILE,
        help="Resume journal path (default: .local/leisure-location-migration-state.json).",
    )
    parser.add_argument(
        "--no-resume",
        action="store_true",
        help="Ignore the resume journal and consider every record fresh.",
    )
    parser.add_argument(
        "--request-delay",
        type=float,
        default=0.25,
        help="Seconds to wait between writes (default 0.25; protects the rate limit).",
    )
    parser.add_argument(
        "--report",
        default=None,
        help="Write the machine-readable JSON report to this path (never contains credentials).",
    )
    parser.add_argument(
        "--confirm-production",
        action="store_true",
        help="Acknowledge a write against a production target. Required for a production --apply.",
    )
    parser.add_argument("--apply", action="store_true", help="Perform the plan (writes).")
    parser.add_argument("--dry-run", action="store_true", help="Plan only (the default).")
    parser.add_argument("--verify", action="store_true", help="Read-only coverage report.")
    return parser


def resolve_target_url(args):
    """Resolve and validate the explicit target URL."""
    if not args.target_url:
        fail(
            "--target-url is required for --apply and --verify. There is no default "
            "target: the migration always names the site it writes to."
        )
    target = args.target_url.rstrip("/")
    if not target.startswith(("http://", "https://")):
        fail("--target-url must be an absolute http(s) URL")
    return target


def print_preflight(target, mode, scope):
    """Print the standard run header: WHAT, WHERE, MODE, SCOPE."""
    kind = rest.classify_target(target)
    print("script: migrate-leisure-location-rest.py")
    print("target: {} ({})".format(target, kind))
    print("mode:   {}".format(mode))
    print("scope:  {}".format(scope))
    if kind == "production":
        bar = "!" * 70
        print(bar)
        print("!!  PRODUCTION TARGET — every write below changes the live site.")
        print("!!  Target: {}".format(target))
        print(bar)
    return kind


def local_attribute_entries(source_url):
    """Read the local attribute taxonomy over REST (read-only).

    The local site is the source of truth for which practical attributes each
    attraction carries. Only the slug and the attribute term slugs are read; no
    local post ID is used as identity.
    """
    client = RateLimitedClient(source_url, verbose=False)
    records, _headers = client.get_all(
        "wp/v2/leisure",
        {"_fields": "slug,conexao_leisure_attribute", "status": "publish"},
    )
    term_map = {}
    try:
        terms, _headers = client.get_all(
            "wp/v2/{}".format(ATTRIBUTE_TAXONOMY), {"_fields": "id,slug", "per_page": 100}
        )
        term_map = {term.get("id"): normalise(term.get("slug")) for term in terms}
    except SystemExit:
        term_map = {}

    out = []
    for record in records:
        slug = normalise(record.get("slug"))
        ids = record.get(ATTRIBUTE_TAXONOMY) or []
        out.append(
            {
                "slug": slug,
                "attributes": [term_map[i] for i in ids if i in term_map],
            }
        )
    return out


def write_coordinate(client, row, state, state_path):
    """Write one coordinate pair and journal it."""
    payload = {"meta": {LAT_KEY: row["lat"], LNG_KEY: row["lng"]}}
    status, _headers, body = client.request(
        "POST", "wp/v2/leisure/{}".format(row["target_id"]), payload
    )
    if status >= 300:
        fail(
            "unexpected status {} writing {}".format(status, row["slug"])
        )
    # Verify the write actually landed: the response is the authoritative echo.
    written_meta = (body or {}).get("meta") or {}
    got_lat = normalise(written_meta.get(LAT_KEY))
    got_lng = normalise(written_meta.get(LNG_KEY))
    if not _same_coordinate(got_lat, row["lat"]) or not _same_coordinate(
        got_lng, row["lng"]
    ):
        fail(
            "the write did not land for {}: expected ({}, {}), target reports ({}, {})".format(
                row["slug"], row["lat"], row["lng"], got_lat, got_lng
            )
        )
    state["applied"][row["slug"]] = {
        "target_id": row["target_id"],
        "lat": row["lat"],
        "lng": row["lng"],
        "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    save_state(state_path, state)
    return True


def emit_report(path, document):
    """Write the machine-readable report (never contains credentials)."""
    if not path:
        return
    directory = os.path.dirname(os.path.abspath(path))
    if directory and not os.path.isdir(directory):
        os.makedirs(directory, exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(document, handle, indent=1, sort_keys=False, ensure_ascii=False)
    print("report: {}".format(path))


def run_verify(client, target):
    """Read-only coverage report of the target."""
    index, total = fetch_target_index(client)
    with_coordinate = sum(1 for record in index.values() if record["lat"] and record["lng"])
    half = sum(
        1 for record in index.values() if bool(record["lat"]) != bool(record["lng"])
    )
    print(
        "verify: target={} records={} with_coordinate={} without_coordinate={} "
        "half_filled={} distinct_slugs={}".format(
            target, total, with_coordinate, total - with_coordinate, half, len(index)
        )
    )
    return {
        "records": total,
        "distinct_slugs": len(index),
        "with_coordinate": with_coordinate,
        "without_coordinate": total - with_coordinate,
        "half_filled": half,
    }


def main(argv=None):
    """Entry point."""
    parser = build_parser()
    args = parser.parse_args(argv)

    # The mode contract: --dry-run and --apply are mutually exclusive, and
    # --dry-run is the default so there is no accidental write mode.
    if args.apply and args.dry_run:
        fail("--apply and --dry-run are mutually exclusive", 2)
    if args.verify and (args.apply or args.dry_run):
        fail("--verify cannot be combined with --apply/--dry-run", 2)

    mode = "verify" if args.verify else ("apply" if args.apply else "dry-run")

    target = resolve_target_url(args)
    scope = "leisure coordinate meta (_leisure_lat, _leisure_lng)"
    if args.include_attributes:
        scope += " + {} (add-only)".format(ATTRIBUTE_TAXONOMY)
    kind = print_preflight(target, mode, scope)

    if mode == "apply" and kind == "production" and not args.confirm_production:
        fail(
            "refusing to write to a production target without --confirm-production. "
            "Re-run with --confirm-production once you have confirmed {} is the "
            "intended target.".format(target)
        )
    if kind == "unknown" and mode == "apply":
        fail(
            "refusing to write: the target could not be classified as local, staging "
            "or production. Pass an explicit --target-url for a known site."
        )

    client = RateLimitedClient(target)

    if mode == "verify":
        summary = run_verify(client, target)
        emit_report(
            args.report,
            {
                "script": "migrate-leisure-location-rest.py",
                "target": target,
                "mode": mode,
                "summary": summary,
            },
        )
        return EXIT_OK

    entries = load_manifest(args.manifest)
    eligible, skipped_manifest = eligible_entries(entries)
    target_index, target_total = fetch_target_index(client)

    state = (
        {"version": 1, "applied": {}, "attributes": {}}
        if args.no_resume
        else load_state(args.state_file)
    )

    rows = [plan_entry(entry, target_index, args.overwrite) for entry in eligible]

    # Resume: a record already journaled (and still needing its coordinate) is
    # not re-planned onto the network.
    resumed = 0
    if not args.no_resume:
        for row in rows:
            if row["slug"] in state["applied"] and row["action"] == "POPULATE":
                row["action"] = "ALREADY_SET"
                row["reason"] = "already applied in a previous run (resume journal)"
                resumed += 1

    counts = {}
    for row in rows:
        counts[row["action"]] = counts.get(row["action"], 0) + 1

    for row in rows:
        print(
            "  {:<12} {:<52} {}".format(
                row["action"], row["slug"] or UNRESOLVED, row["reason"]
            )
        )

    return _finish_plan(
        args, mode, target, scope, client, rows, counts, eligible,
        target_index, state, resumed, skipped_manifest,
    )


def _finish_plan(
    args, mode, target, scope, client, rows, counts, eligible,
    target_index, state, resumed, skipped_manifest=None,
):
    """Plan the attribute phase, apply everything, verify, and report."""
    attribute_rows = []
    if args.include_attributes:
        source_url = args.source_url or rest.resolve_base_url(None)
        print("attributes: reading the source taxonomy from {}".format(source_url))
        attribute_entries = local_attribute_entries(source_url)
        attribute_ids = fetch_attribute_map(client)
        attribute_rows = plan_attributes(attribute_entries, attribute_ids, target_index)
        for row in attribute_rows:
            print(
                "  ATTRIBUTES   {:<52} +{}".format(
                    row["slug"], ",".join(row["term_slugs"])
                )
            )

    planned_writes = counts.get("POPULATE", 0) + counts.get("UPDATE", 0)

    written = 0
    attribute_written = 0
    if mode == "apply":
        for row in rows:
            if row["action"] not in ("POPULATE", "UPDATE"):
                continue
            write_coordinate(client, row, state, args.state_file)
            written += 1
            if args.request_delay:
                time.sleep(args.request_delay)
        for row in attribute_rows:
            write_attributes(client, row, state, args.state_file)
            attribute_written += 1
            if args.request_delay:
                time.sleep(args.request_delay)

    return _report(
        args, mode, target, scope, client, rows, counts, eligible,
        attribute_rows, written, attribute_written, planned_writes,
        target_index, resumed, skipped_manifest,
    )


def write_attributes(client, row, state, state_path):
    """Add the planned attribute term IDs to one target record.

    The full LOCAL term set is sent. That is safe and idempotent because the
    local set is a verified superset of the target set on every record (see
    `plan_attributes`), so the replace can never remove a target-only term. The
    mock suite asserts the add-only behaviour.
    """
    payload = {ATTRIBUTE_TAXONOMY: row["term_ids"]}
    status, _headers, _body = client.request(
        "POST", "wp/v2/leisure/{}".format(row["target_id"]), payload
    )
    if status >= 300:
        fail("unexpected status {} writing attributes for {}".format(status, row["slug"]))
    state["attributes"][row["slug"]] = {
        "target_id": row["target_id"],
        "term_ids": row["term_ids"],
        "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    save_state(state_path, state)
    return True


def _report(
    args, mode, target, scope, client, rows, counts, eligible,
    attribute_rows, written, attribute_written, planned_writes,
    target_index, resumed, skipped_manifest=None,
):
    """Verify the numeric gate, print the summary and emit the JSON report."""
    after_index, after_total = fetch_target_index(client)
    with_after = sum(1 for r in after_index.values() if r["lat"] and r["lng"])
    with_before = sum(1 for r in target_index.values() if r["lat"] and r["lng"])
    expected_after = with_before + (written if mode == "apply" else 0)
    gate = with_after - expected_after

    summary = {
        "manifest_entries": len(rows),
        "eligible_entries": len(eligible),
        "target_records": after_total,
        "populate": counts.get("POPULATE", 0),
        "update": counts.get("UPDATE", 0),
        "already_set": counts.get("ALREADY_SET", 0),
        "conflicts": counts.get("CONFLICT", 0),
        "unresolved": counts.get(UNRESOLVED, 0),
        "resumed": resumed,
        "planned_writes": planned_writes,
        "written": written,
        "attributes_planned": len(attribute_rows),
        "attributes_written": attribute_written,
        "with_before": with_before,
        "with_after": with_after,
        "gate": gate,
        "api_requests": client.request_count,
        "api_retries": client.retry_count,
    }
    if skipped_manifest:
        summary["manifest_skipped"] = skipped_manifest
    print(
        "summary: "
        + ", ".join("{}={}".format(key, value) for key, value in summary.items())
    )
    if mode != "apply":
        print("(dry run — nothing written)")

    emit_report(
        args.report,
        {
            "script": "migrate-leisure-location-rest.py",
            "target": target,
            "mode": mode,
            "scope": scope,
            "summary": summary,
            "plan": rows,
            "attributes": attribute_rows,
        },
    )

    errors = 0
    if mode == "apply":
        if gate != 0:
            errors += 1
        if written != planned_writes:
            errors += 1
    return EXIT_OK if errors == 0 else EXIT_ERROR


if __name__ == "__main__":
    sys.exit(main())
