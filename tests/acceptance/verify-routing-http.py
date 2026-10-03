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

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lib import http_client  # noqa: E402
from lib.matrix import Results, load, run_matrix  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))


def main():
    results = Results("acceptance/routing-http")
    base = http_client.assert_local_base(http_client.base_url())
    print("== HTTP acceptance: routing / language / canonical / hreflang ==")
    print("   base: %s" % base)

    matrix = load(os.path.join(HERE, "matrices", "routing.json"))
    failing = run_matrix(results, matrix, base)

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
