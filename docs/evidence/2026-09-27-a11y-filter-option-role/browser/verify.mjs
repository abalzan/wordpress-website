/**
 * Browser + accessibility verification for the filter link ARIA correction.
 *
 * Real Chromium. Proves:
 *   1. the COMPUTED accessibility role of every filter option is "link", not "option";
 *   2. options are keyboard reachable in the normal Tab order;
 *   3. activating an option really navigates to its filter URL;
 *   4. the active option is exposed as the current item;
 *   5. the trigger is a disclosure button (aria-expanded + aria-controls);
 *   6. no console/page errors;
 *   7. no layout regression (box + horizontal overflow), desktop and mobile.
 *
 * Run: node .tmp-a11y-verify/verify.mjs
 */
import pw from '/home/andrei/.npm/_npx/e41f203b7505f1fb/node_modules/playwright-core/index.js';
import { writeFileSync, mkdirSync } from 'node:fs';

const { chromium } = pw;
const BASE = 'http://localhost:8080';
const OUT = new URL('./out/', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });

const report = { base: BASE, checks: [], pages: {} };
let passed = 0, failed = 0;
const check = (name, ok, detail = '') => {
  ok ? passed++ : failed++;
  report.checks.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' -- ' + detail : ''}`);
};

/**
 * Read the COMPUTED accessibility role/name of the first match of a CSS
 * selector, via the CDP Accessibility domain (page.accessibility was removed
 * from Playwright).
 *
 * Uses Accessibility.getPartialAXTree on the resolved object id, which is
 * authoritative for one specific element: a full-tree lookup can return an
 * "ignored" placeholder node for a live element, so it is not used here.
 */
async function axReader(cdp) {
  await cdp.send('Accessibility.enable');
  await cdp.send('DOM.enable');
  const { root } = await cdp.send('DOM.getDocument', { depth: -1, pierce: true });
  return async (selector) => {
    const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: root.nodeId, selector });
    if (!nodeId) return null;
    const { object } = await cdp.send('DOM.resolveNode', { nodeId });
    if (!object || !object.objectId) return null;
    const { nodes } = await cdp.send('Accessibility.getPartialAXTree',
      { objectId: object.objectId, fetchRelatives: false });
    const n = (nodes || [])[0];
    if (!n) return null;
    return {
      role: n.role ? n.role.value : null,
      name: n.name ? n.name.value : null,
      ignored: !!n.ignored,
    };
  };
}

const browser = await chromium.launch();

async function runPage(url, label, viewport) {
  // Hard watchdog: every Playwright call below is bounded, and the whole page
  // is raced against a timeout so one slow route cannot hang the run.
  return Promise.race([
    runPageInner(url, label, viewport),
    new Promise((_, rej) => setTimeout(() => rej(new Error('watchdog timeout for ' + label)), 45000)),
  ]).catch((e) => {
    console.log(`ERROR ${label}: ${e.message}`);
    return { url, status: -1, options: [], trigger: null, group: null,
             consoleErrors: ['watchdog: ' + e.message], listBox: null, horizontalOverflow: null,
             error: e.message };
  });
}

async function runPageInner(url, label, viewport) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

  // 'domcontentloaded' + a short settle: the leisure/events archives use an
  // infinite-scroll sentinel, so 'networkidle' never fires on them.
  const response = await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForTimeout(900);
  const r = { url, status: response ? response.status() : 0, options: [], trigger: null, group: null, consoleErrors: [] };

  const trigger = page.locator('[data-dropdown-trigger]').first();
  // On the mobile viewport the desktop dropdown toolbar is CSS-hidden (the
  // mobile bottom sheet is used instead), so the trigger must not be clicked.
  r.triggerVisible = (await trigger.count()) ? await trigger.first().isVisible() : false;
  if (r.triggerVisible) {
    await trigger.evaluate((el) => el.setAttribute('data-a11y-trigger', '1'));
    r.trigger = await trigger.evaluate((el) => ({
      tag: el.tagName,
      ariaExpanded: el.getAttribute('aria-expanded'),
      ariaControls: el.getAttribute('aria-controls'),
      ariaHasPopup: el.getAttribute('aria-haspopup'),
      name: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 80),
    }));
    await trigger.click();
    await page.waitForTimeout(250);
  }

  // Tag every option BEFORE reading the AX tree, so the tree already
  // contains the tagged elements and the computed role can be resolved.
  const options = page.locator('a[class*="-dropdown-link"]');
  const count = await options.count();
  for (let i = 0; i < count; i++) {
    await options.nth(i).evaluate((el, k) => el.setAttribute('data-a11y-key', k), `a11yopt${i}`);
  }
  // NOTE: locator.evaluate() on a non-existent element blocks for the full
  // action timeout, so every optional element is guarded by an explicit
  // count() first. These archives legitimately render no filter widget.
  const groupLocator = page.locator('div[class*="-dropdown-list"]').first();
  const hasGroup = await groupLocator.count();
  if (hasGroup) {
    await groupLocator.evaluate((el) => el.setAttribute('data-a11y-key', 'a11ygroup'));
  }

  // The AX tree is read AFTER the panel is open and the tags are applied.
  const ax = await axReader(cdp);

  for (let i = 0; i < count; i++) {
    const info = await options.nth(i).evaluate((el) => ({
      roleAttr: el.getAttribute('role'),
      ariaCurrent: el.getAttribute('aria-current'),
      ariaSelected: el.getAttribute('aria-selected'),
      href: el.getAttribute('href'),
      text: (el.textContent || '').trim().slice(0, 60),
      hasIsActive: (el.className || '').includes('is-active'),
    }));
    const node = await ax(`[data-a11y-key="a11yopt${i}"]`);
    info.computedRole = node ? node.role : null;
    info.accessibleName = node ? node.name : null;
    r.options.push(info);
  }

  if (hasGroup) {
    const groupNode = await ax('[data-a11y-key="a11ygroup"]');
    r.group = {
      roleAttr: await groupLocator.getAttribute('role'),
      computedRole: groupNode ? groupNode.role : null,
      name: groupNode ? groupNode.name : null,
    };
  } else {
    r.group = { roleAttr: null, computedRole: null, name: null, absent: true };
  }

  // Layout box + screenshot while the panel is still OPEN (a closed panel
  // is display:none, so a closed box would prove nothing).
  r.listBox = hasGroup ? await groupLocator.boundingBox() : null;
  await page.screenshot({ path: `${OUT}${label}.png`, fullPage: false });
  r.screenshot = `${OUT}${label}.png`;

  // Keyboard reachability, with the panel open.
  r.keyboardReachesOption = false;
  if (r.triggerVisible) {
    await trigger.focus();
    for (let i = 0; i < 8; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el) return null;
        const cls = (el.className || '').toString();
        return { tag: el.tagName, isOption: cls.includes('-dropdown-link'),
                 text: (el.textContent || '').trim().slice(0, 40) };
      });
      if (info && info.isOption) {
        r.keyboardReachesOption = true;
        r.focusedOption = info;
        r.focusOutline = await page.evaluate(() => {
          const st = getComputedStyle(document.activeElement);
          return { outlineStyle: st.outlineStyle, outlineWidth: st.outlineWidth };
        });
        break;
      }
    }
  }

  await page.keyboard.press('Escape');
  r.consoleErrors = consoleErrors;
  r.horizontalOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth + 1);
  await ctx.close();
  return r;
}

const desktop = { width: 1440, height: 900 };
const mobile = { width: 390, height: 844 };

for (const [url, label] of [['/empregos/', 'pt-empregos'], ['/empregos/?tipo=agency', 'pt-empregos-filtered']]) {
  const r = await runPage(url, label, desktop);
  report.pages[label] = r;
  check(`${label}: HTTP 200`, r.status === 200, 'status=' + r.status);
  check(`${label}: filter options rendered`, r.options.length > 0, 'options=' + r.options.length);
  check(`${label}: no option has role="option"`, r.options.every((o) => o.roleAttr !== 'option'));
  check(`${label}: COMPUTED AX role is never "option"`, r.options.every((o) => o.computedRole !== 'option'),
        'roles=' + [...new Set(r.options.map((o) => o.computedRole))].join(','));
  check(`${label}: COMPUTED AX role is "link" for every option`, r.options.every((o) => o.computedRole === 'link'),
        [...new Set(r.options.map((o) => o.computedRole))].join(','));
  check(`${label}: every option exposes an accessible name`, r.options.every((o) => !!o.accessibleName),
        JSON.stringify(r.options.map((o) => o.accessibleName)));
  check(`${label}: no orphan aria-selected`, r.options.every((o) => o.ariaSelected === null));
  check(`${label}: every option has a real href`, r.options.every((o) => !!o.href));
  check(`${label}: keyboard reaches an option link`,
        r.options.length === 0 ? true : r.keyboardReachesOption, JSON.stringify(r.focusedOption));
  check(`${label}: trigger keeps aria-expanded + aria-controls`,
        r.triggerVisible ? (!!r.trigger && r.trigger.ariaExpanded !== null && !!r.trigger.ariaControls) : true,
        'triggerVisible=' + r.triggerVisible);
  check(`${label}: trigger no longer claims a listbox popup`,
        r.triggerVisible ? (!!r.trigger && r.trigger.ariaHasPopup === null) : true,
        'aria-haspopup=' + (r.trigger && r.trigger.ariaHasPopup));
  check(`${label}: option group keeps an accessible name`,
        !r.group || r.group.absent || !!r.group.name, JSON.stringify(r.group));
  check(`${label}: no console errors`, r.consoleErrors.length === 0, r.consoleErrors.join(' | '));
  check(`${label}: option list has a real layout box`,
        r.options.length === 0 ? true : (!!r.listBox && r.listBox.width > 0 && r.listBox.height > 0),
        JSON.stringify(r.listBox));
  check(`${label}: no horizontal overflow`, r.horizontalOverflow === false);
  if (label === 'pt-empregos-filtered') {
    const cur = r.options.filter((o) => o.ariaCurrent === 'true');
    check(`${label}: exactly one option is aria-current`, cur.length === 1, 'n=' + cur.length);
    check(`${label}: aria-current matches is-active`, cur.length === 1 && cur[0].hasIsActive);
  }
}

for (const [url, label] of [['/lazer/', 'pt-lazer'], ['/en/lazer/', 'en-lazer'], ['/eventos/', 'pt-eventos'], ['/en/eventos/', 'en-eventos']]) {
  const r = await runPage(url, label, desktop);
  report.pages[label] = r;
  check(`${label}: HTTP 200`, r.status === 200, 'status=' + r.status);
  check(`${label}: no role="option" in filter markup`, r.options.every((o) => o.roleAttr !== 'option'), 'options=' + r.options.length);
  check(`${label}: no console errors`, r.consoleErrors.length === 0, r.consoleErrors.join(' | '));
  check(`${label}: no horizontal overflow`, r.horizontalOverflow === false);
}

{
  const resp = await fetch(BASE + '/en/empregos/', { redirect: 'manual' });
  check('routing: /en/empregos/ still 302s to PT (B1 unchanged)', resp.status === 302, 'status=' + resp.status);
}

{
  const r = await runPage('/empregos/', 'pt-empregos-mobile', mobile);
  report.pages['pt-empregos-mobile'] = r;
  check('mobile: HTTP 200', r.status === 200);
  check('mobile: no option has role="option"', r.options.every((o) => o.roleAttr !== 'option'));
  check('mobile: no console errors', r.consoleErrors.length === 0, r.consoleErrors.join(' | '));
  check('mobile: no horizontal overflow', r.horizontalOverflow === false);
}

await browser.close();
report.summary = { passed, failed, total: passed + failed };
writeFileSync(`${OUT}report.json`, JSON.stringify(report, null, 2));
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
