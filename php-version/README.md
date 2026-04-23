# RV Travel Calendar

A personal trip planning tool for RV travelers. Write your itinerary in a
plain text file using a simple syntax, then view it as a color-coded calendar
in any web browser — including on your phone.

## Goals

- Text-first authoring: write and edit trip files in your own editor
- No build step: PHP parses and renders on every request
- Multiple calendars: one file per trip, a landing page to choose between them
- Online editing: a web-based textarea editor for quick changes in the field
- Static output: the rendered calendar is pure HTML, no JavaScript required
- Print-friendly: high-contrast black borders, white background, solid colors

## Requirements

- Apache with PHP 7.4 or later
- The `trips/` directory must be writable by the web server
- No database, no framework, no dependencies

## File Structure

```
index.php        landing page — lists all trip files, upload, create new
calendar.php     parses a trip file and renders the calendar
edit.php         web-based textarea editor for trip files
common.php       shared CSS and utility functions
trips/           directory containing .txt trip files
```

## Deployment

```bash
cp *.php /var/www/html/rv/
mkdir -p /var/www/html/rv/trips
chown www-data:www-data /var/www/html/rv/trips
```

Then navigate to `http://yourserver/rv/`.

For public-facing servers with personal itinerary data, add HTTP basic
authentication via `.htaccess` before deploying real trip files.

## Location Colors

Colors are assigned sequentially to each new location encountered while
parsing the trip file. The palette is defined at the top of `calendar.php`
in the `$COLOR_PALETTE` array — seven hex colors, clearly labeled, easy
to edit.

---

## Trip File Syntax

Trip files are plain `.txt` files. Indentation is the only structural signal:
left-margin lines are structural keywords; indented lines are activity content
attached to the current date.

### title

```
title Spring 2026 Southwest Loop
```

Required. Must be the first non-blank line. The display name shown on the
landing page and calendar header.

### start

```
start 19apr26
```

Optional. Sets the calendar start date, rounded back to the preceding Sunday.
Without a `start` tag, the calendar begins on the Sunday before the first
event date.

### Date line

```
19apr26 little rock
20apr26 arriving dallas texas
```

A left-margin line beginning with a date in `DDmmmYY` or `DDmmmYYYY` format
sets the current date. Text following the date is the location name. The
word `arriving` before the location marks the day as a travel day, which
renders as a diagonal color split between the previous and new location.

If no location is given, the current location carries forward. Gaps between
explicit dates are automatically filled as idle days at the current location.

### Day increment

```
+
```

A bare `+` at the left margin advances the current date by one day.
Useful for specifying multiple consecutive days without typing each date.
Indented lines following belong to the incremented day.

### end

```
end 10jun26
```

Sets the last day of the calendar. Days after this date render as plain
white cells. Without an `end` tag, the calendar renders four weeks past
the last event date, completing that month.

### Activity lines

```
19apr26 little rock
  pack and prep
  [Campsite](https://example.com/campsite)
```

Any line with leading whitespace is an activity line attached to the
current date. Each source line renders as its own line in the calendar
cell. If content exceeds the cell height, a scrollbar appears.

Inline links use standard Markdown syntax and can appear anywhere in
an activity line:

```
  visit [Perot Museum](https://www.perotmuseum.org) in the morning
```

### Reserved words

| Word | Where | Meaning |
|------|-------|---------|
| `title` | first line | calendar display name |
| `start` | left margin | calendar start date |
| `end` | left margin | last rendered date |
| `arriving` | date line | marks day as travel day |
| `+` | left margin | advance current date by one day |

---

## Complete Example

```
title Spring 2026 Southwest Loop
start 19apr26
end 10jun26

19apr26 little rock
  pack and prep
20apr26 arriving dallas texas
  [Perot Museum](https://www.perotmuseum.org)
22apr26 arriving amarillo
  Cadillac Ranch visit
24apr26 arriving loveland colorado
  3d printer conference
+ hiking devils backbone
  [Devils Backbone Brewery](https://www.devilsbackbone.com)
+ check out art district
  sculpture garden
  dinner on the showboat
28apr26 arriving delta colorado
02may26 arriving moab utah
06may26 arriving salt lake utah
```

This produces a continuous calendar grid starting Sunday April 19, with
color-coded location blocks, diagonal splits on travel days, and activity
text in each cell. Days after June 10 render as plain white cells.
