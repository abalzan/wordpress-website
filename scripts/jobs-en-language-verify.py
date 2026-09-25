#!/usr/bin/env python3
"""Stage 8 — EN Jobs page: end-to-end bilingual verification (READ ONLY).

Proves the user-level contract of the Jobs surface: `/en/jobs/` is a genuine
English presentation and `/empregos/` stays fully Portuguese, from the SAME
data source, with no CSS/JS workaround and no frontend translation layer.

The «Vagas»/“Openings” preview section (the real `job` records listed on the
landing) was intentionally removed from both landing pages by product decision —
rendering only: the job records, the language-aware query in
inc/empregos-landing.php and the section CSS are retained. §12 asserts the
section stays absent in either language; the card-level assertions whose only
subject was that section are reported as SKIP instead.

    python3 scripts/jobs-en-language-verify.py --base http://localhost:8080

Read-only: HTTP GET requests only; no credentials, no writes.
Exit code is non-zero when any check fails.
"""
import argparse
import html
import json
import re
import sys
import urllib.error
import urllib.request

PASSED: list = []
FAILED: list = []


def check(ok: bool, name: str, detail: str = "") -> None:
    row = {"check": name, "pass": bool(ok), "detail": detail}
    (PASSED if ok else FAILED).append(row)
    print(("  PASS: " if ok else "  FAIL: ") + name + (f" — {detail}" if not ok and detail else ""))


def fetch(base: str, path: str):
    """GET a URL, returning (status, body)."""
    url = base.rstrip("/") + path
    req = urllib.request.Request(url, headers={"User-Agent": "conexao-jobs-en-verify/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", "replace")
    except Exception as exc:  # noqa: BLE001
        return 0, str(exc)


def visible_text(page: str) -> str:
    """Rendered text, with script/style/svg removed."""
    page = re.sub(r"(?is)<(script|style|svg)[^>]*>.*?</\1>", " ", page)
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"(?s)<[^>]+>", " ", page)))


def attributes(page: str, name: str) -> list:
    return [m.group(1) for m in re.finditer(r'\b' + name + r'="([^"]*)"', page)]


# Portuguese strings that must NEVER appear as user-facing text on /en/jobs/.
# "Irlanda" is intentionally absent: "Conexão BR Irlanda" is the brand and must
# not be translated, so it is asserted as an allowed literal below instead.
PT_LEAKS = [
    "Armazém", "Operacional Geral", "Fábrica / Produção", "Logística", "Hotelaria",
    "Limpeza", "Varejo", "Construção Civil", "Escritório / Administrativo",
    "Agricultura / Sazonal", "Saúde / Cuidados", "Nacional", "Empregador",
    "Enfermeiros", "Operadores de produção", "Processamento de alimentos",
    "Profissionais de saúde", "Equipe de apoio", "Colheita e processamento",
    "Cuidados Domiciliários", "Agroalimentar", "Alimentos", "Saúde e Cuidados",
    "Saúde pública", "Vagas", "Oportunidades", "Oportunidade", "Todas", "Todos",
    "Empregos", "Temporário", "Permanente", "Licenciada", "as vagas",
    "Acompanhe nossas", "min de leitura", "de August", "de agosto",
    "Página não encontrada", "Filtrar por", "Procurar localização",
    "Nenhuma", "Limpar filtros", "Mostrar resultados", "Avisos importantes",
    "Atenção", "Paginação", "← Anterior", "Próximo", "Agência de recrutamento",
    "Setor público", "Histórico de Employment Permits", "Área de trabalho",
    "Localização", "Tipo de oportunidade", "Tipo de contrato", "registro:",
    "Consultar as regras oficiais", "Certifique-se de que",
]

# English strings the page must render.
EN_EXPECTED = [
    "Opportunities", "See openings on Instagram", "Openings", "Job opportunities",
    "Opportunity type", "Work area", "Contract type", "Location",
    "Recruitment agency", "Public sector", "Employment Permit history",
    "Temporary", "Permanent", "Warehouse", "General Operative",
    "Factory / Production", "Logistics", "Hospitality", "Cleaning", "Retail",
    "Construction", "Office / Administration", "Agriculture / Seasonal",
    "Healthcare / Care", "Nationwide", "Filter", "Filters", "Clear",
    "Show results", "No location found", "Warning",
    "Make sure you have the legal right to work in Ireland",
    "Check the official rules", "opens in a new tab",
    "opportunities found", "Next", "Previous", "registration:",
    "Food — Meat Processing", "Public healthcare (HSE)", "Nurses",
    "Healthcare professionals", "Production operators", "Food processing",
    "Home carers", "Home Care", "Healthcare and Care", "Support staff",
    "Harvesting and processing",
]

