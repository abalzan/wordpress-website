#!/usr/bin/env python3
"""Lazer expansion - Stage D: build the scoped 13-record deployment ZIP.

Pure-Python equivalent of scripts/build-lazer-stage-d-zip.php.
Runs anywhere with Python 3.8+ (standard library only) - no WordPress,
no PHP, no database needed.

Reads the 12 approved NEW records from
scripts/data/leisure-expansion-data-3.php (frozen Stage C dataset) and embeds
the pre-verified Knocknarea improvement record, then writes a
conexao-leisure-migration import package (lazer-export/data.json, no image
files). The package touches at most the 13 frozen slugs via the importer's
UUID -> slug -> title matching.

Image safety: the 12 NEW records carry no image (Stage C decision C).
Knocknarea image_data is stripped so production performs a pure
metadata/text update and never touches attachments.

You do NOT upload this .py file to WordPress. Run it on your own computer,
then upload the produced .zip via wp-admin: Lazer -> Importar Lazer
(dry-run preview first, then confirm). Expected preview: found=13,
created=12, updated=1 (Knocknarea), failed=0, images 0/0/0.

Usage:
    python3 scripts/build-lazer-stage-d-zip.py --out /tmp/lazer-stage-d-13-records.zip
    python3 scripts/build-lazer-stage-d-zip.py --data path/to/leisure-expansion-data-3.php --out out.zip
    python3 scripts/build-lazer-stage-d-zip.py --out out.zip --reference dist/lazer-stage-d-13-records.zip
"""
import argparse
import json
import os
import re
import sys
import zipfile
from datetime import datetime

FROZEN_SLUGS = [
    'chester-beatty', 'forty-foot', 'derrigimlagh', 'joyce-tower-museum',
    'the-model', 'doagh-famine-village', 'dursey-island',
    'old-head-of-kinsale', 'lough-muckno-leisure-park', 'jfk-arboretum',
    'cavan-cathedral', 'national-design-craft-gallery',
    'queen-maeves-trail-knocknarea',
]
NEW_SLUGS = [s for s in FROZEN_SLUGS if s != 'queen-maeves-trail-knocknarea']

# Stable export UUIDs (from the verified local Stage C export). Reused so the
# importer matches by UUID first, exactly as the PHP builder would emit.
UUID_MAP = {
    'chester-beatty': '76f6df2e-47e2-4d7b-a2db-2616b266fb7f',
    'forty-foot': '4094e853-12ad-4d7a-bb6b-9cfffd2f7b39',
    'derrigimlagh': 'e237cccb-1e94-4378-a644-6eb60185620c',
    'joyce-tower-museum': 'dd0cf46a-8d00-4487-a5d7-2960dc54d4e2',
    'the-model': '20c845d9-1683-4c4f-846d-980683c0f7a8',
    'doagh-famine-village': '582f80be-c0a8-495a-a96a-495dabdbae4c',
    'dursey-island': 'fddcf86a-655c-4f3b-b3f2-5b03cd9c7a55',
    'old-head-of-kinsale': '9d8e89db-db9d-43d7-b26a-011696cb79a9',
    'lough-muckno-leisure-park': '8c190be6-248a-440d-ad30-2e8abb776d81',
    'jfk-arboretum': '61067e86-76d3-401b-8dc9-809b2a6cd255',
    'cavan-cathedral': '3fff8153-eecf-4fc6-a0ff-a71b3248c6cb',
    'national-design-craft-gallery': '6f74d452-d691-4fc7-b236-d8b897a15ad9',
    'queen-maeves-trail-knocknarea': '4125e1c9-93e0-43a8-96b2-eed0ae50f101',
}

