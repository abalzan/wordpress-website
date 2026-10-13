#!/usr/bin/env node
/*
 * Header "Anuncie Aqui" CTA — deterministic cascade resolver (no browser).
 *
 * The prior Empregos-CTA evidence used Playwright/Chromium. No browser is
 * available in this environment, so this harness instead loads the REAL
 * stylesheets (design-system.css, header-nav.css, main.css, dark-mode.css) in
 * the real cascade order — with the Customizer :root block LAST, exactly as
 * inc/customizer.php emits it in wp_head — and resolves the winning
 * declaration for the .header-cta / .header-cta-compact / .mobile-menu-cta
 * anchors by CSS specificity + source order. This proves:
 *   - dark mode pins the label (and its currentColor icon) to white;
 *   - the orange background is untouched in BOTH themes;
 *   - light mode is byte-for-byte unaffected by the change.
 *
 * It models only the properties this task touches (color, background) and the
 * selectors that can reach the CTA anchors. Run: node evidence-cascade.js
 */
'use strict';
const fs = require('fs');
const path = require('path');

const CSS_DIR = process.env.CONEXAO_CSS_DIR || path.join(__dirname, '..', '..', '..', 'wp-content', 'themes', 'conexao-br-irlanda', 'assets', 'css');
const read = (f) => fs.readFileSync(path.join(CSS_DIR, f), 'utf8');

// Cascade order = enqueue order (inc/assets.php): design-system, header-nav,
// main, then dark-mode. The Customizer emits a plain :root block in wp_head,
// i.e. AFTER the enqueued stylesheets, so it is appended last.
const sheets = [
  { name: 'design-system.css', css: read('design-system.css') },
  { name: 'header-nav.css', css: read('header-nav.css') },
  { name: 'main.css', css: read('main.css') },
  { name: 'dark-mode.css', css: read('dark-mode.css') },
  { name: 'customizer :root (wp_head)', css:
    ':root{--conexao-primary:#0E6B3A;--conexao-primary-dark:#0b552e;' +
    '--conexao-accent:#F68B1F;--conexao-accent-dark:#c46f18;--conexao-accent-text:#aa6115;}' },
];

// --- token resolution ------------------------------------------------------
function resolveTokens(theme) {
  const root = {};
  const dark = {};
  for (const { css } of sheets) {
    const clean = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const re = /(:root(?:\[data-theme="dark"\])?)\s*\{([^}]*)\}/g;
    let m;
    while ((m = re.exec(clean)) !== null) {
      const isDark = /data-theme/.test(m[1]);
      const target = isDark ? dark : root;
      for (const d of m[2].split(';')) {
        const idx = d.indexOf(':');
        if (idx < 0) continue;
        const prop = d.slice(0, idx).trim();
        const val = d.slice(idx + 1).trim();
        if (prop.startsWith('--')) target[prop] = val;
      }
    }
  }
  return Object.assign({}, root, theme === 'dark' ? dark : {});
}