# Portuguese strings that must still render on /empregos/.
PT_EXPECTED = [
    "Empregos", "Oportunidades", "Ver vagas no Instagram",
    "Oportunidades de emprego", "Tipo de oportunidade", "Área de trabalho",
    "Tipo de contrato", "Localização", "Todas", "Todos",
    "Agência de recrutamento", "Setor público", "Histórico de Employment Permits",
    "Armazém", "Operacional Geral", "Fábrica / Produção", "Logística", "Hotelaria",
    "Limpeza", "Varejo", "Construção Civil", "Escritório / Administrativo",
    "Agricultura / Sazonal", "Saúde / Cuidados", "Nacional", "Temporário",
    "Permanente", "Licenciada", "Acompanhe nossas",
    "oportunidades encontradas",
    "Próximo", "Filtrar", "Filtros", "Limpar", "Mostrar resultados",
    "Nenhuma", "Atenção", "Alimentos — Processamento de Carne", "Enfermeiros",
    "Saúde e Cuidados",
]

# Portuguese strings that live in ATTRIBUTES (stripped by visible_text()).
PT_ATTR_EXPECTED = [
    "As vagas mais recentes estão no nosso Instagram",  # meta description / og:description
    "Avisos importantes sobre emprego",                  # notice landmark label
    "Paginação",                                        # pagination landmark label
    "Procurar localização",                             # location search placeholder
]

