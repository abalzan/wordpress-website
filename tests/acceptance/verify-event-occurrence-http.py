#!/usr/bin/env python3
"""HTTP acceptance — the /eventos/ archive presents events by OCCURRENCE date.

Stage 20 added the ICS occurrence-date engine and this suite. It passed only
against the database of whoever had imported the Laois feed by hand: the
deterministic CI site never imported an ICS document at all, so on a fresh CI
site the three ICS events were absent from the archive and 404 on their detail
pages. `scripts/bootstrap-ci-fixtures.php` (step 4c) now imports a real iCalendar
document through the real importer engine under the real `laois_tourism` source
id, so the events exist and this suite measures the shipped code path.

WHAT IS ASSERTED, AND WHY IT IS SHAPED THIS WAY
-----------------------------------------------
The suite never hard-codes an event title or a calendar date. It DISCOVERS the
three ICS events structurally from the rendered Laois archive and derives every
expectation from what the site itself renders:

  * the WEEKLY SERIES is the single card carrying a recurrence chip;
  * the MULTI-DAY event is a card advertising a date RANGE and a clock time;
  * the ALL-DAY event is a card advertising a date range and NO clock time.

The site-local "today" is read from the HTTP `Date` response header converted to
the site timezone, which `bootstrap-ci-fixtures.php` step 1e asserts is
Europe/Dublin. Nothing depends on the runner's own clock and nothing expires:
the fixture anchors its dates relative to the day it runs.

The per-date matching itself (which dates match, which neighbouring dates must
NOT) cannot be observed over HTTP, because the public archive is a
"today .. today+7" list that deliberately accepts no caller-supplied date — a
test-only date parameter would be a production surface with no user benefit.
That half of the contract is proven numerically where a date CAN be asked about:
`bootstrap-ci-fixtures.php --verify` evaluates every calendar day of the series
with the real `Conexao_Event_Recurrence` evaluator and fails closed on a single
false match, and the plugin suite `test-ics-occurrence-dates.php` proves the
same contract exhaustively against a real captured Laois Tourism feed. This
suite proves the rendered archive agrees with that contract.

Read-only: GET requests only, against the LOCAL WordPress.
"""

import os
import re
import sys
from datetime import datetime, timedelta
from email.utils import parsedate_to_datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lib import http_client  # noqa: E402
from lib.matrix import Results  # noqa: E402

# Asserted by scripts/bootstrap-ci-fixtures.php step 1e. The site evaluates
# event dates in this timezone, so the acceptance suite must too.
try:
    from zoneinfo import ZoneInfo
except ImportError:  # pragma: no cover - Python < 3.9 is not supported
    ZoneInfo = None

SITE_TIMEZONE = "Europe/Dublin"

# The card's PT month abbreviations, exactly as template-parts/event-card.php
# renders them. Presentation only: this suite asserts the token the site
# printed, it does not own the map.
PT_MONTHS = {
    1: "JAN", 2: "FEV", 3: "MAR", 4: "ABR", 5: "MAI", 6: "JUN",
    7: "JUL", 8: "AGO", 9: "SET", 10: "OUT", 11: "NOV", 12: "DEZ",
}

# The Portuguese series-end chip, e.g. "até 18 OUT".
SERIES_END_RE = re.compile(r"at[eé]\s+(\d{1,2})\s+([A-Z]{3})", re.IGNORECASE)

MAX_PAGES = 4

# The number of weekly occurrences the CI ICS fixture declares. It is proven
# numerically against the real evaluator by `bootstrap-ci-fixtures.php --verify`
# (every calendar day of the series) and by the plugin suite
# `test-ics-occurrence-dates.php` against a real captured feed. Over HTTP the
# archive renders one card for the current window, so what is derivable here is
# the number of occurrences still AHEAD of the badge: the series is already
# underway, so the badge is its second occurrence and two more follow it.
EXPECTED_SERIES_OCCURRENCES = 4
EXPECTED_OCCURRENCES_AFTER_BADGE = 2

# The Portuguese full weekday names the recurrence label is built from, as
# inc/events.php renders them. Presentation only, like PT_MONTHS above.
PT_WEEKDAYS = {
    1: "segunda-feira", 2: "terça-feira", 3: "quarta-feira",
    4: "quinta-feira", 5: "sexta-feira", 6: "sábado", 7: "domingo",
}

WEEK = timedelta(days=7)


def site_today(headers):
    """The site's local calendar date, read from the HTTP Date header."""
    raw = (headers or {}).get("Date")
    if not raw:
        raise RuntimeError("the response carried no Date header; the site-local date is unknown")
    moment = parsedate_to_datetime(raw)
    if ZoneInfo is not None:
        moment = moment.astimezone(ZoneInfo(SITE_TIMEZONE))
    return moment.date()


def slugify(title):
    """WordPress `sanitize_title()` for the ASCII titles the fixture uses."""
    return re.sub(r"[^a-z0-9]+", "-", title.strip().lower()).strip("-")


