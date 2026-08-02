import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ActualiteMetricsIntegrityV1Tests(unittest.TestCase):
    def test_news_validate_requires_confirmation_and_enqueues_metrics(self):
        php = read("api/news-validate.php")
        self.assertIn("Confirmation explicite du lien original requise", php)
        self.assertIn("p50_metrics_upsert_account", php)
        self.assertIn("p50_metrics_upsert_content", php)
        self.assertIn("p50_mo_enqueue_profile", php)
        self.assertIn("actualite_validated", php)
        self.assertIn("require_role($user,'owner','admin')", php)

    def test_client_uses_news_validate_and_strict_qa(self):
        js = read("v9-tools.js")
        self.assertIn("news-validate.php", js)
        self.assertIn("Confirmer que ce lien est bien", js)
        self.assertIn("originalLinkValidated===true", js)
        self.assertNotIn("originalLinkValidated!==false", js)
        self.assertIn("v9-tools.js?v=15.3", read("index.html"))
        self.assertIn("v9-tools.js?v=15.3", read("sw.js"))

    def test_news_discover_anchors_on_verified_handles(self):
        php = read("api/news-discover.php")
        self.assertIn("p50_social_links", php)
        self.assertIn("status='verified'", php)
        self.assertIn("officialHandles", php)
        self.assertIn("site:tiktok.com/@", php)

    def test_fictive_exposes_exclusions_freshness_and_run_uuid(self):
        php = read("api/metrics-ranking-fictive.php")
        html = read("classement-fictif.html")
        self.assertIn("exclusionSummary", php)
        self.assertIn("excludedSamples", php)
        self.assertIn("freshness", php)
        self.assertIn("runUuid", php)
        self.assertIn("'publicStateWrites'=>0", php)
        self.assertIn("FICTIVE-RANKING-V1.1", php)
        self.assertIn("Exclusions (non classables)", html)
        self.assertIn("runUuid", html)
        self.assertIn("dernière capture", html)


if __name__ == "__main__":
    unittest.main()
