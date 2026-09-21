#!/usr/bin/env bash
#
# stage2-http-verify.sh — Stage 2 (Polylang /en/) HTTP verification.
#
# LOCAL / STAGING ONLY. Read-only: performs GET requests and asserts status
# codes, redirect targets, html lang, og:locale, canonical and hreflang output.
#
# Usage:
#   ./scripts/stage2-http-verify.sh [base-url]      # default http://localhost:8080
#
# Exit code 0 = all checks passed.

set -uo pipefail

BASE="${1:-http://localhost:8080}"
BASE="${BASE%/}"

PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  PASS: %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  FAIL: %s\n' "$1"; }

code_of() { curl -s -o /dev/null -w '%{http_code}' "$BASE$1"; }
location_of() { curl -s -o /dev/null -w '%{redirect_url}' "$BASE$1"; }
body_of() { curl -s "$BASE$1"; }

expect_status() {
	local path="$1" expected="$2" label="$3" got
	got="$(code_of "$path")"
	if [ "$got" = "$expected" ]; then
		pass "$label ($path → $got)"
	else
		fail "$label ($path → $got, expected $expected)"
	fi
}

expect_redirect() {
	local path="$1" expected_code="$2" expected_target="$3" label="$4"
	local got_code got_target
	got_code="$(code_of "$path")"
	got_target="$(location_of "$path")"
	if [ "$got_code" = "$expected_code" ] && [ "$got_target" = "$BASE$expected_target" ]; then
		pass "$label ($path → $got_code $got_target)"
	else
		fail "$label ($path → $got_code $got_target, expected $expected_code $BASE$expected_target)"
	fi
}

# expect_body <path> <literal-pattern> <label>
# NOTE: the body is matched with bash string comparison on purpose — piping
# into `grep -q` makes curl exit early with SIGPIPE, which `set -o pipefail`
# would turn into a spurious failure.
expect_body() {
	local path="$1" pattern="$2" label="$3" body
	body="$(curl -s "$BASE$path")"
	if [[ "$body" == *"$pattern"* ]]; then
		pass "$label"
	else
		fail "$label (pattern not found: $pattern)"
	fi
}

expect_body_absent() {
	local path="$1" pattern="$2" label="$3" body
	body="$(curl -s "$BASE$path")"
	if [[ "$body" == *"$pattern"* ]]; then
		fail "$label (unexpected pattern found: $pattern)"
	else
		pass "$label"
	fi
}

echo "== Stage 2 HTTP verification — $BASE =="

echo
echo "-- 1. Portuguese URL preservation --"
for path in / /guias/ /eventos/ /lazer/ /blog/ /cursos/ /empregos/ /apoiadores/ /irlanda/ /sobre-nos/ /contato/ /moradia/ /saude/; do
	expect_status "$path" 200 "PT URL unchanged"
done

echo
echo "-- 2. Legacy EN→PT redirects (must stay 301, untouched) --"
expect_redirect /guides/ 301 /guias/ "legacy redirect preserved"
expect_redirect /events/ 301 /eventos/ "legacy redirect preserved"
expect_redirect /courses/ 301 /cursos/ "legacy redirect preserved"
expect_redirect /jobs/ 301 /empregos/ "legacy redirect preserved"
expect_redirect /sponsors/ 301 /apoiadores/ "legacy redirect preserved"
expect_redirect /ireland/ 301 /irlanda/ "legacy redirect preserved"
expect_redirect /about-us/ 301 /sobre-nos/ "legacy redirect preserved"
expect_redirect /contact/ 301 /contato/ "legacy redirect preserved"

echo
echo "-- 3. /en/ routing (no collision with the legacy redirects) --"
for path in /en/ /en/guias/ /en/eventos/ /en/lazer/ /en/cursos/ /en/apoiadores/; do
	expect_status "$path" 200 "EN URL resolves"
