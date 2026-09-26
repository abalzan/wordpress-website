"""
REST seeder for the "Empresas com histórico de Employment Permits" directory
(Empregos landing).

Purpose: seed the approved 13-employer dataset through the WordPress REST API.
Safety: production-capable-write. Dry-run is the default and writes nothing.
Scope:
  Creates/updates `permit_employer` records for the 13 approved employers only.
  Does not touch any other post type, user, option or term.
  The dataset must stay identical to conexao_seed_permit_employers() in the
  PHP seeder (scripts/seed-permit-employers.php).

Uses the shared REST client (scripts/lib/rest.py): base URL resolution,
credentials, retries and error handling are centralised there.

Usage:
    export WP_USERNAME='your-wpcom-username'
    export WP_APPLICATION_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'

    python3 scripts/seed-permit-employers-rest.py --dry-run   # preview (default)
    python3 scripts/seed-permit-employers-rest.py --apply     # seed (upsert)

Arguments:
    --dry-run                 Plan only. The default. Zero writes.
    --apply                   Perform the upsert.
    --base-url URL            Target. Default $CONEXAO_SITE_URL, else the
                              local site. There is no production default.
    --confirm-production      Required before a write against a production target.
    --json                    Emit the machine-readable plan on stdout.

Idempotent: existing employers are matched by slug and updated, missing ones are
created — the same behaviour as the PHP seeder.

Requires Python 3.8+ (standard library only).
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib"))

import rest as conexao_rest  # noqa: E402  (path set above, like the acceptance suites)
from plan import PlanBuilder  # noqa: E402

# ---------------------------------------------------------------------------
# Employer data — matches the APPROVED local dataset (local Docker DB, dumped
# and verified in docs/research/2026-09-empregos-production-migration-audit.md
# §4). This is the migration source of truth — NOT the older research-doc
# dataset: no `_employer_sector`, no `_employer_evidence_years` (the local DB
# carries none), `_employer_roles` only where the local record has one.
#
# 'permit': verified = verified HISTORICAL permit evidence (never "currently
# sponsoring"); unverified = plain entry with NO indicator (Kepak); exception
# = renders as a normal permit-history card (Nua Healthcare — no special
# warning). Farm Solutions is deliberately absent (on HOLD until its
# WRC/licensing status is manually resolved).
#
# NOTE: scripts/seed-permit-employers.php still carries the older research-doc
# dataset (sector/years/roles) and is intentionally left untouched by this
# migration — the approved local DB supersedes it here.
# ---------------------------------------------------------------------------
EMPLOYERS = [
    {"name": "Mowlam Healthcare", "slug": "mowlam-healthcare", "roles": "Enfermeiros, Healthcare Assistants", "location": "Nacional (Irlanda)", "website": "https://mowlamhealthcare.com/", "careers": "https://mowlamhealthcare.com/careers/", "permit": "verified"},
    {"name": "Resilience Healthcare", "slug": "resilience-healthcare", "roles": "Healthcare Assistants, Enfermeiros, Equipe de apoio", "location": "Nacional (Irlanda)", "website": "https://resiliencecare.ie/", "careers": "", "permit": "verified"},
    {"name": "InisCare", "slug": "iniscare", "roles": "", "location": "Nacional (Irlanda)", "website": "https://www.iniscare.ie/", "careers": "https://iniscare.ie/new-job/", "permit": "verified"},
    {"name": "UL Hospitals Group", "slug": "ul-hospitals-group", "roles": "", "location": "Limerick", "website": "https://www.hse.ie/eng/region/midwest/", "careers": "", "permit": "verified"},
    {"name": "Cork University Hospital", "slug": "cork-university-hospital", "roles": "", "location": "Cork", "website": "https://cuh.hse.ie/", "careers": "", "permit": "verified"},
    {"name": "University Hospital Galway", "slug": "university-hospital-galway", "roles": "", "location": "Galway", "website": "https://www.saolta.ie/", "careers": "", "permit": "verified"},
    {"name": "Rosderra Irish Meats", "slug": "rosderra-irish-meats", "roles": "", "location": "Nacional (Irlanda)", "website": "https://www.rosderra.ie/", "careers": "https://www.rosderra.ie/careers/", "permit": "verified"},
    {"name": "Dawn Meats", "slug": "dawn-meats", "roles": "", "location": "Nacional (Irlanda)", "website": "https://www.dawnmeats.com/", "careers": "https://www.dawnmeats.com/careers", "permit": "verified"},
    {"name": "ABP Food Group", "slug": "abp-food-group", "roles": "", "location": "Nacional (Irlanda)", "website": "https://abpfoodgroup.com/", "careers": "https://abpfoodgroup.com/careers/", "permit": "verified"},
    {"name": "Monaghan Mushrooms", "slug": "monaghan-mushrooms", "roles": "", "location": "Monaghan; Nacional (Irlanda)", "website": "https://www.monaghan.eu/", "careers": "https://www.monaghan.eu/careers/", "permit": "verified"},
    {"name": "Liffey Meats", "slug": "liffey-meats", "roles": "", "location": "Nacional (Irlanda)", "website": "https://liffeymeats.ie/", "careers": "", "permit": "verified"},
    {"name": "Kepak", "slug": "kepak", "roles": "", "location": "Nacional (Irlanda)", "website": "https://www.kepak.com/", "careers": "https://www.kepak.com/careers/", "permit": "unverified"},
    {"name": "Nua Healthcare", "slug": "nua-healthcare", "roles": "", "location": "Nacional (Irlanda)", "website": "https://www.nuahealthcare.ie/", "careers": "https://www.nuahealthcare.ie/careers/", "permit": "exception"},
]


def build_meta(employer):
    """REST meta payload — mirrors the approved local DB field-by-field.

    Deliberately NOT set (absent from the approved local dataset):
    `_employer_sector`, `_employer_evidence_years`, `_employer_notes`
    (REST-hidden by design), `_employer_evidence_source`.
    """
    return {
        "_employer_roles": employer["roles"],
        "_employer_location": employer["location"],
        "_employer_official_website": employer["website"],
        "_employer_careers_url": employer["careers"],
        "_employer_permit_status": employer["permit"],
        "_employer_last_checked": "2026-09-02",
        "_employer_status": "published",
    }


class WpRest:
    """Adapter preserving this script's original REST call semantics.

    The endpoint paths, the retry count (3, 4xx never retried), the timeout
    (30s) and the pagination rule (per_page=100, stop on a short page) are
    exactly what this script used before Stage I; only the implementation moved
    into scripts/lib/rest.py.
    """

    def __init__(self, base_url, user, app_password):
        self._client = conexao_rest.RestClient(
            base_url,
            user_agent="conexao-br-permit-employer-seeder/1.0",
            authenticated=False,  # credentials supplied explicitly below
            timeout=30,
            retries=3,
        )
        self.base_url = self._client.base_url
        self.headers = conexao_rest.default_headers(
            "conexao-br-permit-employer-seeder/1.0", authenticated=False
        )
        token = conexao_rest.basic_auth_header(user, app_password)
        self.headers["Authorization"] = token
        self._client.headers = self.headers

    def request(self, method, path, payload=None):
        """GET/POST a wp/v2 path with the original retry policy."""
        url = f"{self.base_url}/wp-json/wp/v2/{path}"
        return self._client.request(method, url, payload)

    def assert_write_allowed(self, confirm_production=False):
        """Delegate the production write guard to the shared client."""
        return self._client.assert_write_allowed(confirm_production)

    def list_employers(self):
        """All existing permit_employer posts (id + slug), paged."""
        existing = {}
        page = 1
        while True:
            batch = self.request(
                "GET",
                f"permit_employer?per_page=100&page={page}&context=edit&_fields=id,slug",
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
    parser = argparse.ArgumentParser(
        description="Seed employment-permit employers via the WP REST API."
    )
    conexao_rest.add_common_arguments(parser)
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--dry-run", action="store_true", help="Plan only (default).")
    group.add_argument("--apply", action="store_true", help="Perform the upsert.")
    args = parser.parse_args()

    # Stage I contract: dry-run is the default; --apply is the only write mode.
    dry_run = not args.apply

    user = os.environ.get("WP_USERNAME")
    app_password = os.environ.get("WP_APPLICATION_PASSWORD")
    if not user or not app_password:
        sys.exit(
            "Erro: defina WP_USERNAME e WP_APPLICATION_PASSWORD.\n"
            "Crie a senha em wp-admin → Seu perfil → Senhas de aplicativo\n"
            "(Application Passwords)."
        )

    client = WpRest(args.base_url, user, app_password)

    scope = (
        "Creates/updates permit_employer records for the 13 approved employers "
        "only. Touches no other post type, user, option or term."
    )
    mode = "dry-run" if dry_run else "apply"
    conexao_rest.print_header(
        "seed-permit-employers-rest.py", client.base_url, mode, scope
    )

    # Production write guard: refuse an unconfirmed production apply.
    if not dry_run:
        try:
            client.assert_write_allowed(args.confirm_production)
        except conexao_rest.ProductionTargetError as error:
            sys.exit(f"ERROR: {error}")

    plan = PlanBuilder(
        "seed-permit-employers-rest.py", client.base_url, mode=mode, scope=scope
    )

    # --- Sanity check: authentication ---------------------------------------
    try:
        me = client.request("GET", "users/me?context=edit&_fields=id,name")
    except conexao_rest.RestError as e:
        sys.exit(f"Erro de autenticação em {client.base_url}: {e}")
    print(f"Autenticado como: {me.get('name', '?')} (ID {me.get('id', '?')})")

    # --- Sanity check: CPT availability (registered by conexao-data-model) --
    try:
        existing = client.list_employers()
    except conexao_rest.RestError as e:
        sys.exit(
            f"Erro ao listar permit_employer: {e}\n"
            "(O CPT é registrado pelo plugin conexao-data-model >= 1.5.0 — "
            "confirme que está ativo.)"
        )
    print(f"Empregadores existentes: {len(existing)}")

    # --- Upsert (same semantics as the PHP seeder) --------------------------
    created = updated = 0
    action = "SERIA " if dry_run else ""
    for employer in EMPLOYERS:
        payload = {
            "title": employer["name"],
            "slug": employer["slug"],
            "status": "publish",
            "content": "",  # Card renders only the structured meta (see PHP seeder).
            "meta": build_meta(employer),
        }
        post_id = existing.get(employer["slug"])
        permit_label = employer["permit"]
        row = {"slug": employer["slug"], "title": employer["name"],
               "permit": permit_label, "id": post_id}
        try:
            if post_id:
                print(f"  {action}ATUALIZAR #{post_id} {employer['name']} [{permit_label}]")
                if not dry_run:
                    client.request("POST", f"permit_employer/{post_id}", payload)
                plan.add("update", row)
                updated += 1
            else:
                print(f"  {action}CRIAR        {employer['name']} [{permit_label}]")
                if not dry_run:
                    client.request("POST", "permit_employer", payload)
                plan.add("create", row)
                created += 1
        except conexao_rest.RestError as e:
            plan.add_error(f"{employer['name']}: {e}")
            sys.exit(f"Erro ao salvar {employer['name']}: {e}")

    summary = {
        "create": created,
        "update": updated,
        "skip": 0,
        "conflicts": 0,
        "errors": 0,
        "total": len(EMPLOYERS),
    }
    if args.json:
        plan.write()
    else:
        print(
            f"\n=== Resumo ===\nTotal:       {len(EMPLOYERS)}\n"
            f"Criados:     {created}\nAtualizados: {updated}"
        )
    return conexao_rest.print_summary(
        "seed-permit-employers-rest.py", client.base_url, mode, scope, summary,
        as_json=args.json, json_out=args.json_out,
    )


if __name__ == "__main__":
    sys.exit(main())
