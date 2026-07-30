from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UI = (ROOT / 'live-experience-v4-1.js').read_text(encoding='utf-8')
RADAR = (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8')
DISMISS_UI = (ROOT / 'live-dismiss-ui-v1.js').read_text(encoding='utf-8')
DISMISS_API = (ROOT / 'api' / 'live-dismiss.php').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
PUBLIC = (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class LiveExperienceV41Tests(unittest.TestCase):
    def test_watch_link_keeps_native_new_tab_navigation(self):
        block = re.search(r"const watchLink=.*?\n  }", RADAR, re.S).group(0)
        self.assertIn("setTimeout(()=>{if(profileId)verifyProfile(profileId);},0)", block)
        self.assertNotIn('preventDefault', block)
        self.assertNotIn('stopImmediatePropagation', block)
        self.assertIn('target="_blank"', (ROOT / 'index.html').read_text(encoding='utf-8'))

    def test_live_badge_works_inside_influencer_sheet(self):
        self.assertIn("badge.closest?.('#profileBody')", RADAR)
        self.assertIn("document.querySelector('#profileBody h2')", RADAR)
        self.assertIn("event.target.closest?.('.badge.live-badge')", RADAR)
        self.assertIn('openExternal(live.url)', RADAR)
        self.assertIn("profileBadge.style.cursor='pointer'", DISMISS_UI)

    def test_live_list_receives_share_button(self):
        self.assertIn("button.className='btn p50-share-live'", UI)
        self.assertIn("button.textContent='PARTAGER LE LIVE'", UI)
        self.assertIn('data-live-share-native', UI)
        self.assertIn('data-live-share-whatsapp', UI)
        self.assertIn('data-live-share-copy', UI)

    def test_false_positive_can_be_dismissed_by_admin(self):
        self.assertIn("button.className='btn small danger p50-live-dismiss'", DISMISS_UI)
        self.assertIn("apiFetch('live-dismiss.php'", DISMISS_UI)
        self.assertIn("require_role($user,'owner','admin')", DISMISS_API)
        self.assertIn('p50_live_dismissals', STORAGE)
        self.assertIn('p50_live_v4_is_dismissed', STORAGE)
        self.assertIn("'manually_dismissed'", DISMISS_API + STORAGE)

    def test_share_card_is_short_and_visual(self):
        for label in ('EN DIRECT', 'REGARDE MAINTENANT', 'PASS50'):
            self.assertIn(label, UI)
        self.assertIn('buildShareCanvas', UI)
        self.assertIn('navigator.canShare', UI)
        self.assertIn('https://wa.me/?text=', UI)
        self.assertNotIn('Description détaillée', UI)

    def test_modules_are_loaded_and_cached(self):
        self.assertIn("live-experience-v4-1.js?v=1.1", PUBLIC)
        self.assertIn("live-dismiss-ui-v1.js?v=1.0", PUBLIC)
        self.assertIn("live-dismiss-ui-v1.js?v=1.0", SW)
        self.assertIn("share-center-v1.js?v=1.0", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")


if __name__ == '__main__':
    unittest.main()
