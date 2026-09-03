#!/usr/bin/env python3
"""
REST equivalent of scripts/reorder-primary-menu-blog-apoiadores.php for
environments without wp-load.php / WP-CLI (e.g. WordPress.com — no SSH, no
SFTP, no CLI). Reorders ONLY the stored `menu_order` og the menu assigned to
the theme's "primary" location — same order-only semantics as the PHP script.

Canonical top-level order enforced (desktopand mobile share the same 'primary'
menu, so one order covers both):

   1. Início          (/)
   2. Apoiadores      (/apoiadores/)
   3. Guias           (/guias/)
   4. Eventos         (/eventos/)
   5. Cursos          (/cursos/)
   6. Lazer e turismo (/lazer/)        — render-time insertion by the theme
   7. Empregos        (/empregos/)
   8. Blog            (/blog/)
   9. Contato         (/contato/)

Scope (intentionally narrow — ORDER ONLY):
  - Only inspects the menu assigned to theme location "primary".
  - Item titles, URLs, IDs, meta, classes, parentsand hierarchy are never
    touched; only the WordPress-native `menu_order` value is rewritten
    (the same mechanism as wp-admin drag-and-drop).
  - Items not part of the canonical list (e.g. legacy "Sobre Nós" entries,
    which the theme hides at render time) keep their relative position and are
    simply renumbered in place.
  - "Lazer e turismo" has NO stored menu item — it is inserted at render time
    by the theme (see functions.php conexao_normalize_primary_nav_sections()).
    The script never creates/deletes items, so nothing is written for it.
  - Idempotent: running it again reports "already in canonical order".

Writes through the WordPress REST API (`POST /wp/v2/menu-items/{id}`,
body `{"menu_order": N}`) using an Application Password — same auth/write path
as scripts/seed-recruitment-agencies-rest.py.

Usage:
    # Reads WP_USERNAME / WP_APPLICATION_PASSWORD / WP_BASE_URL from .env at the
    # repo root automatically (real environment variables always win).
    python3 scripts/reorder-primary-menu-rest.py --dry-run   # preview
    python3 scripts/reorder-primary-menu-rest.py             # reorder

Options:
    --base-url URL   Site base URL (default: WP_BASE_URL env or https://conexaobr.ie)
    --menu-id ID     Menu term ID (default: resolve the menu assigned to "primary")
    --dry-run        List what would be renumbered without writing.

Requires Python 3.8+ (standard library only).
"""

import argparse
import base64
import json
import os
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.request

# Canonical order — MUST stay identical to
# scripts/reorder-primary-menu-blog-apoiadores.php (conexao_reorder_canonical_order())
# and functions.php (conexao_normalize_primary_nav_sections()). "Lazer" is
# intentionally included: it has no stored item but its position defines where
# the theme inserts "Lazer e turismo" (between "Cursos" and"Empregos").
CANONICAL_ORDER = [
    "inicio",
    "apoiadores",
    "guias",
    "eventos",
    "cursos",
    "lazer",
    "empregos",
    "blog",
    "irlanda",
    "sobre-nos",
    "contato",
]

# Title → section key (title normalized: lowercase + accents removed + HTML
# stripped — mirrors remove_accents( strtolower( wp_strip_all_tags( ))).
BY_TITLE = {
    "home":"inicio", "inicio":"inicio",
    "blog":"blog",
    "guias":"guias", "guias praticos":"guias",
    "eventos":"eventos",
    "cursos":"cursos",
    "lazer":"lazer", "lazer e turismo":"lazer", "leisure":"lazer",
    "empregos":"empregos", "jobs":"empregos",
    "apoiadores":"apoiadores", "sponsors":"apoiadores",
    "contato":"contato", "contact":"contato",
    "irlanda":"irlanda", "ireland":"irlanda",
    "sobre nos":"sobre-nos", "about us":"sobre-nos",
}

# URL needle → section key (ordered; first match wins — mirrors the
# $by_url table in conexao_reorder_get_section_key()).
BY_URL = [
    ("/blog", "blog"),
    ("/guias", "guias"),
    ("/guides", "guias"),
    ("/eventos", "eventos"),
    ("/events", "eventos"),
    ("/cursos", "cursos"),
    ("/courses", "cursos"),
    ("/lazer", "lazer"),
    ("/leisure", "lazer"),
    ("/empregos", "empregos"),
    ("/jobs", "empregos"),
    ("/apoiadores", "apoiadores"),
    ("/sponsors", "apoiadores"),
    ("/contato", "contato"),
    ("/contact", "contato"),
    ("/irlanda", "irlanda"),
    ("/sobre-nos", "sobre-nos"),
]

