# EN PT SOURCE RECONCILIATION — the seven authored EN rows

| | |
|---|---|
| **Task** | Identify the real current PT-BR source for the 7 remaining authored EN rows whose PT source key no longer resolves |
| **Date** | 2026-09-30 |
| **Branch** | `i18n` |
| **HEAD at start** | `6bc4a6be8654cc083a162445491227a278ce0a47` (unchanged) |
| **Production target** | `https://conexaobr.ie` — **read-only, `GET` only** |
| **Production writes** | **0** |
| **EN records created** | **0** |
| **Repository source changes** | **0** |
| **Status** | **BLOCKED — PT-BR SOURCE RECONCILIATION NEEDS HUMAN REVIEW** (6 of 7 identified with HIGH confidence; 1 genuinely absent) |

> This phase is **investigation only**. Nothing was re-keyed, no manifest was
> regenerated, no dry-run was run, no snapshot was taken and nothing was applied.
> The previous dry-run report was not modified.

---

## 1. The headline finding

**Six of the seven PT-BR sources exist in production — in the TRASH.**

The previous dry-run searched the *published* PT corpus and concluded these seven
rows had no PT source. That was correct about the **published** corpus and wrong
about **production**. A `status=trash` sweep of all three B1 post types returns
exactly 6 trashed posts, and their IDs are **precisely** the six historical PT IDs
of rows 2–7:

