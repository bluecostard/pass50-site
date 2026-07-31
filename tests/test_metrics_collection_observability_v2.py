from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
DIAG = (ROOT / 'api' / 'metrics-cron-diagnostics-core.php').read_text(encoding='utf-8')
CRON = (ROOT / 'api' / 'metrics-cron.php').read_text(encoding='utf-8')
P1 = (ROOT / '.github' / 'workflows' / 'metrics-top50-2h.yml').read_text(encoding='utf-8')
BASELINE = (ROOT / 'api' / 'metrics-public-baseline-core.php').read_text(encoding='utf-8')
SIM_ENDPOINT = (ROOT / 'api' / 'metrics-ranking-publication-simulate-cron.php').read_text(encoding='utf-8')
DEPLOYMENT = (ROOT / '.github' / 'workflows' / 'metrics-publication-period-deployment.yml').read_text(encoding='utf-8')


class MetricsCollectionObservabilityV2Tests(unittest.TestCase):
    def test_worker_diagnostic_is_bounded_and_sanitized(self):
        self.assertIn("WORK-DIAG-V1.0", DIAG)
        self.assertIn('p50_mcd_error_code', DIAG)
        self.assertIn('capturesRecorded', DIAG)
        self.assertIn('duplicatesSkipped', DIAG)
        self.assertIn('quarantined', DIAG)
        self.assertIn('requestsAttempted', DIAG)
        self.assertIn('errorCodes', DIAG)
        self.assertNotIn("'errors'=>", DIAG)
        self.assertNotIn("'lastError'=>", DIAG)

    def test_cron_exposes_only_the_sanitized_diagnostic(self):
        self.assertIn("require __DIR__.'/metrics-cron-diagnostics-core.php'", CRON)
        self.assertIn("'diagnostic'=>p50_mcd_work($pdo,$work)", CRON)
        self.assertIn("$response['diagnosticsVersion']=P50_METRICS_WORK_DIAGNOSTICS_VERSION", CRON)
        self.assertNotIn("'result'=>$work['result']", CRON)

    def test_p1_archives_capture_quality_and_platform_breakdown(self):
        for token in (
            'WORK-DIAG-V1.0', 'p1-work-diagnostics.jsonl', 'capturesRecorded',
            'duplicatesSkipped', 'quarantined', 'byPlatform', 'Captures nouvelles / doublons / quarantaine',
        ):
            self.assertIn(token, P1)
        self.assertIn('.diagnosticsVersion == "WORK-DIAG-V1.0"', P1)
        self.assertIn('metrics-p1-result.json', P1)

    def test_public_baseline_explains_configuration_gaps(self):
        for token in (
            'PUBLIC-BASELINE-P1-V1.1', 'publicProfilesWithoutVerifiedSources',
            'eligibleLinksByPlatform', 'selectedByPlatform', 'skippedConfigurationByPlatform',
        ):
            self.assertIn(token, BASELINE)

    def test_exit_diagnostic_contract_must_be_deployed(self):
        self.assertIn("'exitDiagnosticsContract'=>P50_MRPA_EXIT_DIAGNOSTICS_VERSION", SIM_ENDPOINT)
        self.assertIn('.exitDiagnosticsContract == "PUBSIM-EXIT-DIAG-V1.0"', DEPLOYMENT)
        self.assertIn('PUBSIM-EXIT-DIAG-V1.0', DEPLOYMENT)

    def test_no_public_state_write(self):
        combined = DIAG + CRON + BASELINE + SIM_ENDPOINT
        for token in ('INSERT INTO app_state', 'UPDATE app_state', 'DELETE FROM app_state', 'REPLACE INTO app_state'):
            self.assertNotIn(token, combined)


if __name__ == '__main__':
    unittest.main()
