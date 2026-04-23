#!/usr/bin/env python3
"""tripcal - trip file -> HTML calendar + KML map.

Usage: tripcal.py <trip-file>

Reads a .trip/.txt file in the tripcal grammar, writes two outputs
alongside it: <basename>.html and <basename>.kml.

Requires: requests, Google Maps API key (GOOGLE_MAPS_API_KEY env var
or maps_key.txt adjacent to this script).
"""

import argparse
import hashlib
import html
import json
import os
import re
import sys
import time
from dataclasses import dataclass, field
from datetime import date, timedelta
from pathlib import Path

import requests


# ---------------------------------------------------------------------------
# API key
# ---------------------------------------------------------------------------

def load_api_key():
    env = os.environ.get("GOOGLE_MAPS_API_KEY")
    if env:
        return env.strip()
    key_file = Path(__file__).parent / "maps_key.txt"
    if key_file.exists():
        return key_file.read_text().strip()
    return None


API_KEY = load_api_key()
GEOCODE_URL = "https://maps.googleapis.com/maps/api/geocode/json"
DIRECTIONS_URL = "https://maps.googleapis.com/maps/api/directions/json"
METERS_PER_MILE = 1609.344


# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------

@dataclass
class Stay:
    location: str               # as written
    arrival: date               # resolved arrival date
    nights: int                 # duration
    kml_notes: list = field(default_factory=list)    # list of str (raw markup)
    arrival_activities: list = field(default_factory=list)  # list of str
    day_activities: dict = field(default_factory=dict)  # {1: [str, ...], ...}
    # resolved after geocoding:
    lat: float = None
    lng: float = None
    distance_from_prev_mi: float = None  # miles driven from previous stay
    leg_polyline: list = field(default_factory=list)  # [(lat, lng), ...]

    @property
    def is_origin(self):
        return self.nights == 0


# ---------------------------------------------------------------------------
# Parser
# ---------------------------------------------------------------------------

MONTHS = {
    "jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
    "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12,
}

DATE_RE = re.compile(r"^(\d{1,2})([a-zA-Z]{3})(\d{2,4})?$")


def parse_date_token(tok, default_year=None):
    """Parse DDmmm or DDmmmYY or DDmmmYYYY. Returns date or None."""
    m = DATE_RE.match(tok)
    if not m:
        return None
    d = int(m.group(1))
    mon = MONTHS.get(m.group(2).lower())
    if mon is None:
        return None
    y = m.group(3)
    if y is None:
        if default_year is None:
            return None
        year = default_year
    else:
        year = int(y)
        if year < 100:
            year += 2000
    try:
        return date(year, mon, d)
    except ValueError:
        return None


def split_travel_line(body, warnings, line_no):
    """Parse the body of a 'to' or 'in' line (after the keyword).

    Extract location, optional date token, optional duration (nights).
    Keywords 'on' and 'for' may appear in either order.
    Returns (location_str, date_token_or_None, nights_or_None).
    """
    tokens = body.split()
    loc_tokens = []
    date_tok = None
    nights = None
    i = 0
    while i < len(tokens):
        tl = tokens[i].lower()
        if tl == "on" and i + 1 < len(tokens):
            date_tok = tokens[i + 1]
            i += 2
        elif tl == "for" and i + 1 < len(tokens):
            # expect integer then day/days
            try:
                n = int(tokens[i + 1])
            except ValueError:
                warnings.append(f"line {line_no}: expected integer after 'for', got {tokens[i+1]!r}")
                i += 2
                continue
            if i + 2 >= len(tokens) or tokens[i + 2].lower() not in ("day", "days"):
                warnings.append(f"line {line_no}: 'for {n}' must be followed by 'day' or 'days'")
                i += 2
                continue
            if n < 1:
                warnings.append(f"line {line_no}: duration must be >= 1 day, got {n}")
                n = 1
            nights = n
            i += 3
        else:
            loc_tokens.append(tokens[i])
            i += 1
    location = " ".join(loc_tokens).rstrip(",").strip()
    return location, date_tok, nights


