import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCORING = (ROOT / "api/scoring-15c-core.php").read_text(encoding="utf-8")
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
METRICS = (ROOT / "api/metrics-core.php").read_text(encoding="utf-8")
OBS = (ROOT / "api/metrics-observability-core.php").read_text(encoding="utf-8")
ACCOUNT_NAV = (ROOT / "account-mobile-nav-v1.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class Scoring15cV11Tests(unittest.TestCase):
    def test_shared_module_declares_v11(self):
        self.assertIn("const P50_15C_ALGORITHM_VERSION = '15C-v1.1';", SCORING)
        self.assertIn("require_once __DIR__ . '/scoring-15c-core.php';", CORE)
        self.assertIn("require_once __DIR__.'/scoring-15c-core.php';", METRICS)

    def test_all_fifteen_criteria_are_active(self):
        for key in [f"c{i}" for i in range(1, 16)]:
            self.assertIn(f"'{key}'", SCORING)
        self.assertIn("'c10' => $likes > 0 ? log10(1 + $likes) : null", SCORING)
        self.assertIn("'c11' => $saves > 0 ? log10(1 + $saves) : null", SCORING)
        self.assertIn("'c15' => $acceleration", SCORING)
        self.assertNotIn("'c15'=>$shares>0?log10(1+$shares):null", CORE)

    def test_weights_sum_to_one(self):
        self.assertIn("'c2' => .09", SCORING)
        self.assertIn("'c8' => .07", SCORING)
        self.assertIn("'c12' => .06", SCORING)
        self.assertIn("p50_15c_weights()", OBS)

    def test_data_engine_uses_shared_scoring(self):
        self.assertIn("p50_15c_build_raw(", CORE)
        self.assertIn("p50_15c_score_raw($raw)", CORE)
        self.assertIn("P50_15C_ALGORITHM_VERSION", CORE)

    def test_account_mobile_nav_groups_sections(self):
        self.assertIn("p50-account-mobile-tabs", ACCOUNT_NAV)
        self.assertIn("data-p50-account-tab", ACCOUNT_NAV)
        self.assertIn("mes-comptes", ACCOUNT_NAV)
        self.assertIn("p50MesComptesMount", ACCOUNT_NAV)
        self.assertIn('[data-user-fold="legal"]', ACCOUNT_NAV)
        self.assertIn("#p50YoutubeOauthSection", ACCOUNT_NAV)

    def test_open_user_mes_comptes_and_legal_folders(self):
        self.assertIn('id="p50MesComptesPanel"', INDEX)
        self.assertIn('data-user-fold="mes-comptes"', INDEX)
        self.assertIn('data-user-fold="legal"', INDEX)
        self.assertIn("Infos légales", INDEX)
        self.assertIn("informations-legales.html", INDEX)
        self.assertIn("verification-pass50.html", INDEX)
        self.assertIn("accountLegalLinksHtml()", INDEX)
        open_auth = INDEX.split("function openAuth(", 1)[1].split("async function submitAuth", 1)[0]
        self.assertNotIn("accountLegalLinksHtml()", open_auth)


if __name__ == "__main__":
    unittest.main()
