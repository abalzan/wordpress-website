#!/usr/bin/env bash
# negative-proof.sh — prove test-hero-preload-fallback.php can FAIL.
set -uo pipefail
cd /home/andrei/IdeaProjects/wordpress-website
H=wp-content/themes/conexao-br-irlanda/header.php
T=wp-content/themes/conexao-br-irlanda/tests/test-hero-preload-fallback.php
cp "$H" /tmp/stage20/header.php.pristine

run_case () {
  local name="$1"
  docker compose exec -T wordpress php "/var/www/html/$T" > "/tmp/stage20/neg-$name.txt" 2>&1
  local code=$?
  local fails
  fails=$(grep -c '^  FAIL' "/tmp/stage20/neg-$name.txt" || true)
  echo "MUTATION=$name EXIT=$code FAILING_ASSERTIONS=$fails"
  grep '^  FAIL' "/tmp/stage20/neg-$name.txt" | head -4
  cp /tmp/stage20/header.php.pristine "$H"
  echo
}

# 1. desktop preload deleted entirely
python3 - <<'PY'
import re
p='wp-content/themes/conexao-br-irlanda/header.php'
s=open(p).read()
lines=[l for l in s.split('\n') if 'min-width: 769px)' in l and '<link' in l and 'preload' in l]
open(p,'w').write(s.replace(lines[0]+'\n',''))
PY
run_case desktop-preload-removed

# 2. media attribute stripped from the desktop preload
sed -i 's| fetchpriority="high" media="(min-width: 769px)"| fetchpriority="high"|' "$H"
run_case desktop-media-removed

# 3. imagesrcset stripped from the desktop preload
sed -i 's| imagesrcset="[^"]*"||' "$H"
run_case desktop-imagesrcset-removed

# 4. imagesizes stripped from the desktop preload
sed -i 's| imagesizes="100vw"||' "$H"
run_case desktop-imagesizes-removed

# 5. fallback silently reverted to the 2057w master
sed -i 's|imagesizes="100vw" href="<?php echo esc_url( get_template_directory_uri() . .*/assets/images/conexaobr_Hero_image-1600.webp. ); ?>"|imagesizes="100vw" href="<?php echo esc_url( get_template_directory_uri() . '"'"'/assets/images/conexaobr_Hero_image.webp'"'"' ); ?>"|' "$H"
grep -c 'conexaobr_Hero_image-1600.webp. ); ?>"' "$H"
run_case fallback-reverted-to-master

echo '--- pristine restored? ---'
diff -q "$H" /tmp/stage20/header.php.pristine && echo RESTORED_OK
