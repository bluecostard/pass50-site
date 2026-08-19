#!/usr/bin/env python3
"""Génère le calendrier éditorial TikTok PASS50 (30 j × 12 créneaux)."""

from __future__ import annotations

import csv
import json
from datetime import date, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORMATS = json.loads((ROOT / "formats.json").read_text(encoding="utf-8"))
BRAND = json.loads((ROOT / "brand-kit.json").read_text(encoding="utf-8"))


def week_theme(day: int) -> tuple[int, str, str]:
    for block in FORMATS["weeklyThemes"]:
        start, end = block["days"]
        if start <= day <= end:
            return block["week"], block["theme"], block["overlay"]
    return 4, "bilan", FORMATS["weeklyThemes"][-1]["overlay"]


def hook_for(fmt: dict, day: int) -> str:
    variants = fmt.get("hookVariants") or [fmt["label"]]
    return variants[(day - 1) % len(variants)]


def hashtags_for(day: int) -> str:
    core = BRAND["hashtags"]["core"]
    rot = BRAND["hashtags"]["rotation"]
    max_tags = int(BRAND["hashtags"]["maxPerPost"])
    extra = [rot[(day + i) % len(rot)] for i in range(max(0, max_tags - len(core)))]
    return " ".join((core + extra)[:max_tags])


def generate(start: date | None = None, days: int = 30) -> list[dict]:
    start = start or date.today() + timedelta(days=1)
    rows: list[dict] = []
    for day_offset in range(days):
        day_num = day_offset + 1
        day_date = start + timedelta(days=day_offset)
        week, theme, overlay = week_theme(day_num)
        for fmt in FORMATS["formats"]:
            time_str = fmt["timeLocal"]
            hour, minute = map(int, time_str.split(":"))
            publish = datetime.combine(day_date, datetime.min.time()).replace(
                hour=hour, minute=minute
            )
            if fmt["slot"] >= 10:
                publish += timedelta(days=1 if hour < 6 else 0)
            rows.append(
                {
                    "day": day_num,
                    "date": day_date.isoformat(),
                    "week": week,
                    "theme": theme,
                    "themeOverlay": overlay,
                    "slot": fmt["slot"],
                    "timeLocal": time_str,
                    "publishAt": publish.strftime("%Y-%m-%dT%H:%M"),
                    "formatId": fmt["id"],
                    "formatLabel": fmt["label"],
                    "template": fmt["template"],
                    "durationSec": fmt["durationSec"],
                    "hook": hook_for(fmt, day_num),
                    "cta": BRAND["ctaPrimary"],
                    "hashtags": hashtags_for(day_num),
                    "dataNeeds": "|".join(fmt.get("dataNeeds") or []),
                    "status": "draft",
                    "videoFile": "",
                    "notes": "",
                }
            )
    return rows


def main() -> None:
    rows = generate()
    out = ROOT / "calendar-30d.csv"
    fields = list(rows[0].keys()) if rows else []
    with out.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)
    print(f"Wrote {len(rows)} rows → {out}")


if __name__ == "__main__":
    main()
