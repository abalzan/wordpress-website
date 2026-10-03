#!/usr/bin/env bash
# Capture the LOCAL Import History admin page over HTTP and re-derive the
# operator-visible facts from the rendered page (G1-G3 §12 verification, rerun
# for G4).
#
# LOCAL ONLY: it targets http://localhost:8080 exclusively. It never contacts
# production and uses no production credentials.
#
# If the page cannot be rendered as an authenticated administrator, the script
# says so and exits non-zero rather than reporting an unverified number.
set -uo pipefail

BASE="${BASE:-http://localhost:8080}"
OUT="${OUT:-/tmp/g4-import-history.html}"
COOKIES="$(mktemp)"
trap 'rm -f "$COOKIES"' EXIT

# Credentials come from the environment (repository standard: no secrets in the
# repository). They are the LOCAL WordPress test account only.
if [ -f .env ]; then
  # shellcheck disable=SC1091
  set -a; . ./.env; set +a
fi

ADMIN_USER="${WP_USERNAME:-admin}"
ADMIN_PASS="${WP_TEST_USER_PASSWORD:-}"

if [ -z "$ADMIN_PASS" ]; then
  echo "CAPTURE_FAILED: WP_TEST_USER_PASSWORD is not set in the environment."
  echo "This capture is NOT proof of anything about the admin screen."
  exit 1
fi

# 1. Seed the test cookie, then log in.
curl -sS -c "$COOKIES" -b "$COOKIES" \
  --data-urlencode "log=${ADMIN_USER}" \
  --data-urlencode "pwd=${ADMIN_PASS}" \
  -d "wp-submit=Log+In" -d "redirect_to=${BASE}/wp-admin/" -d "testcookie=1" \
  -o /dev/null "${BASE}/wp-login.php" || { echo "login request failed"; exit 1; }

# 2. Load the Import History screen.
CODE="$(curl -sS -c "$COOKIES" -b "$COOKIES" -o "$OUT" -w '%{http_code}' \
  "${BASE}/wp-admin/admin.php?page=conexao-import-history")"

# The admin bar always links to wp-login.php even on an authenticated page, so
# that is NOT a login indicator. The reliable signal is the screen's own
# heading plus its table markup.
AUTHENTICATED=no
if grep -q 'Import History' "$OUT" && grep -q 'conexao-import-history' "$OUT"; then
  AUTHENTICATED=yes
fi

echo "http_status=${CODE}"
echo "page_bytes=$(wc -c < "$OUT")"
echo "authenticated_page=${AUTHENTICATED}"

if [ "$CODE" != "200" ] || [ "$AUTHENTICATED" != "yes" ]; then
  echo "CAPTURE_FAILED: the Import History screen did not render for an authenticated administrator."
  echo "This capture is NOT proof of anything about the admin screen."
  exit 1
fi

# 3. Re-derive the operator-visible facts from the RENDERED table.
python3 - "$OUT" <<'PY'
import re, sys, collections
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()

rows = re.findall(r'<tr[^>]*>(.*?)</tr>', html, re.S | re.I)
status = collections.Counter()
data_rows = 0
found0_success = 0

for row in rows:
    if 'conexao-' not in row:
        continue
    cells = re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', row, re.S | re.I)
    cells = [re.sub(r'<[^>]+>', '', c).strip() for c in cells]
    if len(cells) < 3:
        continue
    data_rows += 1
    st = next((c.lower() for c in cells if c.lower() in
               ('success', 'warning', 'failed', 'partial')), None)
    if st:
        status[st] += 1
    # A cell pair of (0, success) is the exact defect G1 eliminated.
    for i, c in enumerate(cells[:-1]):
        if c == '0' and cells[i + 1].lower() == 'success':
            found0_success += 1

print(f'rendered_rows={data_rows}')
print('rendered_status=' + repr(dict(status)))
print(f'rendered_found0_and_success={found0_success}')
PY

echo "CAPTURE_OK"