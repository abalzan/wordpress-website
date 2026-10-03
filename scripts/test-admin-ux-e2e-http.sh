#!/bin/bash
# Admin UX — end-to-end wp-admin regression (HTTP level).
# Creates a temp admin, logs in via wp-login.php, exercises the real
# post.php Update flow, list tables, editor screens, leisure images page
# and the Duplicate row action. Cleans up after itself.
set -u
BASE="http://localhost:8080"
WP="docker compose exec -T wordpress php wp-cli.phar --allow-root"
JAR="/tmp/qa-e2e-cookies.txt"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  OK  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
check(){ if [ "$2" != "0" ]; then ok "$1"; else bad "$1"; fi }

rm -f "$JAR"

$WP user create qa-e2e qa-e2e@example.com --role=administrator --user_pass='Qa!Passw0rd!' >/dev/null 2>&1

# --- login (handles the Jetpack math CAPTCHA if it appears) ---
curl -s -c "$JAR" -o /dev/null "$BASE/wp-login.php"
LOGIN_OUT=$(curl -s -b "$JAR" -c "$JAR" \
  --data 'log=qa-e2e&pwd=Qa!Passw0rd!&wp-submit=Log+In&redirect_to=http%3A%2F%2Flocalhost%3A8080%2Fwp-admin%2F&testcookie=1' \
  "$BASE/wp-login.php")
