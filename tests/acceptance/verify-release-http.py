#!/usr/bin/env python3
"""HTTP acceptance — the release smoke matrix, against the LOCAL WordPress.

Stage J. `scripts/verify-deploy.py` is the release gate, but a gate that is only
ever exercised at release time is a gate nobody trusts. This suite runs the SAME
fixed matrix (`scripts/data/release-smoke-matrix.json`) plus the same per-CPT
single discovery and language-layer checks against the local site, through the
shared harness, on every CI run.

It shares the implementation with the deploy verifier rather than restating it:
  - the matrix schema and the HTTP client come from `lib/` (one schema, §8.2);
  - the smoke matrix file is the same file the release gate uses;
  - the local-only guard is inherited, because this suite must never touch a
    deployed site — that separation is deliberate.

Read-only: GET requests only, against the LOCAL WordPress.
"""

import os
import runpy
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))

sys.path.insert(0, os.path.join(REPO_ROOT, "tests", "acceptance"))
sys.path.insert(0, os.path.join(REPO_ROOT, "scripts", "lib"))

from lib import http_client  # noqa: E402
from lib.matrix import Results  # noqa: E402

# Import the deploy verifier's checking functions rather than copying them, so
# the acceptance suite and the release gate can never disagree.
spec_path = os.path.join(REPO_ROOT, "scripts", "verify-deploy.py")
verify_deploy = runpy.run_path(spec_path, run_name="__not_main__")

MATRIX = os.path.join(REPO_ROOT, "scripts", "data", "release-smoke-matrix.json")


def main():
    base = http_client.assert_local_base(http_client.base_url())
    print("== HTTP acceptance: release smoke matrix (local) ==")
    print("   base:   %s" % base)
    print("   matrix: %s" % os.path.relpath(MATRIX, REPO_ROOT))

    results = Results("acceptance/release-http")

    # The shared loader validates the matrix against the one schema before any
    # request is made, so a malformed release gate fails loudly here in CI
    # rather than at release time.
    from lib.matrix import load

    matrix = load(MATRIX)
    print("   rows:   %d" % len(matrix["rows"]))

    print("\n-- release smoke matrix --")
    failing = verify_deploy["run_matrix"](results, matrix, base)

    print("\n-- one single per post type --")
    verify_deploy["check_singles"](results, base)

    print("\n-- language layer --")
    verify_deploy["check_language_layer"](results, base)

    if failing:
        print("\n%d matrix row(s) failed" % failing)
    return results.finish()


if __name__ == "__main__":
    sys.exit(main())
