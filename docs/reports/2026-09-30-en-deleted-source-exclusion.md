# EN DELETED-SOURCE EXCLUSION — one authored row retired

| | |
|---|---|
| **Task** | Retire the single authored EN translation whose canonical PT source was permanently deleted |
| **Date** | 2026-09-30 |
| **Branch** | `i18n` |
| **HEAD** | `6bc4a6be8654cc083a162445491227a278ce0a47` (unchanged — no commit) |
| **Production target** | `https://conexaobr.ie` — **read-only, `GET` only** |
| **Production writes** | **0** |
| **EN records created** | **0** |
| **Authored EN rows removed** | **1** |
| **Unrelated EN payload drift** | **0** |
| **New test failures** | **0** |
| **Status** | **PASS — DELETED PT SOURCE EXPLICITLY RETIRED** |

> Repository data correction only. No production write, no EN content created, no
> translation link created, no PT content touched, no apply, no deploy, no SQL.
> The six trashed-source rows were **not** restored or modified.

---

## 1. What was retired, and why

| | |
|---|---|
| Stage | `en-guide` |
| PT source key (stable identity) | `learner-permit-theory-test-irlanda-cnh-brasileira` |
| EN slug | `brazilian-driving-licence-in-ireland-theory-test-and-learner-permit` |
| Payload SHA-256 before removal | `077375252537f6e2c75f6d7e521516e7145f79842e5af542c7f658e8b830421f` |
| Classification | `NO_REAL_PT_SOURCE` |
| Reason | `PT source permanently deleted; operator-authorized exclusion` |

### How the deletion was established

The row's PT source was a **guide** whose historical production id was `10009`
(recorded in `docs/evidence/2026-09-28-b1-en-guides/pt-snapshot-pre.json`, 5 649
bytes, content SHA-256 `baec746f96e8fd68…`). The reconciliation investigation
proved it is gone from production in **every** state — not merely unpublished:

| Check | Result |
|---|---|
| In the 56 published PT guides | absent — the 10001–10016 band has exactly one gap, at 10009 |
| In the trash (6 posts, 10 pages, 2 guides swept) | absent — the trash holds only guides 463 and 24974 |
| Direct `GET /wp/v2/{type}/10009?status=any` across all 8 post types | 404 in every post type |
| Any `__trashed` slug variant | none |
| Content reproduced by any of the 154 records examined (136 published + 18 trashed) | no |

The only same-subject live record, guide `25031` `carteira-motorista-brasileiros`,
is a **different article** — it answers "can a Brazilian CNH be used, exchanged,
or does it need an IDP" — and it is **already** the accepted PT source of the
`carteira-motorista-brasileiros` row. Reusing it here would create two EN
identities for one PT record. The row's own authored EN body scores
SequenceMatcher **0.0064** against 25031's real 9 201-character body.

So the source is permanently deleted, the row can never resolve, and the
operator authorised its retirement.

---

## 2. The exact dataset change

| File | Change |
|---|---|
| `includes/guide-translation-data.php` | **one** authored row deleted (71 lines) + a 13-line comment left in its place explaining the retirement |
| `includes/exclusions-data.php` | **NEW** — the exclusion registry (read-only, never fed to the engine) |
| `conexao-en-translation.php` | one `require_once` line so the registry loads |
| `docs/plugins/conexao-en-translation.md` | dataset count 51 → 50, plus a "Retired rows" section |

`git diff --stat` on the plugin: **14 insertions, 72 deletions** — 13 of the
insertions are the comment, 1 is the `require_once`.

### Row counts

| Stage | Before | After |
|---|---|---|
| `en-guide` | 51 | **50** |
| `en-page` | 31 | 31 |
| `en-post` | 43 | 43 |

### English payload integrity

Every remaining row was re-serialised with the repository's own
`json_encode` and hashed. Compared against the pre-change baseline:

| Measure | Result |
|---|---|
| Rows removed | **1** (the target) |
| Rows **changed** | **0** |
| Rows added | **0** |
| **Unrelated EN payload drift** | **0** |

Content, title, body, slug, source identity and stage are unchanged for every
other row. The B1/B2 classification of the retired row was B1; removing a row
does not alter any other row's classification.

