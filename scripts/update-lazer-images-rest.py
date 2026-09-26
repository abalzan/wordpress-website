#!/usr/bin/env python3
"""
REST updater for the /lazer/ image audit (WP.com-compatible).

Production runs on WordPress.com where there is no SSH/SFTP/CLI and PHP
scripts cannot be executed. This script therefore updates the Lazer
(leisure) listings entirely through the WordPress REST API using an
Application Password — the same approach as
scripts/seed-recruitment-agencies-rest.py.

What it does, per listing:
  1. Reads the leisure post by SLUG (never by local post ID — IDs are not
     portable between installs).
  2. Downloads the verified replacement image from Wikimedia Commons
     (Special:FilePath, scaled) with a proper User-Agent.
  3. Uploads it into the WordPress Media Library (/wp-json/wp/v2/media).
  4. Sets it as the post's featured image (featured_media) — this is what
     the theme renders as the hero/card image (conexao_leisure_hero_image()
     and template-parts/leisure-card.php both read the post thumbnail).
  5. Writes the attribution meta (_leisure_image_*) in the exact format
     used by the PHP importer (conexao-admin-ux class-leisure-image-admin):
        source      = "Wikimedia Commons"
        source_url  = https://commons.wikimedia.org/wiki/File:<file>
        author      = <Commons author>
        license     = <Commons licence short name>
        attribution = "Foto: <author>[, Licença: <licence>], Fonte: Wikimedia Commons"
        alt_text    = "<post title>, <county>, Irlanda"
        status      = "local"
        attachment_id = <new media ID>

IMPORTANT — required plugin change
----------------------------------
The `_leisure_image_*` meta keys are underscore-prefixed (protected).
WordPress core's register_meta() defaults a protected key's auth_callback
to __return_false, which makes every REST write return
`403 rest_cannot_update`. conexao-data-model now registers these keys with
an explicit auth_callback (see register_leisure_meta()), so REST writes
work. Deploy that plugin update to production BEFORE running this script,
otherwise the image upload succeeds but the attribution meta is rejected
(the script detects this and tells you).

Idempotency / safety
--------------------
- A listing is SKIPPED when its stored `_leisure_image_source_url` already
  equals the target Commons file page (unless --force).
- Nothing is deleted by default. Use --delete-old to remove the superseded
  attachment after a successful update.
- --dry-run prints the full plan without writing anything.

Usage:
    export WP_USERNAME='your-wpcom-username'
    export WP_APPLICATION_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'

    python3 scripts/update-lazer-images-rest.py --dry-run      # preview all
    python3 scripts/update-lazer-images-rest.py                # apply all
    python3 scripts/update-lazer-images-rest.py --slug spike-island-cork
    python3 scripts/update-lazer-images-rest.py --force --delete-old

Options:
    --base-url URL   Site base URL (default: $CONEXAO_SITE_URL, else the local site)
    --slug S         Only process this slug (repeatable / comma-separated)
    --width N        Commons scaled download width in px (default: 2560)
    --force          Update even when the stored source URL already matches
    --delete-old     Delete the superseded attachment after a successful set
    --dry-run        Show the plan without writing
    --list           Print the target table and exit

Requires Python 3.8+ (standard library only).
"""

import argparse
import base64
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

# REST etiquette requires a contactable User-Agent. This is an identity
# string sent to the site being audited, never an operational target: the
# target itself is resolved from CONEXAO_SITE_URL / --base-url.
USER_AGENT = "ConexaoBR-lazer-image-audit/1.0 (+https://conexaobr.ie; contact@conexaobr.ie)"

COMMONS_FILE_PATH = "https://commons.wikimedia.org/wiki/Special:FilePath/"
COMMONS_FILE_PAGE = "https://commons.wikimedia.org/wiki/File:"