def parse_file(path):
    """Parse a trip file. Returns (stays, warnings, title)."""
    text = Path(path).read_text()
    warnings = []
    stays = []
    current = None        # current Stay being annotated
    current_day = None    # current N for continuation under an N: line
    current_scope = None  # "stay_arrival" | "day" | None
    origin_date = None    # to seed year inference

    for line_no, raw in enumerate(text.splitlines(), 1):
        if not raw.strip():
            continue

        # continuation: any leading whitespace
        if raw[0] in " \t":
            text_line = raw.strip()
            if current is None:
                warnings.append(f"line {line_no}: continuation before any 'in'/'to' line")
                continue
            if current_scope == "day" and current_day is not None:
                current.day_activities.setdefault(current_day, []).append(text_line)
            else:  # stay_arrival (default)
                current.arrival_activities.append(text_line)
            continue

        # column 1 tokens
        stripped = raw.rstrip()
        first_space = stripped.find(" ")
        if first_space == -1:
            head = stripped
            body = ""
        else:
            head = stripped[:first_space]
            body = stripped[first_space + 1:].strip()

        head_lower = head.lower()

        # comment
        if stripped.startswith("#"):
            continue

        # in
        if head_lower == "in":
            if stays:
                warnings.append(f"line {line_no}: extra 'in' after trip has started, ignored")
                continue
            loc, date_tok, nights = split_travel_line(body, warnings, line_no)
            if date_tok is None:
                warnings.append(f"line {line_no}: 'in' line requires 'on <date>' with year")
                continue
            d = parse_date_token(date_tok)
            if d is None:
                warnings.append(f"line {line_no}: bad date on 'in' line: {date_tok!r} (year required)")
                continue
            origin_date = d
            current = Stay(location=loc, arrival=d, nights=0)
            stays.append(current)
            current_scope = "stay_arrival"
            current_day = None
            continue

        # to
        if head_lower == "to":
            loc, date_tok, nights = split_travel_line(body, warnings, line_no)
            if nights is None:
                nights = 1
            if not stays:
                warnings.append(f"line {line_no}: 'to' before 'in', ignored")
                continue
            prev = stays[-1]
            # computed arrival = prev arrival + prev nights (origin has nights=0)
            computed = prev.arrival + timedelta(days=prev.nights)
            if date_tok is None:
                arrival = computed
            else:
                default_year = prev.arrival.year
                d = parse_date_token(date_tok, default_year=default_year)
                if d is None:
                    warnings.append(f"line {line_no}: bad date on 'to' line: {date_tok!r}")
                    arrival = computed
                else:
                    # year rollover: if short-form token produced a date earlier
                    # than prev.arrival, bump year
                    if len(date_tok) <= 5 and d < prev.arrival:
                        try:
                            d = date(d.year + 1, d.month, d.day)
                        except ValueError:
                            pass
                    # consistency check
                    if d < computed:
                        warnings.append(
                            f"line {line_no}: explicit arrival {d} is earlier than "
                            f"computed {computed}; previous stay truncated"
                        )
                    elif d > computed:
                        warnings.append(
                            f"line {line_no}: explicit arrival {d} leaves gap after "
                            f"computed {computed} ({(d - computed).days} idle day(s))"
                        )
                    arrival = d
            current = Stay(location=loc, arrival=arrival, nights=nights)
            stays.append(current)
            current_scope = "stay_arrival"
            current_day = None
            continue

        # * arrival-day activity + KML note (single-line entity; scope unchanged)
        if head == "*":
            if current is None:
                warnings.append(f"line {line_no}: '*' before any 'in'/'to', ignored")
                continue
            current.kml_notes.append(body)
            current.arrival_activities.append(body)
            # do NOT change current_scope; * is a single-line entity
            continue

        # N: day marker
        m = re.match(r"^(\d{1,2}):(.*)$", stripped)
        if m:
            if current is None or current.is_origin:
                warnings.append(f"line {line_no}: day marker outside a 'to' stay, ignored")
                continue
            n = int(m.group(1))
            if n < 1 or n >= current.nights + 1:
                # valid range is [1, nights-1] for day markers
                if current.nights < 2:
                    warnings.append(f"line {line_no}: day marker {n}: in a {current.nights}-day stay has no valid day slots")
                else:
                    warnings.append(f"line {line_no}: day marker {n}: outside valid range [1, {current.nights - 1}]")
                current_day = None
                current_scope = "day"
                continue
            body_text = m.group(2).strip()
            current_day = n
            current_scope = "day"
            if body_text:
                # check for leading '*' = also KML
                if body_text.startswith("*"):
                    after = body_text[1:].strip()
                    current.kml_notes.append(after)
                    current.day_activities.setdefault(n, []).append(after)
                else:
                    current.day_activities.setdefault(n, []).append(body_text)
            continue

        # unrecognized column-1 line
        warnings.append(f"line {line_no}: unrecognized: {stripped!r}")

    # title from filename
    title = Path(path).stem

    return stays, warnings, title


