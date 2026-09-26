"""scripts/lib/rest.py — the shared WordPress REST client (Stage I).

Engineering standard §10.2: every Python script that makes WordPress REST
requests should use this module for base-URL resolution, authentication,
retries, pagination and error handling.

It replaces the near-identical ``class WpRest`` copies that existed in a dozen
Python scripts before Stage I. It is Python **standard library only** — the
repository has no third-party Python dependency and this stage adds none.

Design rules
------------
1. **The base URL is resolved in exactly one place.** ``CONEXAO_SITE_URL``
   wins; otherwise the repository's documented local default
   (``http://localhost:8080``, from compose.yaml) is used. There is
   deliberately NO production default: a script can never fall through to
   conexaobr.ie.
2. **Credentials come from the environment only** — ``WP_USERNAME`` and
   ``WP_APPLICATION_PASSWORD``. They are never accepted as a command-line
   value, never logged and never included in an error message.
3. **Writes are gated.** :meth:`RestClient.assert_write_allowed` refuses a
   write against a production target unless the caller passes
   ``confirm_production=True``, and always prints the exact resolved target.
4. **Semantics are opt-in, never imposed.** Each existing script had slightly
   different retry and pagination behaviour, so those are constructor
   parameters with defaults that match the most common existing behaviour. A
   migrated script passes the values it used before, so no endpoint, payload,
   retry count or pagination rule changes.
"""

from __future__ import annotations

import base64
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

__all__ = [
    "RestError",
    "ProductionTargetError",
    "RestClient",
    "classify_target",
    "resolve_base_url",
    "default_headers",
    "basic_auth_header",
    "require_credentials",
    "add_common_arguments",
    "print_header",
    "print_summary",
]

#: The repository's documented local WordPress (compose.yaml port mapping).
LOCAL_BASE_URL = "http://localhost:8080"

#: Hosts that are production. A write against one of these needs confirmation.
PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")

#: Hosts that are unambiguously local.
LOCAL_HOSTS = ("localhost", "127.0.0.1", "0.0.0.0", "::1")

#: REST namespaces used by this repository's scripts.
API_V2 = "wp/v2"


class RestError(RuntimeError):
    """A REST request failed in a way the caller must handle.

    The message never contains credentials: the Authorization header is never
    rendered into an error.
    """


class ProductionTargetError(RestError):
    """A write was refused because the target is production and unconfirmed."""


def classify_target(base_url: str) -> str:
    """Classify a base URL as ``local``/``staging``/``production``/``unknown``.

    Unknown fails closed for writes (see :meth:`RestClient.assert_write_allowed`).
    """
    host = (urllib.parse.urlparse(base_url).hostname or "").lower()
    if not host:
        return "unknown"
    if host in PRODUCTION_HOSTS:
        return "production"
    if host in LOCAL_HOSTS:
        return "local"
    if host.endswith((".local", ".test", ".invalid", ".localhost")):
        return "staging"
    if host.startswith(("staging.", "dev.")):
        return "staging"
    return "unknown"


def resolve_base_url(explicit: str | None = None) -> str:
    """Resolve the one base URL for this run.

    Order: an explicit ``--base-url`` argument, then ``CONEXAO_SITE_URL``, then
    the documented local default. The result never carries a trailing slash.
    """
    candidate = explicit or os.environ.get("CONEXAO_SITE_URL") or LOCAL_BASE_URL
    return candidate.rstrip("/")


def require_credentials() -> tuple[str, str]:
    """Read ``WP_USERNAME`` / ``WP_APPLICATION_PASSWORD`` from the environment.

    Raises:
        RestError: when either variable is missing. The message names the
            variables but never echoes a value.
    """
    user = os.environ.get("WP_USERNAME", "")
    password = os.environ.get("WP_APPLICATION_PASSWORD", "")
    if not user or not password:
        raise RestError(
            "missing WordPress credentials. Export WP_USERNAME and "
            "WP_APPLICATION_PASSWORD (see .env.example). Credentials are read "
            "from the environment only and are never passed on the command line."
        )
    return user, password


def basic_auth_header(user: str, password: str) -> str:
    """Build the HTTP Basic ``Authorization`` value.

    The value is returned to the caller and stored only in request headers; it
    is never printed, logged or written to evidence.
    """
    token = base64.b64encode(f"{user}:{password}".encode("utf-8")).decode("ascii")
    return f"Basic {token}"


def default_headers(user_agent: str, authenticated: bool = True) -> dict:
    """Common request headers shared by every REST script.

    Args:
        user_agent: A descriptive, contactable User-Agent (REST etiquette).
        authenticated: When True the Basic ``Authorization`` header is added,
            reading credentials from the environment.
    """
    headers = {
        "Content-Type": "application/json",
        "User-Agent": user_agent,
    }
    if authenticated:
        user, password = require_credentials()
        headers["Authorization"] = basic_auth_header(user, password)
    return headers


