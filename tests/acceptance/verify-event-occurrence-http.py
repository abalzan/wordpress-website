#!/usr/bin/env python3
"""HTTP acceptance — event archive rendering against OCCURRENCE dates.

Stage 20. Proves, over real HTTP against the LOCAL WordPress, that an imported
ICS series is presented by its real occurrence dates rather than as one
continuously-running multi-day event.

Asserted here is what the archive actually renders for a recurring card:

  * the date badge shows the NEXT OCCURRENCE date, not the series start and
    not "today";
  * the card carries the weekly recurrence label and the series end date;
  * a card is never labelled "Hoje" unless the date really is an occurrence;
  * genuine multi-day and all-day events keep their plain date presentation;
  * the event detail page stays reachable and self-canonical;
  * county filtering, the 404 page and redirect chains are unaffected.

The per-date matching is proven exhaustively in the in-process suite
(`test-ics-occurrence-dates.php`) and against the real database
(`stage20-laois-import-run.php verify`), because the public archive is a
"today + 7-day window" list: it accepts no caller-supplied date, and adding
such a parameter purely for testing would be a production surface for no user
benefit. This suite asserts the rendered occurrence dates of the real cards.

Read-only: GET requests only, against the LOCAL WordPress.
"""

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lib import http_client  # noqa: E402
from lib.matrix import Results  # noqa: E402

# Chair Yoga At Portlaoise Library — weekly Tuesdays, 29 Sep..20 Oct 2026.
CHAIR_TITLE = "Chair Yoga At Portlaoise Library"
CHAIR_SLUG = "chair-yoga-at-portlaoise-library"
CHAIR_SOURCE = "/event/chair-yoga-at-portlaoise-library/"

MULTIDAY_TITLE = "Avenue Q Hit Musical"
MULTIDAY_SLUG = "avenue-q-hit-musical"
ALLDAY_TITLE = "Imposter Art Exhibition"
ALLDAY_SLUG = "imposter-art-exhibition"

MAX_PAGES = 4


def card_text(card):
    """Flatten a card's markup into readable text."""
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", card)).strip()


def badge_date(card):
    """The numeric date badge of a card (e.g. '6-20'), or '' when absent."""
    match = re.search(r'class="event-card-date-day"[^>]*>\s*(\d{1,2}-\d{1,2})', card)
    return match.group(1) if match else ""


def badge_month(card):
    """The month token of a card's date badge (e.g. 'OUT'), or '' when absent."""
    match = re.search(r'class="event-card-date-month"[^>]*>\s*([A-Za-z]{1,4})', card)
    return match.group(1) if match else ""


def collect(base):
    """Walk the Laois-filtered archive and return {title: card_html}."""
    cards = {}
    for page in range(1, MAX_PAGES + 1):
        status, _, body = http_client.fetch_relative(
            base, "/eventos/?county=laois&paged=%d" % page, follow=True)
        if status != 200:
            break
        found = 0
        for match in re.finditer(
                r'<article[^>]*class="[^"]*event-card[^"]*"[^>]*>(.*?)</article>', body, re.S):
            found += 1
            title_match = re.search(r'alt="([^"]+)"', match.group(1))
            if title_match:
                cards.setdefault(title_match.group(1), match.group(0))
        if found == 0:
            break
    return cards
