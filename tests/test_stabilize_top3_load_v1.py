import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
SYNC = (ROOT / "classability-sync-v1.js").read_text(encoding="utf-8")


class StabilizeTop3LoadTests(unittest.TestCase):
    def test_ranking_has_stable_tie_break(self):
        self.assertIn("String(a.id).localeCompare(String(b.id))", INDEX)

    def test_hero_top3_lock_during_boot(self):
        self.assertIn("let previousTop10Order=[],previousTop3Order=[],bootTop3Lock=null,_bootSettleTimer=null;", INDEX)
        self.assertIn("function heroTop3Profiles()", INDEX)
        self.assertIn("if(CLOUD.ready&&top.length>=3&&(!bootTop3Lock||bootTop3Lock.length!==3))bootTop3Lock=top.map(p=>p.id);", INDEX)
        self.assertIn("const top=heroTop3Profiles()", INDEX)

    def test_hero_patches_same_three_without_full_rebuild(self):
        self.assertIn("function heroIdentityKey(top)", INDEX)
        self.assertIn("function syncHeroCards(buzz,top,scoreMotion=false,total=0)", INDEX)
        self.assertIn("buzz.dataset.identity===identityKey", INDEX)
        self.assertIn("buzz.appendChild(card)", INDEX)

    def test_boot_settling_is_delayed_after_cloud_ready(self):
        self.assertIn("const delay=CLOUD.enabled?520:0;", INDEX)
        self.assertIn("_bootSettleTimer=setTimeout(()=>{_bootSettleTimer=null;endBootSettling();},delay);", INDEX)

    def test_classability_defers_post_cloud_render_until_boot_ends(self):
        self.assertIn("function isBootSettling()", SYNC)
        self.assertIn("function schedulePostCloudRepair()", SYNC)
        self.assertIn("if (isBootSettling())", SYNC)
        self.assertIn("schedulePostCloudRepair();", SYNC)


if __name__ == "__main__":
    unittest.main()