class RestClient:
    """A WordPress REST client with shared auth, retry and pagination.

    The retry and pagination behaviour is configurable because the scripts that
    now use this client did not all share one policy; a migrated script passes
    the policy it had, so migrating never silently changes request semantics.

    Args:
        base_url: Explicit target. See :func:`resolve_base_url` for defaults.
        user_agent: Descriptive User-Agent string.
        authenticated: Send Basic auth (default True). Read-only verifiers that
            probe the public API pass False.
        timeout: Per-request timeout in seconds.
        retries: Attempts for a retriable failure (5xx / transport error).
        backoff: Seconds multiplied by the attempt number between retries.
        retry_on_4xx: Retries 4xx too. Off by default because 4xx is
            deterministic; the scripts that never retried 4xx keep that.
    """

    def __init__(
        self,
        base_url: str | None = None,
        *,
        user_agent: str = "ConexaoBR-Scripts/1.0",
        authenticated: bool = True,
        timeout: int = 30,
        retries: int = 3,
        backoff: float = 5.0,
        retry_on_4xx: bool = False,
        namespace: str = API_V2,
    ) -> None:
        self.base_url = resolve_base_url(base_url)
        self.target = classify_target(self.base_url)
        self.timeout = timeout
        self.retries = max(1, int(retries))
        self.backoff = backoff
        self.retry_on_4xx = retry_on_4xx
        self.namespace = namespace.strip("/")
        self.user_agent = user_agent
        self.headers = default_headers(user_agent, authenticated=authenticated)

    # -- target safety ----------------------------------------------------

    def assert_write_allowed(self, confirm_production: bool = False) -> str:
        """Refuse a write unless the target is safe or explicitly confirmed.

        Args:
            confirm_production: The caller saw and accepted the exact target.

        Returns:
            The resolved target class, for the run header.

        Raises:
            ProductionTargetError: production target without confirmation, or
                an unclassifiable target (fail closed).
        """
        target = self.target
        if target == "production" and not confirm_production:
            raise ProductionTargetError(
                f"refusing to write against production target {self.base_url!r} "
                "without --confirm-production. Re-run with --confirm-production "
                "once you have confirmed this is the intended target, or point "
                "CONEXAO_SITE_URL at the intended environment."
            )
        if target == "unknown":
            raise ProductionTargetError(
                f"refusing to write: target {self.base_url!r} could not be "
                "classified as local, staging or production. Set "
                "CONEXAO_SITE_URL or pass --base-url with an explicit target."
            )
        if target == "production":
            print(
                f"confirmed: production apply against {self.base_url} "
                "(--confirm-production)"
            )
        return target

    # -- requests ---------------------------------------------------------

    def url_for(self, path: str) -> str:
        """Absolute URL for a REST path such as ``/job?per_page=100``."""
        if path.startswith("http://") or path.startswith("https://"):
            return path
        if not path.startswith("/"):
            path = "/" + path
        if path.startswith(f"/{self.namespace}"):
            return f"{self.base_url}{path}"
        return f"{self.base_url}/{self.namespace}{path}"

    def request(
        self,
        method: str,
        path: str,
        payload=None,
        *,
        raw: bytes | None = None,
        content_type: str | None = None,
    ):
        """Perform one REST request with the shared retry policy.

        A 4xx raises immediately (deterministic client error). A 5xx or a
        transport error is retried up to ``retries`` times with linear backoff.

        Args:
            method: HTTP method.
            path: REST path or absolute URL.
            payload: JSON-serialisable request body.
            raw: Pre-encoded body (used for media uploads).
            content_type: Overrides the JSON content type for ``raw``.

        Returns:
            The decoded JSON body, or ``None`` for an empty response.

        Raises:
            RestError: on a non-retriable failure or after retries are
                exhausted. The message contains the method, path, status and a
                bounded slice of the response body — never the credentials.
        """
        url = self.url_for(path)
        headers = dict(self.headers)
        body = None
        if raw is not None:
            body = raw
            if content_type:
                headers["Content-Type"] = content_type
        elif payload is not None:
            body = json.dumps(payload).encode("utf-8")

        last_error: Exception | None = None
        for attempt in range(self.retries):
            request = urllib.request.Request(
                url, data=body, headers=headers, method=method.upper()
            )
            try:
                with urllib.request.urlopen(request, timeout=self.timeout) as response:
                    text = response.read().decode("utf-8", "replace")
                return json.loads(text) if text.strip() else None
            except urllib.error.HTTPError as error:
                detail = error.read().decode("utf-8", "replace")[:400]
                message = f"HTTP {error.code} on {method.upper()} {path}: {detail}"
                if error.code < 500 and not self.retry_on_4xx:
                    raise RestError(message) from error
                last_error = RestError(message)
            except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as error:
                last_error = RestError(
                    f"transport failure on {method.upper()} {path}: {error}"
                )
            if attempt < self.retries - 1:
                time.sleep(self.backoff * (attempt + 1))

        raise last_error if last_error else RestError(f"request failed: {method} {path}")

    def get(self, path: str, params: dict | None = None):
        """GET a REST path, appending an optional query string."""
        if params:
            separator = "&" if "?" in path else "?"
            path = f"{path}{separator}{urllib.parse.urlencode(params)}"
        return self.request("GET", path)

    def post(self, path: str, payload):
        """POST a JSON payload."""
        return self.request("POST", path, payload)

    def put(self, path: str, payload):
        """PUT a JSON payload."""
        return self.request("PUT", path, payload)

    def delete(self, path: str):
        """DELETE a resource."""
        return self.request("DELETE", path)

    def get_all(self, path: str, params: dict | None = None, *, per_page: int = 100):
        """Follow WordPress collection pagination and return every item.

        Uses the ``X-WP-Total`` / ``X-WP-TotalPages`` response headers exactly
        as the pre-Stage-I scripts did, so a migrated script sees the same rows
        in the same order.
        """
        query = dict(params or {})
        query.setdefault("per_page", per_page)
        query.setdefault("page", 1)
        query.setdefault("context", "view")

        collected: list = []
        page = 1
        while True:
            query["page"] = page
            separator = "&" if "?" in path else "?"
            request = urllib.request.Request(
                f"{self.url_for(path)}{separator}{urllib.parse.urlencode(query)}",
                headers=self.headers,
                method="GET",
            )
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                total_pages = int(response.headers.get("X-WP-TotalPages") or 1)
                batch = json.loads(response.read().decode("utf-8", "replace") or "[]")
            if isinstance(batch, list):
                collected.extend(batch)
            if page >= total_pages:
                break
            page += 1
        return collected