# Post dates are informational only (the importer does not set post_date).
# Fixed values keep rebuilds byte-stable at the data level.
DATES = {
    'chester-beatty': '2026-09-10 21:14:48',
    'forty-foot': '2026-09-10 21:14:49',
    'derrigimlagh': '2026-09-10 21:14:49',
    'joyce-tower-museum': '2026-09-10 21:14:50',
    'the-model': '2026-09-10 21:14:50',
    'doagh-famine-village': '2026-09-10 21:14:51',
    'dursey-island': '2026-09-10 21:14:52',
    'old-head-of-kinsale': '2026-09-10 21:14:52',
    'lough-muckno-leisure-park': '2026-09-10 21:14:53',
    'jfk-arboretum': '2026-09-10 21:14:54',
    'cavan-cathedral': '2026-09-10 21:14:54',
    'national-design-craft-gallery': '2026-09-10 21:14:55',
    'queen-maeves-trail-knocknarea': '2026-08-21 21:48:59',
}

KNOCKNAREA = {
    'uuid': '4125e1c9-93e0-43a8-96b2-eed0ae50f101',
    'id': '4125e1c9-93e0-43a8-96b2-eed0ae50f101',
    # NOTE: \uXXXX escapes decode to the exact verified accented text
    # (e.g. \u00e9 = e-acute); the file stays byte-stable on any transport.
    'post': {
        'title': "Queen Maeve's Trail (Knocknarea)",
        'content': ('<p>O Knocknarea (327 m) \u00e9 coroado por um enorme cairn atribu\u00eddo '
            '\u00e0 rainha guerreira Medb. A trilha de 40-50 minutos \u00e9 a caminhada mais popular '
            'de Sligo, com panorama de Benbulben ao oceano.</p>'),
        'excerpt': ('Trilha at\u00e9 o cairn de pedra da rainha Medb no topo do Knocknarea, '
            'com vista de Sligo e do Atl\u00e2ntico.'),
        'status': 'publish',
        'slug': 'queen-maeves-trail-knocknarea',
        'date': '2026-08-21 21:48:59',
        'modified': '2026-08-21 21:48:59',
    },
    'meta': {
        '_leisure_county': 'Sligo',
        '_leisure_town': 'Strandhill',
        '_leisure_discover_ireland': 'https://www.discoverireland.ie/sligo/queen-maeve-trail',
        '_leisure_free': '1',
        '_leisure_outdoor': '1',
        '_leisure_parking': '1',
        '_leisure_internal_page': '1',
        '_leisure_image_source': 'Wikimedia Commons',
        '_leisure_image_source_url': 'https://commons.wikimedia.org/wiki/File:Knocknarea.jpg',
        '_leisure_image_author': 'BigBear_Martin',
        '_leisure_image_license': 'Public domain',
        '_leisure_image_attribution': 'Foto: BigBear_Martin, Fonte: Wikimedia Commons',
        '_leisure_image_alt_text': "Queen Maeve's Trail (Knocknarea), Sligo, Irlanda",
    },
    'taxonomies': {
        'conexao_category': ['Caminhadas'],
        'conexao_county': ['Sligo'],
        'conexao_tag': [],
        'conexao_leisure_attribute': ['Estacionamento', 'Exterior', 'Gratuito'],
    },
    'image_data': {
        'id': '', 'filename': '',
        'original_filename': 'Queen-Maeve8217s-Trail-Knocknarea.jpg',
        'mime': 'image/jpeg', 'md5': '',
        'alt': "Queen Maeve's Trail (Knocknarea), Sligo, Irlanda",
        'title': 'Queen Maeve&#8217;s Trail (Knocknarea)', 'caption': '',
        'source': 'Wikimedia Commons',
        'source_url': 'https://commons.wikimedia.org/wiki/File:Knocknarea.jpg',
        'author': 'BigBear_Martin', 'license': 'Public domain',
        'attribution': 'Foto: BigBear_Martin, Fonte: Wikimedia Commons',
        'external_url': '',
    },
    'image': {
        'id': '', 'filename': '',
        'original': 'Queen-Maeve8217s-Trail-Knocknarea.jpg', 'md5': '',
    },
}

