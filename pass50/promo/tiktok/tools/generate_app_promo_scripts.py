#!/usr/bin/env python3
"""Génère scripts/app-promo/day-NN.json depuis campaign-core-messages.json."""

from __future__ import annotations

import json
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "scripts" / "campaign-core-messages.json"
OUT_DIR = ROOT / "scripts" / "app-promo"
FORMATS = ROOT / "formats.json"


def build_day(day: int, start: date, core: dict, formats: dict) -> dict:
    messages = core["messages"]
    day_date = start + timedelta(days=day - 1)
    slots = []

    for fmt in formats["formats"]:
        msg = messages[(fmt["slot"] - 1) % len(messages)]
        hooks = msg["hooks"]
        hook = hooks[(day - 1 + fmt["slot"]) % len(hooks)]
        vis = msg["visualDirection"]
        slots.append(
            {
                "slot": fmt["slot"],
                "timeLocal": fmt["timeLocal"],
                "formatId": fmt["id"],
                "messageId": msg["id"],
                "pillar": msg["pillar"],
                "hook": hook,
                "durationSec": vis.get("durationSec", fmt["durationSec"]),
                "voiceover": msg["voiceover"],
                "onScreen": msg["onScreen"],
                "visualDirection": vis,
                "broll": vis.get("scenes", []),
                "cta": core["ctaPrimary"],
                "hashtags": msg["hashtags"],
            }
        )

    return {
        "version": "APP-PROMO-SCRIPTS-V1.0",
        "day": day,
        "date": day_date.isoformat(),
        "campaign": "app-confiance",
        "dataSource": "pass50/promo/tiktok/scripts/campaign-core-messages.json",
        "note": "Montage UGC / capture app — pas de rendu slide auto. Voir capcut/batch-spec-app-promo.json",
        "slots": slots,
    }


def main() -> None:
    core = json.loads(CORE.read_text(encoding="utf-8"))
    formats = json.loads(FORMATS.read_text(encoding="utf-8"))
    start = date(2026, 8, 20)
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    for day in range(1, 8):
        payload = build_day(day, start, core, formats)
        out = OUT_DIR / f"day-{day:02d}.json"
        out.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"Wrote {out.name} ({len(payload['slots'])} slots)")


if __name__ == "__main__":
    main()
