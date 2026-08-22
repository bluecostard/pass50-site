import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
ENNEMI = (ROOT / 'profile-ennemi-des-djandjou.js').read_text(encoding='utf-8')
ISOUCH = (ROOT / 'profile-isouch.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
INDEX = (ROOT / 'index.html').read_text(encoding='utf-8')
TOOLS = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')
STATUS = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')
CLIENT = (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8')


class EnnemiAndIsouchLiveRadarTests(unittest.TestCase):
    def test_ennemi_keeps_own_tiktok_and_official_fi(self):
        self.assertIn("const PROFILE_ID='ennemi-des-djandjou'", ENNEMI)
        self.assertIn('https://www.tiktok.com/@ennemidesdjandjou', ENNEMI)
        self.assertIn("handle:'@ennemidesdjandjou'", ENNEMI)
        self.assertIn("name:'Ennemi des Djandjou'", ENNEMI)
        self.assertIn("realName:'Ennemi des Djandjou'", ENNEMI)
        self.assertIn("occupation:'Créateur de lives TikTok, débats et société'", ENNEMI)
        self.assertIn("category:'Lives / Débats / Société'", ENNEMI)
        self.assertNotIn("category:'Influenceur'", ENNEMI)
        self.assertNotIn("category:'Société / Divertissement'", ENNEMI)
        self.assertIn("https://www.facebook.com/profile.php?id=61582125968813", ENNEMI)
        self.assertIn("verificationPriority:'P0'", ENNEMI)
        self.assertNotIn("handle:'@prince_du_pays'", ENNEMI)
        self.assertNotIn('Isouch, dit Ennemi', ENNEMI)
        self.assertNotIn('ensureManualLive', ENNEMI)
        self.assertNotIn("eligible:false", ENNEMI.split("function applyProfile", 1)[1])

    def test_isouch_official_identity_uses_full_name(self):
        self.assertIn("const PROFILE_ID='census-isouch'", ISOUCH)
        self.assertIn('https://www.tiktok.com/@prince_du_pays', ISOUCH)
        self.assertIn("handle:'@prince_du_pays'", ISOUCH)
        self.assertIn("name:'Isouch'", ISOUCH)
        self.assertIn("realName:'Nongbé Gethsémané Isaac'", ISOUCH)
        self.assertIn("occupation:'Chroniqueur télé et créateur de contenus, tournée nationale de valorisation des territoires ivoiriens'", ISOUCH)
        self.assertIn("category:'Culture / Télévision / Promotion des territoires'", ISOUCH)
        self.assertNotIn("category:'Influenceur'", ISOUCH)
        self.assertNotIn("category:'Société / Divertissement'", ISOUCH)
        self.assertIn('lavenir.ci', ISOUCH)
        self.assertIn('fratmat.info', ISOUCH)
        self.assertIn("verificationPriority:'P0'", ISOUCH)
        self.assertNotIn("handle:'@ennemidesdjandjou'", ISOUCH)
        self.assertNotIn("const PROFILE_ID='ennemi-des-djandjou'", ISOUCH)
        self.assertNotIn('ensureManualLive', ISOUCH)
        self.assertNotIn("eligible:false", ISOUCH.split("function applyProfile", 1)[1])

    def test_radar_maps_each_handle_to_its_own_fiche(self):
        self.assertIn("'ennemi-des-djandjou|tiktok'=>'https://www.tiktok.com/@ennemidesdjandjou'", SOURCE)
        self.assertIn("'ennemi-des-djandjou|facebook'=>'https://www.facebook.com/profile.php?id=61582125968813'", SOURCE)
        self.assertIn("'census-isouch|tiktok'=>'https://www.tiktok.com/@prince_du_pays'", SOURCE)
        self.assertIn("'handle'=>'ennemidesdjandjou'", SOURCE)
        self.assertIn("'handle'=>'prince_du_pays'", SOURCE)
        self.assertNotIn("'ennemi-des-djandjou|tiktok'=>'https://www.tiktok.com/@prince_du_pays'", SOURCE)
        self.assertNotIn("'prince_du_pays'=>'ennemi-des-djandjou'", SOURCE)
        self.assertIn("'prince_du_pays'=>'census-isouch'", SOURCE)
        self.assertIn('ennemi-des-djandjou', CORE)
        self.assertIn('census-isouch', CORE)

    def test_github_webcast_keeps_handles_apart(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get('ennemidesdjandjou'), 'ennemi-des-djandjou')
        self.assertEqual(handles.get('prince_du_pays'), 'census-isouch')

    def test_fi_shows_official_name_not_generic_influencer(self):
        self.assertIn("p.name||p.realName||p.handle||''", INDEX)
        self.assertIn("p.name||p.realName||p.handle||''", TOOLS)
        self.assertIn('p.occupation', INDEX)
        self.assertIn('p.occupation', TOOLS)
        self.assertIn("p?.name||p?.realName||l.profileName", INDEX)
        self.assertNotIn("p.name||'Influenceur'", INDEX)
        self.assertNotIn("p.name||'Influenceur'", TOOLS)

    def test_status_reads_cache_so_radar_does_not_500(self):
        self.assertIn("if($mode==='status'&&!$force)", STATUS)
        self.assertLess(STATUS.find("p50_live_status_cache_respond()"), STATUS.find('p50_de_sync_registry_from_state()'))
        self.assertIn("const ENDPOINT='./api/live-status.php'", CLIENT)

    def test_loaders_are_cache_busted(self):
        self.assertIn('./profile-ennemi-des-djandjou.js?v=1.3', CONFIG)
        self.assertIn('./profile-isouch.js?v=1.1', CONFIG)
        self.assertIn('./profile-ennemi-des-djandjou.js?v=1.3', SW)
        self.assertIn('./profile-isouch.js?v=1.1', SW)


if __name__ == '__main__':
    unittest.main()
