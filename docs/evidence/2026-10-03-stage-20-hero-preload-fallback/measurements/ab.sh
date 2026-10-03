#!/usr/bin/env bash
# ab.sh — interleaved A/B (BEFORE/AFTER alternating) so drift cannot explain LCP.
set -uo pipefail
cd /home/andrei/IdeaProjects/wordpress-website
H=wp-content/themes/conexao-br-irlanda/header.php
mkdir -p /tmp/stage20/ab
for pair in 1 2 3; do
  for vp in mobile:390:844 desktop:1440:900; do
    name="${vp%%:*}"; rest="${vp#*:}"; w="${rest%%:*}"; h="${rest##*:}"
    for state in before after; do
      cp "/tmp/stage20/header.$state.php" "$H"
      port=$(( 4700 + RANDOM % 200 ))
      timeout 120 python3 /tmp/stage20/ffprobe.py http://localhost:8080/ "$w" "$h" "$port" 1 \
        > "/tmp/stage20/ab/pair$pair-$name-$state.json" 2>/dev/null
      echo "pair$pair $name $state done"
    done
  done
done
cp /tmp/stage20/header.after.php "$H"
echo RESTORED_AFTER
