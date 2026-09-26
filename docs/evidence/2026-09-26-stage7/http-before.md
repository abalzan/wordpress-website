# HTTP BEFORE — Laois events visibility

Captured: 2026-09-26T17:28:18Z — local Docker http://localhost:8080

## Plain /eventos

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

## County filter

| URL | HTTP | cards | target posts (100001-100030) |
|---|---|---|---|
| /eventos/?county=laois (paged=1) | 200 | 10 | 0 |
| /eventos/?county=laois (paged=2) | 200 | 10 | 0 |
| /eventos/?county=laois (paged=3) | 404 | 0 | 0 |

## Language

| URL | HTTP | cards | target posts (100001-100030) |
|---|---|---|---|
| /en/eventos/ | 200 | 10 | 0 |
| /en/eventos/?county=laois | 200 | 10 | 0 |
