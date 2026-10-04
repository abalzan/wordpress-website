#!/usr/bin/env bash
# G5 — spaced upstream reachability probe (LOCAL diagnostic only).
#
# One request per interval to a small set of Eventbrite URLs, recording the
# status and any WAF / Retry-After header. This never retries aggressively and
# never evades anything: it is a well-behaved, low-rate observation of what
# Eventbrite returns from this environment over time.
#
# Usage: g5-upstream-probe.sh <interval_seconds> <iterations> <out_file> <url>...
set -uo pipefail

INTERVAL="${1:-60}"
ITERATIONS="${2:-30}"
OUT="${3:-/tmp/g5-upstream-probe.txt}"
shift 3 || true
URLS=("$@")

UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'

{
	echo "# G5 upstream reachability probe"
	echo "# interval=${INTERVAL}s iterations=${ITERATIONS} urls=${URLS[*]}"
} > "$OUT"

for i in $(seq 1 "$ITERATIONS"); do
	for u in "${URLS[@]}"; do
		line=$(curl -s -o /dev/null -D - -w 'status=%{http_code} time=%{time_total}' \
			-A "$UA" \
			-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
			-H 'Accept-Language: en-IE,en;q=0.9' \
			"$u" 2>/dev/null)
		waf=$(printf '%s' "$line" | grep -i '^x-amzn-waf-action:' | tr -d '\r' | head -1)
		ra=$(printf '%s' "$line" | grep -i '^retry-after:' | tr -d '\r' | head -1)
		status=$(printf '%s' "$line" | grep -o 'status=[0-9]*' | head -1)
		ttime=$(printf '%s' "$line" | grep -o 'time=[0-9.]*' | head -1)
		echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) iter=$i $status $ttime ${waf:-waf=<none>} ${ra:-retry_after=<none>} url=$u" >> "$OUT"
	done
	sleep "$INTERVAL"
done

echo "probe_complete out=$OUT" >> "$OUT"