import json
import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
CENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))

PROFILES = (
    {
        'id': 'census-le-grand-bicongo',
        'name': 'Le grand Bicongo',
        'handle': 'legrandbicongo',
        'overlay': 'profile-le-grand-bicongo.js',
        'occupation': 'Créateur TikTok ; manager de Chocolat Show',
    },
    {
        'id': 'census-chocolat-show-officiel',
        'name': 'Chocolat show officiel',
        'handle': 'chocolat.show.officiel',
        'overlay': 'profile-chocolat-show-officiel.js',
        'occupation': 'Comédien / humoriste',
    },
    {
        'id': 'census-la-legende',
        'name': 'La légende',
        'handle': 'lalegende777',
        'overlay': 'profile-la-legende.js',
        'occupation': 'Humoriste',
    },
)


class LeGrandBicongoChocolatLaLegendeLiveRadarTests(unittest.TestCase):
    def test_overlays_keep_identity_and_skip_manual_live(self):
        p0 = SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0]
        for spec in PROFILES:
            overlay = (ROOT / spec['overlay']).read_text(encoding='utf-8')
            self.assertIn(f"const PROFILE_ID='{spec['id']}'", overlay)
            self.assertIn(f"https://www.tiktok.com/@{spec['handle']}", overlay)
            self.assertIn("verificationPriority:'P0'", overlay)
            self.assertIn('birthDate:null', overlay)
            self.assertIn('birthYear:null', overlay)
            self.assertIn(f"occupation:'{spec['occupation']}'", overlay)
            self.assertNotIn('ensureManualLive', overlay)
            self.assertNotIn("source:'manual'", overlay)
            self.assertNotIn('influenceur', overlay.lower())
            apply = overlay.split('function applyProfile', 1)[1]
            self.assertNotIn('eligible:false', apply)
            self.assertNotIn('classable:false', apply)
            self.assertIn(f"'{spec['id']}'", p0)

    def test_census_handles_stay_official(self):
        for spec in PROFILES:
            profile = next(item for item in CENSUS if item.get('id') == spec['id'])
            self.assertEqual(profile['name'], spec['name'])
            self.assertEqual(
                profile['official_socials']['TikTok'],
                f"https://www.tiktok.com/@{spec['handle']}",
            )

    def test_radar_p0_forced_probe_and_canonicals(self):
        for spec in PROFILES:
            tiktok = f"https://www.tiktok.com/@{spec['handle']}"
            self.assertIn(f"'{spec['id']}|tiktok'=>'{tiktok}'", SOURCE)
            self.assertIn(f"'handle'=>'{spec['handle']}'", SOURCE)
            self.assertIn(f"'{spec['handle']}'=>'{spec['id']}'", SOURCE)
            self.assertIn(f"'{spec['id']}'", CORE)
            self.assertIn(spec['handle'], CORE)
            self.assertIn(spec['name'], STORAGE)

    def test_cache_bust_loaders(self):
        for spec in PROFILES:
            tag = f"./{spec['overlay']}?v=1.0"
            self.assertIn(tag, CONFIG)
            self.assertIn(tag, SW)

    def test_github_webcast_includes_handles(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        for spec in PROFILES:
            self.assertEqual(handles.get(spec['handle']), spec['id'])


if __name__ == '__main__':
    unittest.main()
