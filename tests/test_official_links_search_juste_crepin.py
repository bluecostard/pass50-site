from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
ALPHA = (ROOT / "admin-profile-alphabetical-v1.js").read_text(encoding="utf-8")
PRESERVE = (ROOT / "admin-fi-edit-preserve-v1.js").read_text(encoding="utf-8")
PROTECT = (ROOT / "official-links-protection-v4.js").read_text(encoding="utf-8")
ENGINE = (ROOT / "api" / "data-engine-core.php").read_text(encoding="utf-8")


class OfficialLinksSearchJusteCrepinTests(unittest.TestCase):
    def test_search_uses_all_profiles_not_the_public_ranking(self):
        self.assertIn("function p50AllProfiles()", V9)
        self.assertIn("function p50LinksSearchHaystack(p)", V9)
        self.assertIn("p50AllProfiles().filter(p=>!q||p50LinksSearchHaystack(p).includes(q))", V9)
        self.assertIn("p?.knownAlias", V9)
        self.assertIn("Juste Crépin Gondo", V9)
        self.assertIn("function allOfficialLinkProfiles()", PROTECT)
        self.assertIn("p50AllProfiles", PROTECT)
        self.assertNotIn("all.map(p50v9LinkCard).join('')", PROTECT)
        self.assertNotIn("ranking().slice(0,30).map(p50v9LinkCard)", ALPHA)
        self.assertNotIn("ranking().slice(0,30).map(p50v9LinkCard)", PROTECT)

    def test_search_and_select_do_not_freeze_the_editor(self):
        self.assertIn("active!==search", PRESERVE)
        self.assertNotIn("if(search&&String(search.value||'').trim())return true", PRESERVE)
        self.assertNotIn("active.matches('input,textarea,select')", PRESERVE)
        self.assertIn("p50v9RenderLinks();", V9.split("if(e.target.id==='linksProfileSearch')", 1)[1].split("if(count)", 1)[0])
        self.assertIn("linksProfileSelect", ALPHA)
        self.assertNotIn("appendChunk", ALPHA)

    def test_hydrate_does_not_overwrite_confirmed_or_direct_links(self):
        hydrate = V9.split("async function p50HydrateOfficialLinks", 1)[1].split("function p50LocalLinkAudit", 1)[0]
        self.assertIn("if(p.officialLinkLocks?.[platform])return;", hydrate)
        self.assertIn("if(['owner_verified','manual_verified'].includes(status)&&current)return;", hydrate)
        self.assertIn("if(current&&p50v9IsDirectPlatformLink(platform,current))return;", hydrate)
        self.assertNotIn("save();render();", hydrate)

    def test_youtube_channel_tabs_normalize_to_the_profile(self):
        self.assertIn("featured|videos|streams|live|about|community|playlists|channels", V9)
        self.assertIn("featured|videos|streams|live|about|community|playlists|channels", ENGINE)
        self.assertIn("function p50v9IsDirectPlatformLink", V9)


if __name__ == "__main__":
    unittest.main()