### The two previously-resolved rows are not regressed

| Row | Expected | Verified |
|---|---|---|
| `post::outono-irlanda-alimentacao-bem-estar` | PT `post`, id 25028 | present in `en-post`; payload `d784c1a703acf1f6d424193ed2febc13af376cc480685613f70749618b2a1100` — identical to the blocker-resolution report |
| `guide::carteira-motorista-brasileiros` | PT `guide`, id 25031 | present in `en-guide`; old key `carteira-de-motorista-2` correctly **absent**; payload `fdc72265d332718fcb77ae06b0c09a79f6a6181f4bdbe684082b172dce5598a6` — identical |

### The six trashed-source rows are untouched

| Historical PT id | EN row | State after this task |
|---|---|---|
| 10373 | `health-and-wellbeing-support-in-ireland` | present, payload unchanged |
| 10374 | `support-for-extraordinary-families-in-ireland` | present, payload unchanged |
| 10369 | `courses-and-support-for-entrepreneurs` | present, payload unchanged |
| 10367 | `a-health-tip-for-travellers` | present, payload unchanged |

---

## 3. Exclusion metadata — recorded, not silently absent

The decision survives in three places, so a future contributor who meets the
missing row can see it was retired on purpose:

1. **`includes/exclusions-data.php`** — a machine-readable registry:

```php
'learner-permit-theory-test-irlanda-cnh-brasileira' => array(
    'classification' => 'NO_REAL_PT_SOURCE',
    'reason'         => 'PT source permanently deleted; operator-authorized exclusion',
    'stage'          => 'en-guide',
    'en_slug'        => 'brazilian-driving-licence-in-ireland-theory-test-and-learner-permit',
    'retired_on'     => '2026-09-30',
    'evidence'       => 'docs/reports/2026-09-30-en-pt-source-reconciliation.md; …',
),
```

2. **In-place comment** at the exact point in `guide-translation-data.php` where
   the row used to be, naming the deletion, the id, and why 25031 cannot be
   reused.

3. **`docs/plugins/conexao-en-translation.md`** — a "Retired rows" section
   distinguishing a *retired* row from a *portable exclusion*.

The classification `NO_REAL_PT_SOURCE` is the term the EN rollout reports
already use for an authored row with no resolvable PT source. **No fake PT
identity was invented**: the registry records the PT *stable key* (the slug) and
never a local post ID.

The registry is deliberately **not** wired into the rollout engine — a retired
row must not reappear as a plan object, and it does not.

---

## 4. Manifest before / after

Regenerated from current HEAD against live production by the repository's own
read-only inventory tool. The old manifest was **not** hand-edited.

| Metric | Before | After |
|---|---|---|
| `total_source_objects` | 2 275 | **2 273** |
| `CREATE_CANDIDATE` | 432 | 432 |
| `B2_ONLY` | 1 820 | 1 819 |
| `OUT_OF_SCOPE` | 23 | **22** |
| `CONFLICT` / `INVALID` / `ORPHAN` | 0 / 0 / 0 | **0 / 0 / 0** |
| Consistency checks | ALL PASS | **ALL PASS** |
| Manifest digest | `2872808ca243ace1…` | `ca640585242d92c5…` |

| Verification | Result |
|---|---|
| Retired `source_slug` in the new manifest | **0 entries** |
| Retired `target_slug` in the new manifest | **0 entries** |
| No new missing source appeared | confirmed — `missing_source` went **down**, not up |
| No duplicate target identity | **0** |
| All remaining authored rows resolve | yes, except the 6 trashed rows which are unchanged |

---

## 5. Dry-run before / after

The canonical dry-run was re-run read-only against the regenerated manifest.
**No apply. No production mutation.**

| Metric | Before | After |
|---|---|---|
| **`missing_source`** | 7 | **6** |
| `b1_missing_pt_source` | 7 | **6** |
| `b1_skipped_absent_pt_source` | 7 | **6** |
| `b1_creates` | 120 | 120 |

---

## 6. One digest that moved — and why it is not a regression

The dry-run reports `matches_pt_baseline: False`. This is **not** caused by this
task, and the reason was measured rather than assumed:

