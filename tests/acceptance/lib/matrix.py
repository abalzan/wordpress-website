"""Shared matrix schema + runner for the Stage E acceptance layer.

ONE schema for every HTTP acceptance matrix (PHASE 16). Canonical fields:

    {
      "id": "string",              # unique within the matrix
      "url": "/relative/path",     # relative to the configured local base URL
      "expect_status": 200,        # integer
      "expect_contains": ["..."],  # string fragments that MUST appear
      "expect_absent": ["..."],    # string fragments that MUST NOT appear
      "note": "why this row exists"
    }

Malformed matrix data fails LOUDLY, before any HTTP request is made
(PHASE 37): a matrix is validated in full first, then executed.
"""

import json
import os

from . import http_client

REQUIRED_FIELDS = ("id", "url", "expect_status", "expect_contains", "expect_absent", "note")
LIST_FIELDS = ("expect_contains", "expect_absent")


class MatrixError(RuntimeError):
    """Raised for any malformed matrix definition."""


def load(path):
    """Load and fully validate a matrix file. Raises MatrixError."""
    if not os.path.exists(path):
        raise MatrixError("matrix file not found: %s" % path)
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
    except json.JSONDecodeError as exc:
        raise MatrixError("%s is not valid JSON: %s" % (path, exc)) from exc

    if not isinstance(data, dict):
        raise MatrixError("%s must contain a JSON object with a 'rows' array" % path)
    rows = data.get("rows")
    if not isinstance(rows, list) or not rows:
        raise MatrixError("%s must contain a non-empty 'rows' array" % path)

    seen = set()
    for index, row in enumerate(rows):
        where = "%s row %d" % (path, index)
        if not isinstance(row, dict):
            raise MatrixError("%s must be an object" % where)
        for field in REQUIRED_FIELDS:
            if field not in row:
                raise MatrixError("%s is missing required field %r" % (where, field))

        row_id = row["id"]
        if not isinstance(row_id, str) or not row_id.strip():
            raise MatrixError("%s has an empty or non-string 'id'" % where)
        if row_id in seen:
            raise MatrixError("%s has duplicate id %r" % (where, row_id))
        seen.add(row_id)

        url = row["url"]
        if not isinstance(url, str) or not url.startswith("/"):
            raise MatrixError(
                "%s url must be a relative path starting with '/' (got %r); "
                "absolute or production URLs are not allowed in a matrix" % (where, url))

        status = row["expect_status"]
        if not isinstance(status, int) or isinstance(status, bool):
            raise MatrixError("%s expect_status must be an integer (got %r)" % (where, status))

        for field in LIST_FIELDS:
            value = row[field]
            if not isinstance(value, list):
                raise MatrixError("%s %s must be an array (got %r)" % (where, field, value))
            for item in value:
                if not isinstance(item, str) or item == "":
                    raise MatrixError(
                        "%s %s entries must be non-empty strings (got %r)" % (where, field, item))

        note = row["note"]
        if not isinstance(note, str) or not note.strip():
            raise MatrixError("%s must carry a non-empty 'note' explaining why the row exists" % where)

    return data


class Results:
    """Accumulates pass/fail counts for one acceptance suite."""

    def __init__(self, name):
        self.name = name
        self.passed = 0
        self.failed = 0

    def check(self, condition, label, detail=""):
        if condition:
            self.passed += 1
            print("  PASS  %s" % label)
            return True
        self.failed += 1
        print("  FAIL  %s%s" % (label, ("  -- " + str(detail)) if detail else ""))
        return False

    def finish(self):
        print("\n%s: %d passed, %d failed" % (self.name, self.passed, self.failed))
        return 1 if self.failed else 0


def run_matrix(results, matrix, base=None):
    """Execute a validated matrix. Returns the number of failing ROWS."""
    base = http_client.assert_local_base(base or http_client.base_url())
    failing_rows = 0
    for row in matrix["rows"]:
        status, headers, body = http_client.fetch_relative(base, row["url"])
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
            failing_rows += 1
            results.check(False, "%s %s" % (row["id"], row["url"]), "; ".join(problems))
        else:
            results.check(True, "%s %s (status %s)" % (row["id"], row["url"], status))
    return failing_rows
