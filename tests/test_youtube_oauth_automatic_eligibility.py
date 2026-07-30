from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = (ROOT / 'api' / 'youtube-metrics-bridge-core.php').read_text(encoding='utf-8')
COLLECTORS = (ROOT / 'api' / 'metrics-collectors-core.php').read_text(encoding='utf-8')
ORCHESTRATOR = (ROOT / 'api' / 'metrics-orchestrator-core.php').read_text(encoding='utf-8')
MAP_ENDPOINT = (ROOT / 'api' / 'youtube-metrics-map.php').read_text(encoding='utf-8')


class YouTubeOauthAutomaticEligibilityTests(unittest.TestCase):
    def test_oauth_mapping_can_supply_the_official_source(self):
        self.assertIn('function p50ym_official_profile', BRIDGE)
        self.assertIn("$platform==='YouTube'", COLLECTORS)
        self.assertIn('p50ym_official_profile($pdo,$profileId)', COLLECTORS)
        self.assertIn('https://www.youtube.com/channel/', BRIDGE)
        self.assertIn("'confidence'=>99", BRIDGE)

    def test_orchestrator_includes_mapped_youtube_profiles(self):
        self.assertIn('p50_youtube_oauth_connections', ORCHESTRATOR)
        self.assertIn("'YouTube' platform", ORCHESTRATOR)
        self.assertIn("y.status='active'", ORCHESTRATOR)
        self.assertIn('BINARY y.profile_id=BINARY r.profile_id', ORCHESTRATOR)
        self.assertIn('p50_mo_unique_candidate_rows', ORCHESTRATOR)

    def test_mapping_queues_a_priority_collection_without_blocking_mapping(self):
        self.assertIn('function p50_mo_enqueue_profile', ORCHESTRATOR)
        self.assertIn("priorityOverride", ORCHESTRATOR)
        self.assertIn("reason", ORCHESTRATOR)
        self.assertIn("require __DIR__.'/metrics-orchestrator-core.php'", MAP_ENDPOINT)
        self.assertIn("p50_mo_enqueue_profile(db(),$profileId,'YouTube','p0'", MAP_ENDPOINT)
        self.assertIn("'collectionQueued'", MAP_ENDPOINT)
        self.assertIn("'collectionDeferred'", MAP_ENDPOINT)

    def test_no_public_ranking_write_is_introduced(self):
        combined = BRIDGE + COLLECTORS + ORCHESTRATOR + MAP_ENDPOINT
        self.assertNotIn('data-publish.php', combined)
        self.assertNotIn('UPDATE app_state SET', combined)
        self.assertNotIn('p50_de_publish_score_pipeline', combined)


if __name__ == '__main__':
    unittest.main()
