#!/usr/bin/env python3
"""HTTP acceptance — routing, language, canonical, hreflang and REST.

Migrated (PHASE 18) from the maintained coverage in:
  scripts/stage2-http-verify.sh        (PT URL preservation, legacy EN→PT 301s,
                                        /en/ routing, lang/og:locale, canonical,
                                        hreflang, cache separation, search, REST)
  scripts/stage33-http-verify.py       (redirect precedence, B1/B2 behaviour)

Rows live in matrices/routing.json and use the single shared matrix schema.
The legacy-redirect and B2 rows assert a 301/302 STATUS only (the schema has no
headers field), which is exactly what the original scripts' status assertion
checked for those paths; body/canonical/hreflang rows keep their full fragment
assertions.

Read-only: GET requests only, against the LOCAL WordPress.
"""

import json
import os
import re
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lib import http_client  # noqa: E402
from lib.matrix import Results, load, run_matrix  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))

# The Apoiadores archive is a SINGLE-PAGE directory. Its rendered card set must
# equal the set the site itself reports for the same language scope, and it must
# generate no pagination at all.
APOIADORES_TARGETS = (
    ("pt", "/apoiadores/"),
    ("en", "/en/apoiadores/"),
)


def rendered_apoiaador_links(body: str) -> list:
    """The Apoiador detail links the archive body renders, in document order."""
    return re.findall(r'<h2 class="archive-card-title"><a href="([^"]+)"', body)


def available_apoiaador_links(base: str, language: str) -> list:
    """The Apoiador detail links the site reports for a language.

    `?lang=` is the documented bilingual REST contract
    (`inc/rest-language.php`, `docs/routing.md` § REST): for the B2 post types —
    `sponsor` among them — it mirrors the front-end language policy, so the
    collection is the data-side set for the SAME language scope the archive
    renders.
    """
    status, _headers, body = http_client.fetch_relative(
        base, "/wp-json/wp/v2/sponsor?lang=%s&per_page=100&_fields=link" % language
    )
    if status != 200:
        return []
    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        return []
    return sorted(item["link"] for item in payload if isinstance(item, dict) and item.get("link"))


def check_apoiaadores_single_page(results, base) -> None:
    """The rendered set equals the available set and no pagination exists."""
    print("\n-- /apoiadores/ single-page directory --")

    for language, path in APOIADORES_TARGETS:
        status, _headers, body = http_client.fetch_relative(base, path)
        rendered = rendered_apoiaador_links(body)
        available = available_apoiaador_links(base, language)

        results.check(status == 200, "%s %s answers 200" % (language, path), "status=%s" % status)
        results.check(
            available and len(available) > 0,
            "%s %s: the REST collection for this language is non-empty" % (language, path),
        )
        results.check(
            sorted(rendered) == available,
            "%s %s renders every Apoiador available for that language" % (language, path),
            "rendered=%d unique=%d available=%d" % (len(rendered), len(set(rendered)), len(available)),
        )
        results.check(
            len(rendered) == len(set(rendered)),
            "%s %s renders every Apoiador exactly once" % (language, path),
        )
        results.check(
            "conexao-pagination" not in body and "page-numbers" not in body,
            "%s %s emits no pagination control" % (language, path),
        )
        results.check(
            'rel="next"' not in body and 'rel="prev"' not in body,
            "%s %s emits no rel=next/prev pagination link" % (language, path),
        )

        for link in rendered:
            link_path = urllib.parse.urlparse(link).path
            link_status, _h, _b = http_client.fetch_relative(base, link_path)
            results.check(
                link_status == 200,
                "%s %s: the Apoiador detail link resolves (%s)" % (language, path, link_path),
                "status=%s" % link_status,
            )

        page_two_status, _h, _b = http_client.fetch_relative(base, path + "page/2/")
        results.check(
            page_two_status == 404,
            "%s %spage/2/ is not a paginated Apoiadores view" % (language, path),
            "status=%s" % page_two_status,
        )



def main():
    results = Results("acceptance/routing-http")
    base = http_client.assert_local_base(http_client.base_url())
    print("== HTTP acceptance: routing / language / canonical / hreflang ==")
    print("   base: %s" % base)

    matrix = load(os.path.join(HERE, "matrices", "routing.json"))
    failing = run_matrix(results, matrix, base)

    # --- Apoiadores single-page directory (dynamic) ------------------------
    # The matrix rows prove "no pagination is emitted"; these checks prove the
    # stronger data claim: every Apoiador available for the language is
    # rendered, each exactly once, and no second paginated view exists.
    check_apoiaadores_single_page(results, base)

    # --- Redirect Location header (PHASE 35) -------------------------------
    # The shared matrix schema deliberately has no headers field, so the few
    # redirect rows that must assert their *target* use this suite-level check
    # against the first (unfollowed) response.
    print("\n-- redirect targets (first response, redirects not followed) --")
    for row_id, path, expected in (
        ("legacy-guides", "/guides/", "/guias/"),
        ("legacy-events", "/events/", "/eventos/"),
        ("legacy-jobs", "/jobs/", "/empregos/"),
    ):
        status, headers, _ = http_client.fetch_relative(base, path)
        location = headers.get("Location", "") if headers else ""
        results.check(
            location.endswith(expected),
            "%s %s redirects to %s" % (row_id, path, expected),
            "status=%s Location=%r" % (status, location),
        )

    # Matrix row failures are counted separately from assertion counters so a
    # malformed/absent matrix cannot be masked by a zero counter total.
    if failing:
        print("\n%d matrix row(s) failed" % failing)
    return results.finish()


if __name__ == "__main__":
    sys.exit(main())
