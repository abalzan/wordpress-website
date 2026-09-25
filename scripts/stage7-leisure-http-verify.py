#!/usr/bin/env python3
"""Stage 7 — Leisure card-description HTTP acceptance matrix.

Fetches the Leisure archives (PT + EN, every page), the regression URLs and
the per-card HTML from a running WordPress (the validation clone, or the live
site after rollout) and validates:

  - /lazer/ (PT): card ids/excerpts/count/order/links/images unchanged, and
    identical to the captured production rendering (the authoritative PT set);
  - /en/lazer/ (EN): every `.leisure-card-excerpt` renders the authored
    English translation (18-word trim), never the Portuguese description;
  - both archives: same card count, order, links, images and card markup;
  - SEO/routing fields per URL (status, redirect target, html lang, canonical,
    hreflang, robots, og:locale) — compared before/after.

Usage:
  python3 scripts/stage7-leisure-http-verify.py --base http://127.0.0.1:8765 --label before
  python3 scripts/stage7-leisure-http-verify.py --base http://127.0.0.1:8765 --label after

Writes stage7-work/http-<label>.json (+ .md) and exits non-zero on failure.
'after' additionally diffs against the stored 'before' capture.

Read-only: issues GET requests only.
"""

import argparse
import html
import json
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WORK = ROOT / "stage7-work"


def fetch(base, path, max_hops=3):
    """GET a path, capturing status/redirect chain without cookie state."""
    url = base.rstrip("/") + path
    chain = []
    body = ""
    status = 0
    location = ""
    for _ in range(max_hops + 1):
        req = urllib.request.Request(url, headers={"User-Agent": "stage7-verify/1.0"})
        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                status = response.status
                body = response.read().decode("utf-8", "replace")
                location = response.headers.get("Location", "") or ""
        except urllib.error.HTTPError as error:
            status = error.code
            body = error.read().decode("utf-8", "replace")
            location = error.headers.get("Location", "") or ""
        chain.append({"url": url, "status": status, "location": location})
        if status in (301, 302, 303, 307, 308) and location and len(chain) <= max_hops:
            url = urllib.parse.urljoin(url, location)
            continue
        break
    return {
        "path": path,
        "status": status,
        "location": location,
        "final_url": chain[-1]["url"],
        "chain": chain,
        "body": body,
    }


ARTICLE_RE = re.compile(r'<article\s+id="post-(\d+)"([^>]*)>', re.I)
EXCERPT_RE = re.compile(r'<p class="leisure-card-excerpt">(.*?)</p>', re.S)
TITLE_RE = re.compile(r'<h3 class="leisure-card-title">(.*?)</h3>', re.S)
HREF_RE = re.compile(r'href="([^"]+)"')


def unescape(text):
    return html.unescape(html.unescape(text or "")).strip()


def extract_cards(document):
    """Split the archive HTML into per-card records (order preserved)."""
    cards = []
    matches = list(ARTICLE_RE.finditer(document))
    for match in matches:
        start = match.start()
        close = document.find("</article>", start)
        chunk = document[start:close] if close != -1 else document[start:]

        excerpt_match = EXCERPT_RE.search(chunk)
        title_match = TITLE_RE.search(chunk)
        title_html = title_match.group(1) if title_match else ""
        title_link = HREF_RE.search(title_html)

        cards.append({
            "id": int(match.group(1)),
            "classes": unescape(match.group(2)),
            "excerpt": unescape(excerpt_match.group(1)) if excerpt_match else "",
            "title": unescape(re.sub(r"<[^>]+>", "", title_html)),
            "title_href": title_link.group(1) if title_link else "",
            "hrefs": HREF_RE.findall(chunk),
            "pending_image": "leisure-card-image-pending" in chunk,
            "cta_count": chunk.count('class="leisure-card-cta'),
        })
    return cards


