from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PRESERVE = (ROOT / "admin-fi-edit-preserve-v1.js").read_text(encoding="utf-8")
PROTECT = (ROOT / "official-links-protection-v4.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class FiEditPreserveV1Tests(unittest.TestCase):
    def test_edit_in_progress_blocks_admin_rebuilds(self):
        self.assertIn("function isEditingFi()", PRESERVE)
        self.assertIn("function shouldSkip(kind)", PRESERVE)
        self.assertIn("restoreLinksSearch", PRESERVE)
        self.assertIn("wrap('renderAdminPane','pane')", PRESERVE)
        self.assertIn("wrap('renderAdmin','admin')", PRESERVE)
        self.assertIn("wrap('p50v9RenderLinks','links')", PRESERVE)
        self.assertIn("#profileForm,#hubForm", PRESERVE)
        self.assertIn("linksHaveDraft", PRESERVE)

    def test_same_tab_click_does_not_wipe_an_open_edit(self):
        self.assertIn("if(!(next===currentTab()&&isEditingFi()))markTabSwitch()", PRESERVE)
        self.assertIn(".save-links,.check-links", PRESERVE)
        self.assertIn("id==='profileForm'", PRESERVE)

    def test_official_links_protection_keeps_open_search(self):
        self.assertIn("PASS50-OFFICIAL-LINKS-PROTECTION-V4.5", PROTECT)
        self.assertIn("if(window.PASS50_FI_EDIT_PRESERVE?.busy?.())return;", PROTECT)
        self.assertIn("!window.PASS50_FI_EDIT_PRESERVE?.busy?.()", PROTECT)

    def test_runtime_is_loaded(self):
        self.assertIn("admin-fi-edit-preserve-v1.js?v=1.0", LOADER)
        self.assertIn("official-links-protection-v4.js?v=4.5", LOADER)
        self.assertIn("public-copy-fixes.js?v=1.15", CONFIG)
        self.assertIn("admin-fi-edit-preserve-v1.js?v=1.0", SW)


if __name__ == "__main__":
    unittest.main()
