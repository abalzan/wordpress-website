# Comparison report — Stage 3.6

**Verdict: BLOCKED — CANARY MECHANISM FAILED.** No provider manifest exists for
any of the 76 targets. One of the eight URLs was requested; the provider answered
with an AWS WAF CAPTCHA challenge (`HTTP 405`, `x-amzn-waf-action: captcha`) on
every attempt, so no discovery listing was ever served.

---

## 1. Overall result

| Classification | Targets |
|---|---|
| **PRESENT** | **0** |
| **ABSENT** | **0** |
| **INDETERMINATE** | **76** |
| **Total** | **76** |

Per source:

| Source | Targets | Present | Absent | Indeterminate | Why |
|---|---|---|---|---|---|
| `eventbrite_offaly` | 2 | 0 | 0 | 2 | WAF CAPTCHA (HTTP 405) on all 3 requests |
| `eventbrite_meath` | 22 | 0 | 0 | 22 | not attempted (canary gate) |
| `eventbrite_laois` | 17 | 0 | 0 | 17 | not attempted (canary gate) |
| `eventbrite_mayo` | 14 | 0 | 0 | 14 | not attempted (canary gate) |
| `eventbrite_kerry` | 7 | 0 | 0 | 7 | not attempted (canary gate) |
| `eventbrite_westmeath` | 5 | 0 | 0 | 5 | not attempted (canary gate) |
| `eventbrite_clare` | 5 | 0 | 0 | 5 | not attempted (canary gate) |
| `eventbrite_leitrim` | 4 | 0 | 0 | 4 | not attempted (canary gate) |
| **Total** | **76** | **0** | **0** | **76** | — |

## 2. What the provider evidence establishes

1. **The configured discovery URLs are still the registry's URLs.** All eight
   source ids resolved to an `active` registry entry with a distinct
   `https://www.eventbrite.ie/d/ireland--<county>/all-events/` URL.
2. **A plain, unauthenticated HTTP GET from this environment is refused by AWS
   WAF bot control.** Deterministic across three requests over 46 seconds, from a
   CloudFront edge (`MAN51-P2`), with `x-amzn-waf-action: captcha`.
3. **The refusal is not a rate limit.** No `429`, no `Retry-After`, no
   `X-RateLimit-*`. The plugin's rate-limit path was never entered.
4. **The plugin's registered discovery URLs are not being harvested by this
   stage.** Consequently Stage 3.6 adds **no** upstream membership evidence for
   any of the 76 events.

## 3. What the provider evidence does **not** establish

* It does **not** show that any event is absent. A CAPTCHA page is not an empty
  listing; classifying a target absent from it would be exactly the
  `source_not_found` mistake this chain is investigating.
* It does **not** show that any event is present upstream either — an event cannot
  be inferred present from a challenge page.
* It does **not** establish anything about provider-side publication validity,
  cancellation state, ticket availability or suitability for restoration.
* It does **not** contradict the registry's last successful imports
  (2026-11-14 14:02–14:31) — those came from the WordPress container through a
  different HTTP stack and are simply not reproduced by this harness.

## 4. Absence standard

**No ABSENT classification was issued, so no absence claim needs to be defended.**
Every one of the 76 targets is `INDETERMINATE` with an explicit reason in
`05-target-comparison.tsv`. The strict §4.4 gate (two complete stable passes, G8
ceiling not exceeded, identical unique-ID sets and pagination metadata, no gaps or
unstable pagination) could not be evaluated even once, because page 1 never
returned a listing.

## 5. Expected outcome distribution (§8.1) — acknowledgement

§8.1 predicted a *high INDETERMINATE rate with sparse ABSENT* and warned that this
is intended, not a failure. The outcome here is more extreme and of a **different
kind**: **100% INDETERMINATE, 0 PRESENT, 0 ABSENT**, caused by provider access
refusal before any pagination was observed.