ATTR_MAP = [
    ('family', 'Fam\u00edlias'),
    ('outdoor', 'Exterior'),
    ('indoor', 'Interior'),
    ('booking', 'Necessita reserva'),
    ('pet', 'Pet friendly'),
    ('parking', 'Estacionamento'),
    ('accessibility', 'Acess\u00edvel'),
    ('transport', 'Acesso de transporte p\u00fablico'),
    ('bicycle', 'Bicicleta'),
]

import unicodedata


def _sort_key(s):
    return unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()

FREE_TERMS = ('Gratuito', 'Pago', 'Gratuito em determinadas condi\u00e7\u00f5es')


def die(msg):
    print('ERROR: ' + msg, file=sys.stderr)
    raise SystemExit(1)


def parse_php_value(text):
    text = text.strip()
    if text in ('true', 'TRUE'): return True
    if text in ('false', 'FALSE'): return False
    if text in ('null', 'NULL', 'array()'): return None
    if text.startswith("'") and text.endswith("'") and len(text) >= 2:
        return text[1:-1]
    if text.startswith('"') and text.endswith('"') and len(text) >= 2:
        return text[1:-1]
    try: return int(text)
    except ValueError: pass
    try: return float(text)
    except ValueError: pass
    die('unsupported PHP value: %r' % text)


def split_top_level(body):
    parts, depth, cur, instr, q = [], 0, '', False, ''
    for ch in body:
        if instr:
            cur += ch
            if ch == q: instr = False
            continue
        if ch in ("'", '"'): instr, q, cur = True, ch, cur + ch
        elif ch == '(':
            depth += 1; cur += ch
        elif ch == ')':
            depth -= 1; cur += ch
        elif ch == ',' and depth == 0:
            parts.append(cur); cur = ''
        else: cur += ch
    if cur.strip(): parts.append(cur)
    return parts


def strip_php_comments(text):
    out, instr, q, i, n = '', False, '', 0, len(text)
    while i < n:
        ch = text[i]
        if instr:
            out += ch
            if ch == q: instr = False
            i += 1
        elif ch in ("'", '"'):
            instr, q, out = True, ch, out + ch
            i += 1
        elif ch == '/' and i + 1 < n and text[i + 1] == '/':
            while i < n and text[i] != '\n':
                i += 1
        else:
            out += ch
            i += 1
    return out


def parse_php_array(text):
    text = strip_php_comments(text).strip()
    idx = text.find('array(')
    if idx > 0:
        text = text[idx:]
    if not text.startswith('array('):
        die('expected array(...), got: %r' % text[:60])
    inner = text[len('array('):]
    depth = 1; instr = False; q = ''
    for i, ch in enumerate(inner):
        if instr:
            if ch == q: instr = False
        elif ch in ("'", '"'): instr, q = True, ch
        elif ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1
            if depth == 0:
                inner = inner[:i]; break
    result = {}
    for part in split_top_level(inner):
        part = part.strip().rstrip(',')
        if not part: continue
        m = re.match(r"^'([^']*)'\s*=>\s*(.*)$", part, re.S)
        if not m:
            m = re.match(r'^"([^"]*)"\s*=>\s*(.*)$', part, re.S)
        if not m:
            if 'array(' in part:
                return None  # wrapper block (e.g. "return array(..."), skip
            die('cannot parse entry: %r' % part[:80])
        key, raw = m.group(1), m.group(2).strip()
        if raw.startswith('array('):
            items = []
            for sub in split_top_level(raw[len('array('):-1]):
                sub = sub.strip()
                if sub: items.append(parse_php_value(sub))
            result[key] = items
        else:
            result[key] = parse_php_value(raw)
    return result


