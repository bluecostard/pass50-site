import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
MODULE = (ROOT / "admin-profile-alphabetical-v1.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class AdminProfileAlphabeticalV1Tests(unittest.TestCase):
    def test_french_collator_is_used(self):
        self.assertIn("Intl.Collator('fr'", MODULE)
        self.assertIn("sensitivity:'base'", MODULE)
        self.assertIn("ignorePunctuation:true", MODULE)

    def test_all_management_list_shapes_are_sorted(self):
        for token in (
            "table.admin-table",
            "#linksCards",
            ".media-grid",
            "select[name=\"profileId\"]",
            "#newsProfile",
            "tab==='signals'||tab==='live'",
        ):
            self.assertIn(token, MODULE)

    def test_rankings_are_explicitly_preserved(self):
        self.assertIn("if(tab==='ranking')return", MODULE)
        self.assertIn("score(b)-score(a)", INDEX)
        self.assertNotIn("#top50Body", MODULE)
        self.assertNotIn("#contentGrid", MODULE)

    def test_loader_uses_versioned_non_async_module(self):
        self.assertIn("admin-profile-alphabetical-v1.js?v=1.0", LOADER)
        self.assertIn("pass50AdminProfileAlphabetical", LOADER)
        self.assertIn("'1.0',false", LOADER)


if __name__ == "__main__":
    unittest.main()