def main():
    results = Results("acceptance/event-occurrence-http")
    base = http_client.assert_local_base(http_client.base_url())
    print("== HTTP acceptance: event occurrence-date presentation ==")
    print("   base: %s" % base)

    # --- The archive itself stays healthy -----------------------------------
    status, _, body = http_client.fetch_relative(base, "/eventos/")
    results.check(status == 200, "/eventos/ is 200", "status=%s" % status)
    results.check('<html lang="pt-BR">' in body, "/eventos/ is served as pt-BR")
    results.check('<html lang="en-US">' not in body, "/eventos/ is not the EN document")

    # --- Discover the real cards -------------------------------------------
    cards = collect(base)
    print("   discovered %d Laois event card(s) across %d pages" % (len(cards), MAX_PAGES))

    for title in (CHAIR_TITLE, MULTIDAY_TITLE, ALLDAY_TITLE):
        results.check(title in cards, "%s is listed in the Laois archive" % title)

    chair = cards.get(CHAIR_TITLE)
    if chair:
        text = card_text(chair)
        badge = badge_date(chair)

        # The badge must show the NEXT OCCURRENCE (6 Oct), not the series
        # start (29 Sep) and not today's date.
        results.check(badge == "6-20",
                      "Chair Yoga badge shows its next occurrence (6-20)", "got %r" % badge)
        results.check(badge_month(chair).upper() == "OUT",
                      "Chair Yoga badge month is October",
                      "got %r" % badge_month(chair))
        results.check("Toda ter" in text,
                      "Chair Yoga card carries the weekly recurrence label")
        results.check("20 OUT" in text,
                      "Chair Yoga card shows the series end date (20 OUT)")
        results.check("Hoje" not in text,
                      "Chair Yoga card is not labelled 'Hoje' on a non-occurrence day")
        results.check("29-09" not in text and "29-9" not in text,
                      "Chair Yoga card does not show the series start as its date")
        results.check(CHAIR_SOURCE in chair,
                      "Chair Yoga card links to its original source page")

    multi = cards.get(MULTIDAY_TITLE)
    if multi:
        text = card_text(multi)
        results.check("Toda " not in text,
                      "multi-day event is not labelled as a weekly series")
        results.check("at" + "é " not in text,
                      "multi-day event carries no series-end label")

    allday = cards.get(ALLDAY_TITLE)
    if allday:
        text = card_text(allday)
        results.check("Toda " not in text,
                      "all-day event is not labelled as a weekly series")

    # --- Detail pages -------------------------------------------------------
    for slug, label in ((CHAIR_SLUG, "Chair Yoga"), (MULTIDAY_SLUG, "multi-day event"),
                        (ALLDAY_SLUG, "all-day event")):
        status, headers, body = http_client.fetch_relative(
            base, "/eventos/%s/" % slug, follow=True)
        results.check(status == 200, "%s detail page is 200" % label, "status=%s" % status)
        if status == 200:
            results.check('rel="canonical" href="' in body and ("/eventos/%s/" % slug) in body,
                          "%s detail page is self-canonical" % label)
            results.check(slug.replace("-", " ") in body.lower(),
                          "%s detail page renders its own content" % label)
            results.check("410" not in body[:6000],
                          "%s detail page is not a 410 body" % label)

    # --- Unrelated filtering must be unaffected ----------------------------
    status, _, body = http_client.fetch_relative(base, "/eventos/?county=laois")
    results.check(status == 200, "/eventos/?county=laois is 200", "status=%s" % status)

    status, _, body = http_client.fetch_relative(
        base, "/eventos/?county=__conexao_no_such_county__", follow=True)
    results.check(status == 200, "unknown county filter still returns 200",
                  "status=%s" % status)
    results.check("event-card" not in body,
                  "unknown county filter yields no event cards (no filter regression)")

    status, _, body = http_client.fetch_relative(base, "/eventos/?county=dublin")
    results.check(status == 200, "/eventos/?county=dublin is 200", "status=%s" % status)

    status, _, body = http_client.fetch_relative(base, "/esta-pagina-nao-existe-stage20/")
    results.check(status == 404, "unknown URL is still 404", "status=%s" % status)

    # --- No redirect loop ---------------------------------------------------
    for path in ("/eventos/", "/eventos/%s/" % CHAIR_SLUG, "/en/eventos/"):
        hops, current, status = 0, path, None
        while hops < 6:
            status, headers, _ = http_client.fetch_relative(base, current, follow=False)
            if status and 300 <= status < 400:
                location = headers.get("Location", "") if headers else ""
                if not location:
                    break
                current = location.replace(base, "") or "/"
                hops += 1
                continue
            break
        results.check(hops < 6, "no redirect loop for %s" % path,
                      "hops=%s status=%s" % (hops, status))

    return results.finish()


if __name__ == "__main__":
    sys.exit(1 if main() else 0)