# Stage 4.3 production baseline evidence (read-only)

Everything in this directory was captured **read-only** (`GET`/`HEAD` only — no POST/PUT/PATCH/DELETE,
no login, no content or settings write) against `https://conexaobr.ie` on **2026-09-23**.

| File | What it is |
|---|---|
| `rest_probe.py` | the authenticated read-only inventory harness (credentials read from an env file **outside** the repository, never written to disk by the script) |
| `rest-probe.json` + `raw-rest/**` | machine-readable inventory + raw bodies: `/wp-json/` namespaces, plugin list with versions, themes, post types, taxonomies, settings, per-type counts, EN-language REST probes, menu/menu-item queries, sitemap and robots captures, legacy-redirect status chains |
| `probe.py`, `baseline.json`, `baseline.md`, `findings.md` | round-1 anonymous probe. **Caveat:** its first pass sent a browser User-Agent, which the WordPress.com edge answers with `403` for authenticated calls and intermittently `429` for uncached HTML. Those rows are marked in `findings.md`; the authoritative numbers are the re-measured ones in `rest-probe.json` and `s43-before*.json` |
| `s43-before*.json` / `s43-before*.md` + `s43-before*-raw/**` | the Phase 19 HTTP matrix in `--phase before` mode (measure, no assertions), produced by `scripts/stage43-verify-english.py` |
| `s43-after-selftest-b2.{json,md}` | the same harness in `--phase after` mode, run deliberately against the *pre-deployment* site to prove the assertion path works (12 of 20 checks fail, with exact reasons) |

## Redaction

The raw REST captures of `/wp-json/wp/v2/settings` and `/wp-json/wp/v2/users/me` contained the site
administrator’s e-mail address. It has been replaced with `[redacted-admin-email]` in those two files
so the evidence does not carry unnecessary personal data. Nothing else was altered, and no credential
is stored anywhere in this directory (verified by searching the tree for the credential string and for
its base64 form — the only match in the repository is the pre-existing hard-coded fallback in
`scripts/ivvcc-import-robust.py`, which is reported, not modified).

## Re-running

```bash
# anonymous matrix (no credentials required)
python3 scripts/stage43-verify-english.py --phase before --out /tmp/s43-before

# authenticated inventory (write the credential file OUTSIDE the repo first)
printf 'WP_USERNAME=...\nWP_APPLICATION_PASSWORD=...\n' > /tmp/prod-rest.env && chmod 600 /tmp/prod-rest.env
python3 stage43-work/production-baseline/rest_probe.py rest,counts,rest2,http,follow
```

Both harnesses are safe to re-run: they only read, they space their requests, and they back off on
HTTP 429.