# ---------------------------------------------------------------------------
# Verified replacement images (see the Lazer image audit).
#
# `commons` is the exact Wikimedia Commons file title. `author` +
# `license` + `require_attr` come from the Commons API (imageinfo /
# extmetadata) at audit time. `require_attr` mirrors the client's rule:
# CC0 / Public Domain do not add the licence segment to the attribution.
# ---------------------------------------------------------------------------
TARGETS = [
    {
        "slug": "drumcliffe",
        "county": "Sligo",
        "commons": "Round Tower and church at Drumcliffe, Sligo.JPG",
        "author": "Shane Finan",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces an unrelated sailing ship (State Library of Queensland).",
    },
    {
        "slug": "sliabh-beagh",
        "county": "Monaghan",
        "commons": "Sliabh Beagh Way - geograph.org.uk - 631670.jpg",
        "author": "Kenneth Allen",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces a photo of a trail information plaque. Low resolution (640px).",
    },
    {
        "slug": "sean-mac-diarmada-cottage",
        "county": "Leitrim",
        "commons": "Seán Mac Diarmada's House - geograph.org.uk - 1118481.jpg",
        "author": "Kenneth Allen",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces a Kiltyclogher street scene with the actual cottage. Low resolution (640px).",
    },
    {
        "slug": "shannon-pot",
        "county": "Cavan",
        "commons": "Shannon Pot, Cuilcagh Mountain.jpg",
        "author": "Andrewhumphreys",
        "license": "CC BY-SA 3.0",
        "require_attr": True,
        "why": "Replaces an 1836 engraved map with a photo of the spring.",
    },
    {
        "slug": "boyle-abbey",
        "county": "Roscommon",
        "commons": "Boyle Abbey 2023-05-27 1.jpg",
        "author": "Z thomas",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces a captioned sepia postcard with a modern exterior (2023).",
    },
    {
        "slug": "rathcroghan",
        "county": "Roscommon",
        "commons": "Rathcroghan Mound.png",
        "author": "Sawyer777",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces a 565x374 field aerial with the main Rathcroghan Mound.",
    },
    {
        "slug": "ardagh-heritage-village",
        "county": "Longford",
        "commons": "Fetherston Clock Tower with Longford sign.jpg",
        "author": "SE Keenan",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces a wrong-subject photo (Mike Ardagh investiture, New Zealand).",
    },
    {
        "slug": "belvedere-house-gardens",
        "county": "Westmeath",
        "commons": "Mullingar - Belvedere House and Gardens - 20211014140102.jpg",
        "author": "Olliebailie",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Optional upgrade: colour 2021 photo instead of a monochrome folly shot.",
    },
    {
        "slug": "abbeyleix-heritage-house",
        "county": "Laois",
        "commons": "Heritage House 17.jpg",
        "author": "A.-K. D.",
        "license": "CC0",
        "require_attr": False,
        "why": "Replaces a car-filled street scene with the Heritage House building.",
    },
    {
        "slug": "spike-island-cork",
        "county": "Cork",
        "commons": "Fort Mitchel, Spike Island - geograph.org.uk - 7686086.jpg",
        "author": "Mike Pennington",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces a drawing of Spike Island, WIDNES, ENGLAND (wrong country).",
    },
    {
        "slug": "coole-park",
        "county": "Galway",
        "commons": "Contrasting colours seen in Coole Park.jpg",
        "author": "Megankeane13",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces Coole Park Manor, WISCONSIN, USA (wrong country).",
    },
    {
        "slug": "killruddery-house-gardens",
        "county": "Wicklow",
        "commons": "Killruddery House, Co.Wicklow.jpg",
        "author": "AnaGreysStones",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces an 1834 engraving with a modern photo.",
    },
    {
        "slug": "dun-laoghaire-east-pier",
        "county": "Dublin",
        "commons": "Dun Laoghaire East Pier and Lighthouse - geograph.org.uk - 8045511.jpg",
        "author": "Gareth James",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces a late-1980s George's Street photo (street, not the pier).",
    },
    {
        "slug": "portmarnock-beach",
        "county": "Dublin",
        "commons": "Portmarnock's beach is nicknamed The Velvet Strand (3498070861).jpg",
        "author": "William Murphy",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces a 1933 aircraft-crash photo with a beach/Martello view.",
    },
    {
        "slug": "ross-castle",
        "county": "Kerry",
        "commons": "Ross Castle, Killarney - geograph.org.uk - 5241796.jpg",
        "author": "Dr Neil Clifton",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces an 1841 watercolour with a modern photo (2016).",
    },
    {
        "slug": "mizen-head",
        "county": "Cork",
        "commons": "County Cork Mizen Head Signal Station 1.jpg",
        "author": "Zairon",
        "license": "CC BY 4.0",
        "require_attr": True,
        "why": "Optional upgrade: 2022 Signal Station photo instead of a 1987 bridge photo.",
    },
    {
        "slug": "phoenix-park",
        "county": "Dublin",
        "commons": "Herd of Fallow Deer, Phoenix Park - geograph.org.uk - 6016502.jpg",
        "author": "N Chadwick",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces an 18th-century painting with the park's famous deer herd.",
    },
    # -----------------------------------------------------------------------
    # Batch 2 — /lazer/ image audit for the 23 listings flagged in the review.
    # 13 had no image at all (new expansion records); the rest had wrong,
    # low-quality or clearly outdated images (e.g. Mitchelstown Cave showing
    # "The Four Courts", Dunmore/Kilkenny as 19th-century engravings, Kinsale
    # as a bank, Wild Nephin as a map). Every file was verified on Commons for
    # subject, licence and author before being added here.
    # -----------------------------------------------------------------------
    {
        "slug": "mitchelstown-cave",
        "county": "Cork",
        "commons": "Mitchelstown Cave.jpg",
        "author": "Johneng",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces the wrong 'Four Courts' engraving with a modern colour photo inside the cave.",
    },
    {
        "slug": "national-design-craft-gallery",
        "county": "Kilkenny",
        "commons": "Old Stables, Kilkenny Castle, Cill Chainnigh, Éire (45680619435).jpg",
        "author": "Warren LeMay",
        "license": "CC0",
        "require_attr": False,
        "why": "Adds the Castle Yard building in Kilkenny that houses the gallery (listing had no image).",
    },
    {
        "slug": "jfk-arboretum",
        "county": "Wexford",
        "commons": "JFK Arboretum, Wexford, Ireland - panoramio.jpg",
        "author": "Gfox228",
        "license": "CC BY 3.0",
        "require_attr": True,
        "why": "Adds a landscape view of the arboretum (listing had no image).",
    },
    {
        "slug": "cavan-cathedral",
        "county": "Cavan",
        "commons": "The portico of the Cathedral of SS Patrick and Felim - geograph.org.uk - 3142124.jpg",
        "author": "Eric Jones",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Adds a landscape exterior of the cathedral portico (listing had no image).",
    },
    {
        "slug": "lough-muckno-leisure-park",
        "county": "Monaghan",
        "commons": "The eastern section of Muckno Lough - geograph.org.uk - 4167780.jpg",
        "author": "Eric Jones",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Adds a landscape view across Lough Muckno (listing had no image).",
    },
    {
        "slug": "dursey-island",
        "county": "Cork",
        "commons": "Dursey Island aerial view.jpg",
        "author": "Podstawko",
        "license": "CC0",
        "require_attr": False,
        "why": "Adds an aerial view of the whole island (listing had no image).",
    },
    {
        "slug": "old-head-of-kinsale",
        "county": "Cork",
        "commons": "County Cork - Old Head of Kinsale - 20210728174959.jpg",
        "author": "Jonjobaker",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Adds the actual headland (not Kinsale town) (listing had no image).",
    },
    {
        "slug": "doagh-famine-village",
        "county": "Donegal",
        "commons": "Doagh Famine Village - geograph.org.uk - 51690.jpg",
        "author": "Corinna Schleiffer",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Adds a view of the outdoor museum village (listing had no image).",
    },
    {
        "slug": "joyce-tower-museum",
        "county": "Dublin",
        "commons": "James Joyce Tower 01.JPG",
        "author": "YvonneM",
        "license": "CC BY-SA 3.0",
        "require_attr": True,
        "why": "Adds the Martello tower museum exterior (listing had no image).",
    },
    {
        "slug": "the-model",
        "county": "Sligo",
        "commons": "Sligo - The Model gallery, 2011.jpg",
        "author": "Val",
        "license": "CC BY 2.0",
        "require_attr": True,
        "why": "Adds the gallery exterior in Sligo (listing had no image).",
    },
    {
        "slug": "forty-foot",
        "county": "Dublin",
        "commons": "Steps at the Forty Foot Bathing Place - geograph.org.uk - 6312431.jpg",
        "author": "Aleks Scholz",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Adds the famous bathing-place steps (listing had no image).",
    },
    {
        "slug": "derrigimlagh",
        "county": "Galway",
        "commons": "Derrigimlagh Bog 01.jpg",
        "author": "DirkVE",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Adds the Alcock & Brown landing site on the bog (listing had no image).",
    },
    {
        "slug": "chester-beatty",
        "county": "Dublin",
        "commons": "Exterior of the Chester Beatty, Dublin, Ireland.jpg",
        "author": "Derval O'Carroll",
        "license": "CC BY 4.0",
        "require_attr": True,
        "why": "Adds the museum exterior in Dublin Castle (listing had no image).",
    },
    {
        "slug": "oak-park-forest-park",
        "county": "Carlow",
        "commons": "Arch - geograph.org.uk - 516639.jpg",
        "author": "liam murphy",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces an unrelated 1901 house-party photo with the estate's triumphal arch.",
    },
    {
        "slug": "lismore-castle-gardens",
        "county": "Waterford",
        "commons": "Yew Avenue in Lismore Castle Gardens County Waterford Ireland (42039910785).jpg",
        "author": "Heinz Bunse",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces a 19th-century engraving with the gardens' famous yew avenue.",
    },
    {
        "slug": "castlecomer-discovery-park",
        "county": "Kilkenny",
        "commons": "Fallow deer Discovery Park walled garden.jpg",
        "author": "Laurel Lodged",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Adds the park's walled garden (listing had no image).",
    },
    {
        "slug": "wild-nephin-ballycroy-national-park",
        "county": "Mayo",
        "commons": "Wild Nephin Ballycroy National Park.jpg",
        "author": "Karie Kuiper",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces a park map with a landscape photo of the visitor centre and Nephin Beg range.",
    },
    {
        "slug": "kilkenny-castle",
        "county": "Kilkenny",
        "commons": "Ireland Kilkenny Castle BW 2025-09-15 10-18-56.jpg",
        "author": "Berthold Werner",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces an 1840s painting with a modern exterior photo.",
    },
    {
        "slug": "dunmore-cave",
        "county": "Kilkenny",
        "commons": "Entrance to Dunmore Cave.jpg",
        "author": "Jan-Philipp Litza",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces an 1832 engraving with a photo of the cave entrance.",
    },
    {
        "slug": "malin-head",
        "county": "Donegal",
        "commons": "Malin Head (2019).jpg",
        "author": "Kent Wang",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Upgrades the aerial photo to an iconic ground-level view of Ireland's most northerly point.",
    },
    {
        "slug": "blackrock-castle-observatory",
        "county": "Cork",
        "commons": "Blackrock Castle, Cork - geograph.org.uk - 3051742.jpg",
        "author": "David Hawgood",
        "license": "CC BY-SA 2.0",
        "require_attr": True,
        "why": "Replaces an old monochrome view with a modern landscape photo of the castle.",
    },
    {
        "slug": "kinsale",
        "county": "Cork",
        "commons": "Fishing vessels in the port of Kinsale, Co. Cork (2014).jpg",
        "author": "JoachimKohler-HB",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces a photo of a bank with the town's harbour and fishing port.",
    },
    {
        "slug": "sally-gap",
        "county": "Wicklow",
        "commons": "Sallys Gap viewpoint Wicklow Mountains National Park.jpg",
        "author": "Jonjobaker",
        "license": "CC BY-SA 4.0",
        "require_attr": True,
        "why": "Replaces a crossroads signpost photo with the wider Sally Gap mountain viewpoint.",
    },
]