def extract_head(document):
    head = {}
    lang = re.search(r'<html[^>]*\blang="([^"]+)"', document)
    head["lang"] = lang.group(1) if lang else ""
    title = re.search(r"<title>(.*?)</title>", document, re.S)
    head["title"] = unescape(re.sub(r"\s+", " ", title.group(1))) if title else ""
    canonical = re.search(r'<link rel="canonical" href="([^"]+)"', document)
    head["canonical"] = canonical.group(1) if canonical else ""
    head["hreflang"] = sorted(
        f'{m.group(1)}={m.group(2)}'
        for m in re.finditer(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', document)
    )
    robots = re.search(r'<meta name="robots" content="([^"]+)"', document)
    head["robots"] = robots.group(1) if robots else ""
    og_locale = re.search(r'<meta property="og:locale" content="([^"]+)"', document)
    head["og_locale"] = og_locale.group(1) if og_locale else ""
    return head


def trim_words(text, num_words=18, more="..."):
    """Replicate wp_trim_words() for plain text."""
    text = re.sub(r"<[^>]+>", "", text or "").strip()
    words = [w for w in re.split(r"[\n\r\t ]+", text) if w]
    if len(words) > num_words:
        return " ".join(words[:num_words]) + more
    return " ".join(words)


def load_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--label", required=True, choices=["before", "after"])
    args = parser.parse_args()

    inventory = load_json(WORK / "leisure-description-inventory.json")
    manifest = load_json(WORK / "leisure-description-translations.json")
    clone = load_json(WORK / f"clone-inventory-{args.label}.json")

    production_pt = {r["slug"]: r["card_excerpt_pt"] for r in inventory["records"]}
    en_by_slug = {e["slug"]: e["en_excerpt"] for e in manifest["entries"]}
    clone_by_id = {r["id"]: r for r in clone["records"]}
    page_count = len(inventory["archive"]["pages"])
    per_page = inventory["archive"]["per_page"]

    checks = {"passed": 0, "failed": 0, "rows": []}

    def check(ok, message, detail=""):
        checks["passed" if ok else "failed"] += 1
        checks["rows"].append({"ok": bool(ok), "check": message, "detail": detail})
        return ok

    def archive_paths(prefix, page):
        return f"{prefix}/" if page == 1 else f"{prefix}/page/{page}/"

    pt_pages = []
    en_pages = []
    for page in range(1, page_count + 1):
        pt = fetch(args.base, archive_paths("/lazer", page))
        en = fetch(args.base, archive_paths("/en/lazer", page))
        pt["cards"] = extract_cards(pt["body"]) if pt["status"] == 200 else []
        en["cards"] = extract_cards(en["body"]) if en["status"] == 200 else []
        pt["head"] = extract_head(pt["body"]) if pt["status"] == 200 else {}
        en["head"] = extract_head(en["body"]) if en["status"] == 200 else {}
        pt_pages.append(pt)
        en_pages.append(en)

        expected_count = next(
            (p["cards"] for p in inventory["archive"]["pages"] if p["page"] == page), 0
        )
        check(pt["status"] == 200, f"PT /lazer/ page {page} is 200", str(pt["status"]))
        check(
            len(pt["cards"]) == expected_count,
            f"PT page {page} card count == {expected_count} (production capture)",
            str(len(pt["cards"])),
        )

    # PT acceptance: every card excerpt equals the production-rendered excerpt.
    for page_items in pt_pages:
        for card in page_items["cards"]:
            record = clone_by_id.get(card["id"])
            if not record:
                check(False, f"PT card #{card['id']} maps to a clone leisure record")
                continue
            slug = record["slug"]
            check(
                card["excerpt"] == production_pt.get(slug),
                f"PT card {slug} excerpt == production rendering",
                card["excerpt"][:80],
            )
            check(
                card["excerpt"] == trim_words(record["pt_excerpt"]),
                f"PT card {slug} excerpt == trimmed stored excerpt",
            )


    # EN acceptance (both labels; 'before' documents the B2 fallback defect).
    for index, page_items in enumerate(en_pages):
        page = index + 1
        check(page_items["status"] == 200, f"EN /en/lazer/ page {page} is 200", str(page_items["status"]))
        if page_items["status"] != 200:
            continue
        pt_page = pt_pages[index]
        check(
            [c["id"] for c in page_items["cards"]] == [c["id"] for c in pt_page["cards"]],
            f"EN page {page} card order == PT card order",
        )
        check(
            len(page_items["cards"]) == len(pt_page["cards"]),
            f"EN page {page} card count == PT card count",
            f"{len(page_items['cards'])} vs {len(pt_page['cards'])}",
        )
        for en_card, pt_card in zip(page_items["cards"], pt_page["cards"]):
            record = clone_by_id.get(en_card["id"])
            if not record:
                check(False, f"EN card #{en_card['id']} maps to a clone leisure record")
                continue
            slug = record["slug"]

            check(en_card["hrefs"] == pt_card["hrefs"], f"EN card {slug} links == PT card links")
            check(en_card["classes"] == pt_card["classes"], f"EN card {slug} card classes == PT")
            check(en_card["pending_image"] == pt_card["pending_image"], f"EN card {slug} image state == PT")
            check(en_card["cta_count"] == pt_card["cta_count"], f"EN card {slug} CTA count == PT")

            if args.label == "before":
                check(
                    en_card["excerpt"] == pt_card["excerpt"],
                    f"BEFORE: EN card {slug} excerpt == PT excerpt (B2 fallback documented)",
                )
            else:
                expected_en = trim_words(en_by_slug.get(slug, ""))
                check(
                    bool(expected_en) and en_card["excerpt"] == expected_en,
                    f"AFTER: EN card {slug} excerpt == authored EN translation (18-word trim)",
                    f"got: {en_card['excerpt'][:80]}",
                )
                check(
                    en_card["excerpt"] != pt_card["excerpt"],
                    f"AFTER: EN card {slug} excerpt != PT excerpt (no PT leakage)",
                )

    # Regression URL matrix (status/redirect/head recorded; compared on 'after').
    first_slug = clone["records"][0]["slug"] if clone["records"] else ""
    regression_paths = [
        "/",
        "/en/",
        "/blog/",
        "/en/blog/",
        "/guias/",
        "/en/guias/",
        "/empregos/",
        "/en/jobs/",
        "/en/empregos/",
        "/sitemap.xml",
        "/lazer/?county=dublin",
        "/en/lazer/?county=dublin",
        f"/lazer/{first_slug}/",
        f"/en/lazer/{first_slug}/",
    ]
    regression = {}
    for path in regression_paths:
        response = fetch(args.base, path)
        regression[path] = {
            "status": response["status"],
            "location": response["location"],
            "final_url": response["final_url"],
            "head": extract_head(response["body"]) if response["status"] == 200 else {},
        }
        check(
            response["status"] in (200, 301, 302, 404),
            f"regression {path} responds (status {response['status']})",
        )

    result = {
        "label": args.label,
        "base": args.base,
        "checks": checks,
        "pt_pages": [
            {
                "page": i + 1,
                "status": p["status"],
                "head": p["head"],
                "cards": p["cards"],
            }
            for i, p in enumerate(pt_pages)
        ],
        "en_pages": [
            {
                "page": i + 1,
                "status": p["status"],
                "head": p["head"],
                "cards": p["cards"],
            }
            for i, p in enumerate(en_pages)
        ],
        "regression": regression,
    }


    # 'after' — diff the structural state against the stored 'before' capture.
    before_file = WORK / "http-before.json"
    if args.label == "after" and before_file.exists():
        before = load_json(before_file)
        for index in range(min(len(before["pt_pages"]), len(result["pt_pages"]))):
            for key in ("status",):
                check(
                    before["pt_pages"][index][key] == result["pt_pages"][index][key],
                    f"after-vs-before: PT page {index + 1} {key} unchanged",
                )
            before_cards = before["pt_pages"][index]["cards"]
            after_cards = result["pt_pages"][index]["cards"]
            check(
                before_cards == after_cards,
                f"after-vs-before: PT page {index + 1} cards byte-identical (ids/excerpts/links/images)",
            )
            check(
                before["pt_pages"][index]["head"] == result["pt_pages"][index]["head"],
                f"after-vs-before: PT page {index + 1} SEO head unchanged",
            )
            check(
                before["en_pages"][index]["head"] == result["en_pages"][index]["head"],
                f"after-vs-before: EN page {index + 1} SEO head unchanged",
            )
        for path, data in before["regression"].items():
            if path not in result["regression"]:
                continue
            check(
                data["status"] == result["regression"][path]["status"]
                and data["location"] == result["regression"][path]["location"],
                f"after-vs-before: regression {path} unchanged",
                f"{data['status']} -> {result['regression'][path]['status']}",
            )

    # Write the artefacts.
    out_json = WORK / f"http-{args.label}.json"
    out_json.write_text(
        json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )

    total = checks["passed"] + checks["failed"]
    md = [
        f"# Stage 7 — HTTP acceptance matrix ({args.label})",
        "",
        f"- Base URL: `{args.base}`",
        f"- Checks passed: **{checks['passed']}** / failed: **{checks['failed']}** (total {total})",
        "",
        "| Check | Result |",
        "|---|---|",
    ]
    md += [f"| {row['check']} | {'✅' if row['ok'] else '❌'} |" for row in checks["rows"]]
    (WORK / f"http-{args.label}.md").write_text("\n".join(md) + "\n", encoding="utf-8")

    print(f"[{args.label}] passed {checks['passed']} / failed {checks['failed']} (total {total})")
    if checks["failed"]:
        print("Failed checks:")
        for row in checks["rows"]:
            if not row["ok"]:
                print(f"  - {row['check']} {row['detail']}")
    print(f"written: {out_json}")

    return 1 if checks["failed"] else 0


if __name__ == "__main__":
    sys.exit(main())

