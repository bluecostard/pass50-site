import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/metrics-ranking-core.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api/metrics-ranking.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
TOOLS = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/validate-metrics-ranking-experimental-v1.yml").read_text(encoding="utf-8")


class MetricsRankingExperimentalV1Tests(unittest.TestCase):
    def test_algorithm_periods_and_exact_weights(self):
        self.assertIn("P50_MR_ALGORITHM_VERSION='MR-V1.0'", CORE)
        for key, hours in (("2H", 2), ("24H", 24), ("48H", 48), ("7J", 168), ("15J", 360)):
            self.assertIn(f"'{key}'=>{hours}", CORE)
        weights = [0.07, 0.28, 0.18, 0.16, 0.16, 0.12, 0.03]
        self.assertEqual(sum(weights), 1.0)
        for value in weights:
            self.assertIn(f"=>{value}", CORE)

    def test_delta_preserves_absent_zero_and_publication_fallback(self):
        delta = self._function("p50_mr_metric_delta")
        self.assertIn("'available'=>false,'value'=>null", delta)
        self.assertIn("elseif($item['at']<=$end)$last=$item", delta)
        self.assertIn("if($last===null)return", delta)
        self.assertIn("$item['at']<=$start", delta)
        self.assertIn("$reference['id']===$last['id']", delta)
        self.assertIn("max(0.0,$last['value']-$referenceValue)", delta)
        self.assertIn("$last['value']<$referenceValue", delta)
        self.assertIn("$publishedAt>=$start&&$publishedAt<=$end", delta)
        self.assertIn("$last['at']>$publishedAt", delta)
        self.assertIn("'publishedInsideWindowFallback'=>$fallback", delta)
        self.assertIn("'captures'=>array_values($used)", delta)

    def test_only_usable_confident_captures_are_loaded_in_batches(self):
        load = self._function("p50_mr_load")
        self.assertGreaterEqual(load.count("quality_status='usable'"), 3)
        self.assertGreaterEqual(load.count("confidence>=70"), 3)
        self.assertNotIn("quarantined", load)
        self.assertIn("MAX(observed_at)", load)
        self.assertIn("GROUP BY account_id,COALESCE(content_id,0)", load)

    def test_platform_percentiles_and_acceleration(self):
        percentiles = self._function("p50_mr_percentiles")
        self.assertIn("if($count===2)", percentiles)
        self.assertIn("if($first===$second)return", percentiles)
        self.assertIn("25.0", percentiles)
        self.assertIn("75.0", percentiles)
        self.assertIn("50.0", percentiles)
        self.assertIn("/($n-1)*100", percentiles)
        raw = self._function("p50_mr_platform_raw")
        self.assertIn("log((1+$newReach)/(1+$oldReach))", raw)
        self.assertIn("$oldMeasured&&$newMeasured", raw)
        self.assertIn("log1p($velocity)", raw)

    def test_unique_capture_accounting_stays_internal(self):
        raw = self._function("p50_mr_platform_raw")
        self.assertIn("$usedCaptures[(int)$capture['id']]", raw)
        self.assertIn("$captureCount=count($usedCaptures)", raw)
        self.assertIn("array_sum(array_column($usedCaptures,'confidence'))/$captureCount", raw)
        self.assertIn("$rememberDelta($delta)", raw)
        self.assertIn("if($liveCapture)$remember($liveCapture)", raw)
        self.assertIn("if($latestFollower)$remember($latestFollower)", raw)
        serialized = self._function("p50_mr_period_rows")
        self.assertNotIn("usedCaptures", serialized)
        self.assertNotIn("captureIds", CORE)

    def test_confidence_thresholds_exclusions_and_rank_delta(self):
        period = self._function("p50_mr_period_rows")
        for code in (
            "editorial_not_eligible",
            "no_official_metric_account",
            "no_measurable_content",
            "coverage_below_45",
            "confidence_below_55",
            "stale_captures",
        ):
            self.assertIn(code, period)
        self.assertIn("$coverage<45", period)
        self.assertIn("$confidence<55", period)
        self.assertIn("$row['previousRank']-$row['rank']", period)
        self.assertIn("[0.70,0.20,0.10]", CORE)

    def test_transaction_lock_and_experimental_tables(self):
        self.assertIn("pass50_metrics_ranking_experimental_v1", CORE)
        for table in (
            "p50_metric_ranking_runs",
            "p50_metric_ranking_current",
            "p50_metric_ranking_snapshots",
        ):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {table}", CORE)
        calculate = self._function("p50_mr_calculate")
        self.assertIn("beginTransaction()", calculate)
        self.assertIn("rollBack()", calculate)
        self.assertIn("commit()", calculate)
        self.assertIn("RELEASE_LOCK(?)", calculate)
        self.assertIn("$row['rank']<=100", calculate)

    def test_no_public_pipeline_or_app_state_write(self):
        combined = CORE + ENDPOINT
        for forbidden in (
            "data-publish.php",
            "p50_de_publish_profile",
            "p50_de_publish_score_pipeline",
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "data-engine-core.php",
        ):
            self.assertNotIn(forbidden, combined)
        self.assertNotIn("payload_json", combined)
        self.assertIn("if($_SERVER['REQUEST_METHOD']==='GET')", ENDPOINT)
        self.assertLess(ENDPOINT.index("if($_SERVER['REQUEST_METHOD']==='GET')"), ENDPOINT.index("p50_mr_ensure_schema"))

    def test_endpoint_contract_and_admin_access(self):
        self.assertIn("require_role($user,'owner','admin')", ENDPOINT)
        self.assertIn("require_method('GET','POST')", ENDPOINT)
        for field in ("profileId", "name", "handle", "region", "category", "components", "exclusionReasons"):
            self.assertIn(f"'{field}'", CORE)

    def test_admin_lab_is_isolated_from_public_ranking(self):
        self.assertIn("['rankinglab','Classement expérimental']", UI)
        self.assertLess(UI.index("['rankinglab','Classement expérimental']"), UI.index("['ranking','Classement']"))
        self.assertIn("CLASSEMENT MÉTRIQUE EXPÉRIMENTAL", UI)
        self.assertIn("Ce calcul n’a aucun effet sur le classement public.", UI)
        public_rank = UI[UI.index("function dePublicRank"):UI.index("function deDrawRankingLab")]
        self.assertIn("function dePublicRank(profileId,period)", public_rank)
        self.assertIn("profile.scores?.[period]", public_rank)
        self.assertIn("b.scores[period]", public_rank)
        self.assertNotIn("ranking()", public_rank)
        self.assertNotIn("ui.period", public_rank)
        self.assertNotIn("ui.period=", public_rank)
        self.assertIn("dePublicRank(row.profileId,DE.rankingLabPeriod)", UI)
        block = UI[UI.index("function deDrawRankingLab"):UI.index("async function deCalculateRankingLab")]
        self.assertNotIn(">Publier<", block)

    def test_read_aggregates_all_profiles_before_limiting_rows(self):
        read = self._function("p50_mr_read")
        self.assertIn("SELECT COUNT(*) total_count", read)
        self.assertIn("AVG(confidence)", read)
        self.assertIn("AVG(coverage)", read)
        self.assertIn("SELECT exclusion_reasons_json", read)
        self.assertLess(read.index("SELECT COUNT(*) total_count"), read.index("LIMIT ?"))
        self.assertIn("$limit=max(1,min(200,$limit))", read)

    def test_cache_and_workflow_versions(self):
        self.assertIn("data-engine-ui.js?v=18.1", TOOLS)
        self.assertIn("data-engine-ui.css?v=27.0", TOOLS)
        self.assertIn("data-engine-ui.js?v=18.1", SW)
        self.assertIn("data-engine-ui.css?v=27.0", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")
        self.assertIn("mariadb:11.4", WORKFLOW)
        for variable in ("P50_TEST_DSN", "P50_TEST_DB_USER", "P50_TEST_DB_PASSWORD"):
            self.assertIn(variable, WORKFLOW)
        self.assertNotIn("PASS50_TEST_", WORKFLOW)

    @staticmethod
    def _function(name):
        start = CORE.index(f"function {name}(")
        next_match = re.search(r"\nfunction [a-zA-Z0-9_]+\(", CORE[start + 1:])
        return CORE[start:] if next_match is None else CORE[start:start + 1 + next_match.start()]


if __name__ == "__main__":
    unittest.main()
