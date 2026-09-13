#!/bin/sh
# Stage D — throttled rollout runner (strictly sequential, long cooldowns).
#
# The upstream providers (Eventbrite, Heritage Week) enforce a tight sliding
# window and return HTTP 429 when too many requests arrive quickly. The
# importer's per-county page walk (up to ~8 pages / 45s) exceeds that window
# when counties run back-to-back, so this runner inserts a long cool-down
# between every source.
#
# Usage (inside the WordPress container):
#   sh stage-d-throttled.sh <dry|import> <provider: eb|hw|all> [cooldown_seconds]
#
# Outputs per-source JSON/log under /tmp/stage-d/thr-<id>.json and a summary
# file /tmp/stage-d/throttled-<mode>-<provider>.done

set -u

PHP=/var/www/html/wp-content/plugins/conexao-event-importer/tests/stage-d-rollout.php
OUT=/tmp/stage-d
MODE="${1:-dry}"
PROVIDER="${2:-all}"
COOL="${3:-75}"

mkdir -p "$OUT"

COUNTIES="carlow cavan clare donegal galway kerry kildare kilkenny leitrim limerick longford louth mayo meath monaghan offaly roscommon sligo tipperary waterford westmeath wexford wicklow"

IDS=""
case "$PROVIDER" in
	eb)
		for c in $COUNTIES; do IDS="$IDS eventbrite_$c"; done
		;;
	hw)
		for c in $COUNTIES; do IDS="$IDS heritage_week_$c"; done
		;;
	*)
		for c in $COUNTIES; do IDS="$IDS eventbrite_$c heritage_week_$c"; done
		;;
esac

# Initial cool-down to clear any residual rate-limit state.
sleep 30

rm -f "$OUT/thr-"*.json
for id in $IDS; do
	php "$PHP" "$MODE" "$id" --out="$OUT/thr-$id.json" > "$OUT/thr-$id.log" 2>&1
	# Extract found/status for the summary line.
	found=$(grep -o '"found": [0-9]*' "$OUT/thr-$id.json" 2>/dev/null | head -1)
	status=$(grep -o '"status": "[a-z]*"' "$OUT/thr-$id.json" 2>/dev/null | head -1)
	echo "$(date -u +%H:%M:%S) $id $found $status"
	sleep "$COOL"
done

echo "ALL DONE $(date -u +%H:%M:%S)" > "$OUT/throttled-$MODE-$PROVIDER.done"
echo "throttled $MODE $PROVIDER complete"
