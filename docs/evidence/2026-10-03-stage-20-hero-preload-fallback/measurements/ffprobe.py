#!/usr/bin/env python3
"""Firefox WebDriver probe (stdlib only).

Cold, cache-disabled, private-browsing load of a local URL, then reports:
  - Largest Contentful Paint (buffered PerformanceObserver)
  - every resource whose URL contains Hero_image, with initiatorType
    (what proves the preload link, not the <img>, started the fetch)

Usage: ffprobe.py <url> <width> <height> [driver_port] [runs]
"""
import json
import subprocess
import sys
import time
import urllib.error
import urllib.request

TARGET = sys.argv[1]
WIDTH = int(sys.argv[2])
HEIGHT = int(sys.argv[3])
PORT = int(sys.argv[4]) if len(sys.argv) > 4 else 4455
RUNS = int(sys.argv[5]) if len(sys.argv) > 5 else 1
DRIVER = "http://127.0.0.1:%d" % PORT

# Executed as a function BODY; the last argument is geckodriver's callback.
EXTRACT = r"""
var done = arguments[arguments.length - 1];
var out = { lcp: 0, lcpUrl: '', lcpSize: 0, resources: [], resourceCount: 0, transferAll: 0 };
try {
  var po = new PerformanceObserver(function (list) {
    var entries = list.getEntries();
    for (var i = 0; i < entries.length; i++) {
      out.lcp = Math.round(entries[i].startTime);
      out.lcpUrl = entries[i].url || '';
      out.lcpSize = entries[i].size || 0;
    }
  });
  po.observe({ type: 'largest-contentful-paint', buffered: true });
} catch (e) { out.lcpError = String(e); }
try {
  var nav = performance.getEntriesByType('navigation')[0];
  if (nav) {
    out.ttfb = Math.round(nav.responseStart);
    out.dcl = Math.round(nav.domContentLoadedEventEnd);
    out.load = Math.round(nav.loadEventEnd);
  }
} catch (e) {}
var res = performance.getEntriesByType('resource');
out.resourceCount = res.length;
for (var i = 0; i < res.length; i++) {
  var e = res[i];
  out.transferAll += e.transferSize;
  if (e.name.indexOf('Hero_image') === -1) continue;
  out.resources.push({
    url: e.name.split('/').pop(),
    initiator: e.initiatorType,
    start: Math.round(e.startTime),
    responseEnd: Math.round(e.responseEnd),
    transfer: e.transferSize,
    encoded: e.encodedBodySize,
    decoded: e.decodedBodySize,
  });
}
setTimeout(function () { done(JSON.stringify(out)); }, 700);
"""


def req(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(
        DRIVER + path, data=data, method=method,
        headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(r, timeout=120) as resp:
            return json.loads(resp.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        return json.loads(e.read().decode() or "{}")


def main():
    proc = subprocess.Popen(
        ["geckodriver", "--port", str(PORT), "--host", "127.0.0.1"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(60):
            try:
                req("GET", "/status")
                break
            except Exception:
                time.sleep(0.5)
        results = []
        for _ in range(RUNS):
            # A fresh session == a fresh profile == a genuine cold/private load.
            caps = {"capabilities": {"alwaysMatch": {
                "acceptInsecureCerts": True,
                "moz:firefoxOptions": {
                    "args": ["-headless", "-private"],
                    "prefs": {
                        "browser.cache.disk.enable": False,
                        "browser.cache.memory.enable": False,
                        "network.http.use-cache": False,
                        "browser.sessionhistory.max_total_viewers": 0,
                        "privacy.history.custom": False,
                    },
                },
            }}}
            s = req("POST", "/session", caps)
            sid = (s.get("value") or {}).get("sessionId")
            if not sid:
                print(json.dumps(s)[:800])
                return 1
            req("POST", "/session/%s/window/rect" % sid,
                {"width": WIDTH, "height": HEIGHT, "x": 0, "y": 0})
            req("POST", "/session/%s/url" % sid, {"url": TARGET})
            time.sleep(3)
            r = req("POST", "/session/%s/execute/async" % sid,
                    {"script": EXTRACT, "args": []})
            value = (r.get("value") if isinstance(r.get("value"), str)
                     else json.dumps(r))
            results.append(json.loads(value) if isinstance(value, str) else value)
            req("DELETE", "/session/%s" % sid)
        print(json.dumps(results))
        return 0
    finally:
        proc.terminate()


if __name__ == "__main__":
    sys.exit(main())
