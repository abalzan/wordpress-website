# Stage 5 precondition audit (read-only, 2026-09-30)

Branch i18n, HEAD 078b2d8. No production write was attempted. No production
credential was used, printed or stored.

## Production access channel

| Fact | Value | How established |
|---|---|---|
| Production host | `conexaobr.ie` (WordPress.com) | `docs/deployment.md` |
| Public reachability | HTTP 200 on `/`, `/wp-login.php`, `/wp-json/` | `curl`, read-only, anonymous |
| Deployment method | **Manual ZIP upload via wp-admin** | `docs/deployment.md` |
| Production SSH / WP-CLI / filesystem / DB | **none** | `AGENTS.md`, `docs/releases.md` |
| `WP_USERNAME` (env) | **UNSET** | `env` |
| `WP_APPLICATION_PASSWORD` (env) | **UNSET** | `env` |
| `CONEXAO_SITE_URL` (env) | **UNSET** | `env` |
| `.env` credentials | local Docker only, documented NOT production | `.env` comments, `.env.example` |

`scripts/lib/rest.py` resolves `CONEXAO_SITE_URL` or falls back to
`http://localhost:8080`, and by design has **no production default**. With no
production credential and no CLI, no agent-run install, activation, baseline
capture, dry-run or apply is possible.

## The twelve preconditions (section 2)

| # | Precondition | Result | Evidence |
|---|---|---|---|
| 1 | Production installation authorisation | **ABSENT** | No operator present; deployment is a manual wp-admin upload |
| 2 | Authorisation for live provider smoke test | PRESENT | This task |
| 3 | Provider credential via approved env-only mechanism | **PARTIAL** | `CONEXAO_TRANSLATION_PROVIDER_KEY` unset; a non-approved `OPENAI_API_KEY` (164 chars) is present |
| 4 | No credential in WP options/DB | HOLDS (vacuously) | `Provider_Config` is env-only by construction; no install occurred |
| 5 | Production baseline procedure ready | **ABSENT** | Needs authenticated production read; no credential |
| 6 | Nonce-protected dry-run trigger available | **ABSENT** | See "entry point" below |
| 7 | Human approval bound to plan digests | Present **in code only** | `Apply_Gate`; no UI, no production install |
| 8 | F7 apply trigger exists and authorised | Present **in code only** | `Apply_Gate`; APPLY unreachable in production |
| 9 | Audit retention validated for production volume | **NOT VALIDATED** | `RETENTION = 20`; never exercised in production |
| 10 | Deletion policy `manual_intervention_required` | HOLDS | `Change_Detector::DISPOSITION_MANUAL` |
| 11 | Existing safety gates pass except documented pre-existing | HOLDS | 3 pre-existing failures, all documented, none new |
| 12 | Exact engine checksum recorded | **HOLDS** | `baf85283…a6ce4`, before and after |

## Production entry point: does not exist

Structural scan of `wp-content/plugins/conexao-translation-automation/` for
`add_menu_page`, `add_submenu_page`, `admin_menu`, `admin_post`, `wp_ajax`,
`register_rest_route`, `check_admin_referer`, `wp_verify_nonce`,
`current_user_can`:

**zero hits in runtime code.** Every match is inside a *test* file asserting
the surface is absent. The plugin is an in-process PHP API only
(`Trigger::fire()`), with no HTTP, REST, admin or cron surface. Trigger
commissioning (section 11) therefore requires **new code**, not configuration.

## Live provider probe (read-only, real external call)

Run inside the local container with the env-only credential, fixed
non-production fixture, no WordPress content read or written:

1. `wp_remote_post()` to the real endpoint **succeeded at the transport layer** —
   the request reached OpenAI and was refused with **HTTP 429
   `insufficient_quota`** ("You have no credits remaining"). Connectivity and
   credential transport are proven; **the account has no billing credit**, so no
   successful smoke test is possible.
2. The smoke test itself returned
   `conexao_automation_provider_bad_transport`.

## DEFECT: the real transport response shape is not handled

`Provider_OpenAI::normalise()` accepts a response only when
`is_array($response) && isset($response['code'])`.

A real `wp_remote_post()` return has top-level keys
`headers, body, response, cookies, filename, http_response`; the status code is
at `$response['response']['code']`, and there is **no top-level `code` key**.

Verified empirically in the container against a real return value:

```
real shape passes normalise()? NO -> bad_transport
flat double shape passes?      YES
```

Every test injects `Provider::set_transport( … )` returning the flat
`{ code, body }` shape, so the entire Stage 4 suite passes while the **real**
`wp_remote_post()` path can only ever yield `bad_transport`. The live path was
never exercised, which is why Stage 4 reported this area as proven.

Consequence: even with billing credit, the first live production provider call
would fail closed. This must be fixed and covered by a real-shape test before
any canary.
