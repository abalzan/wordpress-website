#!/usr/bin/env python3
"""Verify no contaminated conexao_town terms exist."""
import argparse, os, re, sys, requests
from requests.auth import HTTPBasicAuth

EIRCODE = re.compile(r'\b[A-Z]\d{2}\s?[A-Z0-9]{4}\b', re.I)
CO_PATTERNS = [re.compile(r'^co\.?\s+', re.I), re.compile(r'^co\.[A-Z]')]
IRE_PATTERNS = [re.compile(r',\s*ireland\s*$', re.I), re.compile(r'\s+ireland\s*$', re.I)]

def load_dotenv(path='.env'):
    env = {}
    if os.path.exists(path):
        with open(path, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, _, val = line.partition('=')
                    env[key.strip()] = val.strip().strip("'").strip('"')
    return env

def check(wp_url, user, pwd):
    s = requests.Session()
    s.auth = HTTPBasicAuth(user, pwd)
    s.headers.update({'Accept': 'application/json'})
    bad = []
    pg = 1
    while True:
        r = s.get(f"{wp_url}/wp-json/wp/v2/conexao_town",
                  params={'per_page': 100, 'page': pg, 'hide_empty': False})
        if r.status_code != 200:
            print(f"Error: HTTP {r.status_code}")
            return None
        terms = r.json()
        if not terms:
            break
        for t in terms:
            n = t.get('name', '')
            if (re.search(EIRCODE, n) or
                any(p.search(n) for p in CO_PATTERNS) or
                any(p.search(n) for p in IRE_PATTERNS)):
                bad.append(f"  ID {t['id']}: '{n}' ({t.get('count', 0)} events)")
        link = r.headers.get('Link', '')
        if 'rel="next"' not in link:
            break
        pg += 1
        if pg > 100:
            break
    return bad

def main():
    p = argparse.ArgumentParser(description='Verify no contaminated town terms')
    p.add_argument('--site', required=True, help='WordPress URL')
    p.add_argument('--user', help='Username (default: from .env)')
    p.add_argument('--password', help='App Password (default: from .env)')
    args = p.parse_args()

    ENV = load_dotenv()
    pwd = args.password or os.environ.get('WP_PASSWORD', '') or ENV.get('WP_APPLICATION_PASSWORD', '')
    user = args.user or os.environ.get('WP_USERNAME', '') or ENV.get('WP_USERNAME', '')

    if not pwd:
        print("Error: No password. Set WP_APPLICATION_PASSWORD in .env or use --password.")
        sys.exit(1)
    if not user:
        print("Error: No username. Set WP_USERNAME in .env or use --user.")
        sys.exit(1)

    print(f"Checking {args.site} for contaminated town terms...")
    bad = check(args.site, user, pwd)
    if bad is None:
        print("Connection failed.")
        sys.exit(2)
    if bad:
        print(f"\nFound {len(bad)} contaminated terms remaining:")
        for b in bad:
            print(b)
        sys.exit(1)
    else:
        print("\nPASS: No contaminated terms found!")
        sys.exit(0)

if __name__ == '__main__':
    main()
