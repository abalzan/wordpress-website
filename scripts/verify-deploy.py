#!/usr/bin/env python3
"""verify-deploy.py — HTTP deployment verification for a release (Stage J).

Purpose: answer one question after a release — *does the deployed site behave the
way this release expects?* — without changing anything about it.

This is the `scripts/verify-deploy.py` required by engineering standard §11:
"Every release runs scripts/verify-deploy.py (HTTP smoke matrix: homepage,
every archive, one single per CPT, /en/ pairs, canonical, hreflang, sitemap,
404)".

The smoke matrix is FIXED (`scripts/data/release-smoke-matrix.json`). It never
grows between releases, only its recorded result changes, so two release
verifications are directly comparable.

Three layers of checking:

  1. **Matrix rows** — status, body fragments and unfollowed redirects, using
     the repository's single shared HTTP matrix schema
     (`tests/acceptance/lib/matrix.py`), so a release gate and a regression test
     can never disagree about what a row means.
  2. **Per-CPT singles** — discovered live from the public REST API, then
     fetched. One single per post type, because a CPT whose archive renders but
     whose singles are broken is a release failure the archive rows cannot see.
     Slugs are discovered rather than hard-coded so the gate cannot rot when
     content is renamed.
  3. **Language-layer integrity** — Portuguese stays canonical, the /en/ pairs
     are reciprocal, and the English layer never serves Portuguese under an
     English URL.

Scope: **read-only, GET requests only.** It authenticates nothing, sends no
credentials, writes nothing and has no mutating code path. A verifier that could
change the site would not be evidence about the site.

Safety: read-only. The target is never defaulted to production: `--site` is
required, so a mistyped invocation cannot silently probe the live site.

Usage:
  verify-deploy.py --site https://<host>            verify a deployment
  verify-deploy.py --site http://localhost:8080     verify the local build
  verify-deploy.py --site <url> --json              machine-readable result
  verify-deploy.py --site <url> --out evidence.json write the result to a file
  verify-deploy.py --list                            print the matrix and exit
  verify-deploy.py --help

Options:
  --site URL        Base URL of the deployment to verify. REQUIRED (no default).
  --matrix FILE     Smoke matrix (default: scripts/data/release-smoke-matrix.json).
  --only ID         Run only matrix rows whose id contains ID.
  --skip-singles    Skip the per-CPT single discovery checks.
  --timeout SECS    Per-request timeout (default 30).
  --out FILE        Write the JSON result to FILE.
  --json            Print the JSON result on stdout.
  --list            Print the matrix rows and exit without any request.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(HERE, "..")
)

# The shared acceptance HTTP client and matrix schema are reused verbatim. Only
# the LOCAL-ONLY guard is not used: this tool exists precisely to verify a
# deployed site, so the production refusal that protects the regression harness
# must not be inherited here. Read-only GETs make that safe.
sys.path.insert(0, os.path.join(REPO_ROOT, "tests", "acceptance"))

from lib import http_client  # noqa: E402
from lib.matrix import MatrixError, load  # noqa: E402

DEFAULT_MATRIX = os.path.join(HERE, "data", "release-smoke-matrix.json")

#: Post type -> (public REST base, off-site redirect allowed).
#:
#: ``leisure`` is a tourism DIRECTORY: a listing that has an Official Website or
#: Discover Ireland URL is intentionally redirected off-site by
#: ``conexao_leisure_external_url()`` (theme inc/seo/redirects.php). That is the
#: designed behaviour, not a broken single, so for leisure a 3xx to an EXTERNAL
#: host is a pass. An unexpected 3xx on any other post type is still a failure.
#:
#: The order matches the CPT order in plugins.json / docs/content-model.md.
CPT_SINGLES = (
    ("guide", "guide", False),
    ("event", "event", False),
    ("course_provider", "course_provider", False),
    ("job", "job", False),
    ("sponsor", "sponsor", False),
    ("leisure", "leisure", True),
    ("post", "posts", False),
)


class Results:
    """Pass/fail accumulator that prints a readable, ordered report."""

    def __init__(self, name):
        self.name = name
        self.passed = 0
        self.failed = 0
        self.checks = []

    def check(self, condition, label, detail=""):
        ok = bool(condition)
        if ok:
            self.passed += 1
        else:
            self.failed += 1
        self.checks.append({"label": label, "ok": ok, "detail": str(detail)})
        print("  %s %s%s" % ("PASS" if ok else "FAIL", label,
                             ("  -- " + str(detail)) if (detail and not ok) else ""))
        return ok

    def finish(self):
        print("")
        print("%s: %d passed, %d failed" % (self.name, self.passed, self.failed))
        return 1 if self.failed else 0


def normalise_site(site):
    """Validate and normalise the deployment base URL."""
    parsed = urllib.parse.urlparse(site)
    if parsed.scheme not in ("http", "https"):
        raise ValueError("--site must be an http(s) URL (got %r)" % site)
    if not parsed.netloc:
        raise ValueError("--site must include a host (got %r)" % site)
    return site.rstrip("/")


def _path_of(url):
    """Path of an absolute URL, for matching against matrix paths."""
    parsed = urllib.parse.urlparse(url)
    return parsed.path or "/"


def run_matrix(results, matrix, base, only=None):
    """Execute the fixed smoke matrix. Returns the number of failing rows."""
    failing = 0
    for row in matrix["rows"]:
        if only and only not in row["id"]:
            continue
        status, _headers, body = http_client.fetch_relative(base, row["url"])
        problems = []

        if status != row["expect_status"]:
            problems.append("status %s, expected %s" % (status, row["expect_status"]))
        for needle in row["expect_contains"]:
            if needle not in body:
                problems.append("missing fragment: %r" % needle)
        for needle in row["expect_absent"]:
            if needle in body:
                problems.append("unexpected fragment: %r" % needle)

        if problems:
            failing += 1
            results.check(False, "%s %s" % (row["id"], row["url"]), "; ".join(problems))
        else:
            results.check(True, "%s %s (status %s)" % (row["id"], row["url"], status))
    return failing


def check_singles(results, base):
    """Discover one public single per post type and verify it serves.

    Discovery uses the PUBLIC REST collection (no credentials, one record). A
    hard-coded slug would turn this gate red the first time content is renamed,
    which is how verification gates decay into ignored gates.
    """
    print("")
    print("-- one single per post type (discovered over the public REST API) --")

    for post_type, rest_base, offsite_ok in CPT_SINGLES:
        url = "%s/wp-json/wp/v2/%s?per_page=1&_fields=link" % (base, rest_base)
        status, _headers, body = http_client.fetch(url)

        if status != 200:
            results.check(False, "%s: REST discovery" % post_type,
                          "GET %s -> %s" % (url, status))
            continue

        try:
            records = json.loads(body)
        except json.JSONDecodeError as exc:
            results.check(False, "%s: REST discovery" % post_type,
                          "response was not JSON (%s)" % exc)
            continue

        # A post type with no public content cannot be smoke-tested; that is a
        # data condition, not a release defect, so it is reported, not failed.
        if not isinstance(records, list) or not records:
            print("  SKIP  %s: no public records to verify (insufficient data)"
                  % post_type)
            continue

        link = (records[0] or {}).get("link")
        if not link:
            results.check(False, "%s: REST discovery" % post_type,
                          "record carried no link")
            continue

        if not link.startswith("http"):
            link = base + link

        path = _path_of(link)
        status, headers, body = http_client.fetch(link)
        location = headers.get("Location", "") if headers else ""

        if offsite_ok and status in (301, 302, 307, 308):
            # Designed behaviour for the tourism directory: the listing hands the
            # visitor to the official site. The redirect must actually leave the
            # site, otherwise it is a broken internal redirect.
            host = urllib.parse.urlparse(location).hostname or ""
            base_host = urllib.parse.urlparse(base).hostname or ""
            results.check(
                bool(host) and host != base_host,
                "%s: single redirects off-site as designed" % post_type,
                "status=%s Location=%r" % (status, location),
            )
            continue

        if not results.check(status == 200, "%s: single %s" % (post_type, path),
                              "status=%s Location=%r" % (status, location)):
            continue

        results.check('rel="canonical"' in body,
                      "%s: single is canonical" % post_type, path)
        if post_type != "post":
            # Native posts are dated URLs and carry no PT/EN language layer.
            results.check("hreflang=" in body,
                          "%s: single declares hreflang alternates" % post_type,
                          path)


def check_language_layer(results, base):
    """Portuguese is canonical; the English layer is reciprocal and never forks."""
    print("")
    print("-- language layer (Portuguese canonical, /en/ reciprocal) --")

    status, _headers, body = http_client.fetch_relative(base, "/")
    if results.check(status == 200, "front page serves 200", "status=%s" % status):
        results.check('<html lang="pt-BR">' in body,
                      "front page is the Portuguese document")
        results.check("hreflang=" in body, "front page declares hreflang alternates")

    status, _headers, body = http_client.fetch_relative(base, "/en/")
    if results.check(status == 200, "/en/ serves 200", "status=%s" % status):
        results.check('<html lang="en-US">' in body,
                      "/en/ is the English document")

    # A legacy English slug must still redirect to its canonical Portuguese URL:
    # a release must never leave an English URL serving English content.
    status, headers, _ = http_client.fetch_relative(base, "/guides/")
    location = headers.get("Location", "") if headers else ""
    results.check(
        status in (301, 302, 307, 308) and location.endswith("/guias/"),
        "legacy /guides/ redirects to the canonical /guias/",
        "status=%s Location=%r" % (status, location),
    )


def build_parser():
    parser = argparse.ArgumentParser(
        prog="verify-deploy.py",
        description="Verify a deployed site over HTTP (read-only GET requests).",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "The smoke matrix is fixed; it does not grow between releases, only\n"
            "its recorded result changes. Exit code 0 means every check passed."
        ),
    )
    parser.add_argument(
        "--site",
        help="Base URL of the deployment to verify. REQUIRED: there is no default,\n"
             "so a mistyped invocation can never silently probe a live site.",
    )
    parser.add_argument("--matrix", default=DEFAULT_MATRIX, help="smoke matrix file")
    parser.add_argument("--only", help="run only matrix rows whose id contains this")
    parser.add_argument("--skip-singles", action="store_true",
                        help="skip the per-CPT single discovery checks")
    parser.add_argument("--timeout", type=int, default=30, help="per-request timeout")
    parser.add_argument("--out", help="write the JSON result to this file")
    parser.add_argument("--json", dest="as_json", action="store_true",
                        help="machine-readable output")
    parser.add_argument("--list", action="store_true",
                        help="print the smoke matrix and exit without any request")
    return parser


def main(argv=None):
    args = build_parser().parse_args(argv)

    if args.timeout:
        os.environ["CONEXAO_TEST_TIMEOUT_HTTP"] = str(args.timeout)

    try:
        matrix = load(args.matrix)
    except MatrixError as exc:
        print("ERROR: %s" % exc, file=sys.stderr)
        return 2

    if args.list:
        print("deployment smoke matrix: %s" % args.matrix)
        print("rows: %d" % len(matrix["rows"]))
        for row in matrix["rows"]:
            print("  %-28s %-34s expect %s" % (row["id"], row["url"], row["expect_status"]))
        return 0

    if not args.site:
        print("ERROR: --site is required. There is deliberately no default target:",
              file=sys.stderr)
        print("       pass the deployment explicitly, e.g. --site http://localhost:8080",
              file=sys.stderr)
        return 2

    try:
        base = normalise_site(args.site)
    except ValueError as exc:
        print("ERROR: %s" % exc, file=sys.stderr)
        return 2

    if not args.as_json:
        print("script: verify-deploy.py")
        print("target: %s" % base)
        print("mode:   verify (read-only, GET only, no credentials)")
        print("scope:  Verifies the fixed release smoke matrix, one single per post")
        print("        type, and the PT/EN language layer. Never writes anything.")
        print("matrix: %s (%d rows)" % (os.path.relpath(args.matrix, REPO_ROOT), len(matrix["rows"])))
        print("")
        print("== deployment verification ==")

    results = Results("verify-deploy")

    print("")
    print("-- smoke matrix --")
    failing_rows = run_matrix(results, matrix, base, only=args.only)

    if not args.only and not args.skip_singles:
        check_singles(results, base)

    if not args.only and not args.skip_singles:
        check_language_layer(results, base)

    rc = results.finish()

    if args.as_json or args.out:
        payload = {
            "site": base,
            "matrix": os.path.relpath(args.matrix, REPO_ROOT),
            "rows": len(matrix["rows"]),
            "failing_rows": failing_rows,
            "passed": results.passed,
            "failed": results.failed,
            "ok": rc == 0,
            "checks": results.checks,
        }
        text = json.dumps(payload, indent=2, ensure_ascii=False)
        if args.out:
            os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
            with open(args.out, "w", encoding="utf-8") as handle:
                handle.write(text + "\n")
            if not args.as_json:
                print("result: %s" % args.out)
        if args.as_json:
            print(text)

    if rc == 0 and not args.as_json:
        print("")
        print("summary: site=%s rows=%d passed=%d failed=0" % (base, len(matrix["rows"]), results.passed))
    return rc


if __name__ == "__main__":
    sys.exit(main())
