from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'metrics-public-baseline-core.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'metrics-public-baseline-cron.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'metrics-public-baseline-p1.yml').read_text(encoding='utf-8')
PERIOD = (ROOT / 'api' / 'metrics-ranking-publication-period-core.php').read_text(encoding='utf-8')


class MetricsPublicBaselineP1V1Tests(unittest.TestCase):
    def test_public_profiles_are_read_from_server_state(self):
        self.assertIn('p50_mrp_public_state($pdo)', CORE)
        self.assertIn("foreach(['2H','24H','48H','7J','15J']", CORE)
        self.assertIn('p50_mrp_public_rows', CORE)
        self.assertNotIn('localStorage', CORE)

    def test_jobs_share_the_canonical_p1_idempotency(self):
        self.assertIn("P50_METRICS_ORCHESTRATOR_VERSION,'p1',$bucket['key'],$profileId,$platform", CORE)
        self.assertIn("'priority'=>50", CORE)
        self.assertIn("'cadence'=>'p1'", CORE)
        self.assertIn("'reason'=>'public_baseline'", CORE)

    def test_verified_authorized_sources_and_freshness_are_preserved(self):
        self.assertIn("s.status='verified'", CORE)
        self.assertIn('s.confidence>=?', CORE)
        self.assertIn('p50_mc_public_access', CORE)
        self.assertIn("quality_status='usable'", CORE)
        self.assertIn("$cfg['fresh']['p1']", CORE)
        self.assertIn('skippedAuthRequired', CORE)
        self.assertIn('skippedUnsupported', CORE)

    def test_coverage_is_explained_by_platform_and_profile(self):
        for token in (
            'PUBLIC-BASELINE-P1-V1.1',
            'publicProfilesWithoutVerifiedSources',
            'eligibleLinksByPlatform',
            'selectedByPlatform',
            'jobsCreatedByPlatform',
            'duplicateJobsByPlatform',
            'skippedConfigurationByPlatform',
            'skippedAuthRequiredByPlatform',
            'skippedUnsupportedByPlatform',
        ):
            self.assertIn(token, CORE)
        self.assertIn('Liens non configurés par plateforme', WORKFLOW)
        self.assertIn('Sources retenues dans ce cycle', WORKFLOW)

    def test_endpoint_is_hmac_strict_and_bounded(self):
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn('HTTP_X_P50_TIMESTAMP', ENDPOINT)
        self.assertIn('HTTP_X_P50_SIGNATURE', ENDPOINT)
        self.assertIn('p50_mo_verify_cron_signature', ENDPOINT)
        self.assertIn("$keys!==['action','dispatchId']", ENDPOINT)
        self.assertIn("['probe','dispatch']", ENDPOINT)
        self.assertIn('P50_METRICS_PUBLIC_BASELINE_VERSION', ENDPOINT)

    def test_workflow_runs_before_regular_p1_and_dispatches_it_on_push(self):
        self.assertIn("cron: '2 */2 * * *'", WORKFLOW)
        self.assertIn('PUBLIC-BASELINE-P1-V1.1', WORKFLOW)
        self.assertIn('metrics-top50-2h.yml/dispatches', WORKFLOW)
        self.assertIn("github.event_name != 'schedule'", WORKFLOW)
        self.assertIn('pass50/metrics-public-baseline-p1', WORKFLOW)
        self.assertIn('publicStateWrites == 0', WORKFLOW)

    def test_exit_diagnostics_explain_remaining_public_losses(self):
        self.assertIn('PUBSIM-EXIT-DIAG-V1.0', PERIOD)
        self.assertIn('function p50_mrpa_exit_diagnostics', PERIOD)
        self.assertIn('missing_experimental_row', PERIOD)
        self.assertIn('exclusionReasons', PERIOD)
        self.assertIn("$result['exitDiagnostics']", PERIOD)

    def test_no_public_mutation(self):
        combined = CORE + ENDPOINT + PERIOD
        for token in ('INSERT INTO app_state', 'UPDATE app_state', 'DELETE FROM app_state', 'REPLACE INTO app_state'):
            self.assertNotIn(token, combined)


if __name__ == '__main__':
    unittest.main()
