import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/metrics-schema-core.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api/metrics-migrate.php").read_text(encoding="utf-8")
OBS = (ROOT / "api/metrics-observability-core.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
INTEGRATION = (ROOT / "tests/metrics_schema_integration.php").read_text(encoding="utf-8")

class MetricsSchemaContractTests(unittest.TestCase):
    def test_six_tables_and_indexes_exist(self):
        for table in ("p50_metric_schema_migrations","p50_metric_accounts","p50_metric_contents","p50_metric_captures","p50_metric_jobs","p50_metric_runs"):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {table}",CORE)
        for index in ("account_key","profile_platform","content_key","capture_key","job_idempotency","run_uuid"):
            self.assertIn(index,CORE)

    def test_required_primitives_exist(self):
        for name in ("ensure_schema","account_key","content_key","capture_key","upsert_account","upsert_content","record_capture","enqueue_job","start_run","finish_run","schema_status","backfill_legacy"):
            self.assertRegex(CORE,rf"function p50_metrics_{name}\(")

    def test_identity_priority_and_url_normalization(self):
        account=CORE[CORE.index("function p50_metrics_account_key"):CORE.index("function p50_metrics_content_key")]
        self.assertLess(account.index("platformAccountId"),account.index("normalize_handle"))
        self.assertLess(account.index("normalize_handle"),account.index("normalize_url"))
        for tracking in ("utm_","fbclid","gclid"):
            self.assertIn(tracking,CORE)

    def test_account_and_content_uniqueness(self):
        self.assertIn("UNIQUE KEY uq_p50_metric_account_profile_platform(profile_id,platform)",CORE)
        self.assertIn("UNIQUE KEY uq_p50_metric_content_key(content_key)",CORE)
        self.assertIn("Une page de profil ne peut pas être enregistrée comme contenu.",CORE)
        for content_type in ("video","post","reel","short","live","unknown"):
            self.assertIn(f"'{content_type}'",CORE)

    def test_capture_idempotency_excludes_run_uuid(self):
        body=CORE[CORE.index("function p50_metrics_capture_key"):CORE.index("function p50_metrics_assert_safe")]
        self.assertNotIn("runUuid",body)
        for part in ("accountId","contentId","platform","sourceType","observedAt","metrics"):
            self.assertIn(part,body)
        self.assertIn("INSERT IGNORE INTO p50_metric_captures",CORE)
        self.assertIn("'duplicate'=>!$created",CORE)

    def test_null_zero_validation_and_immutability(self):
        self.assertIn('function p50_metrics_utc_timezone', CORE)
        self.assertIn('function p50_metrics_now_utc', CORE)
        self.assertIn('function p50_metrics_parse_utc', CORE)
        self.assertIn("new DateTimeZone('+00:00')", CORE)
        self.assertIn('function p50_metrics_signature_value',CORE)
        self.assertIn("['type'=>'null']",CORE)
        self.assertIn("gettype($value)",CORE)
        self.assertIn("is_int($value)",CORE)
        self.assertIn(":negative",CORE)
        self.assertIn("'quarantined'",CORE)
        self.assertIn("CREATE TRIGGER p50_metric_captures_immutable",CORE)
        self.assertIn("Metric captures are immutable",CORE)

    def test_sensitive_payloads_are_rejected(self):
        self.assertRegex(CORE,r"token\|secret\|password\|passwd\|cookie\|authorization\|session")
        self.assertIn("Champ sensible interdit",CORE)
        self.assertNotIn("raw_payload LONGTEXT",CORE)

    def test_migration_is_locked_resumable_and_non_destructive(self):
        self.assertIn("GET_LOCK(?,10)",CORE)
        self.assertIn("RELEASE_LOCK(?)",CORE)
        self.assertIn("ON DUPLICATE KEY UPDATE schema_version",CORE)
        self.assertIn("status='applying'",CORE)
        self.assertIn("reprise possible",CORE)
        self.assertNotRegex(CORE,r"(?i)\b(DROP|TRUNCATE)\s+TABLE\b")

    def test_backfill_sources_and_provenance(self):
        for table in ("p50_social_links","p50_activity_events","p50_activity_metric_history"):
            self.assertIn(table,CORE)
        for field in ("sourceTable","sourceId","legacyUrlHash","originalDate","legacy_backfill_v1"):
            self.assertIn(field,CORE)
        self.assertIn("legacy_activity_event",CORE)
        self.assertIn("NOT EXISTS",CORE)

    def test_endpoint_is_protected_and_has_no_ranking_calls(self):
        self.assertIn("require_role($user,'owner','admin')",ENDPOINT)
        self.assertIn("require_method('GET','POST')",ENDPOINT)
        combined=CORE+ENDPOINT
        for forbidden in ("p50_de_publish_score_pipeline","p50_de_publish_profile","p50_de_15c_window","p50_de_compute_trend_score"):
            self.assertNotIn(forbidden,combined)
        self.assertNotRegex(combined,r"(?i)\b(INSERT|UPDATE)\s+(?:INTO\s+)?app_state\b")

    def test_observability_remains_read_only_and_exposes_canonical_schema(self):
        self.assertIn("'canonicalSchema'=>",OBS)
        for field in ("schemaVersion","migrationStatus","quarantinedCaptures","lastBackfillAt"):
            self.assertIn(field,OBS)
        self.assertIn("Schéma canonique",UI)
        self.assertNotIn("p50_metrics_ensure_schema",OBS)
        self.assertNotIn("p50_metrics_backfill_legacy",OBS)

    def test_integration_covers_lifecycle_and_unchanged_state(self):
        for fragment in ("Migration non rejouable","Compte dupliqué","Variantes URL de contenu","Page de profil acceptée","Doublon de capture","NULL et zéro confondus","Valeur négative","Provenance facultative","Secret accepté","Capture modifiable","Job non idempotent","Run non terminé","Deuxième backfill non idempotent","app_state modifié"):
            self.assertIn(fragment,INTEGRATION)

if __name__=="__main__":
    unittest.main()
