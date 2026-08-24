import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = json.loads((ROOT / "pass50_nouveaux_candidats_90_v19.json").read_text(encoding="utf-8"))
SOURCE = (ROOT / "api" / "live-radar-v4-source.php").read_text(encoding="utf-8")
CORE = (ROOT / "tests" / "live_radar_v4_core.php").read_text(encoding="utf-8")
TOMBSTONE = (ROOT / "api" / "profile-tombstone-core.php").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
DATA = (ROOT / "api" / "data-engine-core.php").read_text(encoding="utf-8")

REMOVED = [
    ("census-didi-b", "Didi B"),
    ("census-himra", "Himra"),
    ("census-roseline-layo", "Roseline Layo"),
    ("census-josey", "Josey"),
    ("census-ks-bloom", "KS Bloom"),
]


class RemovedIdentitiesV1Tests(unittest.TestCase):
    def test_census_no_longer_lists_them(self):
        ids = {row.get("id") for row in CENSUS}
        names = {row.get("name") for row in CENSUS}
        for profile_id, name in REMOVED:
            self.assertNotIn(profile_id, ids)
            self.assertNotIn(name, names)

    def test_tombstones_block_reimport(self):
        for profile_id, _name in REMOVED:
            needle = f"'{profile_id}'"
            self.assertIn(needle, TOMBSTONE)
            self.assertIn(needle, INDEX)

    def test_radar_and_priority_wave_are_clean(self):
        for profile_id, name in REMOVED:
            self.assertNotIn(f"'{profile_id}'", SOURCE)
            self.assertNotIn(f"'{profile_id}'", CORE)
            self.assertNotIn(f"'{profile_id}'", DATA)
            self.assertNotIn(name, SOURCE)
        self.assertNotIn("roselinelayoofficiel", SOURCE)
        self.assertNotIn("roselinelayoofficiel", CORE)

    def test_step12_add_does_not_reinsert_them(self):
        for profile_id, name in REMOVED:
            self.assertNotIn(f'"id":"{profile_id}"', V9)
            self.assertNotIn(f'"name":"{name}"', V9)
            self.assertNotIn(profile_id, V9)
