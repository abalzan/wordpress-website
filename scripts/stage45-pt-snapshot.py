#!/usr/bin/env python3
"""
Stage 4.5 — Portuguese regression checkpoint (Phase 23).

Captures a content+metadata fingerprint of every PT page that will gain an
EN translation, so the post-deployment state can be compared field by field.
The expected diff after the translation rollout is EMPTY (zero unintended PT
changes); the only tolerable differences are Polylang bookkeeping fields not
visible over the public REST surface (none of the fingerprinted fields).

Usage:
  python3 scripts/stage45-pt-snapshot.py                       # capture live
  python3 scripts/stage45-pt-snapshot.py --offline             # from cache
  python3 scripts/stage45-pt-snapshot.py --compare OLD NEW     # diff two snapshots

Read-only. Same pacing/caching rules as the inventory builder.
"""

import argparse
import hashlib
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

UA = "ConexaoBR-Stage45-PTSnapshot/1.0 (read-only)"
BASE = "https://conexaobr.ie"
CACHE = "stage45-work/inventory-cache"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def http_get(url, retries=4):
    opener = urllib.request.build_opener(NoRedirect)
    for attempt in range(retries):
        req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "text/html,application/json"})
        try:
            with opener.open(req, timeout=30) as resp:
                return resp.status, dict(resp.headers), resp.read()
        except urllib.error.HTTPError as exc:
            if exc.code == 429 and attempt < retries - 1:
                time.sleep(6 * (attempt + 1))
                continue
            return exc.code, dict(exc.headers), exc.read()
        except Exception as exc:  # noqa: BLE001
            if attempt < retries - 1:
                time.sleep(4 * (attempt + 1))
                continue
            return 0, {}, str(exc).encode()
    return 0, {}, b""


def sha(text):
    return hashlib.sha256(text.encode("utf-8")).hexdigest()[:16]


def first_meta_description(html_text):
    if not html_text:
        return None
    head = html_text[: html_text.find("</head")]
    m = re.search(r'<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)', head, re.I)
    return m.group(1) if m else None

def capture(base, offline):
    raw_path = os.path.join(CACHE, "pages-rest.json")
    if offline and os.path.exists(raw_path):
        pages = json.load(open(raw_path))
    else:
        status, _, raw = http_get(
            base + "/wp-json/wp/v2/pages?per_page=100&_fields="
                   "id,slug,status,parent,menu_order,template,link,title,content,excerpt,featured_media,modified")
        if status != 200:
            sys.exit(f"REST pages fetch failed: HTTP {status}")
        pages = json.loads(raw)
        os.makedirs(CACHE, exist_ok=True)
        with open(raw_path, "wb") as fh:
            fh.write(raw if isinstance(raw, bytes) else json.dumps(pages).encode())

    snap = {}
    for p in sorted(pages, key=lambda x: x["id"]):
        slug = p["slug"]
        head_path = os.path.join(CACHE, f"page-{slug}.html")
        meta_desc = None
        canonical = None
        if os.path.exists(head_path):
            html_text = open(head_path, encoding="utf-8", errors="replace").read()
        elif not offline:
            status, _, body = http_get(base + "/" + slug + "/")
            html_text = body.decode("utf-8", "replace") if status == 200 and body else ""
            if html_text:
                with open(head_path, "w", encoding="utf-8") as fh:
                    fh.write(html_text)
            time.sleep(1.0)
        else:
            html_text = ""
        if html_text:
            meta_desc = first_meta_description(html_text)
            m = re.search(r'rel=["\']canonical["\'][^>]*href=["\']([^"\']+)', html_text, re.I)
            canonical = m.group(1) if m else None

        snap[slug] = {
            "id": p["id"],
            "slug": slug,
            "title": p["title"]["rendered"],
            "content_sha": sha(p["content"]["rendered"]),
            "excerpt_sha": sha(p["excerpt"]["rendered"]),
            "status": p["status"],
            "parent": p["parent"],
            "menu_order": p["menu_order"],
            "template": p["template"],
            "permalink": p["link"],
            "featured_media": p["featured_media"],
            "modified": p.get("modified"),
            "meta_description": meta_desc,
            "canonical": canonical,
        }
    return snap


def compare(old_path, new_path):
    old = json.load(open(old_path))["pages"]
    new = json.load(open(new_path))["pages"]
    diffs = []
    for slug in sorted(set(old) | set(new)):
        if slug not in old:
            diffs.append((slug, "ADDED", None, None))
            continue
        if slug not in new:
            diffs.append((slug, "REMOVED", None, None))
            continue
        for field in old[slug]:
            if old[slug][field] != new[slug][field]:
                diffs.append((slug, field, old[slug][field], new[slug][field]))
    if not diffs:
        print("PT REGRESSION CHECK: identical (0 unintended PT changes)")
        return 0
    print(f"PT REGRESSION CHECK: {len(diffs)} difference(s):")
    for slug, field, before, after in diffs:
        print(f"  {slug}.{field}: {before!r} -> {after!r}")
    return 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--offline", action="store_true")
    ap.add_argument("--out", default=None)
    ap.add_argument("--compare", nargs=2, metavar=("OLD", "NEW"))
    args = ap.parse_args()

    if args.compare:
        sys.exit(compare(args.compare[0], args.compare[1]))

    snap = capture(args.base.rstrip("/"), args.offline)
    out = args.out or os.path.join("stage45-work", "pt-snapshot.json")
    doc = {
        "generated_by": "scripts/stage45-pt-snapshot.py (read-only)",
        "base": args.base,
        "note": "Pre-translation PT checkpoint (Phase 23). content_sha/excerpt_sha are sha256[:16] of the REST rendered fields.",
        "pages": snap,
    }
    os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
    with open(out, "w", encoding="utf-8") as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=1)
    print(f"wrote {out} ({len(snap)} pages)")


if __name__ == "__main__":
    main()
