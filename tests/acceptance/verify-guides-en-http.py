#!/usr/bin/env python3
"""HTTP acceptance — Stage 9 English guide archive.

Migrated (PHASE 18) from the maintained coverage in
scripts/stage9-guide-http-verify.sh: /en/guias/ is a real English archive
(no B2 notice), self-canonical, with the PT/en/x-default hreflang set, and the
pagination and ?categoria= filters work in both languages.

The static rows live in matrices/guides-en.json. The script's dynamic checks —
discovering a real EN guide slug from the archive and then asserting the EN
single's canonical/hreflang/description/switcher, plus the "replaced master"
302 back to PT — depend on data discovery and therefore stay at suite level
(PHASE 35 allows a small suite-level assertion when a row cannot fit the
standard matrix fields).

Read-only: GET requests only, against the LOCAL WordPress.
"""

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lib import http_client  # noqa: E402
from lib.matrix import Results, load, run_matrix  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))


def main():
    results = Results("acceptance/guides-en-http")
    base = http_client.assert_local_base(http_client.base_url())
    print("== HTTP acceptance: Stage 9 EN guide archive ==")
    print("   base: %s" % base)

    matrix = load(os.path.join(HERE, "matrices", "guides-en.json"))
    run_matrix(results, matrix, base)

    # --- Dynamic rows: a real EN guide, discovered from the archive ---------
    print("\n-- EN guide single (slug discovered from the archive) --")
    _, _, archive = http_client.fetch_relative(base, "/en/guias/")
    # Anchor the discovery on the archive-card title link, exactly as the
    # original scripts/stage9-guide-http-verify.sh did. A loose
    # `/en/guias/<slug>/` match would also hit `<slug>feed/`.
    found = re.search(
        r'archive-card-title"><a href="[^"]*/en/guias/([a-z0-9-]{3,})/"', archive)
    if not results.check(bool(found), "an EN guide link is discoverable in /en/guias/"):
        return results.finish()

    en_slug = found.group(1)
    print("   discovered EN guide slug: %s" % en_slug)

    status, _, body = http_client.fetch_relative(base, "/en/guias/%s/" % en_slug)
    results.check(status == 200, "EN guide single /en/guias/%s/ is 200" % en_slug, "status=%s" % status)
    results.check("language-fallback-notice" not in body,
                  "EN guide single shows no B2 notice", en_slug)
    results.check('rel="canonical" href="' in body and "/en/guias/%s/" % en_slug in body,
                  "EN guide single is self-canonical", en_slug)
    results.check('hreflang="pt-BR"' in body, "EN guide single declares the pt-BR alternate", en_slug)
    results.check('name="description"' in body, "EN guide single carries a meta description", en_slug)
    results.check("language-switcher" in body, "EN guide single renders a language switcher", en_slug)

    # --- Replaced master: the PT slug under /en/ must not serve PT ----------
    print("\n-- replaced master (PT slug under /en/) --")
    pt = re.search(r'hreflang="pt-BR"[^>]*href="[^"]*/guias/([a-z0-9-]{3,})/"', body)
    if results.check(bool(pt), "the EN guide exposes its PT sibling"):
        pt_slug = pt.group(1)
        st, headers, _ = http_client.fetch_relative(base, "/guias/%s/" % pt_slug)
        results.check(st == 200, "PT guide /guias/%s/ is still 200" % pt_slug, "status=%s" % st)
        st, headers, _ = http_client.fetch_relative(base, "/en/guias/%s/" % pt_slug)
        location = headers.get("Location", "") if headers else ""
        results.check(
            st in (301, 302) and location.endswith("/guias/%s/" % pt_slug),
            "the PT slug under /en/ redirects back to the PT guide instead of serving "
            "Portuguese under an English URL",
            "status=%s Location=%r" % (st, location),
        )

    return results.finish()


if __name__ == "__main__":
    sys.exit(main())
