#!/usr/bin/env python3
"""
REST seeder for the curated recruitment-agency directory (Empregos).

WordPress.com-compatible replacement for scripts/seed-recruitment-agencies.php,
which requires wp-load.php / WP-CLI (not available on WordPress.com — no SSH,
no SFTP, no CLI). This script seeds the SAME 15 agencies (identical data,
copied from seed-recruitment-agencies.php — keep the two in sync) through the
WordPress REST API using an Application Password.

The `recruitment_agency` CPT and every `_agency_*` meta except `_agency_notes`
are registered with `show_in_rest => true` (conexao-data-model), so the full
directory can be created/updated remotely. `_agency_notes` is REST-hidden by
design (admin-only maintenance surface) and is not seeded here.

Usage:
    export WP_USERNAME='your-wpcom-username'
    export WP_APPLICATION_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'

    python3 scripts/seed-recruitment-agencies-rest.py --dry-run   # preview
    python3 scripts/seed-recruitment-agencies-rest.py             # seed (upsert)
    python3 scripts/seed-recruitment-agencies-rest.py --update-page
        # also sets the /empregos/ page (ID 11086) body content to the
        # current Instagram hero text (only if it differs)

Options:
    --base-url URL   Site base URL (default: https://conexaobr.ie)
    --page-id ID     Empregos page ID for --update-page (default: 11086)
    --dry-run        List what would be created/updated without writing.

The seeder is idempotent: existing agencies are matched by slug and updated,
missing ones are created — the same behaviour as the PHP seeder.

Requires Python 3.8+ (standard library only).
"""

import argparse
import base64
import json
import os
import sys
import time
import urllib.error
import urllib.request
from datetime import date

