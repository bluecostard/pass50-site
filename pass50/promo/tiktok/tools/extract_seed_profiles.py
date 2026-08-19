#!/usr/bin/env python3
"""Extrait seedProfiles depuis index.html → top50-seed.json."""

from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
INDEX = ROOT / "index.html"
OUT = Path(__file__).resolve().parents[1] / "data" / "top50-seed.json"

ROW_RE = re.compile(
    r"\['([^']+)','([^']+)','([^']+)','[^']*','[^']*','[^']*',\[[^\]]+\],(\d+),(-?\d+),\d+\]"
)


def score_24h(base: int, index: int) -> int:
    return max(10, round(base + ((index * 7) % 9) - 4))


def main() -> None:
    text = INDEX.read_text(encoding="utf-8")
    block = text.split("const seedProfiles=[", 1)[1].split("];", 1)[0]
    profiles = []
    for i, m in enumerate(ROW_RE.finditer(block)):
        pid, name, handle, base, delta = m.groups()
        profiles.append(
            {
                "rank": i + 1,
                "id": pid,
                "name": name,
                "handle": handle,
                "score24H": score_24h(int(base), i),
                "delta": int(delta),
            }
        )

    sorted_by_delta = sorted(profiles, key=lambda p: p["delta"], reverse=True)
    gainer = sorted_by_delta[0]
    spotlight = profiles[3]  # Apoutchou National
    live_morning = profiles[4]  # Maabio
    live_evening = profiles[1]  # Lo Père Daloa

    payload = {
        "version": "TOP50-SEED-V1.1",
        "source": "index.html seedProfiles · ordre classement 24H (base)",
        "periodDefault": "24H",
        "profileCount": len(profiles),
        "profiles": profiles,
        "promoPicks": {
            "top3DisplayOrder": [p["name"] for p in profiles[:3]],
            "top10Names": [p["name"] for p in profiles[:10]],
            "top5_7j": [p["name"] for p in profiles[:5]],
            "biggestGainer": {
                "name": gainer["name"],
                "handle": gainer["handle"],
                "delta": gainer["delta"],
                "score24H": gainer["score24H"],
            },
            "spotlight": {
                "name": spotlight["name"],
                "handle": spotlight["handle"],
                "score24H": spotlight["score24H"],
                "delta": spotlight["delta"],
            },
            "liveExampleMorning": {
                "name": live_morning["name"],
                "handle": live_morning["handle"],
                "platform": "TikTok",
            },
            "liveExampleEvening": {
                "name": live_evening["name"],
                "handle": live_evening["handle"],
                "platform": "TikTok",
            },
            "liveCountToday": 3,
        },
        "dayRotations": _day_rotations(profiles),
    }
    OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {len(profiles)} profiles → {OUT}")


def _day_rotations(profiles: list[dict]) -> list[dict]:
    """Picks promo alternés pour J1–J7 (spotlight, gainer, lives)."""
    pool = profiles[3:20]
    gainers = sorted(profiles, key=lambda p: p["delta"], reverse=True)
    rotations = []
    for day in range(1, 8):
        spotlight = pool[(day - 1) % len(pool)]
        gainer = gainers[(day - 1) % min(5, len(gainers))]
        live_am = profiles[(4 + day) % 12]
        live_pm = profiles[(1 + day) % 12]
        rotations.append(
            {
                "day": day,
                "spotlight": spotlight["name"],
                "gainer": gainer["name"],
                "liveMorning": live_am["name"],
                "liveEvening": live_pm["name"],
                "liveCount": 2 + (day % 3),
            }
        )
    return rotations


if __name__ == "__main__":
    main()