def chip(card, css_class):
    """The text of a card chip such as `event-card-recurrence`, or ''."""
    match = re.search(r'class="[^"]*%s[^"]*"[^>]*>\s*([^<]*)' % re.escape(css_class), card)
    return match.group(1).strip() if match else ""


def card_title(card):
    match = re.search(r'class="event-card-title"[^>]*>\s*<a[^>]*>(.*?)</a>', card, re.S)
    return re.sub(r"\s+", " ", match.group(1)).strip() if match else ""


def card_iso(card):
    """The machine-readable ISO date (or date range) the card advertises."""
    match = re.search(r'class="event-card-date-iso[^"]*"[^>]*datetime="([^"]*)"', card)
    return match.group(1).strip() if match else ""


def parse_iso(value):
    """(start, end) dates for an ISO date or an ISO date range."""
    parts = [p for p in value.split("/") if p]
    if not parts:
        return None, None
    try:
        return (datetime.strptime(parts[0], "%Y-%m-%d").date(),
                datetime.strptime(parts[-1], "%Y-%m-%d").date())
    except ValueError:
        return None, None


def collect(base):
    """Walk the Laois-filtered archive and return the list of card markup."""
    cards = []
    for page in range(1, MAX_PAGES + 1):
        status, _, body = http_client.fetch_relative(
            base, "/eventos/?county=laois&paged=%d" % page, follow=True)
        if status != 200:
            break
        found = re.findall(
            r'<article[^>]*class="[^"]*event-card[^"]*"[^>]*>(.*?)</article>', body, re.S)
        if not found:
            break
        cards.extend(found)
    return cards


def discover(cards):
    """Classify the ICS fixture cards by the shape they render."""
    series, multiday, allday = [], [], []
    for card in cards:
        if chip(card, "event-card-recurrence"):
            series.append(card)
            continue
        start, end = parse_iso(card_iso(card))
        if start is None or end is None or end <= start:
            continue
        (multiday if chip(card, "event-card-time") else allday).append(card)
    return series, multiday, allday


def assert_detail_page(results, base, card, label):
    """The canonical detail URL of a discovered card must resolve to 200."""
    title = card_title(card)
    slug = slugify(title)
    results.check(bool(slug), "%s card exposes a title to derive its permalink from" % label,
                  "title=%r" % title)
    if not slug:
        return
    path = "/eventos/%s/" % slug
    status, headers, body = http_client.fetch_relative(base, path, follow=True)
    results.check(status == 200, "%s detail page is 200" % label, "status=%s url=%s" % (status, path))
    if status != 200:
        return
    results.check('rel="canonical" href="' in body and path in body,
                  "%s detail page is self-canonical" % label)
    results.check(title.lower() in re.sub(r"\s+", " ", body.lower()),
                  "%s detail page renders its own content" % label)
    results.check("410" not in body[:6000], "%s detail page is not a 410 body" % label)
    hops = 0
    current = path
    while hops < 6:
        code, resp_headers, _ = http_client.fetch_relative(base, current, follow=False)
        if code and 300 <= code < 400:
            location = (resp_headers or {}).get("Location", "")
            if not location:
                break
            current = location.replace(base, "") or "/"
            hops += 1
            continue
        break
    results.check(hops < 6, "%s detail page has no redirect loop" % label, "hops=%d" % hops)



