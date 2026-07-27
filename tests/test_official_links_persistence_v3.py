from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api" / "official-links-bulk.php").read_text(encoding="utf-8")
SOCIAL = (ROOT / "api" / "social-links.php").read_text(encoding="utf-8")
CLIENT = (ROOT / "official-links-persistence-v3.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class OfficialLinksPersistenceV3Tests(unittest.TestCase):
    def test_profile_links_are_saved_in_one_transaction(self):
        self.assertIn("$pdo->beginTransaction()", ENDPOINT)
        self.assertIn("p50_de_load_public_state_for_update()", ENDPOINT)
        self.assertIn("p50_de_save_public_state($state", ENDPOINT)
        self.assertIn("$pdo->commit()", ENDPOINT)

    def test_blank_fields_do_not_delete_existing_links(self):
        self.assertIn("if($url==='')continue", ENDPOINT)
        self.assertIn("Un champ vide ne supprime plus jamais", ENDPOINT)
        self.assertNotIn("action:'delete'", CLIENT)

    def test_client_uses_one_bulk_request_instead_of_parallel_writes(self):
        self.assertIn("official-links-bulk.php", CLIENT)
        self.assertIn("action:'save_profile'", CLIENT)
        self.assertNotIn("Promise.allSettled", CLIENT)
        self.assertNotIn("social-links.php", CLIENT)

    def test_browser_draft_is_kept_before_server_write(self):
        draft_position = CLIENT.index("keepDraft(profileItem,links,confirmed)")
        request_position = CLIENT.index("official-links-bulk.php")
        self.assertLess(draft_position, request_position)
        self.assertIn("persistLocal()", CLIENT)

    def test_integrity_sync_restores_previous_work(self):
        self.assertIn("action:'integrity_sync'", CLIENT)
        self.assertIn("checkedAt", CLIENT)
        self.assertIn("p50_social_link_evidence", ENDPOINT)
        self.assertIn("p50_social_link_audit", ENDPOINT)
        self.assertIn("restoredCount", ENDPOINT)

    def test_reading_links_no_longer_mutates_public_state(self):
        get_block, post_block = SOCIAL.split("require_method('POST');", 1)
        self.assertNotIn("p50_de_publish_profile", get_block)
        self.assertIn("p50_de_publish_profile", post_block)

    def test_old_parallel_backups_are_disabled(self):
        self.assertIn("pass50_v227_confirmed_links_backup", CONFIG)
        self.assertIn("pass50_v226_nolimit_links_seeded", CONFIG)
        self.assertIn("official-links-persistence-v3.js?v=3.1", CONFIG)

    def test_cache_keeps_the_persistence_module(self):
        self.assertIn("const CACHE='pass50-v", SW)
        self.assertIn("official-links-persistence-v3.js?v=3.1", SW)


if __name__ == "__main__":
    unittest.main()