# ---------------------------------------------------------------------------
# Agency data — MUST stay identical to conexao_seed_agencies() in
# scripts/seed-recruitment-agencies.php (single source of truth lives there).
#
# 'job_types' holds comma-separated CANONICAL keys from
# Conexao_Data_Model_Agency::job_types(). 'wrc' is only populated where the
# research explicitly provides the licence number (never guessed).
# ---------------------------------------------------------------------------
AGENCIES = [
    {"name": "InSource Recruitment", "slug": "insource-recruitment", "job_types": "logistics,warehouse,general_operative,cleaning,hospitality", "location": "Nacional", "website": "https://www.insource.ie/", "phone": "+353 86 028 6985", "temporary": True, "permanent": True, "order": 1, "wrc": "EA 3972"},
    {"name": "Flexsource", "slug": "flexsource", "job_types": "warehouse,factory_production,logistics,hospitality,construction_labour,retail", "location": "Nacional", "website": "https://www.flexsource.ie/", "phone": "+353 1 895 5700", "temporary": True, "permanent": True, "order": 2, "wrc": ""},
    {"name": "Team Obair", "slug": "team-obair", "job_types": "warehouse,logistics,factory_production,general_operative", "location": "Dublin", "website": "https://teamobair.com/", "phone": "+353 1 453 6722", "temporary": True, "permanent": True, "order": 3, "wrc": ""},
    {"name": "Noel Group", "slug": "noel-group", "job_types": "general_operative,factory_production,logistics,warehouse,construction_labour,hospitality", "location": "Dublin, Limerick, Cork, Waterford, Galway, Naas", "website": "https://noelgroup.ie/", "phone": "+353 1 677 9332", "temporary": True, "permanent": True, "order": 4, "wrc": ""},
    {"name": "Total Solutions", "slug": "total-solutions", "job_types": "construction_labour,warehouse,hospitality,factory_production,logistics,office_admin", "location": "Nacional", "website": "https://totalsolutions.ie/", "phone": "+353 1 628 3610", "temporary": True, "permanent": True, "order": 5, "wrc": ""},
    {"name": "Staffline Recruitment", "slug": "staffline-recruitment", "job_types": "factory_production,logistics,warehouse,hospitality,construction_labour,retail,office_admin", "location": "Nacional", "website": "https://www.staffline.ie/", "phone": "+353 1 890 0190", "temporary": True, "permanent": True, "order": 6, "wrc": ""},
    {"name": "Excel Recruitment", "slug": "excel-recruitment", "job_types": "hospitality,warehouse,retail,logistics,construction_labour,factory_production", "location": "Dublin, Cork, Naas, Galway, Belfast", "website": "https://www.excelrecruitment.com/", "phone": "+353 1 871 7676", "temporary": True, "permanent": True, "order": 7, "wrc": ""},
    {"name": "CREGG", "slug": "cregg", "job_types": "factory_production", "location": "Shannon, Galway, Limerick, Cork, Dublin, Kilkenny, Roscommon", "website": "https://www.cregg.ie/", "phone": "+353 61 363 318", "temporary": True, "permanent": True, "order": 8, "wrc": ""},
    {"name": "FRS Recruitment", "slug": "frs-recruitment", "job_types": "factory_production,construction_labour,agriculture_seasonal", "location": "Dublin, Cork, Galway, Limerick, Kilkenny, Cavan, Kerry, Portlaoise", "website": "https://www.frsrecruitment.com/", "phone": "0818 890 890", "temporary": True, "permanent": True, "order": 9, "wrc": ""},
    {"name": "PE Global", "slug": "pe-global", "job_types": "factory_production,construction_labour", "location": "Nacional", "website": "https://www.peglobal.net/", "phone": "+353 21 429 7900", "temporary": True, "permanent": True, "order": 10, "wrc": ""},
    {"name": "Matrix Recruitment", "slug": "matrix-recruitment", "job_types": "factory_production,office_admin,logistics", "location": "Waterford, Carlow, Athlone, Dublin", "website": "https://matrixrecruitment.ie/", "phone": "+353 51 353 825", "temporary": True, "permanent": True, "order": 11, "wrc": ""},
    {"name": "FlexiStaff", "slug": "flexistaff", "job_types": "logistics,warehouse,factory_production,construction_labour,retail", "location": "Nacional", "website": "https://flexistaff.ie/", "phone": "+353 1 687 6461", "temporary": True, "permanent": True, "order": 12, "wrc": ""},
    {"name": "RecruitmentPlus", "slug": "recruitmentplus", "job_types": "office_admin,hospitality,logistics,general_operative", "location": "Deansgrange, Co. Dublin; Dundalk, Co. Louth", "website": "https://www.recruitmentplus.ie/", "phone": "+353 1 278 8610", "temporary": True, "permanent": True, "order": 13, "wrc": ""},
    {"name": "Gi Group Ireland", "slug": "gi-group-ireland", "job_types": "construction_labour,office_admin", "location": "Cork, Galway", "website": "https://ie.gigroup.com/", "phone": "+353 21 427 4700", "temporary": True, "permanent": True, "order": 14, "wrc": ""},
    {"name": "MCR Personnel", "slug": "mcr-personnel", "job_types": "construction_labour,general_operative,factory_production,warehouse,cleaning", "location": "Nacional", "website": "https://mcrgroup.ie/personnel/", "phone": "+353 1 889 9100", "temporary": True, "permanent": True, "order": 15, "wrc": ""},
    # --- 2026-09 validated expansion (orders 16-27); keep in sync with the PHP seeder ---
    {"name": "TTM Healthcare Solutions", "slug": "ttm-healthcare-solutions", "job_types": "healthcare", "location": "Nacional (Ennis, Co. Clare; Galway)", "website": "https://www.ttmhealthcare.com/", "phone": "+353 65 686 9300", "temporary": True, "permanent": True, "order": 16, "wrc": ""},
    {"name": "Servisource", "slug": "servisource", "job_types": "healthcare", "location": "Nacional", "website": "https://www.servisource.ie/", "phone": "", "temporary": True, "permanent": True, "order": 17, "wrc": ""},
    {"name": "Cpl", "slug": "cpl", "job_types": "healthcare,office_admin,warehouse,logistics,factory_production", "location": "Nacional (Dublin)", "website": "https://www.cpl.com/", "phone": "+353 1 614 6000", "temporary": True, "permanent": True, "order": 18, "wrc": ""},
    {"name": "Access Healthcare", "slug": "access-healthcare", "job_types": "healthcare", "location": "Nacional (Dublin)", "website": "https://www.accesshealthcare.ie/", "phone": "+353 1 649 8500", "temporary": True, "permanent": True, "order": 19, "wrc": ""},
    {"name": "Gibbons Recruitment", "slug": "gibbons-recruitment", "job_types": "construction_labour,factory_production,warehouse,agriculture_seasonal", "location": "Dublin", "website": "https://gibbonsrecruitment.ie/", "phone": "", "temporary": True, "permanent": True, "order": 20, "wrc": ""},
    {"name": "Ward Personnel", "slug": "ward-personnel", "job_types": "construction_labour,general_operative", "location": "Dublin, Cork, Athlone", "website": "https://www.wardpersonnel.com/", "phone": "+353 1 539 0600", "temporary": True, "permanent": True, "order": 21, "wrc": ""},
    {"name": "OSS Recruitment", "slug": "oss-recruitment", "job_types": "construction_labour,factory_production,general_operative", "location": "Dublin", "website": "https://www.ossrecruitment.ie/", "phone": "+353 1 460 5517", "temporary": True, "permanent": True, "order": 22, "wrc": ""},
    {"name": "Hollilander", "slug": "hollilander", "job_types": "healthcare", "location": "Dublin", "website": "https://www.hollilander.ie/", "phone": "+353 1 204 0921", "temporary": True, "permanent": True, "order": 23, "wrc": ""},
    {"name": "Adecco Ireland", "slug": "adecco-ireland", "job_types": "warehouse,logistics,office_admin,factory_production,hospitality", "location": "Nacional", "website": "https://www.adecco.ie/", "phone": "", "temporary": True, "permanent": True, "order": 24, "wrc": ""},
    {"name": "3D Personnel", "slug": "3d-personnel", "job_types": "construction_labour", "location": "Dublin, Cork, Galway", "website": "https://www.3dpersonnel.com/", "phone": "+353 1 513 3101", "temporary": True, "permanent": True, "order": 25, "wrc": ""},
    {"name": "Collins McNicholas", "slug": "collins-mcnicholas", "job_types": "factory_production,office_admin,logistics", "location": "Galway, Cork, Sligo, Athlone", "website": "https://www.collinsmcnicholas.ie/", "phone": "", "temporary": True, "permanent": True, "order": 26, "wrc": ""},
    {"name": "Sigmar Recruitment", "slug": "sigmar-recruitment", "job_types": "office_admin,warehouse,logistics,factory_production", "location": "Galway, Dublin, Cork, Athlone", "website": "https://www.sigmarrecruitment.com/", "phone": "+353 1 474 4600", "temporary": True, "permanent": True, "order": 27, "wrc": ""},
]