def parse_data3(path):
    src = open(path, encoding='utf-8').read()
    records = []
    starts = [m.start() for m in re.finditer(r'array\(', src)]
    for start in starts:
        depth, instr, q, end = 0, False, '', None
        for i in range(start, len(src)):
            ch = src[i]
            if instr:
                if ch == q:
                    instr = False
            elif ch in ("'", '"'):
                instr, q = True, ch
            elif ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
                if depth == 0:
                    end = i + 1
                    break
        if end is None:
            continue
        block = src[start:end]
        if "'slug'" in block and "'title'" in block:
            rec = parse_php_array(block)
            if rec is not None and 'slug' in rec:
                records.append(rec)
    if len(records) != 12:
        die('expected 12 NEW records in %s, parsed %d' % (path, len(records)))
    return records


def build_item(d):
    attrs = [label for key, label in ATTR_MAP if d.get(key)]
    if d.get('indoor') and d.get('outdoor'):
        attrs = [a for a in attrs if a not in ('Interior', 'Exterior')]
        attrs.append('Interior + exterior')
    free_label = (d.get('free_status') or '').strip() or ('Gratuito' if d.get('free') else '')
    if free_label in FREE_TERMS:
        attrs.append(free_label)
    meta = {}
    meta['_leisure_county'] = d.get('county', '')
    meta['_leisure_town'] = d.get('town', '')
    if d.get('address'): meta['_leisure_address'] = d['address']
    if d.get('official_website'): meta['_leisure_official_website'] = d['official_website']
    if d.get('discover_ireland'): meta['_leisure_discover_ireland'] = d['discover_ireland']
    if free_label: meta['_leisure_free'] = free_label
    if d.get('family'): meta['_leisure_family'] = '1'
    if d.get('accessibility'): meta['_leisure_accessibility'] = '1'
    if d.get('pet'): meta['_leisure_pet_friendly'] = '1'
    if d.get('indoor'): meta['_leisure_indoor'] = '1'
    if d.get('outdoor'): meta['_leisure_outdoor'] = '1'
    if d.get('parking'): meta['_leisure_parking'] = '1'
    if d.get('booking'): meta['_leisure_booking'] = '1'
    # NOTE: seed-leisure-expansion.php writes NO legacy meta for transport/bicycle
    # (attribute terms only). Mirrored here so the payload matches exactly.
    if d.get('practical_notes'): meta['_leisure_practical_notes'] = d['practical_notes']
    if d.get('practical_source_url'): meta['_leisure_practical_source_url'] = d['practical_source_url']
    if d.get('practical_last_checked'): meta['_leisure_practical_last_checked'] = d['practical_last_checked']
    slug = d['slug']
    uuid = UUID_MAP[slug]
    date = DATES[slug]
    item = {
        'uuid': uuid,
        'id': uuid,
        'post': {
            'title': d['title'], 'content': d.get('content', ''),
            'excerpt': d.get('excerpt', ''), 'status': 'publish',
            'slug': slug, 'date': date, 'modified': date,
        },
        'meta': meta,
        'taxonomies': {
            'conexao_category': [d['category']],
            'conexao_county': [d['county']],
            'conexao_tag': [],
            'conexao_leisure_attribute': sorted(attrs, key=_sort_key),
        },
        'image_data': {
            'id': '', 'filename': '', 'original_filename': '', 'mime': '',
            'md5': '', 'alt': '', 'title': '', 'caption': '', 'source': '',
            'source_url': '', 'author': '', 'license': '', 'attribution': '',
            'external_url': '',
        },
        'image': {'id': '', 'filename': 'images/', 'original': '', 'md5': ''},
    }
    return item

def knocknarea_item():
    import copy
    return copy.deepcopy(KNOCKNAREA)


