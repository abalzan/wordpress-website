# Stage 6 — HTTP acceptance matrix (after)

- Base URL: `http://127.0.0.1:8765`
- Pairs tested: **1** (every translated job — no sampling)
- Checks passed: **57** / failed: **0** (total 57)

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
| AFTER: /en/jobs/ lists exactly the EN jobs | ✅ |
| no duplicate job card on /en/jobs/ | ✅ |
| every /en/jobs/ card links to an EN job detail | ✅ |
| AFTER: EN card for opportunities is listed and links to the EN job | ✅ |
| AFTER: no PT-primary card for oportunidades on /en/jobs/ | ✅ |
| card title is the EN title for opportunities | ✅ |
| PT job oportunidades is 200 | ✅ |
| PT job oportunidades lang pt-BR | ✅ |
| PT job oportunidades self-canonical | ✅ |
| PT job oportunidades title in <title> | ✅ |
| PT job oportunidades has no B2 notice | ✅ |
| PT job oportunidades hreflang en → EN job | ✅ |
| PT job oportunidades x-default → PT | ✅ |
| AFTER: /en/empregos/oportunidades/ redirects to the PT job (replaced master) | ✅ |
| EN job opportunities is 200 | ✅ |
| EN job opportunities lang en-US | ✅ |
| EN job opportunities self-canonical | ✅ |
| EN job opportunities EN title in <title> | ✅ |
| EN job opportunities has no B2 notice | ✅ |
| EN job opportunities hreflang en → itself | ✅ |
| EN job opportunities hreflang pt-BR → PT job | ✅ |
| EN job opportunities x-default → PT | ✅ |
| EN job opportunities EN meta description | ✅ |
| EN job opportunities breadcrumb has 3 levels | ✅ |
| EN job opportunities breadcrumb Jobs → /en/jobs/ | ✅ |
| EN job opportunities breadcrumb ends on the EN title | ✅ |
| EN job opportunities has no PT body leak | ✅ |
| EN job opportunities has no PT body leak (2) | ✅ |
| PT search finds the PT job (oportunidades) | ✅ |
| EN search finds the EN job (opportunities) | ✅ |
| EN search for opportunities does not duplicate the PT job URL | ✅ |
| PT search for oportunidades does not surface EN job URLs | ✅ |
| sitemap.xml is 200 | ✅ |
| sitemap lists the PT job exactly once (oportunidades) | ✅ |
| sitemap lists the EN job exactly once (opportunities) | ✅ |
| B2 probe job created | ✅ |
| STATE A: untranslated PT job under /en/ is 200 (B2 shell) | ✅ |
| STATE A: B2 notice present on the probe | ✅ |
| STATE A: probe renders the PT body under EN chrome | ✅ |
| STATE A: probe canonical → PT | ✅ |
| STATE A: the untranslated probe appears on /en/jobs/ as a B2 card (canonical PT URL) | ✅ |
| probe removed from /en/jobs/ after deletion | ✅ |
| blog archive /blog/ is 200 | ✅ |
| blog archive /blog/ lang pt | ✅ |
| blog archive /en/blog/ is 200 | ✅ |
| blog archive /en/blog/ lang en | ✅ |
