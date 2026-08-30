#!/usr/bin/env python3
"""kmlroutes - strip a KML down to route geometry only.

Removes every Placemark that carries no line geometry (pins/waypoints,
polygons, photo overlays), and strips stray <Point> children out of any
MultiGeometry that also holds a line. Styles that nothing references are
dropped, and Folders/Documents left empty are pruned.

CLI:
    kmlroutes.py trip.kml trip-routes.kml
    kmlroutes.py trip.kml -            # stdout
    kmlroutes.py trip.kml out.kml -f   # overwrite existing output

Module:
    from kmlroutes import strip_to_routes, Stats
    new_text, stats = strip_to_routes(open("trip.kml").read())
"""

from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path

KML_NS = "http://www.opengis.net/kml/2.2"
GX_NS = "http://www.google.com/kml/ext/2.2"
ATOM_NS = "http://www.w3.org/2005/Atom"

# geometry tags that count as a "route"
LINE_TAGS = {
    f"{{{KML_NS}}}LineString",
    f"{{{GX_NS}}}Track",
    f"{{{GX_NS}}}MultiTrack",
}
POINT_TAG = f"{{{KML_NS}}}Point"
PLACEMARK_TAG = f"{{{KML_NS}}}Placemark"
CONTAINER_TAGS = {f"{{{KML_NS}}}Folder", f"{{{KML_NS}}}Document"}
STYLE_TAGS = {f"{{{KML_NS}}}Style", f"{{{KML_NS}}}StyleMap"}
STYLEURL_TAG = f"{{{KML_NS}}}styleUrl"


@dataclass
class Stats:
    """What a strip pass changed."""
    kept: int = 0            # placemarks retained
    dropped: int = 0         # placemarks removed
    points_pruned: int = 0   # <Point> removed from surviving MultiGeometry
    styles_dropped: int = 0
    folders_pruned: int = 0

    def __str__(self) -> str:
        return (f"kept {self.kept} route placemark(s), dropped {self.dropped} "
                f"non-route placemark(s), pruned {self.points_pruned} point(s), "
                f"{self.styles_dropped} unused style(s), "
                f"{self.folders_pruned} empty container(s)")


def _has_line(elem) -> bool:
    return any(child.tag in LINE_TAGS for child in elem.iter())


def _register_namespaces() -> None:
    ET.register_namespace("", KML_NS)
    ET.register_namespace("gx", GX_NS)
    ET.register_namespace("atom", ATOM_NS)


def strip_to_routes(kml_text: str) -> tuple[str, Stats]:
    """Return (routes-only KML text, Stats). Input is not modified."""
    _register_namespaces()
    root = ET.fromstring(kml_text)
    stats = Stats()

    # 1. drop placemarks with no line geometry
    for parent in list(root.iter()):
        for pm in [c for c in parent if c.tag == PLACEMARK_TAG]:
            if _has_line(pm):
                stats.kept += 1
            else:
                parent.remove(pm)
                stats.dropped += 1

    # 2. prune leftover <Point> inside surviving placemarks (MultiGeometry)
    for pm in list(root.iter(PLACEMARK_TAG)):
        for parent in list(pm.iter()):
            for pt in [c for c in parent if c.tag == POINT_TAG]:
                parent.remove(pt)
                stats.points_pruned += 1

    # 3. drop styles nothing references any more
    used = {(e.text or "").strip().lstrip("#")
            for e in root.iter(STYLEURL_TAG)}
    for parent in list(root.iter()):
        for st in [c for c in parent if c.tag in STYLE_TAGS]:
            sid = st.get("id")
            if sid and sid not in used:
                parent.remove(st)
                stats.styles_dropped += 1

    # 4. prune containers left with no placemarks (innermost first)
    top = set(root)  # never prune the Document that holds the whole file
    changed = True
    while changed:
        changed = False
        for parent in list(root.iter()):
            for c in [c for c in parent if c.tag in CONTAINER_TAGS]:
                if c in top:
                    continue
                if not any(d.tag == PLACEMARK_TAG for d in c.iter()):
                    parent.remove(c)
                    stats.folders_pruned += 1
                    changed = True

    body = ET.tostring(root, encoding="unicode")
    return '<?xml version="1.0" encoding="UTF-8"?>\n' + body + "\n", stats


def strip_file(src, dst, force: bool = False) -> Stats:
    """Strip src -> dst. dst may be '-' for stdout. Returns Stats."""
    src = Path(src)
    text = src.read_text(encoding="utf-8")
    out, stats = strip_to_routes(text)
    if str(dst) == "-":
        sys.stdout.write(out)
        return stats
    dst = Path(dst)
    if dst.resolve() == src.resolve():
        raise ValueError("output would overwrite input")
    if dst.exists() and not force:
        raise FileExistsError(f"{dst} exists (use -f to overwrite)")
    dst.write_text(out, encoding="utf-8")
    return stats


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(
        prog="kmlroutes",
        description="strip a KML to route lines only, removing pins/waypoints")
    ap.add_argument("input", help="source .kml")
    ap.add_argument("output", help="destination .kml, or - for stdout")
    ap.add_argument("-f", "--force", action="store_true",
                    help="overwrite output if it exists")
    ap.add_argument("-q", "--quiet", action="store_true",
                    help="suppress the summary line")
    args = ap.parse_args(argv)

    try:
        stats = strip_file(args.input, args.output, args.force)
    except ET.ParseError as e:
        print(f"kmlroutes: not valid XML: {e}", file=sys.stderr)
        return 2
    except (OSError, ValueError, FileExistsError) as e:
        print(f"kmlroutes: {e}", file=sys.stderr)
        return 1

    if not args.quiet:
        print(stats, file=sys.stderr)
        if stats.kept == 0:
            print("kmlroutes: warning: no line geometry found; output is empty",
                  file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