# ---------------------------------------------------------------------------
# Google Maps calls with on-disk cache
# ---------------------------------------------------------------------------

CACHE_DIR = Path(__file__).parent / ".tripcal-cache"


def cache_get(kind, key):
    CACHE_DIR.mkdir(exist_ok=True)
    h = hashlib.sha256(f"{kind}|{key}".encode()).hexdigest()[:32]
    p = CACHE_DIR / f"{kind}-{h}.json"
    if p.exists():
        try:
            return json.loads(p.read_text())
        except Exception:
            return None
    return None


def cache_put(kind, key, value):
    CACHE_DIR.mkdir(exist_ok=True)
    h = hashlib.sha256(f"{kind}|{key}".encode()).hexdigest()[:32]
    p = CACHE_DIR / f"{kind}-{h}.json"
    p.write_text(json.dumps(value))


def geocode(location):
    cached = cache_get("geocode", location)
    if cached is not None:
        return tuple(cached) if cached else None
    if API_KEY is None:
        return None
    try:
        r = requests.get(GEOCODE_URL, params={"address": location, "key": API_KEY}, timeout=30)
        r.raise_for_status()
        d = r.json()
        if d["status"] != "OK" or not d["results"]:
            cache_put("geocode", location, [])
            return None
        loc = d["results"][0]["geometry"]["location"]
        result = [loc["lat"], loc["lng"]]
        cache_put("geocode", location, result)
        return tuple(result)
    except Exception as e:
        print(f"geocode error for {location!r}: {e}", file=sys.stderr)
        return None


def decode_polyline(s):
    coords = []
    index = lat = lng = 0
    while index < len(s):
        for which in ("lat", "lng"):
            result = shift = 0
            while True:
                b = ord(s[index]) - 63
                index += 1
                result |= (b & 0x1F) << shift
                shift += 5
                if b < 0x20:
                    break
            delta = ~(result >> 1) if (result & 1) else (result >> 1)
            if which == "lat":
                lat += delta
            else:
                lng += delta
        coords.append((lat / 1e5, lng / 1e5))
    return coords


def directions(origin_ll, dest_ll):
    key = f"{origin_ll[0]:.5f},{origin_ll[1]:.5f}->{dest_ll[0]:.5f},{dest_ll[1]:.5f}"
    cached = cache_get("directions", key)
    if cached is not None:
        return cached.get("polyline", []), cached.get("meters")
    if API_KEY is None:
        return [], None
    try:
        params = {
            "origin": f"{origin_ll[0]},{origin_ll[1]}",
            "destination": f"{dest_ll[0]},{dest_ll[1]}",
            "mode": "driving",
            "key": API_KEY,
        }
        r = requests.get(DIRECTIONS_URL, params=params, timeout=30)
        r.raise_for_status()
        d = r.json()
        if d["status"] != "OK":
            cache_put("directions", key, {"polyline": [], "meters": None})
            return [], None
        route = d["routes"][0]
        poly = decode_polyline(route["overview_polyline"]["points"])
        meters = sum(leg["distance"]["value"] for leg in route["legs"])
        cache_put("directions", key, {"polyline": poly, "meters": meters})
        return poly, meters
    except Exception as e:
        print(f"directions error {key}: {e}", file=sys.stderr)
        return [], None


def resolve_geography(stays):
    """Geocode every stay, fetch directions between consecutive stays."""
    for s in stays:
        was_cached = cache_get("geocode", s.location) is not None
        ll = geocode(s.location)
        if ll is None:
            print(f"  geocode failed: {s.location}", file=sys.stderr)
        else:
            s.lat, s.lng = ll
            tag = "cached" if was_cached else "geocoded"
            print(f"  {tag}: {s.location}", file=sys.stderr)
        time.sleep(0.03)
    for i in range(1, len(stays)):
        a, b = stays[i - 1], stays[i]
        if a.lat is None or b.lat is None:
            continue
        dkey = f"{a.lat:.5f},{a.lng:.5f}->{b.lat:.5f},{b.lng:.5f}"
        was_cached = cache_get("directions", dkey) is not None
        poly, meters = directions((a.lat, a.lng), (b.lat, b.lng))
        if meters is not None:
            b.distance_from_prev_mi = meters / METERS_PER_MILE
        b.leg_polyline = poly
        tag = "cached directions" if was_cached else "directions"
        mi = f"{b.distance_from_prev_mi:.0f} mi" if b.distance_from_prev_mi is not None else "no route"
        print(f"  {tag}: {a.location} -> {b.location} ({mi})", file=sys.stderr)
        time.sleep(0.03)


