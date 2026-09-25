# Stage 7 — card-hash evidence (PT unchanged / EN translated)

SHA-256 over the concatenated `post-id|rendered-card-excerpt` lines of all 289
archive cards, from the HTTP captures (`http-before.json`, `http-after.json`),
validation clone at `http://127.0.0.1:8765` with the real production Leisure dataset.

| Capture | Cards | SHA-256 |
|---|---|---|
| BEFORE — `/lazer/` (PT) | 289 | `4300a7ca7d2b0e708b69feec2db874f61e5899bc5568b94217af11598acb40ee` |
| BEFORE — `/en/lazer/` (EN) | 289 | `4300a7ca7d2b0e708b69feec2db874f61e5899bc5568b94217af11598acb40ee` |
| AFTER — `/lazer/` (PT) | 289 | `4300a7ca7d2b0e708b69feec2db874f61e5899bc5568b94217af11598acb40ee` |
| AFTER — `/en/lazer/` (EN) | 289 | `bea00800756f66e1cd50d4139f6abb837e9ca78b41aadad765b2fa01c9075c17` |

Reading:

- **PT output byte-identical before/after** (same hash across the whole 289-card
  set) — Portuguese descriptions changed = 0.
- **BEFORE: the EN archive rendered exactly the PT card set** (identical hash) —
  the documented Stage 7 defect (Portuguese text inside `.leisure-card-excerpt`
  on `/en/lazer/`).
- **AFTER: the EN archive hash differs** because every card now renders its
  authored English description (verified card-by-card in `http-after.md`:
  each excerpt equals the authored translation trimmed to 18 words and differs
  from the Portuguese excerpt).

The PT cards were additionally verified against the production rendering itself:
each PT excerpt equals the excerpt captured from `https://conexaobr.ie/lazer/`
for the same record (289/289, see `leisure-description-inventory.md`).
