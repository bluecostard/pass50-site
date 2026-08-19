"""Tests du kit promo TikTok PASS50."""

from __future__ import annotations

import csv
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PROMO = ROOT / "pass50" / "promo" / "tiktok"


class TikTokPromoKitTests(unittest.TestCase):
    def test_brand_and_formats_json(self):
        brand = json.loads((PROMO / "brand-kit.json").read_text(encoding="utf-8"))
        formats = json.loads((PROMO / "formats.json").read_text(encoding="utf-8"))
        self.assertEqual(brand["colors"]["lime"], "#b7ff00")
        self.assertEqual(formats["slotsPerDay"], 12)
        self.assertEqual(len(formats["formats"]), 12)

    def test_day01_has_twelve_scripts(self):
        day1 = json.loads((PROMO / "scripts" / "day-01.json").read_text(encoding="utf-8"))
        self.assertEqual(len(day1["slots"]), 12)
        raw = (PROMO / "scripts" / "day-01.json").read_text(encoding="utf-8")
        self.assertNotIn("{{", raw, "day-01 must use real names, not placeholders")
        self.assertIn("Emma Lohoues", raw)
        for slot in day1["slots"]:
            self.assertIn("voiceover", slot)
            self.assertIn("onScreen", slot)
            self.assertTrue(slot["voiceover"])

    def test_top50_seed_data(self):
        top = json.loads((PROMO / "data" / "top50-seed.json").read_text(encoding="utf-8"))
        self.assertGreaterEqual(len(top["profiles"]), 10)
        self.assertIn("biggestGainer", top["promoPicks"])

    def test_calendar_generator(self):
        subprocess.run(
            ["python3", str(PROMO / "tools" / "generate_calendar.py")],
            check=True,
            cwd=ROOT,
        )
        rows = list(csv.DictReader((PROMO / "calendar-30d.csv").open(encoding="utf-8")))
        self.assertEqual(len(rows), 360)
        self.assertEqual(len({r["day"] for r in rows}), 30)
        self.assertEqual(len([r for r in rows if r["day"] == "1"]), 12)

    def test_export_spec(self):
        spec = json.loads((PROMO / "export-spec.json").read_text(encoding="utf-8"))
        self.assertIn("responseShape", spec)
        self.assertIn("fieldMapping", spec)


if __name__ == "__main__":
    unittest.main()
