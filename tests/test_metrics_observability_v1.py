import pathlib
import re
import shutil
import subprocess
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/metrics-diagnostic.php").read_text(encoding="utf-8")
CORE = (ROOT / "api/metrics-observability-core.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
CSS = (ROOT / "data-engine-ui.css").read_text(encoding="utf-8")


class MetricsObservabilitySecurityTests(unittest.TestCase):
    def test_endpoint_is_get_only_and_owner_admin_only(self):
        self.assertIn("require_method('GET')", ENDPOINT)
        self.assertRegex(ENDPOINT, r"\$user\s*=\s*auth_user\(\)")
        self.assertIn("require_role($user,'owner','admin')", ENDPOINT)

    def test_diagnostic_does_not_publish_or_recalculate(self):
        combined = ENDPOINT + CORE
        self.assertNotIn("p50_de_publish_score_pipeline", combined)
        self.assertNotIn("p50_de_publish_profile", combined)
        self.assertNotIn("p50_de_compute_trend_score", combined)
        self.assertNotIn("p50_de_ensure_schema", combined)

    def test_diagnostic_contains_no_mutating_sql(self):
        sql_calls = re.findall(
            r"(?:query|prepare)\(\s*([\"'])(.*?)\1",
            CORE,
            flags=re.IGNORECASE | re.DOTALL,
        )
        self.assertGreater(len(sql_calls), 5)
        for _, sql in sql_calls:
            normalized = re.sub(r"\s+", " ", sql).strip().upper()
            self.assertRegex(normalized, r"^(SELECT|WITH)\b")
            self.assertNotRegex(
                normalized,
                r"\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\b",
            )

    def test_app_state_is_read_only(self):
        self.assertIn("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1", CORE)
        self.assertNotRegex(CORE, r"(?i)\bUPDATE\s+app_state\b")
        self.assertNotRegex(CORE, r"(?i)\bINSERT\s+INTO\s+app_state\b")

    def test_error_details_are_limited_and_redacted(self):
        self.assertIn("ORDER BY started_at DESC LIMIT 20", CORE)
        self.assertIn("WHERE status='failed'", CORE)
        self.assertIn("function p50_obs_recent_metric_failures(PDO $pdo, int $limit=20)", CORE)
        self.assertIn("p50_obs_recent_metric_failures($pdo,20)", CORE)
        self.assertIn("$metricsOrchestrator['failedJobs']=$failedJobs", CORE)
        self.assertIn("Bearer [redacted]", CORE)
        self.assertIn("[email]", CORE)
        self.assertIn("[url]", CORE)


class MetricsObservabilityContractTests(unittest.TestCase):
    def test_all_requested_table_volumes_are_counted(self):
        for table in (
            "p50_collection_runs",
            "p50_activity_events",
            "p50_activity_metric_history",
            "p50_ranking_snapshots",
            "p50_profile_registry",
            "p50_social_links",
        ):
            self.assertRegex(CORE, rf"SELECT COUNT\(\*\) FROM {table}\b")

    def test_freshness_dates_and_age_units_are_returned(self):
        for field in (
            "collection_started",
            "collection_finished",
            "collection_success",
            "metric_capture",
            "activity_event",
            "ranking_capture",
            "pipeline_publication",
        ):
            self.assertIn(field, CORE)
        for unit in ("'minutes'", "'hours'", "'days'"):
            self.assertIn(unit, CORE)

    def test_freshness_windows_are_complete(self):
        for bucket in (
            "under2Hours",
            "from2To24Hours",
            "from24To48Hours",
            "from2To7Days",
            "over7Days",
        ):
            self.assertIn(bucket, CORE)
        for boundary in (120, 1440, 2880, 10080):
            self.assertIn(str(boundary), CORE)

    def test_non_classable_reasons_and_thresholds_are_explicit(self):
        for reason in (
            "insufficientConfidence",
            "insufficientCoverage",
            "fewerThanSixCriteria",
            "noRecentMetrics",
        ):
            self.assertIn(reason, CORE)
        self.assertIn("$confidence>=65&&$coverage>=60&&$criteria>=6", CORE)

    def test_platform_diagnostic_has_requested_counters(self):
        for field in (
            "uniqueEvents",
            "metricCaptures",
            "usableMetrics",
            "activeMetrics",
            "coveredProfiles",
            "lastCollectedAt",
            "platformsWithoutData",
        ):
            self.assertIn(field, CORE)

    def test_pipeline_changes_come_from_public_metadata_without_write(self):
        for field in (
            "scoresChanged",
            "ranksChanged",
            "recalculatedProfiles",
            "lastAtomicPublicationAt",
        ):
            self.assertIn(field, CORE)

    @unittest.skipUnless(shutil.which("php"), "PHP CLI indisponible dans cet environnement")
    def test_age_calculation_executes_in_php(self):
        php = subprocess.run(
            [
                "php",
                "-r",
                (
                    f"require {str(ROOT / 'api/metrics-observability-core.php')!r};"
                    "$v=p50_obs_age(gmdate('Y-m-d H:i:s',time()-90061));"
                    "echo $v['minutes'].'|'.$v['hours'].'|'.$v['days'];"
                ),
            ],
            check=True,
            capture_output=True,
            text=True,
        )
        minutes, hours, days = php.stdout.strip().split("|")
        self.assertGreaterEqual(int(minutes), 1501)
        self.assertAlmostEqual(float(hours), 25.02, delta=0.05)
        self.assertAlmostEqual(float(days), 1.04, delta=0.02)


class MetricsObservabilityAdminTests(unittest.TestCase):
    def test_admin_has_metrics_diagnostic_section(self):
        self.assertIn("['metricsdiag','Diagnostic métriques']", UI)
        self.assertIn("metrics-diagnostic.php", UI)
        self.assertIn("DIAGNOSTIC MÉTRIQUES", UI)
        self.assertIn("Lecture seule", UI)

    def test_admin_displays_required_indicators(self):
        for label in (
            "Événements uniques",
            "Captures métriques",
            "Métriques actives",
            "Profils mesurables",
            "Profils classables",
            "Scores modifiés",
            "Rangs modifiés",
            "Couverture par plateforme",
            "Dernières erreurs",
            "Pourquoi le classement reste statique",
            "Tâches échouées de l’orchestrateur",
            "Erreur sécurisée",
        ):
            self.assertIn(label, UI)

    def test_admin_refresh_is_get_only(self):
        diagnostic_call = re.search(
            r"async function deLoadMetricsDiagnostic\(.*?\n\s*}",
            UI,
            flags=re.DOTALL,
        )
        self.assertIsNotNone(diagnostic_call)
        self.assertIn("apiFetch('metrics-diagnostic.php')", diagnostic_call.group(0))
        self.assertNotIn("method:'POST'", diagnostic_call.group(0))

    def test_admin_styles_are_scoped(self):
        self.assertIn(".de-observability-shell", CSS)
        self.assertIn(".de-observability-table", CSS)

    def test_no_ranking_mutation_is_added_to_diagnostic_path(self):
        start = UI.index("async function deLoadMetricsDiagnostic")
        end = UI.index("function deApplyVerifiedBirthsFromHub", start)
        section = UI[start:end]
        self.assertNotIn("save()", section)
        self.assertNotIn("syncCloudState", section)
        self.assertNotIn("data-publish.php", section)
        self.assertNotIn("p50_de_publish", section)


if __name__ == "__main__":
    unittest.main()
