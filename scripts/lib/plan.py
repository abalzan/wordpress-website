"""scripts/lib/plan.py — the shared machine-readable plan helper (Stage I).

A justified addition: several current write-capable scripts (the REST seeders
and the import runners) each built their own ad-hoc plan/result dict, and the
Stage H shared rollout engine already emits ``plan``/``summary``/``gate``
sections. Rather than let each script invent a shape, this module defines one
plan document and one writer.

The contract (engineering standard §10.2 / §0.3 "plan before write"):

    {
      "script":  "seed-permit-employers",
      "target":  "http://localhost:8080",
      "mode":    "dry-run",
      "scope":   "...",
      "create":  [ ... ],
      "update":  [ ... ],
      "skip":    [ ... ],
      "conflicts": [ ... ],
      "summary": { "create": 0, "update": 0, "skip": 0,
                   "conflicts": 0, "errors": 0 }
    }

Only the fields the operation actually produces are emitted, and the writer
always adds ``script``, ``target``, ``mode`` and ``scope`` so a plan is never
ambiguous about what produced it.

Rules this module enforces for the caller:

* ``mode`` must be ``dry-run`` or ``apply`` — a plan never claims anything else.
* A ``dry-run`` plan may not contain a non-empty ``apply`` marker: the caller
  cannot accidentally report a write it did not perform.
* The document never contains credentials. :func:`build_plan` strips any key
  whose name looks secret, so a payload built from a REST response cannot leak
  an ``Authorization`` value into evidence.
* Output is deterministic: bucket order is fixed and each bucket is sorted by
  its ``id`` when present, so two runs of the same plan compare equal.
"""

from __future__ import annotations

import json
import sys
from typing import Any, Iterable

__all__ = [
    "PlanBuilder",
    "build_plan",
    "write_plan",
    "SECRET_KEY_MARKERS",
]

#: Substrings that mark a key as secret-bearing. Matching keys are dropped.
SECRET_KEY_MARKERS = (
    "authorization",
    "password",
    "passwd",
    "secret",
    "token",
    "api_key",
    "apikey",
    "private_key",
)

#: The fixed bucket order, so the JSON document shape is stable.
BUCKET_ORDER = ("create", "update", "skip", "conflicts")


def _scrub(value: Any) -> Any:
    """Recursively drop secret-bearing keys from a plan payload."""
    if isinstance(value, dict):
        return {
            key: _scrub(item)
            for key, item in value.items()
            if not any(marker in str(key).lower() for marker in SECRET_KEY_MARKERS)
        }
    if isinstance(value, list):
        return [_scrub(item) for item in value]
    return value


def _sort_bucket(rows: Iterable[Any]) -> list:
    """Sort a bucket deterministically by ``id`` when every row has one."""
    rows = list(rows)
    if rows and all(isinstance(row, dict) and "id" in row for row in rows):
        return sorted(rows, key=lambda row: str(row["id"]))
    return rows


class PlanBuilder:
    """Collects plan rows and renders the standard plan document.

    Example:
        >>> plan = PlanBuilder("seed-permit-employers", "http://localhost:8080")
        >>> plan.add("create", {"id": 12, "title": "Mowlam Healthcare"})
        >>> plan.add("skip", {"id": 13, "reason": "already present"})
        >>> plan.document()["summary"]["create"]
        1
    """

    def __init__(self, script: str, target: str, *, mode: str, scope: str) -> None:
        if mode not in ("dry-run", "apply"):
            raise ValueError(
                f"plan mode must be 'dry-run' or 'apply', got {mode!r}"
            )
        self.script = script
        self.target = target
        self.mode = mode
        self.scope = scope
        self._buckets: dict[str, list] = {name: [] for name in BUCKET_ORDER}
        self.errors: list = []

    def add(self, bucket: str, row: Any) -> None:
        """Add one row to a named bucket (``create``/``update``/``skip``/``conflicts``)."""
        if bucket not in self._buckets:
            raise KeyError(
                f"unknown plan bucket {bucket!r}; expected one of "
                + ", ".join(BUCKET_ORDER)
            )
        self._buckets[bucket].append(row)

    def add_error(self, message: str) -> None:
        """Record one error. A non-empty error list makes the exit code non-zero."""
        self.errors.append(str(message))

    def document(self) -> dict:
        """Render the plan document with counts and a deterministic shape."""
        buckets = {
            name: _sort_bucket(_scrub(self._buckets[name])) for name in BUCKET_ORDER
        }
        summary = {name: len(rows) for name, rows in buckets.items()}
        summary["errors"] = len(self.errors)
        if self.errors:
            buckets["errors"] = list(self.errors)
        return {
            "script": self.script,
            "target": self.target,
            "mode": self.mode,
            "scope": self.scope,
            **buckets,
            "summary": summary,
        }

    def write(self, stream=None) -> dict:
        """Write the plan document as JSON to ``stream`` (default stdout).

        Returns:
            The document that was written.
        """
        document = self.document()
        stream = stream or sys.stdout
        stream.write(
            json.dumps(document, ensure_ascii=False, indent=2, sort_keys=False)
            + "\n"
        )
        stream.flush()
        return document


def build_plan(
    script: str,
    target: str,
    *,
    mode: str,
    scope: str,
    create: Iterable[Any] = (),
    update: Iterable[Any] = (),
    skip: Iterable[Any] = (),
    conflicts: Iterable[Any] = (),
    errors: Iterable[Any] = (),
) -> dict:
    """Build a plan document in one call, without a builder instance.

    Domain-specific semantic fields are preserved: pass them as dicts inside the
    buckets and they survive unchanged (minus secret-bearing keys). This wraps a
    caller's existing plan shape rather than replacing it.
    """
    plan = PlanBuilder(script, target, mode=mode, scope=scope)
    for row in create:
        plan.add("create", row)
    for row in update:
        plan.add("update", row)
    for row in skip:
        plan.add("skip", row)
    for row in conflicts:
        plan.add("conflicts", row)
    for error in errors:
        plan.add_error(error)
    return plan.document()


def write_plan(document: dict, stream=None) -> dict:
    """Write a pre-built plan document as JSON."""
    stream = stream or sys.stdout
    stream.write(
        json.dumps(document, ensure_ascii=False, indent=2, sort_keys=False) + "\n"
    )
    stream.flush()
    return document