if echo "$LOGIN_OUT" | grep -q 'jetpack_protect_process_math_form'; then
  ANS=$(echo "$LOGIN_OUT" | python3 -c "
import re, html, sys
h = sys.stdin.read()
body = re.sub(r'<style[^>]*>.*?</style>', '', h, flags=re.S)
txt = html.unescape(re.sub(r'<[^>]+>', ' ', body))
m = re.search(r'Prove your humanity\s*(\d+)\s*\+\s*(\d+)', txt)
print(int(m.group(1)) + int(m.group(2)))
")
  A=$(echo "$LOGIN_OUT" | grep -o 'jetpack_protect_answer" value="[^"]*' | head -1 | sed 's/.*value="//')
  curl -s -b "$JAR" -c "$JAR" -o /dev/null \
    --data "jetpack_protect_num=$ANS&jetpack_protect_answer=$A&jetpack_protect_process_math_form=1&log=qa-e2e&pwd=Qa!Passw0rd!&wp-submit=Log+In&redirect_to=http%3A%2F%2Flocalhost%3A8080%2Fwp-admin%2F&testcookie=1" \
    "$BASE/wp-login.php"
fi
CODE=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/wp-admin/")
check "wp-admin session established (HTTP $CODE)" "$([ "$CODE" = "200" ] && echo 1 || echo 0)"
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/wp-admin/"
AUTH=$(curl -s -b "$JAR" "$BASE/wp-admin/index.php" | grep -c 'wpbody-content')
check "wp-admin reachable for qa-e2e" "$AUTH"

# --- admin pages load ---
for PT in guide sponsor event job leisure course_provider; do
  HTML=$(curl -s -b "$JAR" "$BASE/wp-admin/edit.php?post_type=$PT")
  FATAL=$(echo "$HTML" | grep -ci 'fatal error')
  CONN=$(echo "$HTML" | grep -c 'conexao')
  if [ "$FATAL" = "0" ] && [ "$CONN" -gt "0" ]; then ok "list table loads: $PT"; else bad "list table loads: $PT (fatal=$FATAL conexao-hits=$CONN)"; fi
done

HTML=$(curl -s -b "$JAR" "$BASE/wp-admin/post-new.php?post_type=guide")
check "new-Guia editor renders sectioned form" "$(echo "$HTML" | grep -c 'conexao-editor-wrapper')"
check "new-Guia editor has editor nonce" "$(echo "$HTML" | grep -c 'conexao_admin_ux_nonce')"

HTML=$(curl -s -b "$JAR" "$BASE/wp-admin/edit.php?post_type=leisure&page=conexao-leisure-images")
check "leisure images page loads" "$(echo "$HTML" | grep -c 'conexao-leisure-images\|Imagens dos Locais')"

HTML=$(curl -s -b "$JAR" "$BASE/wp-admin/edit.php?post_type=guide")
check "bulk actions UI present on list table" "$(echo "$HTML" | grep -c 'bulk-action-selector')"

echo "$PASS passed, $FAIL failed (phase 1)"
# --- E2E Guia Update via real post.php POST ---
GUIDE=$($WP post create --post_type=guide --post_title='E2E Guia antes' --post_content='<p>Conteudo antes.</p>' --post_status=publish --porcelain 2>/dev/null | tr -d '[:space:]')
if [ -n "$GUIDE" ] && [ "$GUIDE" != "0" ]; then ok "E2E guide created (ID $GUIDE)"; else bad "E2E guide created"; fi

EDIT=$(curl -s -b "$JAR" "$BASE/wp-admin/post.php?post=$GUIDE&action=edit")
NONCE=$(echo "$EDIT" | tr '>' '>\n' | sed -n 's/.*name="conexao_admin_ux_nonce" value="\([^"]*\)".*/\1/p' | head -1)
WPNONCE=$(echo "$EDIT" | tr '>' '>\n' | sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' | head -1)
check "edit screen: editor nonce extracted" "$([ -n "$NONCE" ] && echo 1 || echo 0)"
check "edit screen: core nonce extracted" "$([ -n "$WPNONCE" ] && echo 1 || echo 0)"

CODE=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' \
  --data-urlencode "action=editpost" \
  --data-urlencode "post_ID=$GUIDE" \
  --data-urlencode "post_type=guide" \
  --data-urlencode "_wpnonce=$WPNONCE" \
  --data-urlencode "_wp_http_referer=/wp-admin/post.php?post=$GUIDE&action=edit" \
  --data-urlencode "user_ID=1" \
  --data-urlencode "originalaction=editpost" \
  --data-urlencode "original_post_status=publish" \
  --data-urlencode "post_status=publish" \
  --data-urlencode "original_publish=Atualizar" \
  --data-urlencode "conexao_admin_ux_nonce=$NONCE" \
  --data-urlencode "conexao_publish_action=publish" \
  --data-urlencode "conexao_editor_status_input=1" \
  --data-urlencode "conexao_fields[guide_title]=E2E Guia DEPOIS do update" \
  --data-urlencode "conexao_fields[guide_content]=<p>Conteudo DEPOIS do update via post.php.</p>" \
  --data-urlencode "conexao_fields[guide_useful_links]=" \
  --data-urlencode "conexao_fields[guide_source]=" \
  --data-urlencode "conexao_fields[guide_featured_image]=" \
  --data-urlencode "conexao_fields[guide_county]=" \
  --data-urlencode "conexao_fields[guide_town]=" \
  --data-urlencode "conexao_fields[guide_url]=" \
  "$BASE/wp-admin/post.php")
check "Update POST accepted (HTTP $CODE)" "$CODE"

# Reload the editor and verify persistence ("Update → Reload" from the brief).
RELOAD=$(curl -s -b "$JAR" "$BASE/wp-admin/post.php?post=$GUIDE&action=edit")
echo "$RELOAD" > /tmp/qa-reload.html
check "reload: updated title present" "$(echo "$RELOAD" | grep -c 'E2E Guia DEPOIS do update')"
check "reload: updated content present" "$(echo "$RELOAD" | grep -c 'DEPOIS do update via post.php')"
GONE=$(echo "$RELOAD" | grep -c 'Conteudo antes')
check "reload: old content gone" "$([ "${GONE:-1}" = "0" ] && echo 1 || echo 0)"

# Public single view reflects the update too.
PUB=$(curl -s -L "$BASE/?p=$GUIDE")
check "public single shows updated content" "$(echo "$PUB" | grep -c 'DEPOIS do update via post.php')"

# --- Duplicate row action ---
LISTHTML=$(curl -s -b "$JAR" "$BASE/wp-admin/edit.php?post_type=guide")
DUP_PATH=$(echo "$LISTHTML" | grep -o 'edit\.php?post_type=guide[^"]*conexao_action=duplicate[^"]*' | head -1 | sed 's/&#038;/\&/g')
if [ -n "$DUP_PATH" ]; then
  curl -s -b "$JAR" -o /dev/null -L "$BASE/wp-admin/$DUP_PATH"
  DUPCOUNT=$($WP post list --post_type=guide --s='E2E Guia DEPOIS do update' --format=count 2>/dev/null | tr -d '[:space:]')
  check "duplicate row action created a copy" "$([ "${DUPCOUNT:-0}" -ge 2 ] && echo 1 || echo 0)"
  echo "$LISTHTML" | sed 's/&#038;/\&/g' | grep -o "edit\.php?post_type=guide&conexao_action=archive&post=$GUIDE&[^\"]*" | head -1 > /tmp/qa-archive-url.txt
  ARCH_PATH=$(cat /tmp/qa-archive-url.txt)
  if [ -n "$ARCH_PATH" ]; then
    curl -s -b "$JAR" -o /dev/null -L "$BASE/wp-admin/$ARCH_PATH"
    ARCH_STATUS=$(docker compose exec -T wordpress php wp-cli.phar --allow-root eval "echo Conexao_Admin_Ux_Actions::get_status( $GUIDE, 'guide' );" 2>/dev/null | tail -1 | tr -d '[:space:]')
    check "archive row action archived the guide" "$([ "$ARCH_STATUS" = "archived" ] && echo 1 || echo 0)"
  else
    bad "archive row action URL found for the test guide"
  fi
else
  bad "duplicate row action URL found in list table"
fi

# --- cleanup ---
$WP post delete "$GUIDE" --force >/dev/null 2>&1
for ID in $($WP db query "SELECT post_id FROM wp_postmeta WHERE meta_key='conexao_admin_ux_notice_' UNION SELECT ID FROM wp_posts WHERE post_title LIKE 'E2E Guia%'" --skip-column-names 2>/dev/null | tr -d '[:space:]'); do
  $WP post delete "$ID" --force >/dev/null 2>&1
done
$WP user delete qa-e2e --network --yes >/dev/null 2>&1
rm -f "$JAR"

echo "$PASS passed, $FAIL failed (phase 2)"
if [ "$FAIL" = "0" ]; then
  exit 0
fi
exit 1