def _selected_slugs(values):
    """Normalize --slug input (repeatable and/or comma-separated)."""
    out = set()
    for value in values:
        for part in str(value).split(","):
            part = part.strip()
            if part:
                out.add(part)
    return out


def commons_file_title(commons):
    return commons.replace(" ", "_")


def commons_page_url(commons):
    """Human-readable Commons file page (matches the PHP importer format)."""
    return COMMONS_FILE_PAGE + urllib.parse.quote(
        commons_file_title(commons), safe=":/(),.!'&-_"
    )


def commons_download_url(commons, width):
    """Scaled direct download via Special:FilePath (follows the 302)."""
    return (
        COMMONS_FILE_PATH
        + urllib.parse.quote(commons_file_title(commons), safe=":/(),.!'&-_")
        + "?width={}".format(width)
    )


def build_attribution(target):
    """Exact format used by conexao-admin-ux class-leisure-image-admin.php."""
    text = "Foto: " + target["author"]
    if target["require_attr"]:
        text += ", Licença: " + target["license"]
    text += ", Fonte: Wikimedia Commons"
    return text


def build_meta(target, attachment_id, alt_text):
    return {
        "_leisure_image_attachment_id": attachment_id,
        "_leisure_image_source": "Wikimedia Commons",
        "_leisure_image_source_url": commons_page_url(target["commons"]),
        "_leisure_image_author": target["author"],
        "_leisure_image_license": target["license"],
        "_leisure_image_attribution": build_attribution(target),
        "_leisure_image_alt_text": alt_text,
        "_leisure_image_status": "local",
    }