| Digest | Before | After |
|---|---|---|
| `pt_baseline_identity_digest` (the **stored** baseline) | `cfe4b7a6…` | `cfe4b7a6…` — **unchanged** |
| `identity_digest_all_types` (the **observed** digest) | — | differs |

Isolated by post type, the **only** type whose identity digest moved is
**`event`**:

| Type | Digest moved? |
|---|---|
| `guide`, `page`, `post`, `course_provider`, `job`, `leisure`, `sponsor` | **no** |
| **`event`** | **yes — 1 808 → 1 807 records** |

The events importer re-ran in production between the two reads: 10 event ids
left the set and 10 new ones arrived. **0** events changed status or slug. This
is production-side churn in an unrelated, continuously-imported content type,
and it cannot be caused by a repository-only dataset edit. The stored PT
baseline this repository compares against is unchanged, and `pt_overwrite_attempts`
is 0.

---

## 7. Test results

`./scripts/run-tests.sh` was run **before** and **after** the change, and the two
outputs were diffed line by line.

| Suite | Before | After |
|---|---|---|
| In-process PHP suites | 63 / 63 passed | **63 / 63 passed** |
| Assertions | 4 216 passed, 0 failed | **4 216 passed, 0 failed** |
| Script-contract suites | 5 passed, 1 failed | **5 passed, 1 failed** |
| HTTP acceptance suites | 3 / 3 passed | **3 / 3 passed** |

The `diff` of every `[PASS]`/`[FAIL]` line between the two runs is **empty**.

### Pre-existing failure (unchanged, not introduced here)

```
[FAIL] tests/scripts/verify-i18n-freshness.py
   FAIL: conexao-br-irlanda: …conexao-br-irlanda.pot is older than …/inc/navigation.php
   FAIL: conexao-content:    …conexao-content.pot is older than …/conexao-content.php
   FAIL: conexao-event-runtime: …conexao-event-runtime.pot is older than …php
```

Stale gettext catalogues for **three unrelated plugins**. It failed identically
before this change, concerns no file this task touched, and is recorded in the
repository baseline (`i18n:…:stale_catalogue`). It is **not** suppressed and
**not** fixed here.

### **New failures = 0**

### Static quality — `./scripts/lint.sh`

---

## 8. Production untouched — verified

| Measure | Value |
|---|---|
| HTTP verbs used | **`GET` only** |
| `production_writes` | **0** |
| `apply_invoked` | **False** |
| EN records created | **0** |
| Translation links created | **0** |
| Taxonomy translations created | **0** |
| B2 fields written | **0** |
| PT drift caused by this task | **0** |
| Polylang | **unchanged** — 2 languages, `pt` default, `pt_BR` / `en_US`, `default_lang: pt`, terms 1747 / 1744 |
| Menus | **untouched** |
| `.env` | **unchanged**, never printed |
| Secrets exposed | **NO** |
| Flutter/mobile repository | **not accessed** |
| Six trashed PT posts | **not un-trashed, not restored, not modified** |

---

## 9. Evidence index

| File | Contents |
|---|---|
| `01-en-payload-baseline-before.json` | Payload SHA-256 of all 125 rows before the change |
| `02-en-payload-after.txt` | All rows after + the exclusion registry contents |
| `03-repository-stage-manifests.json` | The repository's own stage manifests, dumped at HEAD |
| `04-en-inventory-manifest.json` | Regenerated inventory manifest (2 273 objects) |
| `05-inventory-run.log` | Inventory run output |
| `06-dry-run-plan.json` | Regenerated dry-run plan |
| `07-mutation-snapshot.json` | Read-only snapshot (432 objects) |
| `08-dry-run.log` | Dry-run output |
| `08-tests-before.log` | Full test suite **before** the change |
| `09-tests-after.log` | Full test suite **after** the change |
| `10-summary-tables.txt` | Dataset change, manifest and dry-run before/after tables |
| `11-safety-ledger.txt` | Full safety ledger |

---

## 10. This is the explicit resolution of ONE of seven

