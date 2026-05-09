#!/usr/bin/env python3
# tripcal-kml.py -- generate a KML route file from a trip file
# Usage: python3 tripcal-kml.py spring2026.txt
#        -> writes spring2026.kml, caches geocoding in spring2026.geocache.json

import sys
import json
import time
import re
import os
import urllib.request
import urllib.parse

# ---------------------------------------------------------------------------
# CONFIGURATION
# ---------------------------------------------------------------------------

NOMINATIM_URL = "https://nominatim.openstreetmap.org/search"
USER_AGENT    = "tripcal-kml/1.0 (personal RV trip planner)"
REQUEST_DELAY = 1.1   # seconds between API calls (Nominatim: max 1/sec)

# Route segment colors (KML format: aabbggrr)
PALETTE = [
    "ffff0000",   # blue
    "ff0000ff",   # red
]

# ---------------------------------------------------------------------------
# TRIP FILE PARSER
# Collects arriving destinations in order. Origin = first arriving stop.
# ---------------------------------------------------------------------------

def parse_date(token):
    return bool(re.match(r'^\d{1,2}[a-zA-Z]{3}\d{2,4}$', token))

def extract_stops(filepath):
    """Return ordered list of arriving location strings."""
    stops = []
    with open(filepath, 'r') as f:
        for line in f:
            line = line.rstrip('\n')
            if not line.strip():
                continue
            stripped = line.strip()
            if re.match(r'^(title|start|end)\s', stripped, re.IGNORECASE):
                continue
            if line[0] in (' ', '\t'):
                continue
            tokens = stripped.split(None, 1)
            first  = tokens[0]
            rest   = tokens[1] if len(tokens) > 1 else ''

            if first == '+' or parse_date(first):
                if re.match(r'arriving\b', rest.strip(), re.IGNORECASE):
                    loc = re.sub(r'^arriving\s*', '', rest.strip(), flags=re.IGNORECASE)
                    if '|' in loc:
                        loc = loc.split('|')[0]
                    loc = loc.strip()
                    if loc:
                        stops.append(loc)
    return stops

# ---------------------------------------------------------------------------
# GEOCODING
# ---------------------------------------------------------------------------

def load_cache(cache_path):
    if os.path.exists(cache_path):
        with open(cache_path, 'r') as f:
            return json.load(f)
    return {}

def save_cache(cache_path, cache):
    with open(cache_path, 'w') as f:
        json.dump(cache, f, indent=2)

def geocode(location, cache, cache_path):
    key = location.lower().strip()
    if key in cache:
        return cache[key]
    params = urllib.parse.urlencode({'q': location, 'format': 'json', 'limit': 1})
    url = NOMINATIM_URL + '?' + params
    req = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
    try:
        print(f"  geocoding: {location} ...", end=' ', flush=True)
        time.sleep(REQUEST_DELAY)
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode())
        if data:
            lat = float(data[0]['lat'])
            lon = float(data[0]['lon'])
            print(f"{lat:.4f}, {lon:.4f}")
            cache[key] = [lat, lon]
            save_cache(cache_path, cache)
            return [lat, lon]
        else:
            print("NOT FOUND")
            return None
    except Exception as e:
        print(f"ERROR: {e}")
        return None

# ---------------------------------------------------------------------------
# KML GENERATION
# One Placemark per leg, each with its own color style.
# ---------------------------------------------------------------------------

def write_kml(kml_path, coords, stops, title):
    no = '<' + 'name' + '>'
    nc = '</' + 'name' + '>'
    so = '<' + 'Style id="' 
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<kml xmlns="http://www.opengis.net/kml/2.2">',
        '  <Document>',
        f'    {no}{title}{nc}',
    ]

    n_segs = len(coords) - 1

    # emit one Style per segment color
    for i in range(n_segs):
        color = PALETTE[i % len(PALETTE)]
        sid   = f"seg{i}"
        lines += [
            f'    {so}{sid}">',
            '      <LineStyle>',
            f'        <color>{color}</color>',
            '        <width>4</width>',
            '      </LineStyle>',
            '    </Style>',
        ]

    # emit one Placemark per segment
    for i in range(n_segs):
        sid       = f"seg{i}"
        seg_name  = f"{stops[i]} to {stops[i+1]}"
        c1        = coords[i]
        c2        = coords[i+1]
        coord_str = f"          {c1[1]},{c1[0]},0\n          {c2[1]},{c2[0]},0"
        lines += [
            '    <Placemark>',
            f'      {no}{seg_name}{nc}',
            f'      <styleUrl>#{sid}</styleUrl>',
            '      <LineString>',
            '        <tessellate>1</tessellate>',
            '        <coordinates>',
            coord_str,
            '        </coordinates>',
            '      </LineString>',
            '    </Placemark>',
        ]

    lines += ['  </Document>', '</kml>']
    with open(kml_path, 'w') as f:
        f.write('\n'.join(lines) + '\n')

# ---------------------------------------------------------------------------
# MAIN
# ---------------------------------------------------------------------------

def main():
    if len(sys.argv) != 2:
        print("Usage: tripcal-kml.py <tripfile.txt>", file=sys.stderr)
        sys.exit(1)

    trip_path  = sys.argv[1]
    base       = os.path.splitext(trip_path)[0]
    kml_path   = base + '.kml'
    cache_path = base + '.geocache.json'
    title      = os.path.basename(base)

    print(f"Parsing:  {trip_path}")
    stops = extract_stops(trip_path)

    if len(stops) < 2:
        print("Error: need at least 2 arriving stops to draw a route.", file=sys.stderr)
        sys.exit(1)

    print(f"Stops:    {len(stops)}")
    for s in stops:
        print(f"  {s}")

    print(f"\nGeocoding (cache: {cache_path})")
    cache = load_cache(cache_path)

    coords = []
    failed = []
    for stop in stops:
        result = geocode(stop, cache, cache_path)
        if result:
            coords.append(result)
        else:
            failed.append(stop)

    if failed:
        print(f"\nWarning: could not geocode: {', '.join(failed)}", file=sys.stderr)

    if len(coords) < 2:
        print("Error: fewer than 2 stops geocoded.", file=sys.stderr)
        sys.exit(1)

    # align stops list to coords (drop any that failed geocoding)
    geocoded_stops = [s for s in stops if s.lower().strip() in cache]

    print(f"\nWriting:  {kml_path} ({len(coords)-1} segments)")
    write_kml(kml_path, coords, geocoded_stops, title)
    print("Done.")

if __name__ == '__main__':
    main()