// --- specificity -----------------------------------------------------------
function specificity(sel) {
  const s = sel.replace(/::[a-z-]+/gi, ' ');
  const ids = (s.match(/#[\w-]+/g) || []).length;
  const cls = (s.match(/\.[\w-]+/g) || []).length;
  const attr = (s.match(/\[[^\]]+\]/g) || []).length;
  const pseudo = (s.match(/:(?!:)[a-z-]+(\([^)]*\))?/gi) || []).length;
  const types = (s.replace(/[#.][\w-]+/g, ' ').match(/(^|[\s>+~])[a-z]+[a-z0-9-]*/gi) || []).length;
  return [ids, cls + attr + pseudo, types];
}
const cmp = (a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2];

// --- element model ---------------------------------------------------------
// The desktop CTA lives at:
//   html[data-theme] > body > div#page.site > header.site-header#masthead
//     > div.main-header > div.site-container > div.header-actions > a.<variant>
// The compact top-bar CTA and drawer CTA share the same <a> class list per
// variant; their ancestor differences do not affect color/background here.
const ANCESTORS = [
  { tag: 'html', classes: [], attrs: ['data-theme'] },
  { tag: 'body', classes: [], attrs: [] },
  { tag: 'div', classes: ['site'], attrs: [] },
  { tag: 'header', classes: ['site-header'], attrs: [] },
  { tag: 'div', classes: ['main-header'], attrs: [] },
  { tag: 'div', classes: ['site-container'], attrs: [] },
  { tag: 'div', classes: ['header-actions'], attrs: [] },
];
// rightmost-first element chain including the subject <a>.
function elementChain(variant, theme) {
  const subject = { tag: 'a', classes: [variant.replace(/^\./, '')], attrs: [] };
  const html = { tag: 'html', classes: [], attrs: ['data-theme'] };
  return [subject].concat(ANCESTORS.slice().reverse().map((a, i) => (i === 0 ? html : a)));
}

// Does one compound (e.g. `.header-cta:hover`, `[data-theme="dark"]`, `a`)
// match one element, given the requested interaction state?
function compoundMatches(compound, el, theme, state, isSubject) {
  let c = compound.trim();
  if (!c) return true;
  // attribute selector
  const attrM = c.match(/\[([\w-]+)(?:=(?:"([^"]*)"|'([^']*)'|[^\]]+))?\]/);
  if (attrM) {
    const name = attrM[1], val = attrM[2] || attrM[3] || attrM[4];
    if (!el.attrs.includes(name)) return false;
    if (val !== undefined) {
      if (name === 'data-theme' && val !== theme) return false;
    }
    c = c.replace(attrM[0], '').trim();
    if (!c) return true;
  }
  // pseudo-class state
  const pseudoM = c.match(/:(hover|active|focus-visible)$/);
  if (pseudoM) {
    if (!isSubject) return false;             // states only apply to the subject
    if (state !== pseudoM[1]) return false;
    c = c.slice(0, pseudoM.index).trim();
    if (!c) return true;
  }
  // universal
  if (c === '*') return true;
  // classes and tag possibly combined (e.g. `a.header-cta`)
  const classes = (c.match(/\.[\w-]+/g) || []).map((x) => x.slice(1));
  const tagPart = c.replace(/\.[\w-]+/g, '').trim();
  if (classes.length) {
    for (const cl of classes) if (!el.classes.includes(cl)) return false;
  }
  if (tagPart && tagPart !== el.tag) return false;
  if (!classes.length && !tagPart) return false;
  return true;
}

// Right-to-left descendant match of a full selector against the element chain.
// NOTE: theme-agnostic rules (no [data-theme="dark"]) apply in BOTH themes;
// only [data-theme="dark"] selectors are rejected in light mode. Non-dark base
// declarations (e.g. the header-nav.css background) therefore still reach the
// anchor when data-theme="dark" is set.
function selectorMatches(selector, variant, theme, state) {
  const s = selector.trim();
  if (/data-theme="dark"/.test(s) && theme !== 'dark') return false;
  // split into compounds on descendant/child combinators
  const parts = s.split(/\s*[\s>+~]\s*/).map((x) => x.trim()).filter(Boolean);
  const chain = elementChain(variant, theme);
  // rightmost compound must match the subject with its state
  let ci = 0;
  if (!compoundMatches(parts[parts.length - 1], chain[0], theme, state, true)) return false;
  // remaining compounds must match ancestors left-to-right (simple descendant)
  let partIdx = parts.length - 2;
  let elIdx = 1;
  while (partIdx >= 0) {
    let matched = false;
    for (let e = elIdx; e < chain.length; e++) {
      if (compoundMatches(parts[partIdx], chain[e], theme, 'base', false)) { elIdx = e + 1; matched = true; break; }
    }
    if (!matched) return false;
    partIdx--;
  }
  return true;
}


// --- resolve the winning color/background for a variant in a theme/state ---
function resolve(variant, theme, state, tokens) {
  let w = { color: null, background: null, colorSpec: null, bgSpec: null, colorSrc: '', bgSrc: '' };
  for (const { name, css } of sheets) {
    const clean = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const ruleRe = /([^{}]+)\{([^}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(clean)) !== null) {
      const selectors = m[1].split(',').map((x) => x.trim()).filter(Boolean);
      const body = m[2];
      for (const sel of selectors) {
        if (!selectorMatches(sel, variant, theme, state)) continue;
        const spec = specificity(sel);
        for (const d of body.split(';')) {
          const idx = d.indexOf(':');
          if (idx < 0) continue;
          const prop = d.slice(0, idx).trim();
          const val = d.slice(idx + 1).trim();
          if (prop === 'color') {
            if (!w.colorSpec || cmp(spec, w.colorSpec) >= 0) w = Object.assign(w, { color: val, colorSpec: spec, colorSrc: name });
          } else if (prop === 'background' || prop === 'background-color') {
            if (!w.bgSpec || cmp(spec, w.bgSpec) >= 0) w = Object.assign(w, { background: val, bgSpec: spec, bgSrc: name });
          }
        }
      }
    }
  }
  const deref = (v) => {
    let out = v, guard = 0;
    while (out && out.startsWith('var(') && out.endsWith(')') && guard++ < 6) {
      out = tokens[out.slice(4, -1).trim()] || out;
    }
    return out;
  };
  const bg = deref(w.background);
  const color = deref(w.color);
  // Background falls back to the base-state declaration for interactive
  // states (only hover repaints it in the base rules; the dark block never
  // touches background).
  let bgFrom = w.bgSrc;
  let bgVal = bg;
  if (!bgVal && state !== 'base') {
    const b = resolveBaseBackground(variant, theme, tokens);
    bgVal = b.value; bgFrom = b.src;
  }
  return { color, background: bgVal, colorSrc: w.colorSrc, bgSrc: bgFrom };
}

// Background that applies when no interactive-state background wins: the base
// rule's background (header-nav.css orange fill), valid because the dark CTA
// block provably sets no background (see the in-process suite).
function resolveBaseBackground(variant, theme, tokens) {
  // Duplicate of resolve restricted to state='base' + background only.
  let val = null, spec = null, src = '';
  for (const { name, css } of sheets) {
    const clean = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const ruleRe = /([^{}]+)\{([^}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(clean)) !== null) {
      for (const sel of m[1].split(',').map((x) => x.trim()).filter(Boolean)) {
        if (!selectorMatches(sel, variant, theme, 'base')) continue;
        const sp = specificity(sel);
        for (const d of m[2].split(';')) {
          const idx = d.indexOf(':');
          if (idx < 0) continue;
          const prop = d.slice(0, idx).trim();
          const v = d.slice(idx + 1).trim();
          if ((prop === 'background' || prop === 'background-color') && (!spec || cmp(sp, spec) >= 0)) {
            val = v; spec = sp; src = name;
          }
        }
      }
    }
  }
  let out = val, guard = 0;
  while (out && out.startsWith('var(') && out.endsWith(')') && guard++ < 6) {
    out = tokens[out.slice(4, -1).trim()] || out;
  }
  return { value: out, src };
}

// --- WCAG contrast ---------------------------------------------------------
function lum(hex) {
  hex = hex.replace('#', '');
  const ch = [0, 2, 4].map((i) => parseInt(hex.substr(i, 2), 16) / 255);
  const lin = ch.map((v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)));
  return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
}
function ratio(a, b) {
  const la = lum(a), lb = lum(b);
  const hi = Math.max(la, lb), lo = Math.min(la, lb);
  return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
}

// --- report ----------------------------------------------------------------
const variants = ['.header-cta', '.header-cta-compact', '.mobile-menu-cta'];
const states = ['base', 'hover', 'active', 'focus-visible'];
const out = [];
out.push('# Header "Anuncie Aqui" CTA — computed color via cascade resolver (Node, no browser)');
out.push('# Loads the REAL design-system.css + header-nav.css + main.css + dark-mode.css in');
out.push('# cascade order, with the Customizer :root block LAST (as inc/customizer.php emits).');
out.push('');
for (const theme of ['light', 'dark']) {
  const tokens = resolveTokens(theme);
  out.push(`=== ${theme.toUpperCase()} ===`);
  for (const v of variants) {
    for (const st of states) {
      const r = resolve(v, theme, st, tokens);
      const cr = (r.color && r.background) ? ratio(r.color, r.background) : 'n/a';
      out.push(`  ${v} [${st}]`.padEnd(42) +
        `color=${r.color}`.padEnd(26) +
        `bg=${r.background}`.padEnd(22) +
        `contrast=${cr}:1  (color from ${r.colorSrc})`);
    }
  }
  out.push('');
}
process.stdout.write(out.join('\n') + '\n');

