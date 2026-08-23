from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
LEGACY = (ROOT / 'api' / 'live-status.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')
CLIENT = (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8')


class LiveRadarRuntimeV4Tests(unittest.TestCase):
    def test_legacy_endpoint_defaults_to_cache_only_status(self):
        self.assertIn("mode'] = 'status'", LEGACY)
        self.assertIn("live-status-cache-core.php", LEGACY)
        self.assertIn("p50_live_status_cache_respond", LEGACY)
        self.assertIn("require __DIR__ . '/live-status-v4.php'", LEGACY)

    def test_legacy_endpoint_cannot_overwrite_v4_results(self):
        self.assertIn("require __DIR__ . '/live-status-v4.php'", LEGACY)
        self.assertNotIn('Radar LIVE V2', LEGACY)
        self.assertNotIn('p50_live_scan_tiktok', LEGACY)

    def test_mysql_session_and_server_clock_are_utc(self):
        self.assertIn("SET time_zone = '+00:00'", ENDPOINT)
        self.assertIn("'serverNow'=>gmdate(DATE_ATOM)", ENDPOINT)

    def test_browser_uses_trust_seconds_instead_of_three_minutes(self):
        self.assertIn('installLiveNormalizerV4', CLIENT)
        self.assertIn('DEFAULT_TRUST_SECONDS', CLIENT)
        self.assertIn('trustSeconds(String(item.platform', CLIENT)
        self.assertIn('confirmation', CLIENT.lower())
        self.assertNotIn("10*60_000:3*60_000", CLIENT)
        index = (ROOT / 'index.html').read_text(encoding='utf-8')
        v9 = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')
        self.assertNotIn("10*60_000:3*60_000", index)
        self.assertNotIn("10*60_000:3*60_000", v9)
        self.assertIn('TikTok:0', CLIENT)
        self.assertIn('detectedLiveStays', (ROOT / 'live-trust-gate-v1.js').read_text(encoding='utf-8'))
        self.assertIn("key==='tiktok'||key==='youtube'", index)
        self.assertIn("key==='tiktok'||key==='youtube'", v9)
        self.assertNotIn('preservedRadarLives', index)
        self.assertIn('mergeLiveStreams(radarLivesKeep,cloud.liveStreams)', index)
        self.assertIn('function mergeLiveStreams(', index)
        self.assertNotIn('db.liveStreams=Array.isArray(cloud.liveStreams)?cloud.liveStreams:[]', index)
        self.assertIn('await refreshLiveStatus();', index)
        self.assertIn('if(!window.__pass50LiveNormalizerV4)normalizeLiveStreams=', v9)

    def test_radar_boots_immediately_and_keeps_lives_without_cloud(self):
        self.assertIn('setTimeout(runQuick,0)', CLIENT)
        self.assertNotIn('setTimeout(runQuick,3000)', CLIENT)
        self.assertIn("document.readyState==='loading'", CLIENT)
        self.assertIn('function bootRadar()', CLIENT)
        index = (ROOT / 'index.html').read_text(encoding='utf-8')
        self.assertNotIn('if(CLOUD.ready){if(changed){render();syncFollowContextAlerts();}', index)

    def test_reasonable_ionos_future_skew_is_repaired(self):
        self.assertIn('futureSkew>5*60_000', CLIENT)
        self.assertIn('futureSkew<=6*60*60_000', CLIENT)
        self.assertIn('item.lastConfirmedAt=fixed', CLIENT)

    def test_full_sweep_distinguishes_active_lives_from_cycle_confirmations(self):
        self.assertIn('activeAutomaticConfirmed', CLIENT)
        self.assertIn('ACTIF', CLIENT)
        self.assertIn('CONFIRMATION', CLIENT)


if __name__ == '__main__':
    unittest.main()