# ---------------------------------------------------------------------------
# Inline markdown: [label](url)
# ---------------------------------------------------------------------------

LINK_RE = re.compile(r"\[([^\]]+)\]\((https?://[^)]+)\)")


def render_inline(s):
    """HTML-escape plain text, convert [label](url) to <a>."""
    out = []
    i = 0
    for m in LINK_RE.finditer(s):
        if m.start() > i:
            out.append(html.escape(s[i:m.start()]))
        out.append(f'<a href="{html.escape(m.group(2))}" target="_blank">{html.escape(m.group(1))}</a>')
        i = m.end()
    if i < len(s):
        out.append(html.escape(s[i:]))
    return "".join(out)


def strip_markdown(s):
    """For KML descriptions: replace [label](url) with plain text 'label (url)'."""
    return LINK_RE.sub(lambda m: f"{m.group(1)} ({m.group(2)})", s)


# ---------------------------------------------------------------------------
# Calendar construction
# ---------------------------------------------------------------------------

# Pastel palette for location backgrounds (from tripcal.awk)
PALETTE = [
    "#ffffd0", "#ffd0ff", "#ffd0d0", "#d0ffff", "#d0ffd0",
    "#d0d0ff", "#ffd0b0", "#f0d0ff", "#d0ffe0", "#fff0d0",
]


def assign_colors(stays):
    """Return {location: hex_color} in order of first appearance."""
    colors = {}
    for s in stays:
        if s.location not in colors:
            colors[s.location] = PALETTE[len(colors) % len(PALETTE)]
    return colors



def build_day_map(stays):
    """
    For every calendar date in the trip, return info for its cell.
    Returns dict: date -> dict with keys:
        'location': str (current location)
        'travel_to': str or None (new location if travel day)
        'travel_from': str or None (previous location if travel day)
        'distance_mi': float or None
        'activities': list of str (raw markdown lines)
        'is_arrival_day': bool
    """
    day_map = {}
    if not stays:
        return day_map
    for i, s in enumerate(stays):
        if s.is_origin:
            # origin: single day, location shown like an arrival (solid bg, no gradient)
            day_map[s.arrival] = {
                "location": s.location,
                "travel_to": s.location,
                "travel_from": None,
                "distance_mi": None,
                "activities": list(s.arrival_activities),
                "is_arrival_day": True,
            }
            continue
        # travel/arrival day
        prev = stays[i - 1]
        # fill any gap days between (prev.arrival + prev.nights) and s.arrival
        # with the previous location (carry-forward)
        if prev.is_origin:
            gap_start = prev.arrival + timedelta(days=1)
        else:
            gap_start = prev.arrival + timedelta(days=prev.nights)
        gap_day = gap_start
        while gap_day < s.arrival:
            if gap_day not in day_map:
                day_map[gap_day] = {
                    "location": prev.location,
                    "travel_to": None,
                    "travel_from": None,
                    "distance_mi": None,
                    "activities": [],
                    "is_arrival_day": False,
                }
            gap_day += timedelta(days=1)
        day_map[s.arrival] = {
            "location": s.location,
            "travel_to": s.location,
            "travel_from": prev.location,
            "distance_mi": s.distance_from_prev_mi,
            "activities": list(s.arrival_activities),
            "is_arrival_day": True,
        }
        # days 1..nights-1 are the numbered days after arrival
        for day_n in range(1, s.nights):
            d = s.arrival + timedelta(days=day_n)
            if d in day_map:
                continue  # next stay's arrival has priority
            day_map[d] = {
                "location": s.location,
                "travel_to": None,
                "travel_from": None,
                "distance_mi": None,
                "activities": list(s.day_activities.get(day_n, [])),
                "is_arrival_day": False,
            }
    return day_map

# ---------------------------------------------------------------------------
# HTML emission
# ---------------------------------------------------------------------------

