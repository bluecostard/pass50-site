from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = (ROOT / 'coules-share-simple-v1.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class CoulesShareSimpleV1Tests(unittest.TestCase):
    def test_duplicate_names_and_percentages_are_removed_from_panel(self):
        self.assertNotIn('Mon vote :', MODULE)
        self.assertNotIn('selected?.name', MODULE)
        self.assertNotIn('opponent?.name', MODULE)
        self.assertNotIn('percentagesAvailable', MODULE)

    def test_image_format_choices_are_removed(self):
        self.assertNotIn('Image portrait', MODULE)
        self.assertNotIn('Image carrée', MODULE)
        self.assertNotIn('voteShareGenerateImage', MODULE)
        self.assertNotIn('voteShareGenerateSquare', MODULE)

    def test_only_essential_actions_remain(self):
        self.assertIn('id="voteShareNative">Partager</button>', MODULE)
        self.assertIn('id="voteShareWhatsapp">WhatsApp</button>', MODULE)
        self.assertIn('id="voteShareCopy">Copier</button>', MODULE)
        self.assertNotIn('id="voteShareDownload"', MODULE)

    def test_single_media_share_path(self):
        self.assertIn('prepareVoteShareFile', MODULE)
        self.assertIn('generateVoteShareVideo', MODULE)
        self.assertIn("window.PASS50_COULES_SHARE_SIMPLE_VERSION='1.2'", MODULE)
        self.assertIn('un seul', MODULE.lower())

    def test_audio_option_stays_available(self):
        self.assertIn('Audio facultatif · 15 s max', MODULE)
        self.assertIn('id="voteShareRecord"', MODULE)
        self.assertIn('Préparer la vidéo', MODULE)

    def test_module_is_loaded_and_cached(self):
        self.assertIn("coules-share-simple-v1.js?v=1.2", CONFIG)
        self.assertIn("coules-share-simple-v1.js?v=1.2", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")

    def test_no_public_state_write_path(self):
        for forbidden in ('INSERT INTO app_state', 'UPDATE app_state', 'DELETE FROM app_state', 'REPLACE INTO app_state'):
            self.assertNotIn(forbidden, MODULE)


if __name__ == '__main__':
    unittest.main()
