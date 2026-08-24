import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOMBSTONE_IDS = [
    "census-jai-horreur-des-fautes-lofficiel",
    "census-simon-adingra",
    "census-sheisthecode",
    "census-les-adresses-de-chez-nous",
    "census-epouse-gnahore",
    "census-le-brouteur",
    "census-oustaz-diakite-yaya",
    "census-reine-a",
    "census-didi-b",
    "census-himra",
    "census-ks-bloom",
    "census-roseline-layo",
    "census-josey",
]
KEPT_IDS = ["census-henri-michel", "census-aissa-amara", "obre-marie-pascale"]


class ProfileTombstoneV1Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.census = json.loads((ROOT / "pass50_nouveaux_candidats_90_v19.json").read_text(encoding="utf-8"))
        cls.core = (ROOT / "api/profile-tombstone-core.php").read_text(encoding="utf-8")
        cls.index = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.v9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
        cls.cleanup = (ROOT / "api/profile-cleanup-cron-v1.php").read_text(encoding="utf-8")
        cls.state = (ROOT / "api/state.php").read_text(encoding="utf-8")
        cls.overlay = (ROOT / "profile-obre-marie-pascale.js").read_text(encoding="utf-8")

    def test_census_no_longer_contains_deleted_profiles(self):
        ids = {row.get("id") for row in self.census}
        for profile_id in TOMBSTONE_IDS:
            self.assertNotIn(profile_id, ids)
        for profile_id in KEPT_IDS[:2]:
            self.assertIn(profile_id, ids)

    def test_php_and_js_share_the_same_seed(self):
        php_ids = re.findall(r"'census-[a-z0-9-]+'", self.core)
        js_block = self.index.split("const P50_TOMBSTONE_PROFILE_IDS=", 1)[1].split("];", 1)[0]
        js_ids = re.findall(r"'census-[a-z0-9-]+'", js_block)
        self.assertEqual(php_ids, js_ids)
        self.assertEqual(len(php_ids), 13)
        self.assertIn("'census-reine-a'", php_ids)
        self.assertIn("'census-sheisthecode'", php_ids)
        self.assertIn("'census-didi-b'", php_ids)
        self.assertIn("'census-himra'", php_ids)
        self.assertIn("'census-ks-bloom'", php_ids)
        self.assertIn("'census-roseline-layo'", php_ids)
        self.assertIn("'census-josey'", php_ids)
        self.assertNotIn("'census-henri-michel'", php_ids)

    def test_admin_delete_and_census_import_honor_tombstones(self):
        self.assertIn("p50RememberDeletedProfileId(id)", self.index)
        self.assertIn("p50ApplyProfileTombstones()", self.index)
        self.assertIn("p50_apply_profile_tombstones($data)", self.state)
        self.assertIn("p50IsDeletedProfileId(id)||p50IsDeletedProfileId(candidate?.id)", self.v9)
        self.assertIn("PROFILE-CLEANUP-V2.1", self.cleanup)
        self.assertNotIn("census-henri-michel", self.cleanup)
        self.assertNotIn("census-reine-a", self.cleanup)
        self.assertNotIn("cacaoispoppin", self.cleanup)

    def test_obre_overlay_does_not_resurrect_a_tombstone(self):
        self.assertIn("p50IsDeletedProfileId(PROFILE_ID)", self.overlay)
        self.assertIn("db.profiles=db.profiles.filter(item=>item&&item.id!==PROFILE_ID)", self.overlay)

    def test_php_tombstone_core_is_idempotent(self):
        result = subprocess.run(
            ["php", str(ROOT / "tests/profile_tombstone_unit.php")],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.stdout.strip(), "ok")


if __name__ == "__main__":
    unittest.main()
