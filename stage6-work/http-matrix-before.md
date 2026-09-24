# Stage 6 — HTTP acceptance matrix (before)

- Base URL: `http://127.0.0.1:8765`
- Pairs tested: **1** (every translated job — no sampling)
- Checks passed: **31** / failed: **0** (total 31)

| Check | Result |
|---|---|
| PT /empregos/ is 200 | ✅ |
| PT /empregos/ lang is pt-BR | ✅ |
| PT /empregos/ self-canonical | ✅ |
| PT /empregos/ lists exactly the public PT jobs | ✅ |
| PT card for oportunidades links to the PT job | ✅ |
| EN /en/jobs/ is 200 | ✅ |
| EN /en/jobs/ lang is en-US | ✅ |
| EN /en/jobs/ self-canonical | ✅ |
| /en/jobs/ hreflang en → itself | ✅ |
| /en/jobs/ hreflang pt-BR → /empregos/ | ✅ |
| /en/jobs/ x-default → PT | ✅ |
| BEFORE: /en/jobs/ shows the B2 set (PT jobs, EN chrome) — not empty | ✅ |
| BEFORE: /en/jobs/ B2 card for oportunidades links to the canonical PT job | ✅ |
| PT job oportunidades is 200 | ✅ |
| PT job oportunidades lang pt-BR | ✅ |
| PT job oportunidades self-canonical | ✅ |
| PT job oportunidades title in <title> | ✅ |
| PT job oportunidades has no B2 notice | ✅ |
| BEFORE: PT job oportunidades emits no EN alternate | ✅ |
| BEFORE: /en/empregos/oportunidades/ is 200 (B2) | ✅ |
| BEFORE: /en/empregos/oportunidades/ shows the B2 notice | ✅ |
| BEFORE: B2 shell lang en-US for oportunidades | ✅ |
| BEFORE: B2 canonical → PT for oportunidades | ✅ |
| PT search finds the PT job (oportunidades) | ✅ |
| sitemap.xml is 200 | ✅ |
| sitemap lists the PT job exactly once (oportunidades) | ✅ |
| BEFORE: sitemap has no EN job URLs (B2 URLs are never indexable) | ✅ |
| blog archive /blog/ is 200 | ✅ |
| blog archive /blog/ lang pt | ✅ |
| blog archive /en/blog/ is 200 | ✅ |
| blog archive /en/blog/ lang en | ✅ |