# ---------------------------------------------------------------------------
# .env support (repo root). Real environment variables always win.
# ---------------------------------------------------------------------------
def load_dotenv():
    """Minimal .env loader for WP_* config (KEY='value' / KEY="value" /
    export KEY=value). Only sets vars that are not already in os.environ."""
    here = os.path.dirname(os.path.abspath(__file__))
    path = os.path.join(os.path.dirname(here), ".env")
    try:
        with open(path, encoding="utf-8") as fh:
            for raw in fh:
                line = raw.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                if line.startswith("export "):
                    line = line[7:].lstrip()
                key, _, value = line.partition("=")
                key = key.strip()
                if not key.startswith("WP_"):
                    continue
                value = value.strip()
                if len (value) >= 2 and value[0] == value[-1]:
                    quote = value[0]
                    if quote in ("'", '"'):
                        value = value[1:-1]
                if key not in os.environ:
                    os.environ[key] = value
    except OSError:
        pass


def display_title(item):
    """REST `title` is a dict under context=view/edit; accept plain str too."""
    t = item.get("title")
    if isinstance(t, dict):
        return t.get("rendered") or t.get("raw", "") or ""
    return t or ""


def strip_tags(text):
    return re.sub(r"<[^>]+>", "", text)


def normalize_title(raw):
    """Mirrors remove_accents( strtolower( trim( wp_strip_all_tags( ))))."""
    text = str(raw) if raw else ""
    text = strip_tags(text).strip().lower()
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return text.strip()


def untrailingslashit(url):
    """Mirrors WP untrailingslashit(): '/' → ''."""
    url = str(url or "")
    if url and url != "/":
        return url.rstrip("/")
    return ""


def get_section_key(item):
    """Map a menu item to its canonical section key (title first, stored URL
    fallback — 1:1 port of conexao_reorder_get_section_key())."""
    title = normalize_title(display_title(item))
    if title in BY_TITLE:
        return BY_TITLE[title]
    url = untrailingslashit(item.get("url", ""))
    for needle, key in BY_URL:
        if needle in url:
            return key
    return None


# ---------------------------------------------------------------------------
# Minimal WP REST client — same pattern as scripts/seed-recruitment-agencies-rest.py
# ---------------------------------------------------------------------------
class WpRest:
    def __init__(self, base_url, username, app_password):
        self.base_url = base_url.rstrip("/")
        token = base64.b64encode(("%s:%s" % (username, app_password)).encode()).decode()
        self.headers = {
            "Authorization": "Basic " + token,
            "Content-Type": "application/json",
        }

    def request(self, method, path, payload=None):
        url = "%s/wp-json/wp/v2/%s" % (self.base_url, path)
        data = json.dumps(payload).encode() if payload is not None else None
        last_error = None
        for attempt in range(3):
            req = urllib.request.Request(url, data=data, method=method)
            for k, v in self.headers.items():
                req.add_header(k, v)
            try:
                with urllib.request.urlopen(req, timeout=60) as resp:
                    body = resp.read()
                    return json.loads(body.decode()) if body else None
            except urllib.error.HTTPError as e:
                raw = e.read().decode(errors="replace")
                try:
                    detail = json.loads(raw).get("message", "")
                except Exception:
                    detail = raw[:300]
                if e.code >= 500 and attempt < 2:
                    last_error = RuntimeError("HTTP %s on %s %s: %s" % (e.code, method, path, detail))
                    time.sleep(2 * (attempt + 1))
                    continue
                raise RuntimeError("HTTP %s on %s %s: %s" % (e.code, method, path, detail))
            except (urllib.error.URLError, TimeoutError) as e:
                reason = getattr(e, "reason", e)
                last_error = RuntimeError("Network error on %s %s: %s" % (method, path, reason))
                time.sleep(2 * (attempt + 1))
        raise last_error


def list_menu_items(rest, menu_id):
    """All top-level nav menu items in stored order (REST `parent` field)."""
    items = []
    page = 1
    while True:
        batch = rest.request(
            "GET",
            "menu-items?menus=%s&per_page=100&page=%d&context=edit&_fields=id,title,url,type,object,object_id,parent,target,classes,menu_order,status"
            % (menu_id, page)
        )
        if not batch:
            break
        items.extend(batch)
        if len(batch) < 100:
            break
        page += 1
    return [i for i in items if int(i.get("parent", 0) or 0) == 0]


def resolve_primary_menu(rest, menu_id_override):
    """Resolve the menu assigned to the theme 'primary' location (mirrors
    conexao_reorder_get_primary_menu()); --menu-id overrides."""
    if menu_id_override:
        return rest.request("GET", "menus/%s" % menu_id_override)
    menus = rest.request("GET", "menus?per_page=100&_fields=id,name,slug,locations")
    for menu in (menus or []):
        locations = menu.get("locations") or []
        if "primary" in locations:
            return menu
    return None