def add_common_arguments(parser) -> None:
    """Add the standard arguments every REST script shares.

    Adds ``--base-url``, ``--json``, ``--confirm-production`` and
    ``--json-out``. Callers keep their own script-specific arguments.
    """
    parser.add_argument(
        "--base-url",
        default=None,
        help=(
            "Site base URL. Default: $CONEXAO_SITE_URL, else the local "
            f"development site ({LOCAL_BASE_URL}). There is no production "
            "default."
        ),
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Emit the machine-readable result on stdout.",
    )
    parser.add_argument(
        "--confirm-production",
        action="store_true",
        help=(
            "Acknowledge a write against a production target. Required before "
            "any write when the resolved target is production."
        ),
    )
    parser.add_argument(
        "--json-out",
        default=None,
        help="Also write the machine-readable result to this file path.",
    )


def print_header(
    script: str,
    base_url: str,
    mode: str,
    scope: str,
    *,
    stream=None,
) -> None:
    """Print the standard run header: WHAT, WHERE, MODE, SCOPE.

    Args:
        script: Short script name.
        base_url: The exact resolved target.
        mode: ``dry-run``/``apply``/``read-only``.
        scope: Declared scope, one line.
        stream: Defaults to stdout.
    """
    stream = stream or sys.stdout
    target = classify_target(base_url)
    stream.write(f"script: {script}\n")
    stream.write(f"target: {base_url} ({target})\n")
    stream.write(f"mode:   {mode}\n")
    stream.write(f"scope:  {scope}\n")
    if target == "production":
        bar = "!" * 68
        stream.write(bar + "\n")
        stream.write("!!  PRODUCTION TARGET — every write below changes the live site.\n")
        stream.write(f"!!  Target: {base_url}\n")
        stream.write(bar + "\n")
    stream.flush()


def print_summary(
    script: str,
    base_url: str,
    mode: str,
    scope: str,
    summary: dict,
    *,
    as_json: bool = False,
    json_out: str | None = None,
    extra: dict | None = None,
) -> int:
    """Print the standard closing summary and return the process exit code.

    With ``as_json`` stdout carries only the JSON document (the run header was
    then sent to stderr by the caller). Otherwise a single ``summary:`` line is
    printed.

    Returns:
        0 when ``summary['errors']`` is 0, else 1. "Nothing to do" is a success
        (exit 0) and is reported through the counts.
    """
    payload = {"script": script, "target": base_url, "mode": mode, "scope": scope}
    if extra:
        payload.update(extra)
    payload["summary"] = summary

    document = json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=False)

    if as_json:
        sys.stdout.write(document + "\n")
    else:
        parts = ", ".join(f"{k}={v}" for k, v in summary.items())
        sys.stdout.write(f"summary: {parts}\n")
    if json_out:
        with open(json_out, "w", encoding="utf-8") as handle:
            handle.write(document + "\n")
    sys.stdout.flush()

    return 1 if int(summary.get("errors", 0)) > 0 else 0
