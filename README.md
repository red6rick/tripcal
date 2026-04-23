# tripcal

Turns a plain-text trip description into an HTML calendar and a KML map in one pass.

The calendar shows each day color-coded by location, travel days as diagonal gradients between the previous and new location's color, activity notes, and driving distance for each leg. The KML contains the route polylines and waypoints with notes, suitable for import into Google My Maps or Google Earth.

## Requirements

- Python 3.12+
- `requests` (only external dependency)
- Google Maps API key with Geocoding and Directions APIs enabled

## Install

```
git clone <repo>
cd tripcal
pip install requests
```

Put your API key either in the environment:

```
export GOOGLE_MAPS_API_KEY=AIza...
```

or in a file next to `tripcal.py`:

```
echo 'AIza...' > maps_key.txt
```

## Usage

```
python3 tripcal.py <trip-file>
```

Writes `<trip-file-basename>.html` and `<trip-file-basename>.kml` next to the input.

Flags:

- `--no-network` — skip all Geocoding and Directions calls; use only what's already in the on-disk cache. Useful for iterating on the trip file or on the renderer without re-billing the API. Geocoded-but-uncached stays will be missing from the KML; the calendar still renders.

Results from every API call are cached in `.tripcal-cache/` next to the script. Reruns of the same trip cost nothing at the API.

## Trip file grammar

Line-oriented text. Column 1 is strict: only specific tokens start a structural line. Any line with leading whitespace is a continuation of the preceding structural scope. Keywords and month names are case-insensitive.

### Column-1 tokens

```
in  <location> on <DDmmmYYYY>
```

Origin. Must be the first structural line. Date requires a full year.

```
to  <location> [on <date>] [for <N> day(s)]
```

Travel/arrival. `on` and `for` can appear in either order. Default duration is 1 night. Short date forms (`DDmmm`, `DDmmmYY`) infer the year from context; the year increments automatically on month rollback.

"N days" means N nights. `to X on 03may for 10 days` arrives 03may, stays 10 nights, departs the morning of 13may.

```
N:  [activity text]
```

Day marker. `N` is 1 to N-1 where N is the nights of the current stay (day 0 is the arrival day). Attaches activity text to day N of the current stay. A 1-night stay has no valid day markers.

```
*  note text
```

Single-line annotation: shown on the arrival-day calendar cell AND added to the stay's KML waypoint description. Each `*` is one line; use another `*` for another line.

```
N: * activity text
```

Combined form: shown on day N of the calendar AND in the KML waypoint description.

```
#  comment
```

Ignored.

### Continuations

A line starting with whitespace continues the most recent structural scope — the current `to` stay or the current `N:` day. Leading whitespace is stripped; the line appears as its own text line in the calendar cell.

### Inline markup

`[label](url)` anywhere in an activity or note. Renders as an HTML link in the calendar; renders as plain `label (url)` text in the KML description.

### Date conflicts

When a `to` line has both an explicit date and a computable one (previous arrival + previous nights), the explicit date wins. A warning is emitted:

- "overlap" when the explicit date is earlier than computed (previous stay truncated)
- "gap" when the explicit date is later than computed (idle carry-forward days inserted on the calendar with the previous location's color)

Warnings print to stderr.

## Example

```
in Little Rock, AR on 10apr2026

to Grapevine, TX on 15apr for 4 days
* [The Vineyards](https://www.vineyardscampground.com/)
2: dinner with jason and mary jo
    reservations at 7pm
3: * [Meow Wolf](https://meowwolf.com/visit/grapevine)

to San Angelo, TX for 2 days
* [San Angelo KOA Holiday](https://koa.com/campgrounds/san-angelo/)

to Odessa, TX
* [Midland Odessa RV Park](https://midlandodessarvpark.org/)
```

This produces a calendar where 10apr through 14apr show Little Rock (one origin day + four idle carry-forward days with a "gap" warning), 15apr is an arrival day with Little Rock→Grapevine gradient and the Vineyards link, 16apr is Grapevine, 17apr shows "dinner with jason and mary jo" over "reservations at 7pm", 18apr shows the Meow Wolf link (also in the Grapevine KML waypoint), and so on.

## Output

### HTML

Sunday-first 7-column grid, one row per week. Each cell is fixed-height (110px) with internal vertical scroll on overflow. Travel-day cells have a 135° diagonal gradient from the previous location's color to the new location's color, with the new location name and driving distance on the second line. Non-travel cells are solid.

A legend above the grid shows each location's color. The palette has 10 pastel colors and cycles on the 11th distinct location.

Print layout is landscape with page breaks between weeks and color preservation on.

### KML

One `<Placemark>` per leg, drawn as a line along the actual driving route with mileage in the name. One `<Placemark>` per waypoint, placed at the geocoded coordinates with `*` notes joined in the description. Waypoints are deduplicated by coordinate rounded to 5 decimal places.

## Troubleshooting

- "no API key set" — export `GOOGLE_MAPS_API_KEY` or create `maps_key.txt`.
- Stay missing from KML — geocoding failed for that location string. Check the terminal output for "geocode failed: ..." and make the location more specific in the trip file.
- Wrong distance — the Directions API routes between the geocoded points, not specific addresses. For RV parks and other specific destinations, include enough in the location string to geocode to the right place (street, city, state).
- Clear the cache to force fresh API calls: `rm -rf .tripcal-cache/`.