| # | Original issue | State |
|---|---|---|
| 1 | `guide::learner-permit-theory-test-irlanda-cnh-brasileira` | **RESOLVED — retired, operator-authorised** (this task) |
| 2 | `post::auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda` | open — PT id 10373 in **trash** |
| 3 | `post::auxilios-para-familias-atipicas-na-irlanda` | open — PT id 10374 in **trash** |
| 4 | `post::cursos-e-apoio-para-empreendedores` | open — PT id 10369 in **trash** |
| 5 | `post::dica-de-saude-para-quem-viaja` | open — PT id 10367 in **trash** |
| 6 | `post::guia-para-quem-esta-com-dificuldades-financeiras` | open — PT id 10375 in **trash** |
| 7 | `post::guia-pratico-para-brasileiros-em-laois` | open — PT id 10376 in **trash** |

**1 of 7 resolved. 6 remain**, all of them the trashed-source rows, which are a
separate authorised operation.

### Next task (not performed here)

The **six trashed PT source restoration + EN re-key** operation. It requires a
production content write (un-trash or republish), which this task did not and
must not perform. Once that is done, re-key those six rows and re-run the
manifest and dry-run, which should reach `missing_source = 0`.


| Step | Result |
|---|---|
| `php -l` (479 files) | **OK — no parse errors** |
| PHPCS vs baseline | **OK — no new violations** |
| PHPStan level 5 | **OK — no errors** |

### Directly relevant suites

| Suite | Result |
|---|---|
| `test-guide-en-translation.php` | **650 passed, 0 failed** |
| `test-translation-completeness.php` | **23 passed, 0 failed** |
| `test-shared-slug-newsletter.php` | **37 passed, 0 failed** |
| `test-blog-en-translation.php` | **28 passed, 0 failed** |
| `verify-documentation-drift.py` | **14 passed, 0 failed** |
| `verify-release-integrity.py` | **210 passed, 0 failed** |
| `verify-script-conventions.py` | **324 passed, 0 failed** |
| `verify-guides-en-http.py` | **20 passed, 0 failed** |

**No assertion was weakened, removed or skipped.** The test files are untouched.

| `b1_updates` | 0 | 0 |
| `b1_translation_links_planned` | 120 | 120 |
| `b1_already_complete` / `b1_conflicts` | 0 / 0 | **0 / 0** |
| `b2_field_updates` | 299 | 299 |
| `b2_creates_en_records` | 0 | **0** |
| `taxonomy_creates` | 13 | 13 |
| `taxonomy_conflicts` | 0 | **0** |
| `duplicate_target_identities` | **0** | **0** |
| `pt_overwrite_attempts` | **0** | **0** |
| `unclassified_objects` | **0** | **0** |
| `unresolved_slug_conflicts` | **0** | **0** |
| `conflicts` / `invalid` / `orphan` | 0 / 0 / 0 | **0 / 0 / 0** |
| `unexpected_pt_mutation_operations` | 0 | **0** |
| `unexpected_en_existing_objects` | 0 | **0** |
| **Plan digest** | `9c245082e490542e…` | `9c245082e490542e…` (**identical**) |

Only the three missing-source counters changed. The plan digest is
byte-identical, which proves nothing else in the plan moved.

| Required check | Result |
|---|---|
| Deleted row absent from the actionable plan | **yes** — neither the key nor the EN slug appears anywhere in the plan JSON |
| Six trashed-source rows explicitly unresolved | **yes** — all six carry `missing_source: true` in the `en-post` plan |
| `newsletter` still resolved as `newsletter` | **yes** |
| `newsletter-2` anywhere in the plan | **no** |
| `unresolved_slug_conflicts` | **0** |
| Duplicate targets | **0** |
| PT overwrite attempts | **0** |
| Unclassified | **0** |

`missing_source = 6` is the expected outcome. It is **not** 0, and must not be
claimed as 0 until the six trashed PT posts are restored and those rows re-keyed.

| 10375 | `guide-for-those-facing-financial-difficulties` | present, payload unchanged |
| 10376 | `practical-guide-for-brazilians-in-laois` | present, payload unchanged |

No PT post was un-trashed, restored or modified. The EN `transporte`
`conexao_category` term is still required by 7 other guides and was left in
place.
