import re
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/metrics-ranking-core.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api/metrics-ranking-cron.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github" / "workflows" / "metrics-ranking-experimental.yml").read_text(encoding="utf-8")
VALIDATE = (ROOT / ".github" / "workflows" / "validate-metrics-ranking-experimental-v1.yml").read_text(encoding="utf-8")


def php_function(source, name):
    start = source.index(f"function {name}(")
    following = re.search(r"\nfunction [A-Za-z0-9_]+\(", source[start + 1 :])
    return source[start:] if following is None else source[start : start + 1 + following.start()]


def simulated_due(runs, now, minimum_minutes):
    minimum_minutes = max(60, min(240, minimum_minutes))
    successes = [run["finished_at"] for run in runs if run["status"] == "success"]
    latest = max(successes) if successes else None
    return latest is None or latest <= now - timedelta(minutes=minimum_minutes)


class MetricsRankingScheduleV1Tests(unittest.TestCase):
    def test_endpoint_is_strict_signed_json_post(self):
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn("$length>16384", ENDPOINT)
        self.assertIn("strlen($raw)>16384", ENDPOINT)
        self.assertIn("^application/json", ENDPOINT)
        self.assertIn("HTTP_X_P50_TIMESTAMP", ENDPOINT)
        self.assertIn("HTTP_X_P50_SIGNATURE", ENDPOINT)
        self.assertIn(">300", ENDPOINT)
        self.assertIn("strlen($secret)<32", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature)", ENDPOINT)
        self.assertIn("p50_mo_config()", ENDPOINT)
        self.assertIn("if(!$cfg['enabled'])", ENDPOINT)

    def test_endpoint_accepts_only_calculate_and_a_bounded_dispatch_id(self):
        self.assertIn("$keys!==['action','dispatchId']", ENDPOINT)
        self.assertIn("!=='calculate'", ENDPOINT)
        self.assertIn("!is_string($input['dispatchId']??null)", ENDPOINT)
        self.assertIn("strlen($dispatchId)>120", ENDPOINT)
        self.assertIn("/^[A-Za-z0-9._-]+$/", ENDPOINT)
        self.assertIn("$now=new DateTimeImmutable('now',new DateTimeZone('UTC'))", ENDPOINT)
        self.assertIn("p50_mrr_readiness($pdo,$now)", ENDPOINT)
        self.assertRegex(ENDPOINT, r"p50_mr_calculate_if_due\(\$pdo,\$now,90,\$dispatchId\)")

    def test_due_policy_uses_only_successful_finished_runs(self):
        due = php_function(CORE, "p50_mr_calculate_if_due")
        self.assertIn("$minimumMinutes=max(60,min(240,$minimumMinutes))", due)
        self.assertIn("algorithm_version=? AND status='success'", due)
        self.assertIn("finished_at IS NOT NULL", due)
        self.assertIn("ORDER BY finished_at DESC,id DESC LIMIT 1", due)
        self.assertIn("'skipped'=>true,'reason'=>'recent_success'", due)
        self.assertIn("array_keys(p50_mr_periods())", due)
        self.assertIn("'cron_2h'", due)

        now = datetime(2026, 7, 28, 12, 0, tzinfo=timezone.utc)
        self.assertFalse(simulated_due([{"status": "success", "finished_at": now - timedelta(minutes=30)}], now, 90))
        self.assertTrue(simulated_due([{"status": "success", "finished_at": now - timedelta(hours=2)}], now, 90))
        self.assertTrue(simulated_due([{"status": "failed", "finished_at": now - timedelta(minutes=5)}], now, 90))

    def test_calculate_remains_backward_compatible_and_metadata_is_whitelisted(self):
        calculate = php_function(CORE, "p50_mr_calculate")
        metadata = php_function(CORE, "p50_mr_run_metadata")
        due = php_function(CORE, "p50_mr_calculate_if_due")
        self.assertIn("string $triggerType,array $metadata=[]", calculate)
        self.assertIn("'readOnlyCanonicalInputs'=>true", metadata)
        self.assertIn("'publicPublication'=>false", metadata)
        self.assertIn("'scheduled'", metadata)
        self.assertIn("'cadence'", metadata)
        self.assertIn("'dispatchId'", metadata)
        self.assertIn("'scheduled'=>true,'cadence'=>'2h','dispatchId'=>$dispatchId", due)
        for forbidden in ("token", "secret", "signature", "cronUrl", "headers", "payload"):
            self.assertNotIn(f"'{forbidden}'", metadata)

    def test_workflow_runs_after_p1_and_reuses_only_existing_secrets(self):
        self.assertIn("name: Metrics Ranking Experimental", WORKFLOW)
        self.assertIn("cron: '57 */2 * * *'", WORKFLOW)
        self.assertIn("workflow_dispatch:", WORKFLOW)
        self.assertIn("group: pass50-metrics-ranking-experimental", WORKFLOW)
        self.assertIn("cancel-in-progress: false", WORKFLOW)
        self.assertIn("timeout-minutes: 10", WORKFLOW)
        secret_names = set(re.findall(r"secrets\.([A-Z0-9_]+)", WORKFLOW))
        self.assertEqual(
            secret_names,
            {"PASS50_METRICS_CRON_URL", "PASS50_METRICS_CRON_SECRET"},
        )
        self.assertIn('*/metrics-cron.php)', WORKFLOW)
        self.assertIn('${CRON_URL%/metrics-cron.php}/metrics-ranking-cron.php', WORKFLOW)
        self.assertIn("dispatch_id=\"${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-ranking\"", WORKFLOW)

    def test_stale_readiness_dispatches_p1_without_dispatching_simulation(self):
        for reason in ("p1_not_observed", "p1_stale", "p1_future_timestamp"):
            self.assertRegex(WORKFLOW, rf'{reason}\).*refresh_p1=true')
        self.assertIn("steps.ranking.outputs.refresh_p1 == 'true'", WORKFLOW)
        self.assertIn('/actions/workflows/metrics-top50-2h.yml/dispatches', WORKFLOW)
        self.assertIn("steps.ranking.outputs.skipped == 'false'", WORKFLOW)
        self.assertIn("steps.ranking.outputs.run_uuid != ''", WORKFLOW)
        self.assertNotIn("steps.ranking.outputs.reason != ''", WORKFLOW)

    def test_workflow_uses_hmac_post_without_exposing_credentials(self):
        self.assertIn("jq -nc", WORKFLOW)
        self.assertIn("printf '%s\\n%s' \"$timestamp\" \"$body\"", WORKFLOW)
        self.assertIn('openssl dgst -sha256 -hmac "$CRON_SECRET" -r', WORKFLOW)
        self.assertIn("curl --fail-with-body --silent --show-error --max-time 55", WORKFLOW)
        self.assertIn('-H "X-P50-Signature: $signature"', WORKFLOW)
        self.assertIn('--data "$body"', WORKFLOW)
        self.assertNotIn("?signature", WORKFLOW.lower())
        ranking_call_end = WORKFLOW.index('"$RANKING_URL"')
        self.assertNotIn("$CRON_SECRET\"", WORKFLOW[ranking_call_end:])
        for field in ("dispatchId", "runUuid", "Algorithme", "Périodes", "Profils classables", "Scores écrits", "Durée"):
            self.assertIn(field, WORKFLOW)

    def test_no_public_state_or_publication_path_is_added(self):
        combined = CORE + ENDPOINT + WORKFLOW
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "data-publish.php",
            "p50_de_publish_profile",
            "p50_de_publish_score_pipeline",
        ):
            self.assertNotIn(forbidden, combined)
        self.assertNotIn("p50_mo_dispatch(", ENDPOINT)
        self.assertNotIn("p50_metrics_process_next_job(", ENDPOINT)


if __name__ == "__main__":
    unittest.main()
