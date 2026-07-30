from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9 = ROOT / "v9-tools.js"
SW = ROOT / "sw.js"
APP_CONFIG = ROOT / "app-config.js"
TEST = ROOT / "tests" / "test_canonical_lionel_yasmine_v24.py"


def replace_required(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old not in text:
        raise RuntimeError(f"Valeur attendue absente pour {label}: {old}")
    return text.replace(old, new)


profiles = json.loads(CENSUS.read_text(encoding="utf-8"))
if not isinstance(profiles, list):
    raise RuntimeError("Le recensement canonique doit être une liste JSON.")

additions = [
    {
        "id": "census-lionel-pcs",
        "name": "Lionel PCS",
        "known_alias": "Lionel Akobé / @lionel_pcs",
        "entity_type": "Personne",
        "zone": "CI",
        "category": "Football / Pronostics sportifs / Divertissement",
        "census_status": "Recensé confirmé — intégration prioritaire",
        "verification_priority": "P0",
        "eligible": False,
        "classable": False,
        "official_socials": {
            "TikTok": "https://www.tiktok.com/@lionel_pcs",
            "Instagram": "https://www.instagram.com/lionel_pcs/",
            "Facebook": "https://www.facebook.com/lionelpcs225",
        },
        "source": {
            "publisher": "Linktree public — LIONEL PCS",
            "date": "2026-07-30",
            "url": "https://linktr.ee/lionelpcsofficiel",
        },
        "notes": "Ajout approuvé PASS50. Identité et comptes directs recoupés depuis le hub public lionelpcsofficiel. Profil non classable jusqu’à validation des métriques récentes.",
        "curated_social_sources": {
            "TikTok": {
                "url": "https://www.tiktok.com/@lionel_pcs",
                "source_name": "Linktree public — LIONEL PCS",
                "source_url": "https://linktr.ee/lionelpcsofficiel",
                "confidence": 96,
            },
            "Instagram": {
                "url": "https://www.instagram.com/lionel_pcs/",
                "source_name": "Linktree public — LIONEL PCS",
                "source_url": "https://linktr.ee/lionelpcsofficiel",
                "confidence": 96,
            },
            "Facebook": {
                "url": "https://www.facebook.com/lionelpcs225",
                "source_name": "Linktree public — LIONEL PCS",
                "source_url": "https://linktr.ee/lionelpcsofficiel",
                "confidence": 96,
            },
        },
    },
    {
        "id": "census-yasmine-fofana",
        "name": "Yasmine Fofana",
        "known_alias": "Afrofoodie / @afrofoodie",
        "entity_type": "Personne",
        "zone": "CI",
        "category": "Gastronomie / Tourisme culinaire / Lifestyle",
        "census_status": "Recensé confirmé — intégration prioritaire",
        "verification_priority": "P1",
        "eligible": False,
        "classable": False,
        "official_socials": {
            "Instagram": "https://www.instagram.com/afrofoodie/",
            "Facebook": "https://www.facebook.com/Afrofoodie.ci/",
            "YouTube": "https://www.youtube.com/@YasmineAfrofoodie",
            "LinkedIn": "https://www.linkedin.com/in/yasminefofana/",
            "X": "https://x.com/afro_foodie",
        },
        "source": {
            "publisher": "Afrofoodie — site officiel de Yasmine Fofana",
            "date": "2026-07-30",
            "url": "https://afrofoodie.ci/",
        },
        "notes": "Ajout approuvé PASS50. Yasmine Fofana est connue publiquement sous le nom Afrofoodie. Identité et réseaux recoupés entre son site officiel et sa fiche IGCAT. Profil non classable jusqu’à validation des métriques récentes.",
        "curated_social_sources": {
            "Instagram": {
                "url": "https://www.instagram.com/afrofoodie/",
                "source_name": "IGCAT — Yasmine Fofana",
                "source_url": "https://igcat.org/fr/team/yasmine-fofana-cote-divoire/",
                "confidence": 96,
            },
            "Facebook": {
                "url": "https://www.facebook.com/Afrofoodie.ci/",
                "source_name": "IGCAT — Yasmine Fofana",
                "source_url": "https://igcat.org/fr/team/yasmine-fofana-cote-divoire/",
                "confidence": 96,
            },
            "YouTube": {
                "url": "https://www.youtube.com/@YasmineAfrofoodie",
                "source_name": "IGCAT — Yasmine Fofana",
                "source_url": "https://igcat.org/fr/team/yasmine-fofana-cote-divoire/",
                "confidence": 96,
            },
            "LinkedIn": {
                "url": "https://www.linkedin.com/in/yasminefofana/",
                "source_name": "IGCAT — Yasmine Fofana",
                "source_url": "https://igcat.org/fr/team/yasmine-fofana-cote-divoire/",
                "confidence": 96,
            },
            "X": {
                "url": "https://x.com/afro_foodie",
                "source_name": "IGCAT — Yasmine Fofana",
                "source_url": "https://igcat.org/fr/team/yasmine-fofana-cote-divoire/",
                "confidence": 96,
            },
        },
    },
]

for addition in additions:
    matches = [index for index, item in enumerate(profiles) if isinstance(item, dict) and item.get("id") == addition["id"]]
    if matches:
        profiles[matches[0]] = addition
        for duplicate_index in reversed(matches[1:]):
            del profiles[duplicate_index]
    else:
        profiles.append(addition)

for addition in additions:
    count = sum(1 for item in profiles if isinstance(item, dict) and item.get("id") == addition["id"])
    if count != 1:
        raise RuntimeError(f"Le profil {addition['id']} doit être présent exactement une fois.")

CENSUS.write_text(json.dumps(profiles, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

v9 = V9.read_text(encoding="utf-8")
v9 = replace_required(v9, "pass50_nouveaux_candidats_90_v19.json?v=22.6", "pass50_nouveaux_candidats_90_v19.json?v=22.7", "version du recensement")
v9 = replace_required(v9, "const CENSUS_VERSION='90-v22';", "const CENSUS_VERSION='92-v24';", "version logique du recensement")
V9.write_text(v9, encoding="utf-8")

sw = SW.read_text(encoding="utf-8")
sw = re.sub(r"const CACHE='[^']+';", "const CACHE='pass50-v45-metrics-control-center-census-92-v24';", sw, count=1)
sw = replace_required(sw, "pass50_nouveaux_candidats_90_v19.json?v=22.6", "pass50_nouveaux_candidats_90_v19.json?v=22.7", "cache du recensement")
sw = replace_required(sw, "public-copy-fixes.js?v=1.0", "public-copy-fixes.js?v=1.1", "cache du chargeur public")
SW.write_text(sw, encoding="utf-8")

app_config = APP_CONFIG.read_text(encoding="utf-8")
app_config = replace_required(app_config, "public-copy-fixes.js?v=1.0", "public-copy-fixes.js?v=1.1", "chargeur public")
app_config = replace_required(app_config, "script.dataset.pass50PublicCopy = '1.0';", "script.dataset.pass50PublicCopy = '1.1';", "marqueur du chargeur public")
APP_CONFIG.write_text(app_config, encoding="utf-8")

TEST.write_text(
    """import json\nimport unittest\nfrom pathlib import Path\n\nROOT = Path(__file__).resolve().parents[1]\nCENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))\nV9 = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')\nSW = (ROOT / 'sw.js').read_text(encoding='utf-8')\nAPP_CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')\n\n\nclass CanonicalLionelYasmineV24Tests(unittest.TestCase):\n    def profile(self, profile_id):\n        matches = [item for item in CENSUS if item.get('id') == profile_id]\n        self.assertEqual(len(matches), 1, profile_id)\n        return matches[0]\n\n    def test_lionel_is_in_canonical_census(self):\n        profile = self.profile('census-lionel-pcs')\n        self.assertEqual(profile['name'], 'Lionel PCS')\n        self.assertFalse(profile['eligible'])\n        self.assertFalse(profile['classable'])\n        self.assertGreaterEqual(len(profile['official_socials']), 3)\n\n    def test_yasmine_is_in_canonical_census(self):\n        profile = self.profile('census-yasmine-fofana')\n        self.assertEqual(profile['name'], 'Yasmine Fofana')\n        self.assertIn('Afrofoodie', profile['known_alias'])\n        self.assertFalse(profile['eligible'])\n        self.assertFalse(profile['classable'])\n        self.assertGreaterEqual(len(profile['official_socials']), 5)\n\n    def test_browser_fetches_the_new_census_revision(self):\n        self.assertIn('pass50_nouveaux_candidats_90_v19.json?v=22.7', V9)\n        self.assertIn("const CENSUS_VERSION='92-v24'", V9)\n        self.assertIn('pass50_nouveaux_candidats_90_v19.json?v=22.7', SW)\n        self.assertIn('pass50-v45-metrics-control-center-census-92-v24', SW)\n\n    def test_public_loader_is_cache_busted(self):\n        self.assertIn('public-copy-fixes.js?v=1.1', APP_CONFIG)\n        self.assertIn("pass50PublicCopy = '1.1'", APP_CONFIG)\n        self.assertIn('public-copy-fixes.js?v=1.1', SW)\n\n\nif __name__ == '__main__':\n    unittest.main()\n""",
    encoding="utf-8",
)

print(json.dumps({"ok": True, "profiles": [item["id"] for item in additions], "censusCount": len(profiles)}, ensure_ascii=False))