def main():
    parser = argparse.ArgumentParser(
        description="Reorder the 'primary' nav menu (order-only) via the WP REST API."
    )
    parser.add_argument("--base-url", default=None, help="Site base URL (default: WP_BASE_URL env or https://conexaobr.ie)")
    parser.add_argument("--menu-id", type=int, default=None, help="Menu term ID (default: resolve the menu assigned to 'primary')")
    parser.add_argument("--dry-run", action="store_true", help="Preview what would be renumbered without writing.")
    args = parser.parse_args()

    load_dotenv()

    user = os.environ.get("WP_USERNAME")
    app_password = os.environ.get("WP_APPLICATION_PASSWORD")
    if not user or not app_password:
        sys.exit(
            "Erro: defina WP_USERNAME e WP_APPLICATION_PASSWORD (no .env ou no ambiente).\n"
            "Crie a senha em wp-admin → Seu perfil → Senhas de aplicativo "
            "(Application Passwords)."
        )

    base_url = args.base_url or os.environ.get("WP_BASE_URL", "https://conexaobr.ie")
    rest = WpRest(base_url, user, app_password)

    # --- Sanity check: authentication -----------------------------------
    try:
        me = rest.request("GET", "users/me?context=edit&_fields=id,name")
    except RuntimeError as e:
        sys.exit("Erro de autenticação em %s: %s" % (base_url, e))
    print("Autenticado como: %s (ID %s)" % (me.get("name", "?"), me.get("id", "?")))

    # --- Resolve the primary menu -----------------------------------------
    try:
        menu = resolve_primary_menu(rest, args.menu_id)
    except RuntimeError as e:
        sys.exit("Erro ao localizar o menu primário: %s" % e)
    if not menu:
        sys.exit(
            "Menu primário não encontrado via localização 'primary'.\n"
            "Use --menu-id <ID> para indicar o menu manualmente."
        )

    locations = ",".join(menu.get("locations") or []) or "—"
    print("Menu primário: %s (ID %s), localização: %s" % (menu.get("name", "?"), menu.get("id"), locations))

    # --- Load stored items (top-level only) -----------------------------
    try:
        items = list_menu_items(rest, menu["id"])
    except RuntimeError as e:
        sys.exit("Erro ao listar os itens do menu: %s" % e)
    if not items:
        sys.exit("O menu primário não possui itens de primeiro nível. Nada a fazer.")

    items.sort(key=lambda i: (int(i.get("menu_order", 0)), int(i.get("id", 0))))
    print("\nOrdem atual gravada:")
    for item in items:
        print("  %2d. #%d %s -> %s" % (int(item.get("menu_order", 0)), item["id"], display_title(item), item.get("url", "")))

    # --- Canonical sort (stable — mirrors the PHP usort) -----------------
    contato_at = CANONICAL_ORDER.index("contato")

    def position(item):
        key = get_section_key(item)
        if key is None or key not in CANONICAL_ORDER:
            return contato_at
        return CANONICAL_ORDER.index(key)

    sorted_items = sorted(items, key=position)  # Python sort is stable (PHP8 usort too).

    moves = []
    for index, item in enumerate(sorted_items):
        new_order = index + 1
        old_order = int(item.get("menu_order", 0))
        if old_order != new_order:
            moves.append((item, old_order, new_order))

    if not moves:
        print("\nMenu primário já está na ordem canônica. Nenhuma alteração.")
        return

    print("\n%d item(ns) fora da ordem canônica:" % len(moves))
    for item, old, new in moves:
        print("  Mover #%d %s: menu_order %d -> %d" % (item["id"], display_title(item), old, new))

    if args.dry_run:
        print("\n(--dry-run: nenhuma alteração foi gravada.)")
        return

    for item, old, new in moves:
        try:
            rest.request("POST", "menu-items/%d" % item["id"], {"menu_order": new})
        except RuntimeError as e:
            sys.exit("Erro ao atualizar #%d (%s): %s" % (item["id"], display_title(item), e))

    print("\nForam reordenados %d item(ns)." % len(moves))

    print("\nOrdem final gravada:")
    for index, item in enumerate(sorted_items):
        print("  %2d. #%d %s -> %s" % (index + 1, item["id"], display_title(item), item.get("url", "")))

    print("\nNota: \"Lazer e turismo\" é inserido em tempo de renderização pelo tema")
    print("(entre Cursos e Empregos) e não possui item de menu gravado — nada a fazer para ele.")
    print("\nA atualização via REST já invalida o cache da WordPress.com; se a ordem")
    print("não aparecer imediatamente, regrave o menu no wp-admin para purgar o cache de borda.")


if __name__ == "__main__":
    main()
