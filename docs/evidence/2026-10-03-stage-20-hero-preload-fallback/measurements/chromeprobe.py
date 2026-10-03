#!/usr/bin/env python3
"""Chromium (Playwright) check: which hero candidate does each width SELECT?

Uses currentSrc (the browser's own resolution result) plus resource timings.
Cold context, cache disabled. Independent engine from the Firefox probe.
"""
import json, sys
from playwright.sync_api import sync_playwright

WIDTHS = [375, 768, 769, 1024, 1440]
JS = """
() => {
  const img = document.querySelector('.hero-background-img');
  const out = { currentSrc: img ? img.currentSrc.split('/').pop() : null,
                naturalWidth: img ? img.naturalWidth : null,
                selectedSources: [], hero: [] };
  if (img && img.parentElement) {
    for (const s of img.parentElement.querySelectorAll('source')) {
      if (s.media && window.matchMedia(s.media).matches) {
        out.selectedSources.push(s.srcset.split('/').pop().split(',')[0].trim());
      }
    }
  }
  for (const e of performance.getEntriesByType('resource')) {
    if (e.name.indexOf('Hero_image') === -1) continue;
    out.hero.push({ url: e.name.split('/').pop(), initiator: e.initiatorType, encoded: e.encodedBodySize });
  }
  return out;
}
"""

out = []
with sync_playwright() as p:
    browser = p.chromium.launch()
    for w in WIDTHS:
        ctx = browser.new_context(viewport={'width': w, 'height': 900},
                                  bypass_csp=True, service_workers='block')
        page = ctx.new_page()
        page.goto('http://localhost:8080/', wait_until='load')
        page.wait_for_timeout(1200)
        data = page.evaluate(JS)
        data['width'] = w
        out.append(data)
        ctx.close()
    browser.close()
print(json.dumps(out, indent=1))