CSS = """
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Playfair+Display:wght@700&display=swap');
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #fff; color: #000; font-family: 'DM Mono', monospace; padding: 2rem; }
h1 { font-family: 'Playfair Display', serif; font-size: 2rem; margin-bottom: 0.5rem; }
.nav { margin-bottom: 1rem; }
.legend { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
.legend-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.7rem; }
.swatch { display: inline-block; width: 12px; height: 12px; border: 1px solid #000; }
.cal-scroll { overflow-x: auto; }
.dow-row { display: grid; grid-template-columns: repeat(7, minmax(135px, 1fr)); min-width: 945px; position: sticky; top: 0; z-index: 10; border: 2px solid #000; border-bottom: none; }
.dow-header { background: #eee; padding: 0.35rem; font-size: 0.62rem; letter-spacing: 0.12em; text-transform: uppercase; font-weight: bold; text-align: center; border-right: 2px solid #000; }
.dow-header:last-child { border-right: none; }
.cal-grid { border-left: 2px solid #000; border-right: 2px solid #000; border-bottom: 2px solid #000; min-width: 945px; }
.cal-week { display: grid; grid-template-columns: repeat(7, minmax(135px, 1fr)); border-top: 2px solid #000; }
.cal-week:first-child { border-top: 2px solid #000; }
.cal-cell { background: #fff; height: 110px; display: flex; flex-direction: column; padding: 0.4rem 0.5rem; font-size: 0.68rem; line-height: 1.5; border-right: 2px solid #000; overflow: hidden; }
.cal-cell:last-child { border-right: none; }
.cal-cell.out-of-trip { background: #fff; }
.day-num { display: block; font-size: 0.65rem; color: #000; letter-spacing: 0.03em; margin-bottom: 0.2rem; font-weight: bold; }
.travel-head { font-size: 0.65rem; font-weight: bold; margin-bottom: 0.15rem; }
.act-scroll { flex: 1; overflow-y: auto; overflow-x: hidden; min-height: 0; }
.act-scroll::-webkit-scrollbar { width: 3px; }
.act-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.3); }
.act-line { display: block; font-size: 0.63rem; color: #000; line-height: 1.45; white-space: pre-wrap; }
.act-line a { color: #0000cc; text-decoration: underline; }
@media print {
    @page { size: landscape; margin: 0.5in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    body { padding: 0; }
    .cal-scroll { overflow: visible; }
    .dow-row, .cal-grid, .cal-week { min-width: 0; }
    .cal-week { break-inside: avoid; page-break-inside: avoid; }
    .cal-cell { break-inside: avoid; page-break-inside: avoid; }
    .nav, .legend, .dow-row { break-after: avoid; }
}
""".strip()


def fmt_ddmmm(d):
    return f"{d.day:02d}{d.strftime('%b').lower()}"


def render_html(title, stays, day_map, colors):
    if not stays:
        return "<html><body>no stays</body></html>"

    # determine calendar range: Sunday on or before first date, through Saturday
    # on or after last date
    first = min(day_map.keys())
    last = max(day_map.keys())
    # Python weekday: Mon=0..Sun=6. We want Sunday as first column.
    # date.weekday() returns 0=Mon..6=Sun; shift so Sunday=0.
    def sun_offset(d):
        return (d.weekday() + 1) % 7
    range_start = first - timedelta(days=sun_offset(first))
    range_end = last + timedelta(days=(6 - sun_offset(last)))

    parts = []
    parts.append("<!DOCTYPE html>")
    parts.append('<html lang="en"><head><meta charset="UTF-8">')
    parts.append('<meta name="viewport" content="width=device-width, initial-scale=1.0">')
    parts.append(f"<title>{html.escape(title)}</title>")
    parts.append(f"<style>{CSS}</style>")
    parts.append("</head><body>")

    parts.append(f'<div class="nav"><h1>{html.escape(title)}</h1></div>')

    # legend
    parts.append('<div class="legend">')
    for loc, hex_color in colors.items():
        parts.append(
            f'<div class="legend-item"><span class="swatch" style="background:{hex_color}"></span>{html.escape(loc)}</div>'
        )
    parts.append("</div>")

    # dow header
    parts.append('<div class="cal-scroll">')
    parts.append('<div class="dow-row">')
    for dname in ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"]:
        parts.append(f'<div class="dow-header">{dname}</div>')
    parts.append("</div>")

    # grid in week chunks
    parts.append('<div class="cal-grid">')
    cur = range_start
    while cur <= range_end:
        parts.append('<div class="cal-week">')
        for _ in range(7):
            parts.append(render_cell(cur, day_map, colors))
            cur += timedelta(days=1)
        parts.append("</div>")
    parts.append("</div>")  # cal-grid
    parts.append("</div>")  # cal-scroll

    parts.append("</body></html>")
    return "\n".join(parts)


