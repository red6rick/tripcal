#!/usr/bin/env python3
"""Merge 2-8 KML files into one, preserving per-file line colors.

Usage: merge_kml.py in1.kml in2.kml [...] out.kml
Last argument is the output file. Refuses to overwrite without confirmation.
"""

import re
import sys
from pathlib import Path


def die(msg):
    sys.exit(f"error: {msg}")


def main():
    args = sys.argv[1:]
    if len(args) < 3:
        die("need at least 2 input files and 1 output file")
    if len(args) > 9:  # 8 inputs + 1 output
        die("max 8 input files")

    inputs = [Path(p) for p in args[:-1]]
    output = Path(args[-1])

    for p in inputs:
        if not p.is_file():
            die(f"input not found: {p}")

    if output.exists():
        reply = input(f"{output} exists. Overwrite? [y/N] ").strip().lower()
        if reply not in ("y", "yes"):
            sys.exit("aborted.")

    merged_styles = []
    merged_placemarks = []
    merged_name = output.stem

    for idx, path in enumerate(inputs, 1):
        text = path.read_text()

        # Extract <Style ...>...</Style> blocks (non-greedy, multiline).
        styles = re.findall(r"<Style\b[^>]*>.*?</Style>", text, flags=re.DOTALL)

        # Rewrite style ids to be unique per source file.
        id_map = {}
        for s in styles:
            m = re.search(r'<Style\s+id="([^"]+)"', s)
            if not m:
                continue
            old_id = m.group(1)
            new_id = f"{old_id}_{idx}"
            id_map[old_id] = new_id
            new_style = s.replace(f'id="{old_id}"', f'id="{new_id}"', 1)
            merged_styles.append(new_style)

        # Extract <Placemark>...</Placemark> blocks.
        placemarks = re.findall(r"<Placemark\b.*?</Placemark>", text, flags=re.DOTALL)

        # Rewrite styleUrl references to match the renamed ids.
        for pm in placemarks:
            for old_id, new_id in id_map.items():
                pm = pm.replace(f"#{old_id}", f"#{new_id}")
            merged_placemarks.append(pm)

    body = "\n".join(merged_styles + merged_placemarks)
    kml = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<kml xmlns="http://www.opengis.net/kml/2.2">\n'
        "<Document>\n"
        f"<name>{merged_name}</name>\n"
        f"{body}\n"
        "</Document>\n"
        "</kml>\n"
    )

    output.write_text(kml)
    print(f"wrote {output} ({len(inputs)} files merged)")


if __name__ == "__main__":
    main()
