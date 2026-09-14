#!/usr/bin/env python3
"""
WordPress REST API Script: Event Town Term Cleanup
Cleans up contaminated `conexao_town` terms caused by Eircode fragments.

Works via WordPress REST API (requires Application Passwords on WP.com,
or basic auth on self-hosted).

Usage:
    python wp_rest_cleanup_event_towns.py --site https://conexaobr.ie [--execute]
    python wp_rest_cleanup_event_towns.py --help

Authentication is read from .env file (WP_USERNAME, WP_APPLICATION_PASSWORD)
or can be passed via command line.

Requirements: pip install requests

Safety:
    - By default runs in dry-run mode (no changes)
    - Requires --execute to perform actual changes
    - Always backs up terms before making changes
"""

import argparse, json, os, re, sys, time
from typing import Any, Dict, List, Optional
import requests
from requests.auth import HTTPBasicAuth

# ---------------------------------------------------------------------------
# Eircode pattern and other regexes
# ---------------------------------------------------------------------------

EIRCODE_RE = re.compile(r'\b[A-Z]\d{2}\s?[A-Z0-9]{4}\b', re.I)
CO_PATTERNS = [
    re.compile(r'^co\.?\s+', re.I),      # "Co X", "Co. X"
    re.compile(r'^co\.[A-Z]'),           # "Co.X" (no space, uppercase follows)
]
IRE_PATTERNS = [
    re.compile(r',\s*ireland\s*$', re.I),
    re.compile(r'\s+ireland\s*$', re.I),
]


# ---------------------------------------------------------------------------
# .env file loading
# ---------------------------------------------------------------------------

