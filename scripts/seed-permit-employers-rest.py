#!/usr/bin/env python3
"""
REST seeder for the "Empresas com histórico de Employment Permits" directory
(Empregos landing).

WordPress.com-compatible companion to scripts/seed-permit-employers.php
(which requires wp-load.php). Seeds the SAME 13 employer records through the
WordPress REST API using an Application Password. The data below MUST stay
identical to conexao_seed_permit_employers() in the PHP seeder.

The `permit_employer` CPT and every `_employer_*` meta except
`_employer_notes` are registered with `show_in_rest => true`
(conexao-data-model >= 1.5.0). `_employer_notes` is REST-hidden by design
and is not seeded here.

Usage:
    export WP_USERNAME='your-wpcom-username'
    export WP_APPLICATION_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'

    python3 scripts/seed-permit-employers-rest.py --dry-run   # preview
    python3 scripts/seed-permit-employers-rest.py             # seed (upsert)

Options:
    --base-url URL   Site base URL (default: https://conexaobr.ie)
    --dry-run        List what would be created/updated without writing.

Idempotent: existing employers are matched by slug and updated, missing
ones are created — the same behaviour as the PHP seeder.

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
    def __init__(self, base_url, user, app_password):
        self.base_url = base_url.rstrip("/")
        token = base64.b64encode(f"{user}:{app_password}".encode()).decode()
        self.headers = {
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "User-Agent": "conexao-br-permit-employer-seeder/1.0",
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
    parser = argparse.ArgumentParser(description="Seed employment-permit employers via the WP REST API.")
    parser.add_argument("--base-url", default=os.environ.get("WP_BASE_URL", "https://conexaobr.ie"))
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
        existing = rest.list_employers()
    except RuntimeError as e:
        sys.exit(f"Erro ao listar permit_employer: {e}\n"
                 "(O CPT é registrado pelo plugin conexao-data-model >= 1.5.0 — confirme que está ativo.)")
    print(f"Empregadores existentes: {len(existing)}")

    # --- Upsert (same semantics as the PHP seeder) ---------------------------
    created = updated = 0
    action = "SERIA " if args.dry_run else ""
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
        try:
            if post_id:
                print(f"  {action}ATUALIZAR #{post_id} {employer['name']} [{permit_label}]")
                if not args.dry_run:
                    rest.request("POST", f"permit_employer/{post_id}", payload)
                updated += 1
            else:
                print(f"  {action}CRIAR        {employer['name']} [{permit_label}]")
                if not args.dry_run:
                    rest.request("POST", "permit_employer", payload)
                created += 1
        except RuntimeError as e:
            sys.exit(f"Erro ao salvar {employer['name']}: {e}")

    print(f"\n=== Resumo ===\nTotal:       {len(EMPLOYERS)}\nCriados:     {created}\nAtualizados: {updated}")


if __name__ == "__main__":
    main()
