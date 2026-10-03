# HTTP AFTER — Laois events visibility

Captured: 2026-09-26T17:33:07Z — local Docker http://localhost:8080

## Plain /eventos (ordering/pagination must be unchanged)

| URL | HTTP | cards | target posts (100001-100030) |
|---|---|---|---|
| /eventos/ | 200 | 10 | 0 |
| /eventos/page/13/ | 200 | - | 6 |
| /eventos/page/14/ | 200 | - | 10 |
| /eventos/page/20/ | 200 | - | 5 |
| /eventos/page/23/ | 200 | - | 3 |
| /eventos/page/25/ | 200 | - | 3 |
| /eventos/page/28/ | 200 | - | 1 |
| /eventos/page/32/ | 200 | - | 2 |

## County filter — the fix target

| URL | HTTP | cards | target posts (100001-100030) |
|---|---|---|---|
| /eventos/?county=laois (paged=1) | 200 | 10 | 8 |
| /eventos/?county=laois (paged=2) | 200 | 10 | 10 |
| /eventos/?county=laois (paged=3) | 200 | 10 | 9 |
| /eventos/?county=laois (paged=4) | 200 | 10 | 3 |
| /eventos/?county=laois (paged=5) | 200 | 10 | 0 |
| /eventos/?county=laois (paged=6) | 404 | 0 | 0 |

**Target events found across the county filter: 30 / 30**

## Other counties must be unaffected

| URL | HTTP | cards | target posts |
|---|---|---|---|
| /eventos/?county=dublin | 200 | 10 | 0 |
| /eventos/?county=kerry | 200 | 10 | 0 |
| /eventos/?county=cork | 200 | 10 | 0 |
| /eventos/?county=donegal | 200 | 10 | 0 |

## Language

| URL | HTTP | cards | target posts |
|---|---|---|---|
| /en/eventos/ | 200 | 10 | 0 |
| /en/eventos/?county=laois | 200 | 10 | 8 |
