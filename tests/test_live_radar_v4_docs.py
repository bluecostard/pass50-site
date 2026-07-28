from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
NOTES = (ROOT / 'LIVE_RADAR_V4_NOTES.md').read_text(encoding='utf-8')


class LiveRadarV4DocsTests(unittest.TestCase):
    def test_docs_cover_the_full_chain(self):
        for text in ('lien officiel vérifié', 'sondes multi-plateformes', 'stockage durable', 'publication des seuls directs confirmés'):
            self.assertIn(text, NOTES)
        for state in ('live', 'probable', 'replay', 'offline', 'unknown'):
            self.assertIn(f'`{state}`', NOTES)


if __name__ == '__main__':
    unittest.main()
