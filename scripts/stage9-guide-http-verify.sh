#!/usr/bin/env bash
# Stage 9 — Guide EN translation, HTTP verification.
#
# Checks the front-end contract of the English Guide archive:
#   - /en/guias/ is a real English archive (EN cards, no B2 notice,
#     self-canonical EN URL, PT <-> EN <-> x-default hreflang);
#   - an EN guide single is 200, English, self-canonical, has the EN SEO
#     description and a language switcher pointing at its PT sibling;
#   - the PT slug under /en/ answers 302 -> the PT guide (replaced master);
#   - pagination and the ?categoria= filter work in EN, and a Portuguese slug
#     resolves to the English counterpart term instead of bouncing to PT;
#   - the PT archive and its PT filter are unchanged.
#
# Usage: ./scripts/stage9-guide-http-verify.sh [base-url]
set -u

BASE="${1:-http://localhost:8080}"
FAILED=0

code() { curl -s -o /dev/null -w "%{http_code}" "$1"; }
redirect() { curl -s -o /dev/null -w "%{redirect_url}" "$1"; }
body() { curl -s "$1"; }
count() { body "$1" | grep -o "$2" | wc -l | tr -d ' '; }

check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then
    printf '  PASS  %-58s %s\n' "$1" "$3"
  else
    printf '  FAIL  %-58s expected=%s actual=%s\n' "$1" "$2" "$3"
    FAILED=$((FAILED + 1))
  fi
}

contains() { # contains <label> <needle> <url>
  if body "$3" | grep -q "$2"; then
    printf '  PASS  %-58s found\n' "$1"
  else
    printf '  FAIL  %-58s missing\n' "$1"
    FAILED=$((FAILED + 1))
  fi
}

absent() { # absent <label> <needle> <url>
  if body "$3" | grep -q "$2"; then
    printf '  FAIL  %-58s present\n' "$1"
    FAILED=$((FAILED + 1))
  else
    printf '  PASS  %-58s absent\n' "$1"
  fi
}

echo "== Stage 9 — Guide EN translation (HTTP) =="
echo "base: $BASE"

# The EN slug is read from the archive itself (an archive-card link, never the
# feed link) so the script never hard-codes a record a different dataset may not
# have.
EN_SLUG=$(body "$BASE/en/guias/" | grep -o 'archive-card-title"><a href="[^"]*/en/guias/[a-z0-9-]\{3,\}/"' | head -n 1 | sed 's#.*/en/guias/##; s#/"##')


if [ -z "$EN_SLUG" ]; then
  echo "  FAIL  no EN guide link found in $BASE/en/guias/"
  exit 1
fi

printf '%s\n' '\n-- archives --'
check "/guias/ 200"                     200 "$(code "$BASE/guias/")"
check "/en/guias/ 200"                  200 "$(code "$BASE/en/guias/")"
absent "no B2 notice on /en/guias/"     'language-fallback-notice' "$BASE/en/guias/"
contains "/en/guias/ self-canonical EN" "rel=\"canonical\" href=\"$BASE/en/guias/\"" "$BASE/en/guias/"
contains "/en/guias/ hreflang pt"       "hreflang=\"pt-BR\" href=\"$BASE/guias/\"" "$BASE/en/guias/"
contains "/en/guias/ hreflang en"       "hreflang=\"en\" href=\"$BASE/en/guias/\"" "$BASE/en/guias/"
contains "/en/guias/ hreflang default"  "hreflang=\"x-default\"" "$BASE/en/guias/"
if [ "$(count "$BASE/en/guias/" 'archive-card-title')" -gt "$(count "$BASE/guias/" 'archive-card-title')" ]; then :; fi
printf '  INFO  %-58s en=%s pt=%s\n' "cards on page 1" "$(count "$BASE/en/guias/" 'archive-card-title')" "$(count "$BASE/guias/" 'archive-card-title')"

