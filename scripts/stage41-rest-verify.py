#!/usr/bin/env python3
"""
stage41-rest-verify.py — Stage 4.1 bilingual REST contract verification.

LOCAL / STAGING ONLY. Read-only: performs GET requests against the WordPress
REST API and builds the Phase 13 machine-readable HTTP verification matrix
(JSON rows + console summary) for the Stage 4.1 report.

Covers, for PT (lang=pt) and EN (lang=en) plus the legacy no-lang surface:
  - the seven content collections (events, lazer, guides, blog, employment,
    courses, sponsors) and their detail endpoints;
  - real EN detail, B2 fallback detail, source-inherited EN event detail;
  - the translated-taxonomy filters (EN documents / PT documentos) and the
    shared county/town filters (dublin);
  - search (cross-type + typed collections);
  - invalid `lang` handling;
  - backward compatibility (requests without `lang`).

For every row the matrix records: request URL, `lang`, HTTP status, record
language(s), fallback state, canonical URL (the `link` field), translation
metadata presence, duplicate-identity check and hidden-event check.

Usage:
  python3 scripts/stage41-rest-verify.py [base-url] [host-header]

  base-url    defaults to http://localhost:8080 (Docker). In the Stage 4.1
              sandbox the server runs on http://127.0.0.1:8090.
  host-header optional Host header override (the sandbox serves 8080 URLs on
              port 8090: pass "localhost:8080").

Exit code 0 = every check passed.
"""

import json
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080").rstrip("/")
HOST = sys.argv[2] if len(sys.argv) > 2 else None

PASS = 0
FAIL = 0
ROWS = []


