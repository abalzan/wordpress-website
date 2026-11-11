#!/usr/bin/env python3
"""HTTP acceptance — commemorative presentations and the opt-in landing page.

Two layers, both read-only and both against the LOCAL WordPress:

  1. The shared matrix (`matrices/commemorative.json`): no-event negative proof,
     the PT/EN landing page with its canonical/hreflang, the CPT privacy
     invariants, the sitemap archival policy and the public-query activation
     negative proof.

  2. An authenticated layer the matrix schema cannot express, because it needs a
     real login and a nonce derived from THAT session: it logs in as the local
     test administrator, opens the commemorative event editor, takes the signed
     preview link the engine prints, and proves that the preview renders the
     package on the homepage (hero + ambient) and on an internal page (ambient
     only) while an unauthenticated visitor gets neither.

Nothing here writes to WordPress, changes a schedule or contacts production.
"""

import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lib import http_client  # noqa: E402
from lib.matrix import Results, load, run_matrix  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
MATRIX = os.path.join(HERE, "matrices", "commemorative.json")

USERNAME = os.environ.get("WP_USERNAME", "test")
PASSWORD = os.environ.get("WP_TEST_USER_PASSWORD", "test")


def login(base):
    """Log in through wp-login.php. Returns a cookie-keeping opener, or None."""
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    form = urllib.parse.urlencode(
        {
            "log": USERNAME,
            "pwd": PASSWORD,
            "wp-submit": "Log In",
            "redirect_to": base + "/wp-admin/",
            "testcookie": "1",
        }
    ).encode()

    try:
        opener.open(urllib.request.Request(base + "/wp-login.php", data=form), timeout=http_client.timeout())
        response = opener.open(base + "/wp-admin/", timeout=http_client.timeout())
        body = response.read().decode("utf-8", "replace")
    except (urllib.error.HTTPError, urllib.error.URLError):
        return None

    if "wp-admin-bar-my-account" not in body and "adminmenu" not in body:
        return None

    return opener


def get(opener, url):
    """GET a same-base URL and return (status, body)."""
    try:
        response = opener.open(url, timeout=http_client.timeout())
        return response.getcode(), response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", "replace")
    except urllib.error.URLError as error:
        return 0, str(error)


def find_preview_url(opener, base, event_key):
    """Find the signed preview link the engine prints for one event key."""
    status, body = get(opener, base + "/wp-admin/edit.php?post_type=commemorative_event&post_status=all")

    if status != 200:
        return None, None

    ids = sorted(set(re.findall(r"post=(\d+)&(?:amp;)?action=edit", body)))

    for post_id in ids:
        status, page = get(opener, base + "/wp-admin/post.php?post=%s&action=edit" % post_id)

        if status != 200:
            continue

        match = re.search(
            r"https?://[^\"'<> ]*\?[^\"'<> ]*conexao_preview=%s[^\"'<> ]*" % re.escape(event_key),
            page,
        )

        if match:
            url = match.group(0)
            url = url.replace("&#038;", "&").replace("&amp;", "&")

            return url, post_id

    return None, None
SITEMAP_ROW = "pt-sitemap-does-not-publish-commemorative-urls"


def report_sitemap_evidence(base, matrix, results):
    """Classify who serves /sitemap.xml and say honestly what the row proves.

    The matrix row can only be an *effective* exclusion proof when the serving
    producer enumerates pages. Where a sitemap plugin answers with an index, the
    row passes vacuously, so the documentation must say so — otherwise a green
    row would be mistaken for end-to-end proof (the H1 finding of the
    commemorative-events r10 final review).
    """
    row = next((item for item in matrix["rows"] if item["id"] == SITEMAP_ROW), None)

    if row is None:
        results.check(False, "the sitemap evidence row exists in the matrix")
        return

    status, _headers, body = http_client.fetch_relative(base, "/sitemap.xml")

    if status != 200:
        results.check(False, "GET /sitemap.xml answers", "got %s" % status)
        return

    # A sitemap INDEX lists sitemap documents, not URLs; a URLSET lists URLs.
    is_index = "<sitemapindex" in body

    if is_index:
        results.check(
            "vacuous" in row["note"].lower(),
            "the matrix labels the sitemap row VACUOUS where an index is served "
            "(the theme producer does not run, so the row is not an exclusion proof)",
        )
    else:
        results.check(
            "/natal/" not in body and "/en/christmas/" not in body and "commemorative_event" not in body,
            "the serving urlset does not publish the commemorative URLs "
            "(effective exclusion proof by the serving producer in this environment)",
        )


def main():
    base = http_client.assert_local_base(http_client.base_url())
    results = Results("verify-commemorative-http")
    matrix = load(MATRIX)

    run_matrix(results, matrix, base)
    report_sitemap_evidence(base, matrix, results)

    # -- Authenticated preview layer ---------------------------------------
    opener = login(base)

    if opener is None:
        results.check(
            False,
            "the local test administrator can log in",
            "set WP_USERNAME / WP_TEST_USER_PASSWORD",
        )
        return results.finish()

    results.check(True, "the local test administrator can log in")

    preview_url, post_id = find_preview_url(opener, base, "halloween")

    if not preview_url:
        results.check(False, "the engine prints a signed preview link", "no preview link found in the event editor")
        return results.finish()

    results.check(True, "the engine prints a signed preview link (event post %s)" % post_id)

    status, body = get(opener, preview_url)
    results.check(status == 200, "the preview homepage returns 200", "got %s" % status)
    results.check('data-preview="1"' in body, "the preview banner is rendered for the administrator")
    results.check(
        "ce-placement--homepage_hero" in body and "seasonal-halloween-pumpkin" in body,
        "the homepage hero placement renders the Halloween package for the administrator",
    )
    results.check("ce-placement--site_wide_ambient" in body, "the site-wide ambient placement renders")
    results.check(
        "conexao-ce-css-presentations-halloween" in body and "conexao-ce-css-presentations-christmas" not in body,
        "only the previewed package's stylesheet is enqueued",
    )

    query = urllib.parse.urlparse(preview_url).query
    status, internal_body = get(opener, base + "/guias/?" + query)

    results.check(status == 200, "the preview on an internal page returns 200", "got %s" % status)
    results.check(
        "ce-placement--site_wide_ambient" in internal_body and "ce-placement--homepage_hero" not in internal_body,
        "an internal page previews the ambient placement and never the homepage hero",
    )
    results.check('data-preview="1"' in internal_body, "the preview is visibly marked on internal pages too")

    # The very same URL without the session must activate nothing.
    anonymous = urllib.request.build_opener()
    anonymous_status, anonymous_body = get(anonymous, preview_url)

    results.check(
        anonymous_status == 200,
        "the preview URL still serves the public page anonymously",
        "got %s" % anonymous_status,
    )
    results.check(
        'data-preview="1"' not in anonymous_body and "ce-package--halloween" not in anonymous_body,
        "the same preview URL is inert for an unauthenticated visitor",
    )

    return results.finish()


if __name__ == "__main__":
    sys.exit(main())

