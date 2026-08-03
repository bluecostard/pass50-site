import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/metrics-orchestrator-core.php").read_text()
QUEUE = (ROOT / "api/metrics-queue-core.php").read_text()
CRON = (ROOT / "api/metrics-cron.php").read_text()
ADMIN = (ROOT / "api/metrics-orchestrator.php").read_text()
COLLECTORS = (ROOT / "api/metrics-collectors-core.php").read_text()
OBS = (ROOT / "api/metrics-observability-core.php").read_text()
UI = (ROOT / "data-engine-ui.js").read_text()
CONFIG = (ROOT / "api/config.example.php").read_text()


class MetricsOrchestratorV1Tests(unittest.TestCase):
    def test_cadences_priorities_limits_and_freshness(self):
        for value in ("seconds'=>900", "seconds'=>7200", "seconds'=>43200",
                      "priority'=>10", "priority'=>50", "priority'=>100",
                      "contentLimit'=>3", "contentLimit'=>5"):
            self.assertIn(value, CORE)
        for value in ("p0_min_freshness_minutes']??12", "p1_min_freshness_minutes']??90",
                      "p2_min_freshness_minutes']??600"):
            self.assertIn(value, CORE)

    def test_selection_is_server_side_and_verified(self):
        self.assertIn("p50_profile_registry", CORE)
        self.assertIn("r.alive=1", CORE)
        self.assertIn("s.status='verified'", CORE)
        self.assertIn("s.confidence>=?", CORE)
        self.assertIn("p50_mc_public_access", CORE)
        self.assertIn("p50_ranking_snapshots", CORE)
        self.assertIn("BETWEEN 1 AND ?", CORE)
        self.assertNotIn("localStorage", CORE)

    def test_live_source_is_recent_and_explicit(self):
        self.assertIn("p50_live_streams", CORE)
        self.assertIn("status='live'", CORE)
        self.assertIn("last_seen_at>=DATE_SUB", CORE)
        self.assertIn("'status'=>'unavailable'", CORE)

    def test_deterministic_job_identity_and_stable_observation(self):
        for value in ("P50_METRICS_ORCHESTRATOR_VERSION", "$cadence['key']", "$bucket['key']",
                      "$profileId", "$platform", "'observedAt'=>$bucket['observedAt']"):
            self.assertIn(value, CORE)
        self.assertNotIn("runUuid,$cadence['key'],$bucket", CORE)
        self.assertIn("(string)$payload['observedAt']", CORE)

    def test_claim_and_recovery(self):
        self.assertIn("SELECT GET_LOCK(?,2)", CORE)
        self.assertIn("ORDER BY priority ASC,scheduled_at ASC,id ASC LIMIT 1 FOR UPDATE", CORE)
        self.assertIn("random_bytes(32)", CORE)
        self.assertIn("WHERE id=? AND lock_token=? AND status='running'", CORE)
        self.assertIn("p50_metrics_recover_stale_jobs", CORE)
        self.assertIn("status='retry_wait'", CORE)
        self.assertIn("status='failed'", CORE)

    def test_retry_policy(self):
        self.assertIn("1=>5,2=>30,default=>120", CORE)
        self.assertIn("if($rateLimited)return 60", CORE)
        self.assertIn("p50_mo_transient_result", CORE)
        for status in ("configuration_missing", "authorization_required",
                       "unsupported_account_type", "unavailable_or_blocked"):
            self.assertIn(status, CORE)

    def test_scheduled_runs_are_linked_but_manual_contract_stays_compatible(self):
        self.assertIn("'jobUuid'=>$job['job_uuid']", CORE)
        self.assertIn("'triggerType'=>$cadence['trigger']", CORE)
        self.assertRegex(COLLECTORS, r"function p50_metrics_collect_profile\([^)]*array \$options=\[\]")
        self.assertIn("'triggerType'=>(string)($options['triggerType']??'manual')", COLLECTORS)

    def test_preview_is_read_only(self):
        dispatch = re.search(r"function p50_mo_dispatch.*?\n}\n", CORE, re.S).group(0)
        self.assertIn("if(!$preview)p50_metrics_recover_stale_jobs", dispatch)
        self.assertIn("if($preview)continue", dispatch)

    def test_cron_security(self):
        self.assertIn("REQUEST_METHOD']!=='POST'", CRON)
        self.assertIn("16384", CRON)
        self.assertIn("HTTP_X_P50_TIMESTAMP", CRON)
        self.assertIn("HTTP_X_P50_SIGNATURE", CRON)
        self.assertIn("p50_mo_verify_cron_signature", CRON)
        self.assertIn("hash_equals", CORE)
        self.assertIn('timestamp."\\n".$raw', CORE)
        self.assertIn("strlen($secret)<32", CORE)
        self.assertIn(">300", CORE)
        self.assertIn("['dispatch','work','queue']", CRON)
        for cadence in ("'p0'=>", "'p1'=>", "'p2'=>"):
            self.assertIn(cadence, CORE)

    def test_queue_contract_is_read_only_and_p1_scoped(self):
        self.assertIn("function p50_moq_snapshot", QUEUE)
        self.assertIn("priority=50", QUEUE)
        self.assertIn("'p1Remaining'", QUEUE)
        self.assertIn("'p1RetryWait'", QUEUE)
        self.assertIn("'p1WaitSeconds'", QUEUE)
        self.assertNotIn("last_error", QUEUE)
        self.assertNotIn("payload_json", QUEUE)
        self.assertIn("$response['queue']=p50_moq_snapshot($pdo)", CRON)
        for forbidden in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state", "REPLACE INTO app_state"):
            self.assertNotIn(forbidden, QUEUE)
            self.assertNotIn(forbidden, CRON)

    def test_admin_is_restricted_and_safe(self):
        self.assertIn("require_role($user,'owner','admin')", ADMIN)
        for action in ("preview", "enqueue", "work_one", "recover_stale"):
            self.assertIn(f"'{action}'", ADMIN)
        for forbidden in ("token", "secret", "url", "endpoint", "headers", "sql", "query"):
            self.assertIn(f"'{forbidden}'", ADMIN)

    def test_auth_exclusion_metadata_is_safe_without_weakening_secret_filter(self):
        self.assertIn("skippedAuthRequired", CORE)
        schema = (ROOT / "api/metrics-schema-core.php").read_text()
        self.assertIn("token|secret|password|passwd|cookie|authorization|session", schema)

    def test_observability_and_ui(self):
        self.assertIn("'metricsOrchestrator'=>$metricsOrchestrator", OBS)
        self.assertIn("automationObservedRecently", OBS)
        self.assertIn('metadata_json LIKE \'%\\"source\\":\\"cron_hmac\\"%\'', CORE)
        self.assertIn("p50_metrics_table_exists($pdo,'p50_metric_jobs')", CORE)
        self.assertIn("AUTOMATISATION DES MÉTRIQUES", UI)
        self.assertIn("INSTALLER LE SCHÉMA CANONIQUE", UI)
        self.assertIn("L’automatisation collecte les métriques mais ne modifie pas encore le classement public.", UI)
        for label in ("Prévisualiser", "Planifier un cycle", "Traiter une tâche",
                      "Récupérer les tâches bloquées"):
            self.assertIn(label, UI)

    def test_server_configuration_has_no_real_secret(self):
        for key in ("cron_secret", "orchestrator_enabled", "p0_max_profiles",
                    "p1_max_profiles", "p1_max_rank", "p2_max_profiles",
                    "priority_profile_ids", "p0_min_freshness_minutes",
                    "p1_min_freshness_minutes", "p2_min_freshness_minutes",
                    "worker_lock_timeout_minutes"):
            self.assertIn(key, CONFIG)
        self.assertIn("orchestrator_enabled", CONFIG)
        self.assertIn("PASS50_METRICS_ORCHESTRATOR_ENABLED", CONFIG)
        self.assertIn("'false'", CONFIG)

    def test_workflows_are_bounded_and_do_not_publish(self):
        expected = {
            "metrics-priority-15m.yml": ("*/15 * * * *", "timeout-minutes: 15", "p0"),
            "metrics-top50-2h.yml": ("7 */2 * * *", "timeout-minutes: 75", "p1"),
            "metrics-census-12h.yml": ("23 */12 * * *", "timeout-minutes: 120", "p2"),
        }
        for name, (schedule, timeout, cadence) in expected.items():
            text = (ROOT / ".github/workflows" / name).read_text()
            self.assertIn(schedule, text)
            self.assertIn("workflow_dispatch:", text)
            self.assertIn(timeout, text)
            self.assertIn("secrets.PASS50_METRICS_CRON_URL", text)
            self.assertIn("secrets.PASS50_METRICS_CRON_SECRET", text)
            self.assertIn("openssl dgst -sha256 -hmac", text)
            self.assertIn("remaining", text)
            self.assertRegex(text, r"seq 1 \d+")
            self.assertIn(f'cadence:"{cadence}"', text)
            for forbidden in ("data-publish.php", "p50_de_publish", "MYSQL_PASSWORD",
                              "YOUTUBE_API", "TIKTOK_ACCESS", "FACEBOOK_ACCESS"):
                self.assertNotIn(forbidden, text)

    def test_no_ranking_or_app_state_mutation(self):
        combined = CORE + QUEUE + CRON + ADMIN
        for forbidden in ("p50_de_publish_score_pipeline", "p50_de_publish_profile",
                          "p50_de_15c_window", "data-publish.php",
                          "UPDATE app_state", "INSERT INTO app_state",
                          "UPDATE p50_profile_registry SET score",
                          "UPDATE p50_ranking_snapshots"):
            self.assertNotIn(forbidden, combined)


if __name__ == "__main__":
    unittest.main()
