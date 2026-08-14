import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / '.github/workflows/content-freshness-5m.yml').read_text(encoding='utf-8')
CRON = (ROOT / 'api/content-freshness-cron-v4.php').read_text(encoding='utf-8')


class ContentFreshnessV41ResilienceTests(unittest.TestCase):
    def test_server_cycle_is_bounded(self):
        self.assertIn("set_time_limit(95);", CRON)
        self.assertIn("'maxIterations'=>10", CRON)
        self.assertIn("'timeBudgetMs'=>70000", CRON)
        self.assertIn("'resilience'=>'V4.1'", CRON)

    def test_client_cycle_is_bounded_and_retry_limited(self):
        self.assertIn('--max-time 85', WORKFLOW)
        self.assertIn('for attempt in 1 2; do', WORKFLOW)
        self.assertNotIn('for attempt in $(seq 1 5); do', WORKFLOW)

    def test_dispatch_id_is_unique_per_cycle(self):
        self.assertIn('fresh-v41-c${cycle}-a${attempt}', WORKFLOW)

    def test_failed_cycle_does_not_abort_tour(self):
        self.assertIn('La tournée continue.', WORKFLOW)
        self.assertIn('degraded_cycles=$((degraded_cycles+1))', WORKFLOW)
        self.assertIn('if [ "$completed_cycles" -eq 0 ]; then', WORKFLOW)

    def test_watchdog_and_progress_artifact_exist(self):
        self.assertIn('progress.ndjson', WORKFLOW)
        self.assertIn('aucune progression réussie depuis plus de 15 minutes', WORKFLOW)
        self.assertIn('cancel-in-progress: true', WORKFLOW)


if __name__ == '__main__':
    unittest.main()