# Current /empregos/ landing body (page-empregos.php renders it via the_content).
EMPREGOS_CONTENT = (
    "<!-- wp:paragraph -->\n"
    "<p>As vagas mais recentes estão no nosso Instagram.<br>"
    "Acompanhe nossas publicações para encontrar novas oportunidades "
    "de trabalho na Irlanda.</p>\n"
    "<!-- /wp:paragraph -->"
)


def build_meta(agency):
    """REST meta payload — mirrors update_post_meta() calls in the PHP seeder."""
    meta = {
        "_agency_job_types": agency["job_types"],
        "_agency_location": agency["location"],
        "_agency_website": agency["website"],
        "_agency_phone": agency["phone"],
        "_agency_temporary": bool(agency["temporary"]),
        "_agency_permanent": bool(agency["permanent"]),
        "_agency_order": int(agency["order"]),
        "_agency_status": "published",
        "_agency_last_checked": date.today().strftime("%Y-%m-%d"),
    }
    if agency.get("wrc"):
        meta["_agency_wrc_licence"] = agency["wrc"]
    else:
        # No licence in the research: never carry over a stale value.
        meta["_agency_wrc_licence"] = ""
    return meta


class WpRest:
    def __init__(self, base_url, user, app_password):
        self.base_url = base_url.rstrip("/")
        token = base64.b64encode(f"{user}:{app_password}".encode()).decode()
        self.headers = {
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "User-Agent": "conexao-br-agency-seeder/1.0",
        }

    def request(self, method, path, payload=None):
        url = f"{self.base_url}/wp-json/wp/v2/{path}"
        data = json.dumps(payload).encode() if payload is not None else None
        req = urllib.request.Request(url, data=data, headers=self.headers, method=method)
        # Small retry loop — the WordPress.com edge occasionally returns 5xx.
        last_error = None
        for attempt in range(3):
            try:
                with urllib.request.urlopen(req, timeout=30) as resp:
                    body = resp.read().decode()
                    return json.loads(body) if body else None
            except urllib.error.HTTPError as e:
                detail = e.read().decode("utf-8", "replace")[:400]
                # 4xx errors are deterministic — do not retry those.
                if e.code < 500:
                    raise RuntimeError(f"HTTP {e.code} on {method} {path}: {detail}") from e
                last_error = RuntimeError(f"HTTP {e.code} on {method} {path}: {detail}")
            except urllib.error.URLError as e:
                last_error = RuntimeError(f"Network error on {method} {path}: {e.reason}")
            time.sleep(2 * (attempt + 1))
        raise last_error

    def list_agencies(self):
        """All existing agency posts (id + slug), paged."""
        existing = {}
        page = 1
        while True:
            batch = self.request(
                "GET",
                f"recruitment_agency?per_page=100&page={page}&context=edit&_fields=id,slug",
            )
            if not batch:
                break
            for post in batch:
                existing[post.get("slug", "")] = post["id"]
            if len(batch) < 100:
                break
            page += 1
        return existing


