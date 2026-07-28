import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api/metrics-migrate.php").read_text(encoding="utf-8")


class MetricsSchemaActivationTests(unittest.TestCase):
    def test_install_button_depends_on_migration_status(self):
        self.assertIn("canonical.migrationStatus==='applied'", UI)
        self.assertIn("INSTALLER LE SCHÉMA CANONIQUE", UI)
        self.assertIn("Schéma installé", UI)

    def test_explanation_is_explicit_and_non_publishing(self):
        self.assertIn(
            "Cette opération crée les tables métriques et importe les données existantes. "
            "Elle ne modifie ni les scores, ni les rangs, ni le classement public.",
            UI,
        )

    def test_dedicated_action_confirms_and_posts_bounded_migration(self):
        action = UI[UI.index("async function deInstallMetricsSchema"):UI.index("function deRenderMetricsDiagnostic")]
        self.assertIn("confirm(", action)
        self.assertIn("apiFetch('metrics-migrate.php'", action)
        self.assertRegex(action, r"method:'POST'")
        self.assertRegex(action, r"action:'migrate',limit:1000")

    def test_button_is_disabled_during_work_and_retryable(self):
        action = UI[UI.index("async function deInstallMetricsSchema"):UI.index("function deRenderMetricsDiagnostic")]
        self.assertIn("button.disabled=true", action)
        self.assertIn("Installation et import des données en cours…", action)
        self.assertIn("finally", action)
        self.assertIn("button.disabled=false", action)
        self.assertIn("Elle peut être relancée.", action)

    def test_success_reloads_and_verifies_diagnostic(self):
        action = UI[UI.index("async function deInstallMetricsSchema"):UI.index("function deRenderMetricsDiagnostic")]
        self.assertIn("DE.metricsDiagnostic=null", action)
        self.assertIn("await deLoadMetricsDiagnostic(true)", action)
        self.assertIn("migrationStatus!=='applied'", action)
        for field in ("accountsCreated", "contentsCreated", "capturesRecorded", "duplicatesSkipped", "quarantinedCount", "errors"):
            self.assertIn(field, action)

    def test_collection_controls_follow_schema_state(self):
        self.assertIn("Installe d’abord le schéma canonique.", UI)
        self.assertRegex(UI, r'class="btn de-collect-metrics"[^>]+schemaApplied')
        self.assertRegex(UI, r'id="deMetricProfile"[^>]+schemaApplied')

    def test_endpoint_security_and_safe_error(self):
        self.assertIn("require_role($user,'owner','admin')", ENDPOINT)
        self.assertIn("$limit=max(1,min(1000", ENDPOINT)
        self.assertIn("Migration métrique interrompue. Elle peut être relancée sans supprimer de données.", ENDPOINT)
        responses = "\n".join(line for line in ENDPOINT.splitlines() if "json_response(" in line)
        for sensitive in ("dsn", "password", "PDOException", "SELECT", "INSERT", "UPDATE"):
            self.assertNotIn(sensitive, responses)

    def test_no_ranking_or_publication_action(self):
        combined = UI[UI.index("async function deInstallMetricsSchema"):UI.index("function deRenderMetricsDiagnostic")] + ENDPOINT
        for forbidden in (
            "data-publish.php",
            "p50_de_publish_score_pipeline",
            "p50_de_publish_profile",
            "p50_de_compute_trend_score",
            "UPDATE app_state",
            "INSERT INTO app_state",
        ):
            self.assertNotIn(forbidden, combined)


if __name__ == "__main__":
    unittest.main()
