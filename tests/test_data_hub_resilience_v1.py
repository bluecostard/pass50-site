import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
HUB_ENDPOINT = (ROOT / "api/data-hub.php").read_text(encoding="utf-8")
PUBLISH_ENDPOINT = (ROOT / "api/data-publish.php").read_text(encoding="utf-8")
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class DataHubResilienceTests(unittest.TestCase):
    def test_hub_endpoint_has_timeout_and_fatal_handler(self):
        self.assertIn("set_time_limit(180)", HUB_ENDPOINT)
        self.assertIn("register_shutdown_function", HUB_ENDPOINT)
        self.assertIn("Hub Data Engine interrompu", HUB_ENDPOINT)
        self.assertIn("Hub Data Engine indisponible", HUB_ENDPOINT)
        self.assertIn("p50_metrics_safe_error", HUB_ENDPOINT)

    def test_publish_endpoint_returns_detail_on_failure(self):
        self.assertIn("set_time_limit(300)", PUBLISH_ENDPOINT)
        self.assertIn("register_shutdown_function", PUBLISH_ENDPOINT)
        self.assertIn("'detail'=>p50_metrics_safe_error", PUBLISH_ENDPOINT)
        self.assertIn("Publication du classement interrompue", PUBLISH_ENDPOINT)

    def test_hub_payload_batches_queries_instead_of_per_profile_loops(self):
        self.assertIn("function p50_de_hub_batch_verified_facts", CORE)
        self.assertIn("function p50_de_hub_batch_best_facts", CORE)
        self.assertIn("function p50_de_hub_batch_social_links", CORE)
        self.assertIn("function p50_de_hub_batch_last_runs", CORE)
        self.assertIn("function p50_de_hub_trend_candidate", CORE)
        self.assertNotIn("'trendCandidate'=>p50_de_compute_trend_score($id)", CORE)
        self.assertIn("p50_de_hub_batch_verified_facts($profileIds,$threshold)", CORE)
        self.assertIn("p50_de_hub_trend_candidate($sp)", CORE)

    def test_ui_surfaces_server_detail_for_hub_and_publish(self):
        self.assertIn("function deApiErrorMessage", UI)
        self.assertIn("timeoutMs:180000", UI)
        self.assertIn("deApiErrorMessage(err,'Moteur indisponible')", UI)
        self.assertIn("deApiErrorMessage(err,'Publication impossible')", UI)
        self.assertNotIn("fichiers API V19 sont déployés", UI)
        self.assertIn("err.detail=data.detail", INDEX)


if __name__ == "__main__":
    unittest.main()