def validate(items):
    slugs = [it['post']['slug'] for it in items]
    if slugs != FROZEN_SLUGS:
        die('payload order/slugs mismatch: %r' % slugs)
    # REVIEW/REJECTED slugs that must never appear as RECORD slugs.
    # (Substring scan would false-positive: 'connemara' is a legitimate word
    # inside the Derrigimlagh description. Compare slugs instead.)
    banned_slugs = ('national-archives', 'arranmore-island', 'dan-o-hara',
                    'connemara-heritage', 'duncannon-fort', 'galway-city',
                    'mullaghmore', 'jerpoint-glass-studio')
    slugset = set(slugs)
    bad = [b for b in banned_slugs if b in slugset]
    if bad:
        die('REVIEW/REJECTED leakage: %r' % bad)
    seen_urls = {}
    for it in items:
        for key in ('_leisure_official_website', '_leisure_discover_ireland'):
            url = it['meta'].get(key, '')
            if url:
                if url in seen_urls:
                    die('duplicate URL %s (%s, %s)' % (url, seen_urls[url], it['post']['slug']))
                seen_urls[url] = it['post']['slug']
    for it in items:
        slug = it['post']['slug']
        if slug == 'queen-maeves-trail-knocknarea':
            continue
        if it['image_data'].get('filename') or it['image'].get('filename') not in ('', 'images/'):
            die('%s carries an image' % slug)
    return True


def build_payload(items):
    return {
        'manifest': {
            'format': 'conexao-lazer-export',
            'version': '2.0',
            'post_type': 'leisure',
            'exported_at': datetime.now().astimezone().isoformat(timespec='seconds'),
            'source_url': 'local',
            'item_count': len(items),
            'packaged': True,
            'package': {'images_dir': 'images', 'data_file': 'data.json'},
            'stage': 'lazer-expansion-stage-d scoped 13-record set',
        },
        'items': items,
    }


def write_zip(payload, dest):
    data = json.dumps(payload, ensure_ascii=False, indent=4)
    with zipfile.ZipFile(dest, 'w', zipfile.ZIP_DEFLATED) as zf:
        zf.writestr('lazer-export/data.json', data.encode('utf-8'))


def main():
    ap = argparse.ArgumentParser(description='Build the scoped Stage D 13-record Lazer ZIP (no WordPress needed).')
    ap.add_argument('--data', default=None, help='path to leisure-expansion-data-3.php')
    ap.add_argument('--out', required=True, help='output .zip path')
    ap.add_argument('--reference', default=None, help='reference ZIP to diff against (byte-level data check)')
    args = ap.parse_args()
    base = os.path.dirname(os.path.abspath(__file__))
    data_path = args.data or os.path.join(base, 'data', 'leisure-expansion-data-3.php')
    if not os.path.isfile(data_path):
        die('data file not found: %s' % data_path)
    records = parse_data3(data_path)
    by_slug = {r['slug']: r for r in records}
    missing = [s for s in NEW_SLUGS if s not in by_slug]
    if missing:
        die('frozen slugs missing from data file: %s' % ', '.join(missing))
    items = [build_item(by_slug[s]) for s in NEW_SLUGS]
    items.append(knocknarea_item())
    validate(items)
    payload = build_payload(items)
    write_zip(payload, args.out)
    print('Scoped Stage D package: %s' % args.out)
    print('  Payload items : %d' % len(items))
    print('  ZIP size      : %d bytes' % os.path.getsize(args.out))
    print('  Slugs         : %s' % ', '.join(FROZEN_SLUGS))
    if args.reference:
        with zipfile.ZipFile(args.reference) as zf:
            ref = json.load(zf.open('lazer-export/data.json'))
        new_payload = dict(payload)
        ref_items = {it['post']['slug']: it for it in ref['items']}
        new_items = {it['post']['slug']: it for it in payload['items']}
        diffs = []
        for slug in FROZEN_SLUGS:
            a = dict(ref_items[slug]); b = dict(new_items[slug])
            for k in ('post', 'meta', 'taxonomies', 'image_data', 'image'):
                if a.get(k) != b.get(k):
                    diffs.append('%s.%s differs' % (slug, k))
        if diffs:
            print('  Reference diff:')
            for d in diffs:
                print('    - ' + d)
            die('reference mismatch')
        print('  Reference diff: identical (post/meta/taxonomies/image)')
    print('STAGE-D PACKAGE OK')


if __name__ == '__main__':
    main()