def main():
    results = Results("acceptance/event-occurrence-http")
    base = http_client.assert_local_base(http_client.base_url())
    print("== HTTP acceptance: event occurrence-date presentation ==")
    print("   base: %s" % base)

    # --- The archive itself stays healthy -----------------------------------
    status, headers, body = http_client.fetch_relative(base, "/eventos/")
    results.check(status == 200, "/eventos/ is 200", "status=%s" % status)
    results.check('<html lang="pt-BR">' in body, "/eventos/ is served as pt-BR")
    results.check('<html lang="en-US">' not in body, "/eventos/ is not the EN document")

    try:
        today = site_today(headers)
    except Exception as exc:  # noqa: BLE001 - reported as a failure, not raised
        results.check(False, "the site-local date is readable from the response", str(exc))
        return results.finish()
    print("   site-local today: %s" % today)

    # --- Discover the ICS fixture events from what the site rendered ---------
    cards = collect(base)
    series, multiday, allday = discover(cards)
    print("   %d Laois card(s): series=%d multiday=%d allday=%d"
          % (len(cards), len(series), len(multiday), len(allday)))

    results.check(len(series) == 1, "exactly one weekly series is listed in the Laois archive",
                  "found %d" % len(series))
    results.check(len(multiday) == 1, "exactly one multi-day event is listed in the Laois archive",
                  "found %d" % len(multiday))
    results.check(len(allday) == 1, "exactly one all-day event is listed in the Laois archive",
                  "found %d" % len(allday))

    # --- The weekly series ---------------------------------------------------
    if series:
        card = series[0]
        iso = card_iso(card)
        start, end = parse_iso(iso)
        results.check(start is not None, "the series card advertises a machine-readable date",
                      "iso=%r" % iso)
        if start is not None:
            # THE REGRESSION ASSERTION. Before the fix the collapsed
            # DTSTART..DTEND pair was rendered as a continuous date range,
            # which advertises the event on every day in between.
            results.check(end == start,
                          "the series card advertises ONE occurrence date, not a date range",
                          "iso=%r" % iso)
            results.check(start > today,
                          "the series badge is a FUTURE occurrence, not the past series start",
                          "badge=%s today=%s" % (start, today))
            results.check(start - WEEK < today,
                          "the series badge is the NEXT occurrence, not a later one",
                          "badge=%s today=%s" % (start, today))

            end_chip = chip(card, "event-card-recurrence-end")
            parsed = SERIES_END_RE.search(end_chip)
            results.check(bool(parsed), "the series card carries its series-end chip",
                          "chip=%r" % end_chip)
            if parsed:
                last = start + WEEK * EXPECTED_OCCURRENCES_AFTER_BADGE
                results.check(
                    int(parsed.group(1)) == last.day
                    and parsed.group(2).upper() == PT_MONTHS[last.month],
                    "the series-end chip is the LAST occurrence of the series",
                    "chip=%r expected=%d %s"
                    % (end_chip, last.day, PT_MONTHS[last.month]))
                gap = (last - start).days
                results.check(gap == 7 * EXPECTED_OCCURRENCES_AFTER_BADGE,
                              "exactly %d further weekly occurrences follow the badge"
                              % EXPECTED_OCCURRENCES_AFTER_BADGE, "gap=%d days" % gap)
                results.check(gap % 7 == 0,
                              "the series occurrences are exactly 7 days apart", "gap=%d" % gap)

            label = chip(card, "event-card-recurrence")
            results.check(bool(label), "the series card carries the weekly recurrence label")
            results.check(
                PT_WEEKDAYS[start.isoweekday()] in label.lower(),
                "the recurrence label names the weekday of the advertised occurrence",
                "label=%r occurrence=%s" % (label, start))
            # Today and tomorrow can never be occurrences of this series: its
            # weekday sits five days behind today's. A "Hoje"/"Amanhã" chip here
            # would mean the card was matching a continuous range rather than a
            # real occurrence date.
            results.check("Hoje" not in card and "Amanhã" not in card,
                          "the series card is not labelled today/tomorrow on a non-occurrence day")
        assert_detail_page(results, base, card, "series")

    # --- The multi-day event -------------------------------------------------
    if multiday:
        card = multiday[0]
        start, end = parse_iso(card_iso(card))
        results.check(start is not None and end is not None and end > start,
                      "the multi-day card advertises its full date range",
                      "iso=%r" % card_iso(card))
        if start is not None and end is not None:
            results.check((end - start).days >= 1,
                          "the multi-day event stays visible throughout its span",
                          "span=%d days" % (end - start).days)
        results.check(bool(chip(card, "event-card-time")),
                      "the multi-day event keeps its clock time")
        results.check(not chip(card, "event-card-recurrence"),
                      "the multi-day event never became a weekly series")
        assert_detail_page(results, base, card, "multi-day")

    # --- The all-day event ---------------------------------------------------
    if allday:
        card = allday[0]
        start, end = parse_iso(card_iso(card))
        results.check(start is not None and end is not None and end > start,
                      "the all-day card advertises its date range",
                      "iso=%r" % card_iso(card))
        results.check(not chip(card, "event-card-time"),
                      "the all-day event is presented as all-day (no clock time)")
        results.check(not chip(card, "event-card-recurrence"),
                      "the all-day event never became a weekly series")
        assert_detail_page(results, base, card, "all-day")

    # --- Unrelated filtering must be unaffected ----------------------------
    status, _, _ = http_client.fetch_relative(base, "/eventos/?county=laois")
    results.check(status == 200, "/eventos/?county=laois is 200", "status=%s" % status)

    status, _, body = http_client.fetch_relative(
        base, "/eventos/?county=__conexao_no_such_county__", follow=True)
    results.check(status == 200, "unknown county filter still returns 200", "status=%s" % status)
    results.check("event-card" not in body,
                  "unknown county filter yields no event cards (no filter regression)")

    status, _, _ = http_client.fetch_relative(base, "/eventos/?county=dublin")
    results.check(status == 200, "/eventos/?county=dublin is 200", "status=%s" % status)

    status, _, _ = http_client.fetch_relative(base, "/esta-pagina-nao-existe-occurrence/")
    results.check(status == 404, "unknown URL is still 404", "status=%s" % status)

    # --- No redirect loop ---------------------------------------------------
    for path in ("/eventos/", "/en/eventos/"):
        hops, current, status = 0, path, None
        while hops < 6:
            status, resp_headers, _ = http_client.fetch_relative(base, current, follow=False)
            if status and 300 <= status < 400:
                location = (resp_headers or {}).get("Location", "")
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
