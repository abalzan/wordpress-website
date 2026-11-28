# Plan — REST API and administrative write security audit

| | |
|---|---|
| **Task title** | Audit custom REST endpoints and administrative write operations |
| **Date** | 2026-11-27 |
| **Author / agent** | Cline (application security engineer) |
| **Branch** | `audit-rest-admin-security` |
| **Repository baseline (starting SHA)** | `ceaa1c0` |
| **Applicable skill(s)** | `wp-security-review`, `wp-repository`, `wp-testing` |
| **Plan status** | approved |

## 1. Scope

Review every custom write-capable surface — `admin_post_*`, `wp_ajax_*`, the
custom `register_rest_route()` endpoints, and the meta `auth_callback`
registrations — and make the review **durable**: encode its conclusions as a
fail-closed static gate plus an in-process regression suite, so the next endpoint
cannot ship unprotected.

## 2. Explicit non-goals

- **No refactor of a passing handler.** Where a handler already checks
  capability + nonce and validates input, it is left byte-identical.
- **No authentication added to the two public REST read endpoints.** They exist
  so a Lazer visitor who grants geolocation can filter the archive; making them
  authenticated would break the feature.
- **No new capability, role or nonce vocabulary.**
- **Not** auditing WordPress core, Polylang, Jetpack or any third-party plugin.
- **Not** a penetration test: no production request, no fuzzing, no load test.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §2.1 (sanitise/escape), §4.2 ("every
admin write path verifies a nonce AND a capability before writing"), §7 (REST
standard: allowlist, `auth_callback` for writable meta), §8 (testing);
`docs/routing.md` §English; `docs/content-model.md` (meta).

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-security-review` |
| **Its preconditions** | The full write-surface inventory, not a sample |
| **Its guardrails that bind this task** | Never weaken a permission check to make an endpoint testable; distinguish public read-only by design from privileged write; classify, do not hand-wave |
| **Its verification commands** | `python3 tests/scripts/verify-write-endpoint-authorization.py`, `./scripts/run-tests.sh --only conexao-br-irlanda` |
| **Its evidence location** | `docs/evidence/2026-11-27-security-audit/` |

## 4. Current-state findings

Inventory taken by static enumeration of `wp-content/` (34 hooks, 2 routes,
21 meta registrations) and by reading every handler body:

| Surface | Count | State |
|---|---|---|
| `admin_post_*` handlers | 32 | every one capability-checked; every state-changing one nonce-checked |
| `wp_ajax_*` handlers | 2 (`conexao_leisure_wiki_search`, `conexao_leisure_wiki_import`) | nonce → capability → object-level `edit_post`, plus `upload_files` for the import |
| `admin_post_nopriv_*` / `wp_ajax_nopriv_*` | **0** | no anonymous write surface exists |
| `register_rest_route()` | 2 | both `READABLE`-only with `permission_callback => __return_true` — public read by design |
| `register_post_meta`/`register_meta` | 21 | protected REST-exposed keys declare `edit_post`-scoped `auth_callback`s; 13 use computed keys in loops |

Existing coverage already proves the REST meta contract in both directions
(`conexao-data-model/tests/test-leisure-coordinate-rest-permissions.php`:
anonymous 401 `rest_cannot_edit`, no-capability 403, authorised 200, refused
requests persist nothing).

**The gap** was not a vulnerability but a *durability* one: nothing prevented a
future handler from shipping without a capability or nonce check, and nothing
asserted that the two public REST routes stay read-only. If
`WP_REST_Server::READABLE` were changed to `ALLMETHODS`, every existing suite
would still pass while the endpoints became an anonymous write surface.

## 5. Proposed approach

1. **`tests/scripts/verify-write-endpoint-authorization.py`** — a permanent,
   fail-closed static gate over the whole `wp-content/` write surface:
   - capability on every handler, resolved through `'admin_post_' . CONST` /
     `'admin_post_' . self::CONST` as well as literals;
   - nonce on every handler that **can reach a state change**, followed one to
     three calls deep so the repository's documented guard-chain delegation
     (`handle_http()` → `handle_request()`) is understood rather than flagged;
   - no `*_nopriv_*` registration;
   - every REST route declares a `permission_callback`, and a non-read-only
     route never pairs with `__return_true`;
   - every protected, REST-exposed meta declares an `auth_callback`, and never
     `__return_true`;
   - coverage floors (≥25 handlers, ≥2 routes, ≥10 meta registrations) so a gate
     that audited nothing cannot pass;
   - **self-proofs**: each detector is re-run on a synthetic violation and MUST
     report it.
2. **`wp-content/themes/conexao-br-irlanda/tests/test-public-rest-write-rejection.php`** —
   closes the read-only gap with real `rest_do_request()` calls: the contract
   read from the live REST registry, anonymous reads still 200, POST/PUT/PATCH/
   DELETE refused with `rest_no_route` (404 — WordPress's real answer for a
   matched path with an unsupported method), and a content fingerprint proving
   no row was created.

**Rejected:** an allowlist of "endpoints we trust". A list cannot notice the
endpoint added tomorrow, which is exactly the failure mode being fixed.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `tests/scripts/verify-write-endpoint-authorization.py` | **new** gate | durable enforcement |
| `wp-content/themes/conexao-br-irlanda/tests/test-public-rest-write-rejection.php` | **new** suite | proves the public routes stay read-only |
| `docs/reports/2026-11-27-rest-admin-security-audit.md` | **new** report | the findings and the limits of the review |
| `docs/testing.md` | add the gate to the permanent-gate table | §9.2 |

## 7. Files explicitly expected NOT to change

No `wp-content/plugins/*/includes/**` file — **zero handler code changes**,
because the review found no genuine vulnerability in scope. `plugins.json`,
`composer.json`, `phpstan.neon.dist`, `phpcs.xml.dist` and the workflow are
untouched.

## 8. Content / data impact

None. The new suite is read-only; it creates no post, term, user or option.

## 9. Route / HTTP impact

No route change. The suite asserts the existing contract.

## 10. Polylang / English impact

None.

## 11. Security impact

Positive and additive: the write surface becomes machine-checked. No permission
check is relaxed anywhere; the gate fails closed on an unresolvable hook constant.

## 12. Performance impact

None at runtime. The gate is a static file read.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| What is the verification of the production result? | n/a — static + local in-process only |

## 14. Test plan

- `python3 tests/scripts/verify-write-endpoint-authorization.py` → 0 failed, and a
  real mutation (`wp_ajax_nopriv_`) makes it exit 1.
- `./scripts/run-tests.sh --only conexao-br-irlanda` → the new suite passes and no
  other theme suite regresses.
- Existing data-model and admin-ux suites unchanged.

## 15. Acceptance matrix plan

None (no request-visible change to a public route).

## 16. Rollback plan

`git revert`: the gate and the suite disappear; no runtime behaviour changes, so
there is nothing else to undo.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/reports/2026-11-27-rest-admin-security-audit.md` | findings, classifications, limitations |
| `docs/testing.md` | list the new permanent gate |

## 18. Release implications

None. Tests-and-tooling only → no component version bump (§11).

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Gate false positives on a legitimate delegation | Medium | delegation is followed to depth 3; the nonce rule applies only where a write is reachable |
| Gate rot (a detector silently stops matching) | Medium | self-proofs + coverage floors |
| Overstating coverage | Medium | the report states exactly what was and was not inspected |
| Scope creep into refactoring working handlers | Medium | non-goal; zero handler files change |

## 20. Verification gates

1. New gate → `17 passed, 0 failed` on the real tree.
2. Mutation (`wp_ajax_nopriv_` added) → exit 1; reverted → exit 0.
3. New in-process suite → `43 passed, 0 failed`.
4. Theme component run → no regression beyond pre-existing local failures.

## 21. Completion criteria

Standard §14 plus the security-report obligations of the request: findings
classified, remediation stated where applicable, the reviewed surface and the
unreviewed surface named explicitly.

_Last verified: 2026-11-27 by the REST/admin security audit plan_

