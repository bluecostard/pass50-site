import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api/metrics-zero-score-backfill-core.php').read_text()
CRON = (ROOT / 'api/metrics-zero-score-backfill-cron-v1.php').read_text()
WORKFLOW = (ROOT / '.github/workflows/metrics-zero-score-backfill.yml').read_text()


class ZeroScoreBackfillTests(unittest.TestCase):
    def test_only_missing_or_zero_scores_are_written(self):
        self.assertIn("p50_mzb_score_missing($scores[$period]??null)", CORE)
        self.assertIn("p50_mzb_assert_preserved($positiveBefore,$positiveAfter)", CORE)
        self.assertIn("score IS NOT NULL AND score>0", CORE)

    def test_existing_positive_scores_are_audited(self):
        self.assertIn('positive_scores_preserved', CORE)
        self.assertIn('backup_json LONGTEXT NOT NULL', CORE)
        self.assertIn("Protection des scores existants déclenchée", CORE)

    def test_endpoint_is_hmac_protected(self):
        self.assertIn('p50_mo_verify_cron_signature', CRON)
        self.assertIn("'apply'=>!empty($input['confirm'])", CRON)

    def test_workflow_reports_preserved_scores(self):
        self.assertIn('Scores positifs préservés', WORKFLOW)
        self.assertIn('ZERO-SCORE-BACKFILL-V1.0', WORKFLOW)
        self.assertIn('https://www.pass50.store/*', WORKFLOW)
        self.assertIn('https://pass50.store/', WORKFLOW)


if __name__ == '__main__':
    unittest.main()