def build_alt_text(post, target):
    """Mirror the importer: "<title>, <county>, Irlanda"."""
    raw_title = (post.get("title") or {}).get("raw")
    if not raw_title:
        raw_title = (post.get("title") or {}).get("rendered") or target["slug"]
    title = re.sub(r"&amp;", "&", raw_title)
    county = (post.get("meta") or {}).get("_leisure_county") or target.get("county") or ""
    if county:
        return "{}, {}, Irlanda".format(title, county)
    return "{}, Irlanda".format(title)


def pick_mime(ctype, commons):
    if ctype in ("image/jpeg", "image/png", "image/gif", "image/webp"):
        return ctype
    return "image/png" if commons.lower().endswith(".png") else "image/jpeg"


# ---------------------------------------------------------------------------
# Minimal WordPress REST client (standard library only).
# ---------------------------------------------------------------------------
class WpRest:
    def __init__(self, base_url, user, app_password, timeout=120):
        self.base = base_url.rstrip("/") + "/wp-json/wp/v2"
        token = base64.b64encode(
            "{}:{}".format(user, app_password).encode("utf-8")
        ).decode("ascii")
        self.auth = "Basic " + token
        self.timeout = timeout

    def _open(self, req, retries=8):
        last_error = None
        for attempt in range(retries):
            try:
                with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                    body = resp.read().decode("utf-8", "replace")
                    return json.loads(body) if body else None
            except urllib.error.HTTPError as e:
                detail = e.read().decode("utf-8", "replace")[:500]
                error = RuntimeError(
                    "HTTP {} on {} {}: {}".format(e.code, req.get_method(), req.full_url, detail)
                )
                # 429 (rate limit) and 5xx are transient — retry with backoff.
                # Other 4xx are deterministic — do not retry.
                if e.code != 429 and e.code < 500:
                    raise error from e
                last_error = error
                retry_after = e.headers.get("Retry-After")
                if retry_after and str(retry_after).strip().isdigit():
                    time.sleep(min(int(retry_after), 60))
                    continue
            except urllib.error.URLError as e:
                last_error = RuntimeError("Network error on {}: {}".format(req.full_url, e.reason))
            # Exponential backoff (capped) for transient failures, e.g. the
            # WordPress.com edge rate limiter returning 429.
            time.sleep(min(5 * (attempt + 1), 45))
        raise last_error

    def request(self, method, path, payload=None, raw=None, content_type=None):
        url = self.base + "/" + path.lstrip("/")
        headers = {
            "Authorization": self.auth,
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        }
        data = None
        if raw is not None:
            data = raw
            headers["Content-Type"] = content_type
        elif payload is not None:
            data = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json; charset=utf-8"
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        return self._open(req)

    def upload_media(self, filename, content, mime, fields):
        boundary = "----ConexaoBR" + uuid.uuid4().hex
        chunks = []
        for name, value in fields.items():
            if value in (None, ""):
                continue
            chunks.append(
                ("--{}\r\nContent-Disposition: form-data; name=\"{}\"\r\n\r\n{}\r\n"
                 .format(boundary, name, value)).encode("utf-8")
            )
        chunks.append(
            ("--{}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{}\"\r\n"
             "Content-Type: {}\r\n\r\n".format(boundary, filename, mime)).encode("utf-8")
        )
        chunks.append(content)
        chunks.append(("\r\n--{}--\r\n".format(boundary)).encode("utf-8"))
        return self.request(
            "POST", "media", raw=b"".join(chunks),
            content_type="multipart/form-data; boundary={}".format(boundary),
        )