def api(path):
    """GET a REST path; returns (status, parsed_json_or_text)."""
    req = urllib.request.Request(
        BASE + path,
        headers={
            "User-Agent": "stage41-rest-verify",
            "Accept": "application/json",
            **({"Host": HOST} if HOST else {}),
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            body = resp.read().decode("utf-8", "replace")
            return resp.status, json.loads(body) if body else None
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        try:
            return exc.code, json.loads(body)
        except ValueError:
            return exc.code, body


def check(condition, label):
    global PASS, FAIL
    if condition:
        PASS += 1
        print(f"  PASS: {label}")
    else:
        FAIL += 1
        print(f"  FAIL: {label}")


def collect(path, lang=None, expect=200, label=""):
    """Fetch a collection; record the matrix row; return (status, rows)."""
    sep = "&" if "?" in path else "?"
    url = path + (f"{sep}lang={lang}&per_page=100" if lang else "?per_page=100")
    status, data = api(url)
    rows = data if isinstance(data, list) else []
    langs = sorted({(r.get("conexao_language") or {}).get("lang") for r in rows if isinstance(r, dict)})
    fallbacks = sum(
        1 for r in rows if isinstance(r, dict) and (r.get("conexao_language") or {}).get("is_fallback")
    )
    uuids = [
        (r.get("meta") or {}).get("_event_export_uuid")
        for r in rows
        if isinstance(r, dict) and (r.get("meta") or {}).get("_event_export_uuid")
    ]
    # Duplicate identity is forbidden WITHIN one language collection. The
    # legacy no-lang surface intentionally lists both records of a translated
    # identity (master + linked translation, distinguishable by
    # conexao_language.lang) — that is the pre-Stage-4.1 behaviour.
    if lang:
        dup = len(uuids) != len(set(uuids))
    else:
        seen = {}
        dup = False
        for r in rows:
            if not isinstance(r, dict):
                continue
            m = r.get("conexao_language") or {}
            key = (m.get("lang"), (r.get("meta") or {}).get("_event_export_uuid"))
            if key[1]:
                if key in seen:
                    dup = True
                seen[key] = True
    hidden = any(
        (r.get("meta") or {}).get("_event_status") in ("expired", "rejected", "source_not_found")
        for r in rows
        if isinstance(r, dict)
    )
    ROWS.append(
        {
            "url": url,
            "lang": lang or "(none)",
            "status": status,
            "records": len(rows),
            "record_langs": langs,
            "fallback_records": fallbacks,
            "duplicate_identity": dup,
            "hidden_event_visible": hidden,
            "translation_metadata": all(isinstance(r, dict) and "conexao_language" in r for r in rows),
        }
    )
    check(status == expect, f"{label or 'collection'} {url} -> {status} (expected {expect})")
    check(not dup, f"{label or url}: no duplicate identity")
    check(not hidden, f"{label or url}: no hidden event visible")
    check(
        all(isinstance(r, dict) and "conexao_language" in r for r in rows),
        f"{label or url}: every record carries conexao_language",
    )
    return status, rows


def detail(path, lang=None, expect=200, label=""):
    """Fetch a detail record; record the matrix row; return (status, data)."""
    sep = "&" if "?" in path else "?"
    url = path + (f"{sep}lang={lang}" if lang else "")
    status, data = api(url)
    meta = (data or {}).get("conexao_language") if isinstance(data, dict) else None
    ROWS.append(
        {
            "url": url,
            "lang": lang or "(none)",
            "status": status,
            "record_lang": (meta or {}).get("lang"),
            "fallback": (meta or {}).get("is_fallback"),
            "canonical_url": (data or {}).get("link") if isinstance(data, dict) else None,
            "translations": (meta or {}).get("translations"),
            "error_code": (data or {}).get("code") if isinstance(data, dict) else None,
        }
    )
    check(status == expect, f"{label or 'detail'} {url} -> {status} (expected {expect})")
    return status, data


def find_id(route, slug):
    status, data = api(f"{route}?slug={slug}&per_page=5")
    if status == 200 and isinstance(data, list) and data:
        return data[0]["id"]
    return None


def main():
    print("== Stage 4.1 REST HTTP verification matrix ==")
    print(f"base: {BASE}" + (f" (Host: {HOST})" if HOST else ""))

    # Fixture discovery (portable: resolve by slug). The hidden event is the
    # exception: the `_event_status` gate legitimately keeps it out of every
    # public collection, so its ID cannot be discovered through the API — the
    # pilot dataset id (77) is used, overridable as the 3rd CLI argument. The
    # authoritative dynamic hidden-event assertions live in the PHP suite
    # (tests/test-stage41-rest-language.php, fixtures resolved from the DB).
    fixtures = {
        "event_pt": find_id("/wp-json/wp/v2/event", "festa-junina-dublin-2026"),
        "event_en": find_id("/wp-json/wp/v2/event", "festa-junina-dublin-2026-en"),
        "event_src_en": find_id("/wp-json/wp/v2/event", "irish-dance-workshop-dublin"),
        "event_b2": find_id("/wp-json/wp/v2/event", "feijoada-beneficente-cork"),
        "event_hidden": int(sys.argv[3]) if len(sys.argv) > 3 else 77,
        "leisure_pt": find_id("/wp-json/wp/v2/leisure", "phoenix-park"),
        "leisure_en": find_id("/wp-json/wp/v2/leisure", "phoenix-park-en"),
        "leisure_b2": find_id("/wp-json/wp/v2/leisure", "cliffs-of-moher"),
        "guide_pt": find_id("/wp-json/wp/v2/guide", "como-tirar-o-pps-number"),
        "guide_en": find_id("/wp-json/wp/v2/guide", "how-to-get-a-pps-number"),
        "post_pt": find_id("/wp-json/wp/v2/posts", "comunidade-celebra-festa-junina-em-dublin"),
        "post_en": find_id("/wp-json/wp/v2/posts", "community-celebrates-festa-junina-in-dublin"),
        "job_en": find_id("/wp-json/wp/v2/job", "kitchen-assistant-dublin"),
        "course_en": find_id("/wp-json/wp/v2/course_provider", "fetch-courses-en"),
        "sponsor_en": find_id("/wp-json/wp/v2/sponsor", "brasil-market-dublin-en"),
    }
    missing = [k for k, v in fixtures.items() if not v and k != "event_hidden"]
    check(not missing, f"all pilot fixtures resolve by slug (missing: {missing or 'none'})")

    routes = [
        ("/wp-json/wp/v2/event", "events"),
        ("/wp-json/wp/v2/leisure", "lazer"),
        ("/wp-json/wp/v2/guide", "guides"),
        ("/wp-json/wp/v2/posts", "blog"),
        ("/wp-json/wp/v2/job", "employment"),
        ("/wp-json/wp/v2/course_provider", "courses"),
        ("/wp-json/wp/v2/sponsor", "sponsors"),
    ]

    print("\n-- PT collections + details --")
    for route, name in routes:
        collect(route, "pt", label=f"PT {name}")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_pt']}", "pt", label="PT event detail")
    detail(f"/wp-json/wp/v2/leisure/{fixtures['leisure_pt']}", "pt", label="PT lazer detail")
    detail(f"/wp-json/wp/v2/guide/{fixtures['guide_pt']}", "pt", label="PT guide detail")
    detail(f"/wp-json/wp/v2/posts/{fixtures['post_pt']}", "pt", label="PT blog detail")

    print("\n-- EN collections + details --")
    for route, name in routes:
        collect(route, "en", label=f"EN {name}")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_en']}", "en", label="EN real translation detail")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_b2']}", "en", label="EN B2 fallback detail")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_src_en']}", "en", label="source-inherited EN event detail")
    detail(f"/wp-json/wp/v2/leisure/{fixtures['leisure_en']}", "en", label="EN lazer translation detail")
    detail(f"/wp-json/wp/v2/leisure/{fixtures['leisure_b2']}", "en", label="EN lazer B2 detail")
    detail(f"/wp-json/wp/v2/guide/{fixtures['guide_en']}", "en", label="EN guide translation detail")
    detail(f"/wp-json/wp/v2/posts/{fixtures['post_en']}", "en", label="EN blog translation detail")
    detail(f"/wp-json/wp/v2/job/{fixtures['job_en']}", "en", label="EN job translation detail")
    detail(f"/wp-json/wp/v2/course_provider/{fixtures['course_en']}", "en", label="EN course translation detail")
    detail(f"/wp-json/wp/v2/sponsor/{fixtures['sponsor_en']}", "en", label="EN sponsor translation detail")

    print("\n-- language-mismatch details (404 + recovery pointer) --")
    s, d = detail(f"/wp-json/wp/v2/event/{fixtures['event_pt']}", "en", expect=404, label="PT master under lang=en")
    check(
        isinstance(d, dict) and d.get("code") == "conexao_rest_language_unavailable"
        and (d.get("data") or {}).get("translations", {}).get("en", {}).get("id") == fixtures["event_en"],
        "PT master under lang=en carries the EN translation pointer",
    )
    s, d = detail(f"/wp-json/wp/v2/event/{fixtures['event_en']}", "pt", expect=404, label="EN record under lang=pt")
    check(
        isinstance(d, dict) and d.get("code") == "conexao_rest_language_unavailable"
        and (d.get("data") or {}).get("translations", {}).get("pt", {}).get("id") == fixtures["event_pt"],
        "EN record under lang=pt carries the PT master pointer",
    )
    detail(f"/wp-json/wp/v2/guide/{fixtures['guide_pt']}", "en", expect=404, label="B1 guide under lang=en")

    print("\n-- hidden event + nonexistent --")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_hidden']}", "en", expect=404, label="hidden (expired) event detail")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_hidden']}", expect=404, label="hidden event detail (no lang)")
    detail("/wp-json/wp/v2/event/999999", "en", expect=404, label="nonexistent event detail")

    print("\n-- invalid lang --")
    for route in [r for r, _ in routes] + ["/wp-json/wp/v2/search", "/wp-json/wp/v2/conexao_county"]:
        sep = "&" if "?" in route else "?"
        status, data = api(f"{route}{sep}lang=xx&per_page=5")
        ROWS.append({"url": f"{route}{sep}lang=xx", "lang": "xx", "status": status,
                     "error_code": (data or {}).get("code") if isinstance(data, dict) else None})
        check(
            status == 400 and isinstance(data, dict) and data.get("code") == "conexao_rest_invalid_lang",
            f"invalid lang on {route} -> 400 conexao_rest_invalid_lang",
        )

    print("\n-- taxonomy filters --")
    status, data = api("/wp-json/wp/v2/conexao_category?per_page=100&lang=en")
    en_terms = {t["slug"]: t["id"] for t in (data or []) if isinstance(t, dict)}
    status, data = api("/wp-json/wp/v2/conexao_category?per_page=100&lang=pt")
    pt_terms = {t["slug"]: t["id"] for t in (data or []) if isinstance(t, dict)}
    check("documents" in en_terms and "finances" in en_terms, "EN category terms resolve (documents, finances)")
    check("documentos" in pt_terms and "financas" in pt_terms, "PT category terms resolve (documentos, financas)")
    check("documents" not in pt_terms and "documentos" not in en_terms, "translated term sets do not mix languages")

    status, rows = collect(
        f"/wp-json/wp/v2/guide?conexao_category={en_terms.get('documents', 0)}", "en",
        label="EN guides filtered by EN term documents",
    )
    check(
        any(r.get("id") == fixtures["guide_en"] for r in rows) and not any(
            r.get("id") == fixtures["guide_pt"] for r in rows
        ),
        "EN guide filter returns the EN guide, not the PT master",
    )
    collect(
        f"/wp-json/wp/v2/guide?conexao_category={pt_terms.get('documentos', 0)}", "pt",
        label="PT guides filtered by PT term documentos",
    )

    status, data = api("/wp-json/wp/v2/conexao_county?per_page=100&lang=en")
    counties = {t["slug"]: t["id"] for t in (data or []) if isinstance(t, dict)}
    status, data = api("/wp-json/wp/v2/conexao_county?per_page=100&lang=pt")
    counties_pt = {t["slug"]: t["id"] for t in (data or []) if isinstance(t, dict)}
    check(counties == counties_pt and "dublin" in counties, "shared county terms identical in PT and EN (dublin)")

    status, rows = collect(
        f"/wp-json/wp/v2/event?conexao_county={counties.get('dublin', 0)}", "en",
        label="EN events filtered by shared county dublin",
    )
    check(
        any(r.get("id") == fixtures["event_en"] for r in rows) and not any(
            r.get("id") == fixtures["event_pt"] for r in rows
        ),
        "shared-county EN filter contains the EN record and replaces the PT master",
    )
    collect(
        f"/wp-json/wp/v2/event?conexao_county={counties.get('dublin', 0)}", "pt",
        label="PT events filtered by shared county dublin",
    )

    print("\n-- search --")
    for lang, expect_en, expect_pt in (("(none)", True, True), ("pt", False, True), ("en", True, False)):
        url = "/wp-json/wp/v2/search?search=festa&per_page=100" + (f"&lang={lang}" if lang != "(none)" else "")
        status, data = api(url)
        rows = data if isinstance(data, list) else []
        ids = [r.get("id") for r in rows if isinstance(r, dict)]
        has_en = fixtures["post_en"] in ids or fixtures["event_en"] in ids
        has_pt = fixtures["post_pt"] in ids or fixtures["event_pt"] in ids
        ROWS.append({"url": url, "lang": lang, "status": status, "records": len(rows), "record_ids": ids})
        check(status == 200, f"search ({lang}) -> 200")
        check(has_en is expect_en and has_pt is expect_pt, f"search ({lang}) membership: EN={has_en} PT={has_pt}")
        check(all(isinstance(r, dict) and "conexao_language" in r for r in rows),
              f"search ({lang}): every result carries conexao_language")

    print("\n-- backward compatibility (no lang) --")
    for route, name in routes:
        status, rows = collect(route, None, label=f"legacy {name}")
        all_ids = {r.get("id") for r in rows}
        _, pt_rows = api(f"{route}?lang=pt&per_page=100")
        _, en_rows = api(f"{route}?lang=en&per_page=100")
        combined = {r.get("id") for r in (pt_rows or [])} | {r.get("id") for r in (en_rows or [])}
        check(all_ids == combined, f"legacy {name} = PT ∪ EN memberships (nothing added or removed)")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_en']}", None, label="legacy detail of an EN record")
    detail(f"/wp-json/wp/v2/event/{fixtures['event_pt']}", None, label="legacy detail of a PT record")

    print(f"\nStage 4.1 REST verification: {PASS} passed, {FAIL} failed")
    with open("stage41-rest-matrix.json", "w", encoding="utf-8") as fh:
        json.dump({"base": BASE, "rows": ROWS}, fh, indent=2, ensure_ascii=False)
    print("matrix written: stage41-rest-matrix.json")
    return 0 if FAIL == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
