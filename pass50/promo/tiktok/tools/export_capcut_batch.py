#!/usr/bin/env python3
"""Exporte un CSV CapCut batch depuis scripts/day-NN.json."""

from __future__ import annotations

import argparse
import csv
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
SEED = ROOT / "data" / "top50-seed.json"
OUT_DIR = ROOT / "capcut" / "exports"


def _photo_map(seed: dict) -> dict[str, str]:
    return {p["id"]: p.get("photoUrl") or "" for p in seed.get("profiles", [])}


def _names_to_photos(text: str, by_name: dict[str, dict]) -> list[str]:
    urls: list[str] = []
    for p in by_name.values():
        if p["name"] in text:
            url = p.get("photoUrl") or ""
            if url and url not in urls:
                urls.append(url)
    return urls[:3]


def _slug(s: str) -> str:
    s = re.sub(r"[^a-zA-Z0-9_-]+", "-", s.lower()).strip("-")
    return s[:60] or "slot"


def export_day(day: int) -> Path:
    script_path = SCRIPTS / f"day-{day:02d}.json"
    if not script_path.exists():
        raise FileNotFoundError(script_path)

    day_data = json.loads(script_path.read_text(encoding="utf-8"))
    seed = json.loads(SEED.read_text(encoding="utf-8"))
    by_name = {p["name"]: p for p in seed["profiles"]}

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    out_path = OUT_DIR / f"day-{day:02d}-capcut.csv"

    rows: list[dict] = []
    for slot in day_data["slots"]:
        on_screen = slot.get("onScreen") or []
        voiceover = " | ".join(slot.get("voiceover") or [])
        photos = _names_to_photos(
            " ".join(on_screen + slot.get("voiceover", []) + [slot.get("hook", "")]),
            by_name,
        )
        video_file = (
            f"day-{day:02d}_slot-{slot['slot']:02d}_{_slug(slot['formatId'])}.mp4"
        )
        rows.append(
            {
                "day": day,
                "date": day_data.get("date", ""),
                "slot": slot["slot"],
                "timeLocal": slot["timeLocal"],
                "formatId": slot["formatId"],
                "durationSec": slot["durationSec"],
                "hook": slot.get("hook", ""),
                "line1": on_screen[0] if len(on_screen) > 0 else "",
                "line2": on_screen[1] if len(on_screen) > 1 else "",
                "line3": on_screen[2] if len(on_screen) > 2 else "",
                "line4": on_screen[3] if len(on_screen) > 3 else "",
                "line5": on_screen[4] if len(on_screen) > 4 else "",
                "voiceover": voiceover,
                "hashtags": slot.get("hashtags", ""),
                "photo1Url": photos[0] if len(photos) > 0 else "",
                "photo2Url": photos[1] if len(photos) > 1 else "",
                "photo3Url": photos[2] if len(photos) > 2 else "",
                "videoFile": video_file,
                "cta": "Lien en bio → pass50.store",
            }
        )

    fields = list(rows[0].keys()) if rows else []
    with out_path.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)

    print(f"Wrote {len(rows)} rows → {out_path}")
    return out_path


def main() -> None:
    parser = argparse.ArgumentParser(description="Export CapCut batch CSV")
    parser.add_argument("--day", type=int, default=1, help="Jour (1–30)")
    parser.add_argument("--all", action="store_true", help="Exporter J1–J7")
    args = parser.parse_args()

    if args.all:
        for d in range(1, 8):
            export_day(d)
    else:
        export_day(args.day)


if __name__ == "__main__":
    main()
