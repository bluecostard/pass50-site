#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class PublicRankingAppV1Tests(unittest.TestCase):
    def test_contract_and_core_helpers(self):
        core = read("api/public-ranking-core.php")
        self.assertIn("PASS50-PUBLIC-RANKING-V1", core)
        self.assertIn("p50_public_ranking_build", core)
        self.assertIn("p50_public_ranking_persist", core)
        self.assertIn("p50_public_ranking_response", core)
        self.assertIn("p50_public_ranking_canonical_name", core)
        self.assertIn("public_ranking", core)
        self.assertIn("'2H', '24H', '48H', '7J', '15J'", core)
        self.assertIn("P50_PUBLIC_RANKING_LIMIT = 50", core)

    def test_public_endpoint_is_cors_ready(self):
        endpoint = read("api/public-ranking.php")
        self.assertIn("Access-Control-Allow-Origin: *", endpoint)
        self.assertIn("p50_public_ranking_response", endpoint)
        self.assertIn("p50_public_edge_cache(60, 120)", endpoint)
        self.assertIn("action'] ?? 'rebuild'", endpoint)
        self.assertIn("require_role($user, 'owner', 'admin')", endpoint)

    def test_publish_writes_public_snapshot(self):
        apply_core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("public-ranking-core.php", apply_core)
        self.assertIn("p50_public_ranking_build($newState", apply_core)
        self.assertIn("p50_public_ranking_persist($pdo,$publicSnap)", apply_core)

    def test_desktop_boots_from_public_ranking_api(self):
        home = read("index.html")
        self.assertIn("Contrat app V1", home)
        self.assertIn("loadPublicRanking", home)
        self.assertIn("public-ranking.php", home)
        self.assertIn("rankingFromPublicApi", home)
        self.assertIn("CLOUD.publicRanking", home)

    def test_deploy_prioritizes_public_ranking_api(self):
        deploy = read(".github/workflows/deploy-ionos.yml")
        self.assertIn("public-ranking.php", deploy)
        self.assertIn("public-ranking-core.php", deploy)


if __name__ == "__main__":
    unittest.main()
