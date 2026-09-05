import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
FEED = (ROOT / "mon-fil.js").read_text(encoding="utf-8")
PRONO = (ROOT / "pronostics.html").read_text(encoding="utf-8")
NAV = (ROOT / "mobile-bottom-nav-v1.js").read_text(encoding="utf-8")


class GuestLoginGatesV1Tests(unittest.TestCase):
    def test_auth_modal_has_no_legal_links(self):
        open_auth = INDEX.split("function openAuth(", 1)[1].split("async function submitAuth", 1)[0]
        self.assertIn("$('#authBody').innerHTML=content;open('authModal')", open_auth.replace(" ", ""))
        self.assertNotIn("accountLegalLinksHtml()", open_auth)
        self.assertIn("accountLegalLinksHtml()", INDEX)
        self.assertIn('data-user-fold="legal"', INDEX)

    def test_top50_and_top10_scroll_require_login(self):
        self.assertIn("function syncGuestRankingGate()", INDEX)
        self.assertIn("syncGuestRankingGate()", INDEX)
        self.assertIn("p50-guest-locked", INDEX)
        open_top50 = INDEX.split("function openTop50()", 1)[1].split("function ", 1)[0]
        self.assertIn("if(!requireAuth())return;", open_top50)
        self.assertIn("id=\"top50Btn\"", INDEX)
        self.assertIn("id=\"top10Grid\"", INDEX)
        self.assertIn("id=\"buzz\"", INDEX)

    def test_mon_fil_requires_login(self):
        self.assertIn("location.replace('./mon-espace.html')", FEED)
        self.assertIn("if (!state.user)", FEED)
        self.assertIn("PASS50-FOLLOW-FEED-PAGE-V2.23", FEED)

    def test_prono_requires_login(self):
        self.assertIn("function requireLogin()", PRONO)
        self.assertIn("location.replace('./mon-espace.html')", PRONO)
        self.assertIn("if(!requireLogin())return;", PRONO)

    def test_bottom_nav_gates_feed_and_prono(self):
        self.assertIn("PASS50-MOBILE-BOTTOM-NAV-V1.11", NAV)
        self.assertIn("function guestNeedsAuth()", NAV)
        self.assertIn("tab === 'feed' || tab === 'prono'", NAV)
        self.assertIn("go('./mon-espace.html')", NAV)


if __name__ == "__main__":
    unittest.main()