def download_image(url):
    req = urllib.request.Request(
        url, headers={"User-Agent": USER_AGENT, "Accept": "image/*,*/*"}
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        ctype = resp.headers.get("Content-Type", "").split(";")[0].strip()
        return resp.read(), ctype


def get_listing(rest, slug):
    posts = rest.request(
        "GET",
        "leisure?slug={}&context=edit&_fields=id,slug,title,featured_media,meta,link".format(
            urllib.parse.quote(slug)
        ),
    )
    return posts[0] if posts else None


def main():
    parser = argparse.ArgumentParser(
        description="Update the /lazer/ listing images via the WordPress REST API."
    )
    parser.add_argument("--base-url", default=os.environ.get("CONEXAO_SITE_URL", rest_mod.LOCAL_BASE_URL))
    parser.add_argument("--slug", action="append", default=[],
                        help="Only process this slug (repeatable or comma-separated).")
    parser.add_argument("--width", type=int, default=2560,
                        help="Commons scaled download width in px (default: 2560).")
    parser.add_argument("--force", action="store_true",
                        help="Update even when the stored source URL already matches.")
    parser.add_argument("--delete-old", action="store_true",
                        help="Delete the superseded attachment after a successful set.")
    parser.add_argument("--dry-run", action="store_true", help="Preview without writing.")
    parser.add_argument("--list", action="store_true", help="Print the target table and exit.")
    args = parser.parse_args()

    selected = _selected_slugs(args.slug)
    if selected:
        unknown = selected - {t["slug"] for t in TARGETS}
        if unknown:
            sys.exit("Slug(s) não encontrados na tabela de alvos: " + ", ".join(sorted(unknown)))
    targets = [t for t in TARGETS if not selected or t["slug"] in selected]

    if args.list:
        print("{:<30} {:<11} {:<58} {}".format("SLUG", "COUNTY", "NEW IMAGE (Wikimedia Commons)", "LICENCE"))
        for t in targets:
            print("{:<30} {:<11} {:<58} {}".format(t["slug"], t["county"], t["commons"], t["license"]))
        print("\nTotal: {} alvo(s).".format(len(targets)))
        return

    user = os.environ.get("WP_USERNAME")
    app_password = os.environ.get("WP_APPLICATION_PASSWORD")
    if not user or not app_password:
        sys.exit(
            "Erro: defina WP_USERNAME e WP_APPLICATION_PASSWORD.\n"
            "Crie a senha em wp-admin → Seu perfil → Senhas de aplicativo (Application Passwords)."
        )

    rest = WpRest(args.base_url, user, app_password)

    try:
        me = rest.request("GET", "users/me?context=edit&_fields=id,name")
    except RuntimeError as e:
        sys.exit("Erro de autenticação em {}: {}".format(args.base_url, e))
    print("Autenticado como: {} (ID {})".format(me.get("name", "?"), me.get("id", "?")))
    print("Site: {}\n".format(args.base_url))

    action = "SERIA " if args.dry_run else ""
    updated = skipped = failed = 0
    meta_warning = False

    for target in targets:
        slug = target["slug"]
        print("=== {} ===".format(slug))
        # Space out requests to avoid tripping the WordPress.com edge rate
        # limiter (429) on long runs.
        time.sleep(2)
        try:
            post = get_listing(rest, slug)
        except RuntimeError as e:
            print("  ERRO ao ler o post: {}\n".format(e))
            failed += 1
            continue
        if not post:
            print("  ERRO: nenhum leisure com slug '{}'.\n".format(slug))
            failed += 1
            continue

        pid = post["id"]
        old_media = post.get("featured_media") or 0
        target_url = commons_page_url(target["commons"])
        stored_url = (post.get("meta") or {}).get("_leisure_image_source_url") or ""

        print("  post #{:<7} {}".format(pid, post.get("link", "")))
        print("  imagem atual: media {} — {}".format(
            old_media or "(nenhuma)", stored_url or "(sem fonte registrada)"))
        print("  nova imagem : {}  [{}]".format(target["commons"], target["license"]))
        print("  motivo      : {}".format(target["why"]))

        if stored_url == target_url and not args.force:
            print("  = JÁ ATUALIZADO (source_url coincide). Use --force para repetir.\n")
            skipped += 1
            continue

        alt_text = build_alt_text(post, target)
        print("  alt text    : {}".format(alt_text))

        if args.dry_run:
            print("  {}ATUALIZAR (dry-run — nada gravado)\n".format(action))
            updated += 1
            continue

        try:
            data, ctype = download_image(commons_download_url(target["commons"], args.width))
        except Exception as e:
            print("  ERRO ao baixar do Commons: {}\n".format(e))
            failed += 1
            continue
        if len(data) < 2048:
            print("  ERRO: download suspeito ({} bytes).\n".format(len(data)))
            failed += 1
            continue
        mime = pick_mime(ctype, target["commons"])
        filename = "{}{}".format(slug, ".png" if mime == "image/png" else ".jpg")
        print("  baixado: {} bytes ({})".format(len(data), mime))

        try:
            media = rest.upload_media(filename, data, mime, {
                "title": (post.get("title") or {}).get("raw") or slug,
                "alt_text": alt_text,
                "caption": build_attribution(target),
            })
        except RuntimeError as e:
            print("  ERRO ao enviar mídia: {}\n".format(e))
            failed += 1
            continue
        new_id = media.get("id")
        print("  mídia criada: #{:<7} {}".format(new_id, media.get("source_url", "")))

        payload = {"featured_media": new_id, "meta": build_meta(target, new_id, alt_text)}
        try:
            rest.request("POST", "leisure/{}".format(pid), payload)
        except RuntimeError as e:
            message = str(e)
            if "403" in message or "rest_cannot" in message:
                meta_warning = True
                print("  AVISO: metadados de atribuição recusados ({}).".format(message[:200]))
                print("         O plugin conexao-data-model atualizado (auth_callback em "
                      "register_leisure_meta) está ativo em produção?")
                try:
                    rest.request("POST", "leisure/{}".format(pid), {"featured_media": new_id})
                    print("  OK: imagem em destaque atualizada (atribuição NÃO atualizada).")
                except RuntimeError as e2:
                    print("  ERRO: {}\n".format(e2))
                    failed += 1
                    continue
            else:
                print("  ERRO ao gravar o post: {}\n".format(e))
                failed += 1
                continue
        else:
            print("  OK: imagem em destaque + atribuição atualizadas.")

        if args.delete_old and old_media and old_media != new_id:
            try:
                rest.request("DELETE", "media/{}?force=true".format(old_media))
                print("  antiga mídia #{} removida.".format(old_media))
            except RuntimeError as e:
                print("  aviso: não foi possível remover a mídia #{}: {}".format(old_media, e))

        updated += 1
        print("")

    print("=== Resumo ===")
    print("Alvos:       {}".format(len(targets)))
    print("Atualizados: {}".format(updated))
    print("Já OK:       {}".format(skipped))
    print("Falhas:      {}".format(failed))
    if args.dry_run:
        print("\n(--dry-run: nenhuma alteração foi gravada.)")
    elif updated:
        _target = args.base_url or os.environ.get("CONEXAO_SITE_URL", "http://localhost:8080")
        print(f"\nVerifique {_target}/lazer/ — o cache de borda é purgado ao gravar o "
              "post. Se necessário, regrave a página no wp-admin.")
    if meta_warning:
        print("\nAVISO: parte dos metadados de atribuição não foi gravada — confirme que o "
              "plugin conexao-data-model atualizado está ativo em produção.")


if __name__ == "__main__":
    main()