```
Local-instance capture (commit a5f4de4), ids 10348..10389  : 42

---

## 2. Reconciliation table

| # | Stage | Old PT key | Authored EN slug | Actual PT type | Actual PT slug | PT ID | Confidence | Matching evidence | Action |
|---|---|---|---|---|---|---:|---|---|---|
| 1 | `en-guide` | `learner-permit-theory-test-irlanda-cnh-brasileira` | `brazilian-driving-licence-in-ireland-theory-test-and-learner-permit` | `guide` | — | **absent** | **none** | historical id 10009 recorded; absent from published, trash and all 8 post types; best candidate scores 0.043 | **BLOCKED — SOURCE NOT FOUND** |
| 2 | `en-post` | `auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda` | `health-and-wellbeing-support-in-ireland` | `post` | `auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda__trashed` | **10373** | **HIGH** | id + slug + title + date match the historical record exactly; in trash | **RE-KEY** (after un-trash) |
| 3 | `en-post` | `auxilios-para-familias-atipicas-na-irlanda` | `support-for-extraordinary-families-in-ireland` | `post` | `auxilios-para-familias-atipicas-na-irlanda__trashed` | **10374** | **HIGH** | id + slug + title + date match exactly; in trash | **RE-KEY** (after un-trash) |
| 4 | `en-post` | `cursos-e-apoio-para-empreendedores` | `courses-and-support-for-entrepreneurs` | `post` | `cursos-e-apoio-para-empreendedores__trashed` | **10369** | **HIGH** | id + slug + title match; content identical to historical PT (seq 1.0000); 4/4 URLs shared with the EN | **RE-KEY** (after un-trash) |
| 5 | `en-post` | `dica-de-saude-para-quem-viaja` | `a-health-tip-for-travellers` | `post` | `dica-de-saude-para-quem-viaja__trashed` | **10367** | **HIGH** | id + slug + title match; content identical to historical PT; EN is a byte-level translation (seq 0.9935) | **RE-KEY** (after un-trash) |
| 6 | `en-post` | `guia-para-quem-esta-com-dificuldades-financeiras` | `guide-for-those-facing-financial-difficulties` | `post` | `guia-para-quem-esta-com-dificuldades-financeiras__trashed` | **10375** | **HIGH** | id + slug + title + date match exactly; in trash | **RE-KEY** (after un-trash) |
| 7 | `en-post` | `guia-pratico-para-brasileiros-em-laois` | `practical-guide-for-brazilians-in-laois` | `post` | `guia-pratico-para-brasileiros-em-laois__trashed` | **10376** | **HIGH** | id + slug + title + date match exactly; in trash | **RE-KEY** (after un-trash) |

**Totals: 6 RE-KEY — HIGH CONFIDENCE · 1 BLOCKED · 0 REQUIRES HUMAN REVIEW · 0 MEDIUM**

### Per-row detail


### Rejected alternatives (and why)

| Candidate | Considered for | Rejected because |
|---|---|---|
| guide `25031` `carteira-motorista-brasileiros` | row 1 | Already the accepted PT source of the previously-resolved row 2 — claiming it twice would create two EN identities for one PT record. Content also fails: the row-1 EN body scores **seq 0.0064 / jaccard 0.0444** against 25031's real 9 201-character body. It is a different article (can a CNH be used / exchanged / does it need an IDP). |
| post `10386` `o-que-todo-pequeno-empreendedor…` | row 5 | A different article that merely lists `coachpatriciavidal` among several links. **seq 0.0134** against the row-5 EN body. A shared social link is not content identity. Already claimed by its own EN row. |
| post `10368` `capacitacao-em-laois…` | row 4 | A different article (a list of English-course providers in Co. Laois) sharing only 2 tokens. **seq 0.0079**. Six of the nine distinctive markers of the real PT 10369 (LEADER 2023, Laois Chamber Alliance, R32 VY22, Laois Business Awards, `laois.ie/business-and-economy`, the `laoispartnershipcompany` Instagram) appear in **no** record at all. Already claimed. |
| page `11293` `saude`, page `22` `familia`, page `37` `laois` | rows 2, 3, 7 | Wrong post type, generic one-word titles, already claimed. |
| every other live record | all rows | Full similarity ranking of all 136 published records against each authored EN body: best false-positive jaccard 0.081, sequence 0.017. Noise. |

---

## 3. Why four rows could not be content-matched — and why that is not a gap

Rows 2, 3, 6 and 7 have an authored EN body of **8 bytes: `<p></p>`**. The
historical PT body was **7 bytes: `<p></p>`**. The English is a *faithful and
complete* translation of an empty PT placeholder.

An empty body cannot be content-matched, because there is no content on either
side. For these four rows the identity evidence is therefore Level 1 only — the
recorded historical record id, corroborated by an exact slug, title and date
match. That is stronger evidence than a content match, not weaker: it is
identity, not inference. The Stage-M commit message records the decision
explicitly:

> "four are empty placeholders published with an empty body … They are translated
> faithfully as published. They are NOT deleted and NOT filled in with invented
> articles."

---

## 4. Row 1 — the one genuine `BLOCKED`

The authored EN row is a complete, structured guide (Introduction / Who this is
for / Step by step / Documents / Costs / Timelines / Common mistakes / Official
sources). Its PT original was **guide 10009**, 5 649 bytes, recorded in
`pt-snapshot-pre.json` with a content hash. That record:

* is **not** among the 56 published guides (the 10001–10016 band has exactly one
  gap, at 10009);
* is **not** in the trash (the trash holds only guides 463 and 24974);
* is **not** retrievable by direct id across any of the 8 post types with
  `status=any`;
* has **no** `__trashed` slug variant.

Exhaustive search of all 154 records (136 published + 18 trashed) found no record
reproducing its content. The nearest same-subject record, guide 25031, is a
different article and is already owned by row 2.

Per the task's own rule — *"`BLOCKED — SOURCE NOT FOUND` … Do not use it merely
because the original slug is absent"* — this row is not blocked on a missing
slug. It is blocked because an exhaustive search of the repository **and** of
production, in every post status including trash, failed to identify the known
PT-BR article. Guide 10009 was hard-deleted, not trashed. No HIGH or MEDIUM
mapping is proposed for it, and none should be invented.

**This is the one decision the operator must make**, and it is editorial:
republish the guide's PT source, or retire the authored EN row.

| # | PT title | EN title | Source environment | Historical evidence | Content-match evidence |
|---|---|---|---|---|---|
| 1 | *(gone)* CNH brasileira na Irlanda: quando você precisa fazer theory test e learner permit | Brazilian Driving Licence in Ireland: When You Need the Theory Test and Learner Permit | local instance only | `pt-snapshot-pre.json` id 10009, 5 649 B, sha256 `baec746f…`; `dry-run.json` maps the key to pt_id 10009 | n/a — no record exists to match against |

---

## 5. The prerequisite the operator must authorise

Rows 2–7 are correctly identified, but a re-key alone **will not resolve them**,
because the shared engine matches a *live, published* PT record. The six sources
are in the trash.

Un-trashing six PT posts is a **production content write**, which this phase is
forbidden to perform and which the task's outcome vocabulary does not offer. So
it is reported, not acted upon. Before the next phase can produce a clean
dry-run, the operator must authorise either:

* **(a) un-trash** the six PT posts, or
* **(b) republish** them under new identities.

**Recommended key for a re-key, once authorised:** the authored key stays the PT
`post_name` (`auxilios-…-na-irlanda`, etc.), **not** the `__trashed` variant and
**not** the production id. The engine resolves by PT stable key; the `__trashed`
suffix is a WordPress artefact of the trash state and would resolve to nothing.
Production ids are recorded here as verification evidence only, per the task's
constraint.

---

## 6. English payload integrity

A re-key changes **only** the array key. The payload SHA-256 of every row is
recorded in
[`06-en-payload-integrity.txt`](evidence/2026-09-30-en-pt-source-reconciliation/06-en-payload-integrity.txt)
and must be byte-identical after any approved edit. All seven rows are **B1**
(`object_type=record`) and that classification is unaffected by a re-key. EN
title, body, excerpt, meta description, slug, stage and B1/B2 classification are
all preserved.

| Row | Payload SHA-256 |
|---|---|
| 1 | `077375252537f6e2c75f6d7e521516e7145f79842e5af542c7f658e8b830421f` |
| 2 | `7516cb3622bc577898fa4cbf69f4954833f3b5a07fdaba46a4b4c37a3421284e` |
| 3 | `74ef13b0f58a5e0783cd47e861eb8010ae5c5afb7780393a877fed62a4c6898e` |
| 4 | `753b830affd6b0f3d1c6a76559f60ed40a5eaeef6be91707a7954d8f4640018d` |
| 5 | `8849f99f6b207ea4f66202d3cb6a3e83c8a0028d74df743330c1d2b8b6a50606` |
| 6 | `117ac22c9eb18f74f9687ccf56b0bec6df1924ab82bd93c6f080b69d11089dc6` |
| 7 | `1dde714b9865e299548451e9bfd894c5b00b01d4f118180454796d3644d6ce6e` |

---

## 7. Source uniqueness

The six trashed records each resolve **one-to-one** to their own historical
record: same id, same `post_name` (plus `__trashed`), same title, same date. No
second plausible candidate exists for any of them, so no row is ambiguous. No
duplicate source, no duplicate target identity, and no conflict with the 2 268 PT
sources already claimed by authored rows. Production holds 0 EN records and 0 EN
translation links, so every EN target slug is free.

Full working: [`04-uniqueness-and-rejected-candidates.txt`](evidence/2026-09-30-en-pt-source-reconciliation/04-uniqueness-and-rejected-candidates.txt).

---

## 8. Safety ledger

| | |
|---|---|
| Production writes | **0** — `GET` only |
| EN records created / links created | **0 / 0** |
| PT records created / modified / deleted / un-trashed | **0 / 0 / 0 / 0** |
| Polylang, taxonomy, menu, option changes | **0** |
| Translation apply / writer / SQL | **none** |
| Repository source changes | **0** |
| Authored EN data / manifests / stages / engine / slug policy / tests | **unchanged** |
| Dry-run re-run / snapshot / apply | **no / no / no** |
| `.env` | **unchanged**, never printed |
| Secrets exposed | **NO** |
| Flutter/mobile repository | **not accessed** |
| Previous dry-run report | **not modified** |

Full ledger:
[`09-safety-ledger.txt`](evidence/2026-09-30-en-pt-source-reconciliation/09-safety-ledger.txt).

---

## 9. Evidence index

| File | Contents |
|---|---|
| [`00-baseline.txt`](evidence/2026-09-30-en-pt-source-reconciliation/00-baseline.txt) | HEAD, working tree, digests, PT protection baseline |
| [`01-methodology.txt`](evidence/2026-09-30-en-pt-source-reconciliation/01-methodology.txt) | Search order, matching hierarchy, why content matching is exhaustive |
| [`02-historical-pt-identity.txt`](evidence/2026-09-30-en-pt-source-reconciliation/02-historical-pt-identity.txt) | The real historical PT id of each of the seven rows, and the exact id-gap arithmetic |
| [`03-live-pt-corpus-search.txt`](evidence/2026-09-30-en-pt-source-reconciliation/03-live-pt-corpus-search.txt) | Live published-corpus search: slugs, ids, entities, similarity rankings, rejected candidates |
| [`04-uniqueness-and-rejected-candidates.txt`](evidence/2026-09-30-en-pt-source-reconciliation/04-uniqueness-and-rejected-candidates.txt) | One-to-one check and every rejected alternative |
| [`05-production-read-log.txt`](evidence/2026-09-30-en-pt-source-reconciliation/05-production-read-log.txt) | Raw production read log (published + trash) |
| [`06-en-payload-integrity.txt`](evidence/2026-09-30-en-pt-source-reconciliation/06-en-payload-integrity.txt) | Per-row payload SHA-256, EN slug/title/body, B1/B2 |
| [`07-trash-source-verification.txt`](evidence/2026-09-30-en-pt-source-reconciliation/07-trash-source-verification.txt) | The six trashed sources verified against the authored EN rows |
| [`08-row1-exhaustive.txt`](evidence/2026-09-30-en-pt-source-reconciliation/08-row1-exhaustive.txt) | Row 1: every state checked, 154 records examined |
| [`09-safety-ledger.txt`](evidence/2026-09-30-en-pt-source-reconciliation/09-safety-ledger.txt) | Full safety ledger |

---

## 10. Recommended next step (not performed)

1. **Operator decides** row 1: republish the PT source of guide 10009, or retire
   the authored EN row.
2. **Operator authorises** a production content action for the six trashed PT
   posts: un-trash, or republish under new identities.
3. Only then: apply the six approved source-identity corrections → regenerate the
   EN manifest → rerun the dry-run → snapshot → verify a deterministic
   zero-blocker plan.

This phase stops here. **No data was changed.**

| 2 | Auxílios e Apoio Relacionados a Saúde e Bem-Estar na Irlanda | Health and Wellbeing Support in Ireland | production (trash) + local capture | id 10373, date 2026-03-20T11:08:34 in both | PT and EN bodies are both empty placeholders — faithful, complete |
| 3 | Auxílios para Famílias Atípicas na Irlanda | Support for Non-Standard Families in Ireland | production (trash) + local capture | id 10374, date 2026-03-20T11:10:52 in both | both empty placeholders — faithful, complete |
| 4 | Cursos e Apoio para Empreendedores | Courses and Support for Entrepreneurs | production (trash) + local capture | id 10369, date 2026-03-19T16:46:20 in both | **strongest row**: trashed PT body ≡ historical PT body (seq 1.0000); EN shares all 4 URLs incl. `laoispartnership.ie`, `…/contact-us/`, `laois.ie/business-and-economy/…` |
| 5 | Dica de Saúde Para Quem Viaja | A Health Tip for Travellers | production (trash) + local capture | id 10367, date 2026-03-17T20:43:02 in both | byline stub; EN is a byte-level translation (seq 0.9935), same Instagram URL |
| 6 | Guia Para quem está com Dificuldades Financeiras | A Guide for Anyone Facing Financial Difficulties | production (trash) + local capture | id 10375, date 2026-03-20T11:13:33 in both | both empty placeholders — faithful, complete |
| 7 | Guia Prático para Brasileiros em Laois | A Practical Guide for Brazilians in Laois | production (trash) + local capture | id 10376, date 2026-03-20T11:15:20 in both | both empty placeholders — faithful, complete |

Live production published, same band                        : 36
In the capture but not published                            : 10367, 10369, 10373, 10374, 10375, 10376
In the trash, with the SAME id, post_name and title          : all six
```

The records were never renamed, re-typed or moved. They were trashed, and
WordPress appended `__trashed` to each `post_name`, which is why a slug search
never found them. The operator's statement — "these articles exist in PT-BR" — is
confirmed, with one exception in §4.

**Row 1 is the single exception.** Its historical PT guide (id 10009) does not
exist in production in *any* state: not published, not in the trash, not under
any other post type.
