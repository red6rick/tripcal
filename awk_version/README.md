# tripcal — awk version

**All of the code here was written by Claude, Anthropic's AI assistant.** I did
not write it. I specified it, argued with it, and approved it. See Credits at
the bottom.

This is an earlier pass at the problem, kept for historical continuity. The
current implementation is the Python one in the repository root; see the
top-level `README.md`.

## Contents

```
tripcal.awk       trip file -> self-contained HTML calendar
tripcal-kml.py    trip file -> KML route, with a per-trip geocode cache
```

## Usage

```
awk -f tripcal.awk spring2026.txt > spring2026.html
python3 tripcal-kml.py spring2026.txt
```

`tripcal-kml.py` writes `spring2026.kml` alongside the input and caches
geocoding results in `spring2026.geocache.json`. It uses the Python standard
library only and needs a Google Maps API key.

The trip file syntax is the older left-margin/date-line grammar documented in
`../php-version/README.md`, not the `in`/`to` grammar the Python version
parses. The two are not interchangeable.

## Credits

**The awk script and the Python KML generator in this directory were written by
Claude, Anthropic's AI assistant**, over the course of an iterative design
conversation. The project goals, the trip file syntax, the layout judgements,
and all real-world testing are the author's.

## License

MIT — do what you like, no warranty of any kind. Copyright (c) 2026 Rick
VanNorman; full text in `../LICENSE`.