def render_cell(d, day_map, colors):
    label = fmt_ddmmm(d)
    if d not in day_map:
        return f'<div class="cal-cell out-of-trip"><span class="day-num">{label}</span></div>'
    info = day_map[d]
    if info["is_arrival_day"] and info["travel_from"] is not None:
        pc = colors.get(info["travel_from"], "#cccccc")
        nc = colors.get(info["travel_to"], "#cccccc")
        style = f"background:linear-gradient(135deg,{pc} 50%,{nc} 50%);"
    else:
        bg = colors.get(info["location"], "#dddddd")
        style = f"background:{bg};"

    out = [f'<div class="cal-cell" style="{style}">']
    out.append(f'<span class="day-num">{label}</span>')
    if info["is_arrival_day"]:
        dist = info["distance_mi"]
        dist_str = f" ({dist:.0f} mi)" if dist is not None else ""
        out.append(
            f'<span class="travel-head">{html.escape(info["travel_to"])}{dist_str}</span>'
        )
    if info["activities"]:
        out.append('<div class="act-scroll">')
        for act in info["activities"]:
            out.append(f'<span class="act-line">{render_inline(act)}</span>')
        out.append("</div>")
    out.append("</div>")
    return "".join(out)


# ---------------------------------------------------------------------------
# KML emission
# ---------------------------------------------------------------------------

KML_HEADER = """<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
<name>{name}</name>
<description>{desc}</description>
<Style id="routeStyle">
  <LineStyle><color>ff0000ff</color><width>4</width></LineStyle>
</Style>
"""

KML_FOOTER = "</Document>\n</kml>\n"


def render_kml(title, stays):
    if not stays:
        return KML_HEADER.format(name=html.escape(title), desc="") + KML_FOOTER

    total_mi = sum(s.distance_from_prev_mi or 0 for s in stays)
    desc = f"Total: {total_mi:.1f} mi" if total_mi > 0 else ""

    parts = [KML_HEADER.format(name=html.escape(title), desc=html.escape(desc))]

    # legs
    for i, s in enumerate(stays):
        if not s.leg_polyline:
            continue
        coords = "\n".join(f"{lng},{lat},0" for lat, lng in s.leg_polyline)
        miles = s.distance_from_prev_mi or 0
        parts.append(
            f"<Placemark>\n"
            f"  <name>Leg {i} ({miles:.1f} mi)</name>\n"
            f"  <styleUrl>#routeStyle</styleUrl>\n"
            f"  <LineString><tessellate>1</tessellate><coordinates>\n"
            f"{coords}\n"
            f"  </coordinates></LineString>\n"
            f"</Placemark>\n"
        )

    # waypoints (dedup by rounded coord)
    seen = set()
    for s in stays:
        if s.lat is None:
            continue
        k = (round(s.lat, 5), round(s.lng, 5))
        if k in seen:
            continue
        seen.add(k)
        notes = "\n".join(strip_markdown(n) for n in s.kml_notes)
        parts.append(
            f"<Placemark>\n"
            f"  <name>{html.escape(s.location)}</name>\n"
            f"  <description>{html.escape(notes)}</description>\n"
            f"  <Point><coordinates>{s.lng},{s.lat},0</coordinates></Point>\n"
            f"</Placemark>\n"
        )

    parts.append(KML_FOOTER)
    return "".join(parts)


# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser(description="trip file -> HTML calendar + KML")
    ap.add_argument("trip_file")
    ap.add_argument("--no-network", action="store_true", help="skip geocoding/directions (use cache only)")
    args = ap.parse_args()

    trip_path = Path(args.trip_file)
    if not trip_path.exists():
        sys.exit(f"no such file: {trip_path}")

    stays, warnings, title = parse_file(trip_path)
    if not stays:
        sys.exit("no stays parsed; aborting")

    if not args.no_network:
        if API_KEY is None:
            warnings.append("no API key set; skipping geocoding (set GOOGLE_MAPS_API_KEY or create maps_key.txt)")
        resolve_geography(stays)

    colors = assign_colors(stays)
    day_map = build_day_map(stays)

    html_out = render_html(title, stays, day_map, colors)
    kml_out = render_kml(title, stays)

    html_path = trip_path.with_suffix(".html")
    kml_path = trip_path.with_suffix(".kml")
    html_path.write_text(html_out)
    kml_path.write_text(kml_out)

    for w in warnings:
        print(f"warning: {w}", file=sys.stderr)
    print(f"wrote {html_path}")
    print(f"wrote {kml_path}")


if __name__ == "__main__":
    main()
