#!/usr/bin/env python3
"""Analyse a G4-era Eventbrite request trace for pacing / rate-limit facts.

Reads the archived full-run request trace (or a G5 probe trace) and reports:
  - inter-request intervals during healthy pagination;
  - requests and pages before the first 429;
  - the 429 retry intervals;
  - whether the 429 condition is page-specific or source-wide;
  - every Retry-After header observed (none are recorded in this format).

Usage: g5-trace-analysis.py <trace.log> [<probe.json>]
"""
import re
import sys
from collections import OrderedDict

ROW = re.compile(
    r"^\s*(?P<n>\d+)\s+(?P<host>\S+)\s+(?P<path>\S+)\s+code=(?P<code>\d+)"
    r"\s+err=(?P<err>\S*)\s+t=\s*(?P<t>[\d.]+)s"
)
EB = "www.eventbrite.ie"


def parse_log(path):
    rows = []
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            m = ROW.match(line)
            if m:
                rows.append({
                    "n": int(m.group("n")),
                    "host": m.group("host"),
                    "path": m.group("path"),
                    "code": int(m.group("code")),
                    "t": float(m.group("t")),
                })
    return rows


def main():
    rows = [r for r in parse_log(sys.argv[1]) if r["host"] == EB]
    if not rows:
        print("no eventbrite rows found")
        return 1

    print("eventbrite_requests={}".format(len(rows)))
    print("status_distribution={}".format(
        {c: sum(1 for r in rows if r["code"] == c)
         for c in sorted({r["code"] for r in rows})}))

    # Healthy pagination intervals: consecutive 200s on the SAME path.
    healthy, last_t, last_path = [], None, None
    for r in rows:
        if r["code"] == 200 and last_t is not None and r["path"] == last_path:
            healthy.append(round(r["t"] - last_t, 2))
        if r["code"] == 200:
            last_t, last_path = r["t"], r["path"]
    if healthy:
        print("healthy_same_page_interval min={} median={} max={}".format(
            min(healthy), sorted(healthy)[len(healthy) // 2], max(healthy)))

    # Every inter-request interval, per consecutive pair.
    gaps = [round(rows[i + 1]["t"] - rows[i]["t"], 2) for i in range(len(rows) - 1)]
    if gaps:
        gs = sorted(gaps)
        print("all_intervals min={} p50={} p90={} max={}".format(
            gs[0], gs[len(gs) // 2], gs[int(len(gs) * 0.9)], gs[-1]))

    first = next((i for i, r in enumerate(rows) if r["code"] == 429), None)
    if first is None:
        print("first_429=none")
        return 0

    print("first_429_request_index={} (1-based request #{})".format(first, first + 1))
    print("first_429_elapsed_seconds={}".format(rows[first]["t"]))
    before = [r for r in rows[:first] if r["code"] == 200]
    print("requests_before_first_429={}".format(len(before)))
    print("pages_fetched_before_first_429={}".format(
        len({r["path"] for r in before})))

    # Pages that were being paginated when the first 429 arrived.
    print("first_429_path={}".format(rows[first]["path"]))

    # Retry intervals of the very first rate-limited page.
    retry = []
    path = rows[first]["path"]
    i = first
    while i < len(rows) and rows[i]["path"] == path:
        if i > first:
            retry.append(round(rows[i]["t"] - rows[i - 1]["t"], 2))
        i += 1
    print("first_429_page_attempts={}".format(i - first))
    print("first_429_retry_intervals={}".format(retry))

    # Source-wide test: after the first 429, which distinct paths keep failing?
    after = rows[first:]
    paths_after = OrderedDict()
    for r in after:
        e = paths_after.setdefault(r["path"], {"total": 0, "429": 0})
        e["total"] += 1
        if r["code"] == 429:
            e["429"] += 1
    wholly = [p for p, e in paths_after.items() if e["429"] == e["total"]]
    print("distinct_paths_after_first_429={}".format(len(paths_after)))
    print("paths_100pct_429_after_first_429={}/{}".format(len(wholly), len(paths_after)))
    print("example_wholly_blocked={}".format(wholly[:6]))
    print("total_429={}".format(sum(1 for r in rows if r["code"] == 429)))
    print("retry_after_headers_observed=0 (not recorded by this tracer)")
    return 0


if __name__ == "__main__":
    sys.exit(main())