done
expect_redirect /en/blog/ 302 /blog/ "untranslated posts page → 302 (never 301)"
expect_redirect /en/empregos/ 302 /empregos/ "untranslated page → 302 (never 301)"
expect_redirect /en/irlanda/ 302 /irlanda/ "untranslated page → 302 (never 301)"

echo
echo "-- 4. Language attributes / locale --"
expect_body / '<html lang="pt-BR">' 'PT home renders lang="pt-BR"'
expect_body /en/ '<html lang="en-US">' 'EN home renders lang="en-US"'
expect_body / 'property="og:locale" content="pt_BR"' 'PT og:locale = pt_BR'
expect_body /en/ 'property="og:locale" content="en_US"' 'EN og:locale = en_US'

echo
echo "-- 5. Canonical ownership --"
expect_body / "rel=\"canonical\" href=\"$BASE/\"" 'PT home is self-canonical'
expect_body /en/ "rel=\"canonical\" href=\"$BASE/en/\"" 'EN home is self-canonical'
expect_body /eventos/ "rel=\"canonical\" href=\"$BASE/eventos/\"" 'PT archive is self-canonical'
expect_body /en/eventos/ "rel=\"canonical\" href=\"$BASE/en/eventos/\"" 'EN archive is self-canonical'

echo
echo "-- 6. hreflang (only real translations / content-backed archives) --"
expect_body / "hreflang=\"pt-BR\" href=\"$BASE/\"" 'PT home declares pt-BR alternate'
expect_body / 'hreflang="x-default"' 'PT home declares x-default'
expect_body /en/ "hreflang=\"en\" href=\"$BASE/en/\"" 'EN home declares en alternate'
expect_body /en/ "hreflang=\"pt-BR\" href=\"$BASE/\"" 'EN home declares pt-BR alternate'
expect_body /en/eventos/ "hreflang=\"en\" href=\"$BASE/en/eventos/\"" 'EN archive declares its own alternate'
expect_body /en/eventos/ "hreflang=\"pt-BR\" href=\"$BASE/eventos/\"" 'EN archive declares the PT alternate'

echo
echo "-- 7. Cache separation (PT and EN must never share cached HTML) --"
body_of / >/dev/null
expect_body /en/ '<html lang="en-US">' 'EN request after PT warm-up is not PT-cached'
expect_body /en/ 'property="og:locale" content="en_US"' 'EN og:locale not served from the PT cache'
body_of /en/ >/dev/null
expect_body / '<html lang="pt-BR">' 'PT request after EN warm-up is not EN-cached'
expect_body / 'property="og:locale" content="pt_BR"' 'PT og:locale not served from the EN cache'

echo
echo "-- 8. Search stays in its language context --"
expect_status '/?s=dublin' 200 'PT search responds'
expect_status '/en/?s=dublin' 200 'EN search responds (no redirect)'

echo
echo "-- 9. REST language scoping --"
expect_status '/wp-json/wp/v2/guide?lang=pt-br' 200 'REST guide collection (pt-br)'
expect_status '/wp-json/wp/v2/event?lang=pt-br' 200 'REST event collection (pt-br)'
expect_status '/wp-json/wp/v2/leisure?lang=pt-br' 200 'REST leisure collection (pt-br)'
expect_status '/wp-json/wp/v2/posts?lang=pt-br' 200 'REST posts collection (pt-br)'
expect_status '/wp-json/wp/v2/guide?lang=en' 200 'REST guide collection (en)'
expect_status '/wp-json/wp/v2/event?lang=en' 200 'REST event collection (en)'

echo
echo "-- 10. robots.txt / sitemap / 404 / feeds --"
expect_body /robots.txt 'Disallow: /en/?s=' 'robots.txt disallows /en/?s='
expect_body /robots.txt 'Disallow: /en/search/' 'robots.txt disallows /en/search/'
expect_status /sitemap.xml 200 'sitemap serves'
expect_status /nao-existe-xyz/ 404 'unknown PT URL is a 404'
expect_status /feed/ 200 'feed serves'

echo
printf '== Stage 2 HTTP verification: %d passed, %d failed ==\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
