#!/bin/sh
# Stage D — strict sequential batch runner (NO concurrency).
#
# Concurrency triggered Eventbrite/Heritage Week HTTP 429 rate limits, so every
# batch runs strictly one after another with a cooldown between batches.
#
# Usage (inside the WordPress container):
#   sh stage-d-sequential.sh <dry|import> [batch...]
#
# Examples:
#   sh stage-d-sequential.sh dry 1 2 3 4 5
#   sh stage-d-sequential.sh import 1
#
# Outputs: /tmp/stage-d/batch<N>-<mode>.json + .log

set -u

PHP=/var/www/html/wp-content/plugins/conexao-event-importer/tests/stage-d-rollout.php
OUT=/tmp/stage-d
MODE="${1:-dry}"
shift 1 2>/dev/null || true

mkdir -p "$OUT"

B1="eventbrite_carlow eventbrite_cavan eventbrite_clare eventbrite_donegal eventbrite_galway heritage_week_carlow heritage_week_cavan heritage_week_clare heritage_week_donegal heritage_week_galway"
B2="eventbrite_kerry eventbrite_kildare eventbrite_kilkenny eventbrite_leitrim eventbrite_limerick heritage_week_kerry heritage_week_kildare heritage_week_kilkenny heritage_week_leitrim heritage_week_limerick"
B3="eventbrite_longford eventbrite_louth eventbrite_mayo eventbrite_meath eventbrite_monaghan heritage_week_longford heritage_week_louth heritage_week_mayo heritage_week_meath heritage_week_monaghan"
B4="eventbrite_offaly eventbrite_roscommon eventbrite_sligo eventbrite_tipperary eventbrite_waterford heritage_week_offaly heritage_week_roscommon heritage_week_sligo heritage_week_tipperary heritage_week_waterford"
B5="eventbrite_westmeath eventbrite_wexford eventbrite_wicklow heritage_week_westmeath heritage_week_wexford heritage_week_wicklow"

if [ $# -eq 0 ]; then
	set -- 1 2 3 4 5
fi

for b in "$@"; do
	eval "IDS=\$B$b"
	echo "[sequential] mode=$MODE batch=$b starting $(date -u +%H:%M:%S)"
	php "$PHP" "$MODE" $IDS --out="$OUT/batch$b-$MODE.json" > "$OUT/batch$b-$MODE.log" 2>&1
	echo "[sequential] mode=$MODE batch=$b done $(date -u +%H:%M:%S)"
	# Cool-down between batches to stay well under upstream rate limits.
	sleep 20
done

echo "[sequential] ALL DONE $(date -u +%H:%M:%S)" > "$OUT/sequential-$MODE.done"
echo "[sequential] all done"
