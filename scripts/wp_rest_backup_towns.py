#!/usr/bin/env python3
"""Backup all conexao_town terms to JSON before making changes."""
import argparse, json, os, sys, requests
from requests.auth import HTTPBasicAuth

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

def backup(wp_url, user, pwd, outfile):
    s = requests.Session()
    s.auth = HTTPBasicAuth(user, pwd)
    s.headers.update({'Accept': 'application/json'})
    all_terms = []
    pg = 1
    while True:
        r = s.get(f"{wp_url}/wp-json/wp/v2/conexao_town",
                  params={'per_page': 100, 'page': pg, 'hide_empty': False})
        if r.status_code != 200:
            print(f"Error: HTTP {r.status_code}")
            return 1
        terms = r.json()
        if not terms:
            break
        all_terms.extend(terms)
        link = r.headers.get('Link', '')
        if 'rel="next"' not in link:
            break
        pg += 1
        if pg > 100:
            break
    with open(outfile, 'w', encoding='utf-8') as f:
        json.dump(all_terms, f, indent=2, ensure_ascii=False)
    print(f"Backed up {len(all_terms)} terms to {outfile}")
    return 0

def main():
    p = argparse.ArgumentParser(description='Backup conexao_town terms to JSON')
    p.add_argument('--site', required=True, help='WordPress URL')
    p.add_argument('--user', help='Username (default: from .env)')
    p.add_argument('--password', help='App Password (default: from .env)')
    p.add_argument('--output', default='town_terms_backup.json', help='Output file')
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

    print(f"Backing up town terms from {args.site}...")
    sys.exit(backup(args.site, user, pwd, args.output))

if __name__ == '__main__':
    main()
