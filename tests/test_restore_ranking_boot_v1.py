import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")


class RestoreRankingBootV1Tests(unittest.TestCase):
    def test_birth_lock_never_spreads_non_iterable_facts(self):
        self.assertIn("function p50VerifiedFactsList(p)", INDEX)
        self.assertIn("function p50EnsurePlainObject(value)", INDEX)
        self.assertIn("p.dataEngine=p50EnsurePlainObject(p.dataEngine)", INDEX)
        self.assertNotIn("[...(p.dataEngine.verifiedFacts||[]),'birth_date']", INDEX)
        self.assertNotIn("[...(p.dataEngine?.verifiedFacts||[]),'birth_date']", INDEX)

    def test_boot_survives_migrate_or_save_failure(self):
        self.assertIn("try{migrateDb();applyPass50V6Patch();}", INDEX)
        self.assertIn("try{save();}", INDEX)
        self.assertIn("try{p50FreezeExistingBirth(p)}catch{}}", INDEX)

    def test_engine_ui_does_not_spread_object_facts(self):
        self.assertNotIn("[...(p.dataEngine.verifiedFacts||[]),'birth_date']", UI)
        self.assertIn("Array.isArray(p.dataEngine.verifiedFacts)?p.dataEngine.verifiedFacts:[]", UI)


if __name__ == "__main__":
    unittest.main()
