from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UI = (ROOT / 'live-experience-v4-1.js').read_text(encoding='utf-8')
PUBLIC = (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class LiveExperienceV41Tests(unittest.TestCase):
    def test_watch_link_opens_new_tab_before_old_document_handler(self):
        self.assertIn("window.addEventListener('click'", UI)
        self.assertIn("const watch=target.closest('.live-watch-link')", UI)
        self.assertIn("event.stopImmediatePropagation()", UI)
        self.assertIn("window.open(url,'_blank')", UI)
        self.assertIn("anchor.target='_blank'", UI)
        self.assertIn('backgroundVerify(live)', UI)

    def test_live_badge_is_clickable(self):
        self.assertIn("document.querySelectorAll('.badge.live-badge')", UI)
        self.assertIn("badge.dataset.liveClickable='1'", UI)
        self.assertIn("role','link'", UI)
        self.assertIn("target.closest('.badge.live-badge[data-live-clickable=\"1\"]')", UI)
        self.assertIn("event.key==='Enter'||event.key===' '", UI)

    def test_live_list_receives_share_button(self):
        self.assertIn("button.className='btn p50-share-live'", UI)
        self.assertIn("button.textContent='PARTAGER LE LIVE'", UI)
        self.assertIn("data-live-share-native", UI)
        self.assertIn("data-live-share-whatsapp", UI)
        self.assertIn("data-live-share-copy", UI)

    def test_share_card_is_short_and_visual(self):
        for label in ('EN DIRECT', 'REGARDE MAINTENANT', 'PASS50'):
            self.assertIn(label, UI)
        self.assertIn('buildShareCanvas', UI)
        self.assertIn('navigator.canShare', UI)
        self.assertIn('https://wa.me/?text=', UI)
        self.assertNotIn('textarea name=', UI)
        self.assertNotIn('Description détaillée', UI)

    def test_module_is_loaded_and_cached(self):
        self.assertIn("live-experience-v4-1.js?v=1.0", PUBLIC)
        self.assertIn("live-experience-v4-1.js?v=1.0", SW)
        self.assertIn("pass50-v47-live-radar-v4-1", SW)


if __name__ == '__main__':
    unittest.main()
