# Stage 5 — Blog EN translation matrix

Base: http://localhost:8090

| result | check |
|---|---|
| PASS | PT /blog/ 200 |
| PASS | PT /blog/ lang=pt-BR |
| PASS | PT /blog/ self-canonical |
| PASS | PT /blog/ no EN fallback notice |
| PASS | PT /blog/ hreflang pair present |
| PASS | PT /blog/ shows Portuguese post titles |
| PASS | PT /blog/ has PT pagination |
| PASS | PT /blog/page/5/ 404 (out of range) |
| PASS | EN /en/blog/ 200 (no redirect to PT) |
| PASS | EN /en/blog/ lang=en-US |
| PASS | EN /en/blog/ self-canonical |
| PASS | EN /en/blog/ hreflang -> PT /blog/ |
| PASS | EN /en/blog/ no B2 fallback notice |
| PASS | EN /en/blog/ og:locale=en_US |
| PASS | EN /en/blog/ not noindex |
| PASS | EN /en/blog/ shows English titles |
| PASS | EN /en/blog/ shows no Portuguese card when an EN translation exists |
| PASS | EN /en/blog/ filter bar uses EN category labels |
| PASS | EN /en/blog/page/2/ 200 |
| PASS | EN /en/blog/page/2/ English titles |
| PASS | EN /en/blog/page/2/ canonical collapses to archive |
| PASS | EN /en/blog/page/3/ 200 |
| PASS | EN /en/blog/page/3/ English titles |
| PASS | EN /en/blog/page/3/ canonical collapses to archive |
| PASS | EN /en/blog/page/4/ 200 |
| PASS | EN /en/blog/page/4/ English titles |
| PASS | EN /en/blog/page/4/ canonical collapses to archive |
| PASS | EN /en/blog/?categoria=<EN slug> filters the EN set |
| PASS | EN view with a PT category slug does not return the EN set |
| PASS | PT filter bar uses PT category labels |
| PASS | EN /en/category/<en-slug>/ 200 |
| PASS | EN category archive lang=en-US |
| PASS | EN category archive self-canonical |
| PASS | EN category archive has English posts |
| PASS | PT category archive unchanged (PT posts) |
| PASS | EN single post 200 |
| PASS | EN single lang=en-US |
| PASS | EN single self-canonical |
| PASS | EN single hreflang -> PT post |
| PASS | EN single body is English |
| PASS | EN single has no B2 notice |
| PASS | PT single canonical unchanged |
| PASS | PT single still Portuguese |
| PASS | PT single links to its EN translation (switcher) |
| PASS | EN post with external references renders |
| PASS | EN post keeps external links untouched |
| PASS | EN navigation Blog link is /en/blog/ |
| PASS | EN navigation has no PT /blog/ leak |
| PASS | PT navigation Blog link is /blog/ |
| PASS | PT navigation has no /en/blog/ leak |
| PASS | sitemap served |
| PASS | sitemap lists PT blog URLs |
| PASS | sitemap lists EN blog URLs |
| PASS | sitemap lists the EN posts page |
| PASS | sitemap lists the PT posts page |
| PASS | sitemap has no duplicate URLs |
| PASS | sitemap carries alternates for translated posts |
| PASS | EN search returns the EN translation |
| PASS | EN search does not show the PT post title |
| PASS | PT search still returns the PT post |

60 passed, 0 failed
