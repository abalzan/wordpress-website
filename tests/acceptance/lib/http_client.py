"""Shared HTTP support for the Stage E acceptance layer (PHASE 15).

Python standard library only (no requests, no pytest, no browser framework) —
engineering standard §8.1 / dependency policy.

Responsibilities:
  - GET requests with a deterministic timeout;
  - redirects are NOT followed by default, so a 301/302/307/308 row asserts the
    FIRST response (status + Location), not the final page (PHASE 35);
  - local-only URL safety: a production host is refused before any socket is
    opened (PHASE 17 / PHASE 43 / PHASE 61);
  - readable failures.
"""

import os
import urllib.error
import urllib.parse
import urllib.request

# Production hosts the harness must never contact. A production verifier is a
# separate manual tool, not a local regression test.
PRODUCTION_HOSTS = frozenset({"conexaobr.ie", "www.conexaobr.ie"})

DEFAULT_BASE_URL = "http://localhost:8080"
DEFAULT_TIMEOUT = 30

# Header inspection under test needs raw redirects, so we build one opener with
# a handler that refuses to follow them.
class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Return None so urllib surfaces the 3xx response instead of following it."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


_OPENER_NO_REDIRECT = urllib.request.build_opener(_NoRedirect)
_OPENER_FOLLOW = urllib.request.build_opener()


class ProductionUrlRejected(RuntimeError):
    """Raised when a base URL points at a production host."""


def base_url():
    """Resolve the acceptance base URL (CONEXAO_TEST_BASE_URL)."""
    return os.environ.get("CONEXAO_TEST_BASE_URL", DEFAULT_BASE_URL).rstrip("/")


def assert_local_base(url):
    """Refuse production hosts. Returns the normalised URL.

    This is the library-level half of PHASE 17; scripts/run-tests.sh performs
    the same check before the harness starts.
    """
    host = urllib.parse.urlparse(url).hostname or ""
    host = host.lower()
    if host in PRODUCTION_HOSTS:
        raise ProductionUrlRejected(
            "refusing to run acceptance tests against the production host %r. "
            "The Stage E harness is local-only; set CONEXAO_TEST_BASE_URL to a "
            "local WordPress (default http://localhost:8080)." % host
        )
    return url.rstrip("/")


def timeout():
    try:
        return int(os.environ.get("CONEXAO_TEST_TIMEOUT_HTTP", DEFAULT_TIMEOUT))
    except ValueError:
        return DEFAULT_TIMEOUT


def fetch(url, follow=False):
    """GET `url` and return (status, headers, body-text).

    `follow=False` (the default) never follows redirects, so a redirect row can
    assert the original status and its Location header.
    """
    opener = _OPENER_FOLLOW if follow else _OPENER_NO_REDIRECT
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "conexao-acceptance/1.0", "Accept-Language": "en"},
    )
    try:
        with opener.open(request, timeout=timeout()) as response:
            return response.status, response.headers, response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        # A 3xx/4xx is a legitimate result under test, not an error.
        return exc.code, exc.headers, exc.read().decode("utf-8", "replace")
    except urllib.error.URLError as exc:
        return 0, {}, "transport error: %s" % exc.reason


def fetch_relative(base, path, follow=False):
    """GET a matrix-relative path against `base`."""
    if not path.startswith("/"):
        raise ValueError("matrix url must be relative and start with '/': %r" % path)
    return fetch(base + path, follow=follow)