def load_dotenv(path='.env'):
    """Load key=value pairs from .env file."""
    env = {}
    if os.path.exists(path):
        with open(path, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, _, val = line.partition('=')
                    env[key.strip()] = val.strip().strip("'").strip('"')
    return env


# ---------------------------------------------------------------------------
# WordPress API client
# ---------------------------------------------------------------------------

class WordPressAPI:
    """Minimal WordPress REST API client with Basic Auth support."""

    def __init__(self, base_url: str, username: str, password: str):
        self.base_url = base_url.rstrip('/')
        self.username = username
        self.password = password
        self.session = requests.Session()
        self.session.auth = HTTPBasicAuth(username, password)
        self.session.headers.update({
            'Accept': 'application/json',
            'User-Agent': 'WordPress-Town-Cleanup/1.0 (Python)',
        })

    def _request(self, method: str, endpoint: str, **kwargs) -> Dict[str, Any]:
        """Make a REST API request."""
        url = f"{self.base_url}/wp-json/wp/v2/{endpoint}"
        try:
            response = self.session.request(method, url, timeout=30, **kwargs)
            response.raise_for_status()
            return response.json() if response.status_code != 204 else {}
        except requests.exceptions.RequestException as e:
            raise RuntimeError(f"API request failed: {e}")

    def get_terms(self, taxonomy: str, per_page: int = 100) -> List[Dict[str, Any]]:
        """Get all terms for a taxonomy (handles pagination)."""
        terms: List[Dict[str, Any]] = []
        page = 1
        while True:
            params = {'per_page': per_page, 'page': page, 'hide_empty': False}
            data = self._request('GET', taxonomy, params=params)
            if isinstance(data, dict) or not data:
                break
            terms.extend(data)

            # Check Link header for next page
            head = self.session.head(
                f"{self.base_url}/wp-json/wp/v2/{taxonomy}",
                params={'per_page': per_page, 'page': page},
            )
            link = head.headers.get('Link', '')
            if 'rel="next"' not in link:
                break
            page += 1
            if page > 100:
                break
        return terms

    def get_term_relationships(self, term_taxonomy_id: int) -> List[Dict[str, Any]]:
        """Get object relationships for a term (events attached to it)."""
        try:
            return self._request('GET', f'terms/{term_taxonomy_id}/relationships')
        except Exception:
            return []

    def get_post_terms(self, post_id: int, taxonomy: str) -> List[Dict[str, Any]]:
        """Get terms assigned to a post."""
        try:
            return self._request('GET', f'posts/{post_id}/{taxonomy}')
        except Exception:
            return []

    def set_post_terms(self, post_id: int, taxonomy: str,
                       term_ids: List[int]) -> Dict[str, Any]:
        """Replace terms on a post."""
        return self._request('POST', f'posts/{post_id}/{taxonomy}',
                             json={'ids': term_ids})

    def create_term(self, taxonomy: str, name: str,
                    slug: Optional[str] = None) -> Dict[str, Any]:
        """Create a new term."""
        data: Dict[str, Any] = {'name': name}
        if slug:
            data['slug'] = slug
        return self._request('POST', taxonomy, json=data)

    def delete_term(self, term_id: int, taxonomy: str) -> bool:
        """Permanently delete a term."""
        try:
            self._request('DELETE', f'{taxonomy}/{term_id}',
                          params={'force': True})
            return True
        except Exception as e:
            print(f"    Warning: could not delete term {term_id}: {e}")
            return False


# ---------------------------------------------------------------------------
# Town name utilities
# ---------------------------------------------------------------------------

def sanitize_town_name(name: str) -> str:
    """Strip Eircode fragments from a town name.

    Returns '' when the value contains no alphabetic content after stripping
    (e.g. a standalone Eircode like "A92 DF7X." becomes "." -> invalid).
    """
    name = name.strip()
    if not name:
        return ''
    cleaned = EIRCODE_RE.sub('', name)
    cleaned = re.sub(r'\s*,\s*|\s+', ' ', cleaned)
    cleaned = cleaned.strip(' ,-.')
    if not cleaned or not re.search(r'[A-Za-z]', cleaned):
        return ''
    return cleaned


def is_non_town_name(name: str) -> bool:
    """Return True if the name is a non-town value (Co X, X Ireland, etc.)."""
    name = name.strip()
    if not name:
        return True
    if any(p.search(name) for p in CO_PATTERNS):
        return True
    if any(p.search(name) for p in IRE_PATTERNS):
        return True
    return False


def extract_locality_from_non_town(name: str) -> str:
    """Try to extract a locality from non-town names like 'X, Ireland' -> 'X'."""
    name = name.strip()
    if any(p.search(name) for p in CO_PATTERNS):
        return ''
    m = re.search(r'^(.+?)\s*,\s*ireland\s*$', name, re.I)
    if m:
        return m.group(1).strip()
    m = re.search(r'^(.+?)\s+ireland\s*$', name, re.I)
    if m:
        return m.group(1).strip()
    return ''


def categorize_terms(terms: List[Dict[str, Any]]) -> Dict[str, List[Dict[str, Any]]]:
    """Split terms into clean, contaminated, standalone-eircode, non-town buckets."""
    result = {
        'clean': [],
        'contaminated': [],
        'standalone_eircode': [],
        'non_town': [],
    }
    for term in terms:
        name = term.get('name', '')
        if EIRCODE_RE.search(name):
            cleaned = sanitize_town_name(name)
            entry = {**term, 'clean_name': cleaned}
            if cleaned:
                result['contaminated'].append(entry)
            else:
                result['standalone_eircode'].append(entry)
        elif is_non_town_name(name):
            result['non_town'].append({
                **term,
                'clean_name': extract_locality_from_non_town(name),
            })
        else:
            result['clean'].append(term)
    return result


def find_or_create_term(wp: WordPressAPI, clean_name: str,
                        clean_map: Dict[str, int]) -> int:
    """Find an existing clean term, or create one. Returns term_id or 0."""
    key = clean_name.lower()
    if key in clean_map:
        return clean_map[key]

    # Search by name via the terms endpoint
    try:
        for term in wp.get_terms('conexao_town'):
            if term.get('name', '').lower() == key:
                clean_map[key] = term['id']
                return term['id']
    except Exception:
        pass

    # Create a new term
    result = wp.create_term('conexao_town', clean_name)
    tid = result.get('id', 0)
    if tid:
        clean_map[key] = tid
        print(f"    Created new term: '{clean_name}' (ID: {tid})")
    return tid


def migrate_event(wp: WordPressAPI, event_id: int, from_id: int,
                  to_id: int) -> bool:
    """Move an event from one town term to another."""
    try:
        current = wp.get_post_terms(event_id, 'conexao_town')
        cids = [t['id'] for t in current]

        if to_id in cids:
            if from_id in cids:
                wp.set_post_terms(event_id, 'conexao_town',
                                  [x for x in cids if x != from_id])
                print(f"        Removed duplicate contaminated term")
            return True

        new_ids = [x for x in cids if x != from_id] + [to_id]
        wp.set_post_terms(event_id, 'conexao_town', new_ids)
        print(f"        Migrated event {event_id}")
        return True
    except Exception as e:
        print(f"        Error on event {event_id}: {e}")
        return False


def unclassify_event(wp: WordPressAPI, event_id: int, term_id: int) -> bool:
    """Remove a town term from an event (town undeterminable)."""
    try:
        current = wp.get_post_terms(event_id, 'conexao_town')
        new_ids = [t['id'] for t in current if t['id'] != term_id]
        wp.set_post_terms(event_id, 'conexao_town', new_ids)
        print(f"        Removed town classification from event {event_id}")
        return True
    except Exception as e:
        print(f"        Error on event {event_id}: {e}")
        return False


def get_event_ids_for_term(wp: WordPressAPI, term: Dict[str, Any]) -> List[int]:
    """Return event IDs attached to a term."""
    eids = [r['object_id'] for r in wp.get_term_relationships(term['id'])]
    if eids:
        return eids

    # Fallback: iterate through events and check their terms
    print(f"    Relationships API unavailable, scanning events...")
    pg = 1
    while True:
        try:
            evs = wp._request('GET', 'events',
                              params={'per_page': 100, 'page': pg, 'fields': 'ids'})
        except Exception:
            break
        if not evs:
            break
        for eid in evs:
            terms_on_event = wp.get_post_terms(eid, 'conexao_town')
            if any(t['id'] == term['id'] for t in terms_on_event):
                eids.append(eid)
        if len(evs) < 100:
            break
        pg += 1
        if pg > 50:
            break
    return eids


def banner(text: str, char: str = '='):
    print(f"\n{char * 60}")
    print(f"  {text}")
    print(f"{char * 60}\n")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description='Clean contaminated conexao_town terms via WordPress REST API',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Dry run (audit only):
  python wp_rest_cleanup_event_towns.py --site https://conexaobr.ie

  # Actually perform the cleanup:
  python wp_rest_cleanup_event_towns.py --site https://conexaobr.ie --execute

  # Backup first, then cleanup:
  python wp_rest_backup_towns.py --site https://conexaobr.ie
  python wp_rest_cleanup_event_towns.py --site https://conexaobr.ie --execute

Credentials are read from .env file (WP_USERNAME, WP_APPLICATION_PASSWORD).
You can also pass them via --user/--password or environment variables.
"""
    )
    parser.add_argument('--site', required=True, help='WordPress site URL')
    parser.add_argument('--user', help='Username (default: .env WP_USERNAME)')
    parser.add_argument('--password', help='App Password (default: .env WP_APPLICATION_PASSWORD)')
    parser.add_argument('--execute', action='store_true',
                        help='Actually perform changes (default: dry-run only)')
    parser.add_argument('--no-backup', action='store_true',
                        help='Skip creating a backup before changes')
    parser.add_argument('--verify', action='store_true',
                        help='Run verification after cleanup')
    args = parser.parse_args()

    dry_run = not args.execute

    # Read credentials from .env or command line
    ENV = load_dotenv()
    pwd = (args.password or
           os.environ.get('WP_PASSWORD', '') or
           ENV.get('WP_APPLICATION_PASSWORD', ''))
    user = (args.user or
            os.environ.get('WP_USERNAME', '') or
            ENV.get('WP_USERNAME', ''))

    if not pwd:
        print("Error: No password provided.")
        print("  Set WP_APPLICATION_PASSWORD in .env, or")
        print("  Use --password 'your-app-password', or")
        print("  Export WP_PASSWORD='your-app-password'")
        return 1
    if not user:
        print("Error: No username provided.")
        print("  Set WP_USERNAME in .env, or")
        print("  Use --user 'your-username', or")
        print("  Export WP_USERNAME='your-username'")
        return 1

    # Show what we're doing
    mode = "DRY RUN (no changes)" if dry_run else "EXECUTE (changes WILL be made)"
    banner(f"TOWN TERM CLEANUP - {mode}")
    print(f"Site:       {args.site}")
    print(f"User:       {user}")
    print(f"Backup:     {'Yes' if not args.no_backup and not dry_run else 'No'}")

    # Connect
    try:
        wp = WordPressAPI(args.site, user, pwd)
        print("Status:    Connected to WordPress API")
    except Exception as e:
        print(f"Status:    FAILED - {e}")
        return 1

    # Backup before making changes
    if not dry_run and not args.no_backup:
        ts = time.strftime('%Y%m%d_%H%M%S')
        backup_file = f"town_terms_backup_{ts}.json"
        print(f"\nCreating backup: {backup_file}")
        try:
            terms_before = wp.get_terms('conexao_town')
            with open(backup_file, 'w', encoding='utf-8') as f:
                json.dump(terms_before, f, indent=2, ensure_ascii=False)
            print(f"  Backed up {len(terms_before)} terms")
        except Exception as e:
            print(f"  Warning: Backup failed ({e})")

    # Fetch all terms
    print("\nFetching all conexao_town terms...")
    try:
        terms = wp.get_terms('conexao_town')
        print(f"  Found {len(terms)} terms total")
    except Exception as e:
        print(f"  Error fetching terms: {e}")
        return 1

    # Categorize
    cats = categorize_terms(terms)

    banner("AUDIT RESULTS")
    print(f"  Clean terms:                {len(cats['clean'])}")
    print(f"  Eircode-contaminated:      {len(cats['contaminated'])}")
    print(f"  Standalone Eircodes:       {len(cats['standalone_eircode'])}")
    print(f"  Non-town (Co X / X Ireland): {len(cats['non_town'])}")

    total = (len(cats['contaminated']) +
             len(cats['standalone_eircode']) +
             len(cats['non_town']))

    if total == 0:
        print("\n  PASS: No contaminated terms found. Nothing to do.")
        return 0

    # Show details
    print("\n  --- Eircode-contaminated terms (will be merged) ---")
    for t in cats['contaminated']:
        print(f"    Term {t['id']}: '{t['name']}' ({t['count']} events) "
              f"-> '{t['clean_name']}'")

    print("\n  --- Standalone Eircodes (town undeterminable, will be removed) ---")
    for t in cats['standalone_eircode']:
        print(f"    Term {t['id']}: '{t['name']}' ({t['count']} events) -> REMOVE")

    print("\n  --- Non-town terms (will be removed or migrated) ---")
    for t in cats['non_town']:
        if t['clean_name']:
            print(f"    Term {t['id']}: '{t['name']}' ({t['count']} events) "
                  f"-> '{t['clean_name']}'")
        else:
            print(f"    Term {t['id']}: '{t['name']}' ({t['count']} events) -> REMOVE")

    if dry_run:
        banner("DRY RUN COMPLETE - No changes were made")
        print("\nTo apply these changes, run with --execute")
        print("  python wp_rest_cleanup_event_towns.py --site ... --execute")
        return 0

    # ---------------------------------------------------------------------------
    # EXECUTE CLEANUP
    # ---------------------------------------------------------------------------
    banner("EXECUTING CLEANUP")

    # Build map of clean term names -> IDs (refresh from DB in case of
    # concurrent changes or previous cleanup runs)
    clean_map: Dict[str, int] = {}
    try:
        for t in wp.get_terms('conexao_town'):
            clean_map[t['name'].lower()] = t['id']
    except Exception:
        pass

    stats = {'migrated': 0, 'removed': 0, 'events_migrated': 0,
             'events_unclassified': 0, 'errors': 0}

    # --- Process contaminated terms ---
    print("\n[1/3] Processing Eircode-contaminated terms...")
    for t in cats['contaminated']:
        clean_name = t['clean_name']
        print(f"\n  Term {t['id']}: '{t['name']}' -> '{clean_name}'")

        target_id = find_or_create_term(wp, clean_name, clean_map)
        if not target_id:
            print(f"    ERROR: could not find or create target term")
            stats['errors'] += 1
            continue

        eids = get_event_ids_for_term(wp, t)
        print(f"    {len(eids)} event(s) attached")

        for eid in eids:
            if migrate_event(wp, eid, t['id'], target_id):
                stats['events_migrated'] += 1
                stats['migrated'] += 1
            else:
                stats['errors'] += 1

        if wp.delete_term(t['id'], 'conexao_town'):
            print(f"    Deleted contaminated term {t['id']}")
        else:
            stats['errors'] += 1

    # --- Process standalone Eircode terms ---
    print("\n[2/3] Processing standalone Eircode terms...")
    for t in cats['standalone_eircode']:
        print(f"\n  Term {t['id']}: '{t['name']}' -> REMOVE (town undeterminable)")

        eids = get_event_ids_for_term(wp, t)
        print(f"    {len(eids)} event(s) will lose town classification")

        for eid in eids:
            if unclassify_event(wp, eid, t['id']):
                stats['events_unclassified'] += 1
                stats['removed'] += 1
            else:
                stats['errors'] += 1

        if wp.delete_term(t['id'], 'conexao_town'):
            print(f"    Deleted standalone Eircode term {t['id']}")
        else:
            stats['errors'] += 1

    # --- Process non-town terms ---
    print("\n[3/3] Processing non-town terms...")
    for t in cats['non_town']:
        if t['clean_name']:
            print(f"\n  Term {t['id']}: '{t['name']}' -> '{t['clean_name']}'")
        else:
            print(f"\n  Term {t['id']}: '{t['name']}' -> REMOVE (county marker)")

        target_id = None
        if t['clean_name']:
            target_id = find_or_create_term(wp, t['clean_name'], clean_map)

        eids = get_event_ids_for_term(wp, t)
        print(f"    {len(eids)} event(s) attached")

        for eid in eids:
            if t['clean_name'] and target_id:
                if migrate_event(wp, eid, t['id'], target_id):
                    stats['events_migrated'] += 1
                    stats['migrated'] += 1
                else:
                    stats['errors'] += 1
            else:
                if unclassify_event(wp, eid, t['id']):
                    stats['events_unclassified'] += 1
                    stats['removed'] += 1
                else:
                    stats['errors'] += 1

        if wp.delete_term(t['id'], 'conexao_town'):
            print(f"    Deleted non-town term {t['id']}")
        else:
            stats['errors'] += 1

    # --- Summary ---
    banner("CLEANUP COMPLETE")

    print(f"  Terms migrated:           {stats['migrated']}")
    print(f"  Terms removed:            {stats['removed']}")
    print(f"  Events migrated:          {stats['events_migrated']}")
    print(f"  Events left without town: {stats['events_unclassified']}")
    print(f"  Errors:                   {stats['errors']}")

    # --- Post-cleanup verification ---
    if args.verify or stats['errors'] == 0:
        print("\n" + "-" * 40)
        print("Running verification...")
        try:
            final_terms = wp.get_terms('conexao_town')
        except Exception as e:
            print(f"  Verification fetch failed: {e}")
        else:
            still_bad = []
            for t in final_terms:
                n = t.get('name', '')
                if (EIRCODE_RE.search(n) or
                    is_non_town_name(n)):
                    still_bad.append(f"    ID {t['id']}: '{n}' ({t.get('count', 0)} events)")

            print(f"  Total terms after cleanup: {len(final_terms)}")
            if still_bad:
                print(f"  WARNING: {len(still_bad)} contaminated terms still remain:")
                for b in still_bad:
                    print(b)
            else:
                print("  PASS: Zero contaminated terms remain!")

    if stats['errors'] > 0:
        print(f"\n  WARNING: {stats['errors']} error(s) occurred during cleanup.")
        return 1
    else:
        print("\n  All operations completed successfully.")
        return 0


if __name__ == '__main__':
    sys.exit(main())
