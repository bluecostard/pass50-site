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

    def test_days_02_to_07_filled(self):
        for n in range(2, 8):
            path = PROMO / "scripts" / f"day-{n:02d}.json"
            self.assertTrue(path.exists(), f"missing {path.name}")
            day = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual(len(day["slots"]), 12)
            raw = path.read_text(encoding="utf-8")
            self.assertNotIn("{{", raw)
            self.assertIn("Emma Lohoues", raw)

    def test_top50_seed_data(self):
        top = json.loads((PROMO / "data" / "top50-seed.json").read_text(encoding="utf-8"))
        self.assertGreaterEqual(len(top["profiles"]), 10)
        self.assertIn("biggestGainer", top["promoPicks"])
        self.assertIn("dayRotations", top)
        self.assertEqual(len(top["dayRotations"]), 7)
        with_photo = [p for p in top["profiles"] if p.get("photoUrl")]
        self.assertGreaterEqual(len(with_photo), 3)

    def test_calendar_generator(self):
        subprocess.run(
            ["python3", str(PROMO / "tools" / "generate_calendar.py")],
            check=True,
            cwd=ROOT,
        )
        subprocess.run(
            ["python3", str(PROMO / "tools" / "generate_scripts.py")],
            check=True,
            cwd=ROOT,
        )
        if (PROMO / "output" / "day-01").exists():
            subprocess.run(
                [
                    "python3",
                    str(PROMO / "tools" / "render_videos.py"),
                    "--sync-calendar",
                    "--day",
                    "7",
                ],
                check=True,
                cwd=ROOT,
            )
        with (PROMO / "calendar-30d.csv").open(encoding="utf-8") as f:
            rows = list(csv.DictReader(f))
        self.assertEqual(len(rows), 360)
        self.assertEqual(len({r["day"] for r in rows}), 30)
        self.assertEqual(len([r for r in rows if r["day"] == "1"]), 12)
        day1_notes = [r["notes"] for r in rows if r["day"] == "1"]
        self.assertTrue(any("Emma Lohoues" in n for n in day1_notes))

    def test_export_spec(self):
        spec = json.loads((PROMO / "export-spec.json").read_text(encoding="utf-8"))
        self.assertIn("responseShape", spec)
        self.assertIn("fieldMapping", spec)

    def test_capcut_batch_export(self):
        subprocess.run(
            ["python3", str(PROMO / "tools" / "export_capcut_batch.py"), "--day", "1"],
            check=True,
            cwd=ROOT,
        )
        csv_path = PROMO / "capcut" / "exports" / "day-01-capcut.csv"
        self.assertTrue(csv_path.exists())
        with csv_path.open(encoding="utf-8") as f:
            rows = list(csv.DictReader(f))
        self.assertEqual(len(rows), 12)
        self.assertIn("Emma Lohoues", rows[0]["line2"])
        self.assertTrue(rows[0]["videoFile"].endswith(".mp4"))

    def test_render_smoke(self):
        out_dir = PROMO / "output" / "_test"
        if out_dir.exists():
            for p in out_dir.glob("*.mp4"):
                p.unlink()
        subprocess.run(
            [
                "python3",
                str(PROMO / "tools" / "render_videos.py"),
                "--day",
                "1",
                "--slot",
                "1",
            ],
            check=True,
            cwd=ROOT,
        )
        mp4 = PROMO / "output" / "day-01" / "day-01_slot-01_top3_matin.mp4"
        self.assertTrue(mp4.exists())
        self.assertGreater(mp4.stat().st_size, 10_000)


if __name__ == "__main__":
    unittest.main()