* It does **not** match §8.1's expectation: §8.1 assumes listings are served and
  the budget/truncation rules thin out the confident answers. Here nothing was
  served, so the mechanism that §8.1 describes was never exercised.
* No URL exceeded the per-URL request cap (max used: 3 of 30) or the 120 s per-URL
  budget. The global budget is barely touched (3 of 200). This is **not** a budget
exhaustion outcome either.
* The high INDETERMINATE count is therefore **not** the benign "large source"
  indeterminacy that §8.1 sanctions; §8.1's guidance must not be used to soften
  this result into a PASS. The correct verdict is BLOCKED, not PASS WITH
  LIMITATION, because the canary mechanism itself failed.

## 6. Completion-criteria assessment (§8)

| Criterion | Result |
|---|---|
| Stage 3.5 evidence committed before the first provider request | **PASS** — HEAD `d41253b`, 15 tracked files, clean |
| Harness proven not to bootstrap WordPress or the plugin | **PASS** — `harness/isolation-check-output.txt` |
| Offaly canary mechanism passes before other URLs | **FAIL** — no listing served; response structure unexpected |
| Every unique target URL attempted, or a precise reason recorded | **PASS** — 1 attempted, 7 with the §5 gate reason recorded |
| URL-to-source-ID grouping recorded and used for budget accounting | **PASS** — 8 URLs, 1:1, recorded in `00-` and `06-` |
| All 76 target rows have a presence classification | **PASS** — all 76 `INDETERMINATE` with reasons |
| ABSENT used only under full G8/G10 completeness | **PASS** (vacuously) — zero ABSENT issued |
| Request count, hashes, bytes, elapsed, rate limits, budget per URL | **PASS** — `04-request-log.md`, `08-rate-limit-and-budget.md` |
| No event/status/source/registry/log/history/continuation/cron/cohort change | **PASS** — `09-preservation-confirmation.md` |
| No importer / reconciliation / continuation / cron / retention run | **PASS** |
| No shared DB write | **PASS** — `@@transaction_read_only = 1` |
| `git diff -- wp-content/` empty | **PASS** |
| Point-in-time caveat and restoration boundary explicit | **PASS** — §7 and `11-restoration-plan-addendum.md` |
| Stage 2/3/3.5 verdicts and evidence unchanged | **PASS** |
| No commit, push, deploy, activate or release | **PASS** |

The single FAIL is decisive: §8 states that if the canary mechanism or isolation
fails, the report is **BLOCKED**, and "[n]ever upgrade an indeterminate result
merely to achieve a PASS".

## 7. Point-in-time caveat

Even had listing pages been served, every manifest and comparison would be a
point-in-time observation of a live ranking with no provider snapshot token: a
present event can disappear and an absent event can reappear, and two matching
passes are operational evidence, not a transactional snapshot. Here the stronger
statement applies: **no observation exists at all**, so nothing in this folder is
time-sensitive evidence about the 76 events. Fetch timestamps of the three refused
requests are `2026-11-15T20:33:43Z`, `20:34:12Z` and `20:34:29Z`; any future
verification must be re-taken fresh immediately before a decision.

## 8. What would unblock this stage

Not attempted here, and not authorised here — recorded only so the next decision
is informed:

1. A fetch path that the provider's bot control accepts without circumventing it
   (e.g. an environment whose egress is not challenged, or an operator-supplied,
   explicitly authorised export of the listing pages).
2. Re-running this exact harness, unchanged, against such a path — the isolation
   proof, the G8/G10 logic, the ledger format and the classification rules are
   already in `harness/` and need no redesign.
3. Any decision to raise the 30-request per-URL cap remains a separate,
   separately-authorised change; this stage did not raise it.

Explicitly **not** a solution: solving the CAPTCHA by browser automation, or
spoofing browser headers beyond the configured User-Agent — both are prohibited by
§6.2 and would turn an access refusal into deliberate bot-control evasion.