# Real Irish place names must be byte-identical on BOTH pages.
PLACE_NAMES = [
    "Athlone", "Carlow", "Cavan", "Cork", "Dublin", "Dundalk", "Galway", "Kerry",
    "Kilkenny", "Limerick", "Naas", "Portlaoise", "Roscommon", "Shannon",
    "Sligo", "Waterford",
]

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://localhost:8080")
    parser.add_argument("--write", default="")
    args = parser.parse_args()

    base = args.base

    print("== Stage 8 — EN Jobs bilingual verification (HTTP) ==\n")

    print("-- Availability --")
    en_status, en = fetch(base, "/en/jobs/")
    pt_status, pt = fetch(base, "/empregos/")
    check(en_status == 200, "GET /en/jobs/ is 200", str(en_status))
    check(pt_status == 200, "GET /empregos/ is 200", str(pt_status))
    if en_status != 200 or pt_status != 200:
        print("\ncannot continue without both pages")
        return 1

    en_text = visible_text(en)
    pt_text = visible_text(pt)

    # The directory paginates at 24 cards: the Employment-Permit employer cards
    # (sector/roles) and the notice/pagination chrome live on the later pages, so
    # the full EN corpus is collected across every page before auditing. The
    # LANGUAGE contract is per-page (checked again in section 11), this is only
    # about not asserting page-1-only expectations against a paginated list.
    en_all = en_text
    for page_no in (2, 3):
        status, page = fetch(base, f"/en/jobs/?pagina={page_no}")
        if status == 200:
            en_all += " " + visible_text(page)

    # -----------------------------------------------------------------------
    print("\n-- 1. /en/jobs/ renders the real English page body --")
    check("Our latest job openings" in en_text, "the EN Jobs body is the authored English copy")
    check("Follow our posts" in en_text, "the second EN sentence is present")
    check("As vagas mais recentes" not in en_text, "the PT body does not leak into EN")
    check("Acompanhe nossas publicações" not in en_text, "no PT body sentence leaks into EN")
    title = re.search(r"<title>([^<]*)</title>", en)
    check(bool(title) and "Jobs" in title.group(1), "the EN <title> is English",
          title.group(1) if title else "no <title>")

    # -----------------------------------------------------------------------
    print("\n-- 2. No Portuguese user-facing text on /en/jobs/ --")
    leaks = [(n, en_text.count(n)) for n in PT_LEAKS if n in en_text]
    check(not leaks, "no Portuguese UI string leaks into /en/jobs/",
          "; ".join(f"{n}×{c}" for n, c in leaks))
    # Brand and proper nouns are never translated.
    check("Conexão BR Irlanda" in en_text, "the brand name is preserved (never translated)")
    check("Department of Enterprise" in en_text,
          "the official Department of Enterprise reference is preserved")
    check("Employment Permit" in en_text, "the official Employment Permit term is preserved")

    # -----------------------------------------------------------------------
    print("\n-- 3. Required English UI is present --")
    missing = [n for n in EN_EXPECTED if n not in en_all]
    check(not missing, "every required English string renders (visible text)", ", ".join(missing))

    # Some contract strings are accessibility labels rather than visible copy
    # (the notice and the pagination landmark). They are asserted against the
    # raw markup, because stripping tags would hide exactly what is measured.
    for attr_string in ("Important notices about jobs and Employment Permits", "Pagination"):
        check(attr_string in en, f"EN accessibility label present: {attr_string!r}")

    # -----------------------------------------------------------------------
    print("\n-- 4. Dates and read time are language-correct --")
    en_dates = re.findall(r"\b\d{1,2} de \w+ de \d{4}\b", en_text)
    check(not en_dates, "no PT-style date on /en/jobs/", ", ".join(en_dates))
    check("de August" not in en_text, 'the mixed "25 de August de 2026" form is gone')
    check("min de leitura" not in en_text, "no Portuguese read time on /en/jobs/")
    # The «Vagas»/“Openings” preview section — removed from the Jobs landing by
    # product decision (rendering only: see the comment in page-empregos.php) —
    # was the only dated/read-time surface on /empregos/ and /en/jobs/. Its
    # card-level date/read-time assertions therefore run only while that surface
    # is rendered (restoring the section re-enables them). The source-level
    # date-format and read-time contract stays asserted in-process by
    # tests/test-jobs-en-language.php.
    if "empregos-jobs" in en:
        en_long = re.findall(r"\b[A-Z][a-z]+ \d{1,2}, \d{4}\b", en_text)
        check(bool(en_long), "an English long date renders", str(en_long[:2]))
        check("minute read" in en_text, "the read time is English")
    else:
        print("  SKIP: no dated/read-time surface on /en/jobs/ (the preview section is not rendered)")

    # -----------------------------------------------------------------------
    print("\n-- 5. Work area / location / contract / type labels --")
    for label in ("Warehouse", "Nationwide", "Temporary", "Permanent",
                  "Recruitment agency", "Public sector", "Employment Permit history"):
        check(label in en_text, f"EN label: {label}")
    for name in PLACE_NAMES:
        check(name in en_text, f"real place name preserved on EN: {name}")

    # Desktop and mobile must not diverge.
    for label in ("Opportunity type", "Work area", "Contract type", "Location"):
        count = en_text.count(label)
        check(count >= 2, f"'{label}' is identical on desktop and mobile", f"count={count}")

    # -----------------------------------------------------------------------
    print("\n-- 6. Filter VALUES (slugs) are unchanged --")
    for value in ("area=warehouse", "contrato=temporario", "contrato=permanente",
                  "localizacao=nacional", "tipo=agency", "tipo=permit_history"):
        check(value in en, f"internal filter value unchanged: ?{value}")
    check(not re.search(r"area=(armazem|logistica|fabrica)", en),
          "no translated filter slug was introduced")

    # -----------------------------------------------------------------------
    print("\n-- 7. Result count, empty states, pagination --")
    counts = re.findall(r"\b\d+ (?:opportunities|opportunity) found\b", en_text)
    check(bool(counts), "the result count is English and pluralized", ", ".join(counts))
    check("No opportunities found" in en_text or "No location found" in en_text,
          "the empty/partial empty states are English")
    check("Next" in en_text or "page-numbers" in en, "pagination renders in English")

    # -----------------------------------------------------------------------
    print("\n-- 8. Accessibility and metadata --")
    for attr in ("alt", "aria-label", "title", "placeholder"):
        values = set(attributes(en, attr))
        pt_in_attr = sorted(v for v in values if any(n in v for n in PT_LEAKS))
        check(not pt_in_attr, f"no Portuguese value in {attr} attributes",
              "; ".join(repr(v) for v in pt_in_attr[:5]))
    alts = set(attributes(en, "alt"))
    check(bool(alts & {"Jobs", "Opportunities"}),
          "the featured image alt is English on /en/jobs/", str(sorted(alts)[:5]))

    # -----------------------------------------------------------------------
    print("\n-- 9. Internal links stay in the English context --")
    # Scope: the JOBS page content. The site chrome (header CTA "Advertise",
    # footer legal links, the PT language-switcher entry and the hreflang
    # alternates) legitimately points at Portuguese for pages that have no
    # English translation yet — the approved B1 state, identical on /en/,
    # /en/blog/ and /en/eventos/, and explicitly out of this stage's scope.
    # What this stage owns is that no link INSIDE the Jobs content escapes.
    jobs_body = re.search(r"(?s)<main.*?</main>", en)
    jobs_body = jobs_body.group(0) if jobs_body else en
    body_links = re.findall(r'href="(http://localhost:8080[^"#?]*)', jobs_body)
    pt_body_links = sorted({u for u in body_links if "/en/" not in u})
    check(not pt_body_links, "no link inside the Jobs content escapes to Portuguese",
          ", ".join(pt_body_links[:6]))
    check("/en/jobs/" in jobs_body, "the EN filter links point at /en/jobs/")
    check("instagram.com" in en, "the Instagram URL is preserved")
    check("tel:" in en, "telephone links are preserved")
    # The EN language switcher must still offer Portuguese.
    check("/empregos/" in en, "the PT language-switcher target is preserved (by design)")

    # -----------------------------------------------------------------------
    print("\n-- 10. Portuguese page is unchanged --")
    # Page 1 + page 2: the permit-employer cards (sector/roles) and the
    # pagination/notice chrome only render on the later pages.
    pt_p2 = visible_text(fetch(base, "/empregos/?pagina=2")[1])
    pt_all = pt_text + " " + pt_p2
    pt_missing = [n for n in PT_EXPECTED if n not in pt_all]
    check(not pt_missing, "every expected Portuguese string still renders",
          ", ".join(pt_missing))
    # The PT accessibility/metadata layer, asserted against the raw markup.
    pt_attr_missing = [n for n in PT_ATTR_EXPECTED if n not in pt]
    check(not pt_attr_missing, "the PT accessibility/metadata layer is unchanged",
          ", ".join(pt_attr_missing))
    check("Our latest job openings" not in pt_text, "the PT page has no English body")
    if "empregos-jobs" in pt:
        check("de agosto de" in pt_text, "the PT date presentation is preserved")
    else:
        print("  SKIP: no dated surface on /empregos/ (the preview section is not rendered)")
    check('lang="pt-BR"' in pt, "the PT page declares pt-BR")
    check('lang="en-US"' in en, "the EN page declares en-US")

    # -----------------------------------------------------------------------
    print("\n-- 11. Filtered + paginated EN views stay English --")
    for path in ("/en/jobs/?tipo=agency", "/en/jobs/?area=warehouse",
                 "/en/jobs/?contrato=temporario", "/en/jobs/?localizacao=dublin",
                 "/en/jobs/?pagina=2"):
        status, page = fetch(base, path)
        if status != 200:
            check(False, f"GET {path} is 200", str(status))
            continue
        text = visible_text(page)
        found = [(n, text.count(n)) for n in PT_LEAKS if n in text]
        check(not found, f"{path} stays fully English",
              "; ".join(f"{n}×{c}" for n, c in found))

    # -----------------------------------------------------------------------
    print("\n-- 12. The «Vagas»/“Openings” preview section is intentionally absent --")
    # Product decision: the section was removed from the Jobs landing pages
    # (rendering only — the job records, the language-aware query in
    # inc/empregos-landing.php and the section CSS are retained). /empregos/ and
    # /en/jobs/ are rendered by the same template, so the section must be gone
    # from both languages. If it is ever restored, update this guard (the
    # card-level checks above re-enable themselves automatically).
    check("empregos-jobs" not in pt, "PT /empregos/ renders no «Vagas» preview section")
    check("empregos-jobs" not in en, "EN /en/jobs/ renders no “Openings” preview section")
    _, pt_paged = fetch(base, "/empregos/?pagina=2")
    check("empregos-jobs" not in pt_paged, "the section is absent on paginated PT views too")

    total = len(PASSED) + len(FAILED)
    print(f"\n== {len(PASSED)} passed, {len(FAILED)} failed (of {total}) ==")

    if args.write:
        with open(args.write, "w", encoding="utf-8") as fh:
            json.dump({"base": base, "passed": PASSED, "failed": FAILED}, fh,
                      indent=1, ensure_ascii=False)
        print(f"wrote {args.write}")

    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())