def main():
    parser = argparse.ArgumentParser(description="Seed recruitment agencies via the WP REST API.")
    parser.add_argument("--base-url", default=os.environ.get("WP_BASE_URL", "https://conexaobr.ie"))
    parser.add_argument("--page-id", type=int, default=11086)
    parser.add_argument("--update-page", action="store_true",
                        help="Also set the /empregos/ page body to the current Instagram hero text.")
    parser.add_argument("--dry-run", action="store_true", help="Preview without writing.")
    args = parser.parse_args()

    user = os.environ.get("WP_USERNAME")
    app_password = os.environ.get("WP_APPLICATION_PASSWORD")
    if not user or not app_password:
        sys.exit(
            "Erro: defina WP_USERNAME e WP_APPLICATION_PASSWORD.\n"
            "Crie a senha em wp-admin → Seu perfil → Senhas de aplicativo\n"
            "(Application Passwords)."
        )

    rest = WpRest(args.base_url, user, app_password)

    # --- Sanity check: authentication ---------------------------------------
    try:
        me = rest.request("GET", "users/me?context=edit&_fields=id,name")
    except RuntimeError as e:
        sys.exit(f"Erro de autenticação em {args.base_url}: {e}")
    print(f"Autenticado como: {me.get('name', '?')} (ID {me.get('id', '?')})")

    # --- Sanity check: CPT availability (registered by conexao-data-model) --
    try:
        existing = rest.list_agencies()
    except RuntimeError as e:
        sys.exit(f"Erro ao listar recruitment_agency: {e}\n"
                 "(O CPT é registrado pelo plugin conexao-data-model — confirme que está ativo.)")
    print(f"Agências existentes: {len(existing)}")

    # --- Upsert (same semantics as the PHP seeder) ---------------------------
    created = updated = 0
    action = "SERIA " if args.dry_run else ""
    for agency in AGENCIES:
        payload = {
            "title": agency["name"],
            "slug": agency["slug"],
            "status": "publish",
            "content": "",  # Card renders only the structured meta (see PHP seeder).
            "meta": build_meta(agency),
        }
        post_id = existing.get(agency["slug"])
        wrc_label = f"WRC: {agency['wrc']}" if agency.get("wrc") else "sem WRC"
        try:
            if post_id:
                print(f"  {action}ATUALIZAR #{post_id} {agency['name']} [{agency['location']}] {wrc_label}")
                if not args.dry_run:
                    rest.request("POST", f"recruitment_agency/{post_id}", payload)
                updated += 1
            else:
                print(f"  {action}CRIAR        {agency['name']} [{agency['location']}] {wrc_label}")
                if not args.dry_run:
                    rest.request("POST", "recruitment_agency", payload)
                created += 1
        except RuntimeError as e:
            sys.exit(f"Erro ao salvar {agency['name']}: {e}")

    print(f"\n=== Resumo ===\nTotal:       {len(AGENCIES)}\nCriadas:     {created}\nAtualizadas: {updated}")

    # --- Optional: sync the /empregos/ page body -----------------------------
    if args.update_page:
        try:
            page = rest.request("GET", f"pages/{args.page_id}?context=edit&_fields=id,slug,content")
        except RuntimeError as e:
            sys.exit(f"Erro ao ler a página {args.page_id}: {e}")
        current = page.get("content", {}).get("raw", "")
        if EMPREGOS_CONTENT.strip() in current:
            print(f"\nPágina {args.page_id} (/empregos/) já contém o conteúdo novo — nada a fazer.")
        else:
            print(f"\n{action}ATUALIZAR página {args.page_id} (/empregos/) com o conteúdo novo.")
            if not args.dry_run:
                rest.request("POST", f"pages/{args.page_id}", {"content": EMPREGOS_CONTENT})
                print("Página atualizada. (Gravar a página também aciona a invalidação "
                      "do cache de página da WordPress.com.)")

    if args.dry_run:
        print("\n(--dry-run: nenhuma alteração foi gravada.)")
    elif created or updated:
        print("\nVerifique https://conexaobr.ie/empregos/ — se a seção 'Agências de "
              "recrutamento' não aparecer, regrave a página /empregos/ no wp-admin "
              "para purgar o cache de borda da WordPress.com.")


if __name__ == "__main__":
    main()