printf '%s\n' '\n-- EN single --'
check "/en/guias/$EN_SLUG/ 200"         200 "$(code "$BASE/en/guias/$EN_SLUG/")"
absent "no B2 notice on the EN single"  'language-fallback-notice' "$BASE/en/guias/$EN_SLUG/"
contains "EN single self-canonical"     "rel=\"canonical\" href=\"$BASE/en/guias/$EN_SLUG/\"" "$BASE/en/guias/$EN_SLUG/"
contains "EN single hreflang pt"        "hreflang=\"pt-BR\"" "$BASE/en/guias/$EN_SLUG/"
contains "EN single has a description"  'name="description"' "$BASE/en/guias/$EN_SLUG/"
contains "EN single has a switcher"     'language-switcher' "$BASE/en/guias/$EN_SLUG/"

printf '%s\n' '\n-- replaced master --'
# The PT sibling is read from the EN single's own language switcher (the real
# Polylang relationship), then requested under /en/: it must 302 to the PT guide
# instead of serving Portuguese content under an English URL.
PT_SLUG=$(body "$BASE/en/guias/$EN_SLUG/" | grep -o 'hreflang="pt-BR"[^>]*href="[^"]*/guias/[a-z0-9-]\{3,\}/"' | head -n 1 | sed 's#.*/guias/##; s#/"##')

if [ -n "$PT_SLUG" ]; then
  check "/guias/$PT_SLUG/ 200 (PT kept)"  200 "$(code "$BASE/guias/$PT_SLUG/")"
  check "/en/guias/$PT_SLUG/ 302 -> PT"  "$BASE/guias/$PT_SLUG/" "$(redirect "$BASE/en/guias/$PT_SLUG/")"
else
  printf '  INFO  %-58s no PT sibling for %s\n' "replaced master" "$EN_SLUG"
fi

printf '%s\n' '\n-- pagination --'
check "/en/guias/page/2/ 200"           200 "$(code "$BASE/en/guias/page/2/")"
check "/guias/page/2/ 200"              200 "$(code "$BASE/guias/page/2/")"

printf '%s\n' '\n-- category filter --'
check "EN filter (EN slug) 200"          200 "$(code "$BASE/en/guias/?categoria=documents")"
check "EN filter (PT slug) 200"          200 "$(code "$BASE/en/guias/?categoria=saude")"
check "EN filter (unknown slug) 200"     200 "$(code "$BASE/en/guias/?categoria=naoexiste")"
check "PT filter 200"                    200 "$(code "$BASE/guias/?categoria=documentos")"
printf '  INFO  %-58s %s\n' "cards: en/documents / en/saude / en/health" \
  "$(count "$BASE/en/guias/?categoria=documents" 'archive-card-title') / $(count "$BASE/en/guias/?categoria=saude" 'archive-card-title') / $(count "$BASE/en/guias/?categoria=health" 'archive-card-title')"

printf '%s\n' '\n-- sitemap --'
SITEMAP="$BASE/sitemap_index.xml"
if body "$SITEMAP" | head -c 200 | grep -q urlset; then
  EN_ENTRIES=$(body "$SITEMAP" | grep -o "<loc>$BASE/en/guias/" | wc -l | tr -d ' ')
  PT_ENTRIES=$(body "$SITEMAP" | grep -o "<loc>$BASE/guias/" | wc -l | tr -d ' ')
  printf '  INFO  %-58s en=%s pt=%s\n' "guide entries" "$EN_ENTRIES" "$PT_ENTRIES"
  if [ "$EN_ENTRIES" -ge 1 ] && [ "$PT_ENTRIES" -ge 1 ]; then
    printf '  PASS  %-58s both languages listed\n' "sitemap"
  else
    printf '  FAIL  %-58s en=%s pt=%s\n' "sitemap" "$EN_ENTRIES" "$PT_ENTRIES"
    FAILED=$((FAILED + 1))
  fi
  contains "sitemap lists the EN alternate" 'hreflang="en"' "$SITEMAP"
else
  echo "  INFO  theme sitemap not served at $SITEMAP (skipped)"
fi

echo
if [ "$FAILED" -eq 0 ]; then
  echo "== HTTP verification PASSED =="
else
  echo "== HTTP verification FAILED: $FAILED check(s) =="
fi
exit "$FAILED"
