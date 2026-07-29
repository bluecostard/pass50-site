from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = (ROOT / 'connector-sections-v1.js').read_text(encoding='utf-8')
LOADER = (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class ConnectorSectionsV1Tests(unittest.TestCase):
    def test_dom_writes_are_idempotent(self):
        self.assertIn("if(node&&node.textContent!==value)node.textContent=value", SCRIPT)
        self.assertIn("if(node&&node.getAttribute(name)!==value)node.setAttribute(name,value)", SCRIPT)
        self.assertIn("if(panel.hidden!==effective)panel.hidden=effective", SCRIPT)

    def test_observer_is_frame_throttled(self):
        self.assertIn('requestAnimationFrame(scan)', SCRIPT)
        self.assertIn('if(scheduled)return', SCRIPT)
        self.assertNotIn('queueMicrotask(scan)', SCRIPT)

    def test_safe_version_is_forced(self):
        self.assertIn('connector-sections-v1.js?v=1.1', LOADER)
        self.assertIn('connector-sections-v1.js?v=1.1', SW)
        self.assertNotIn('connector-sections-v1.js?v=1.0', LOADER + SW)

    def test_future_connectors_remain_supported(self):
        self.assertIn('PASS50_CONNECTOR_SECTIONS', SCRIPT)
        self.assertIn('register(section,key', SCRIPT)
        self.assertIn('p50YoutubeOauthSection', SCRIPT)
        self.assertIn('p50MetaOauthSection', SCRIPT)


if __name__ == '__main__':
    unittest.main()
