import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = ROOT / 'pass50_nouveaux_candidats_90_v19.json'
V9 = ROOT / 'v9-tools.js'
INDEX = ROOT / 'index.html'
SW = ROOT / 'sw.js'
TEST = ROOT / 'tests/test_observateur_ebene_canonical_profile.py'
SCRIPT = ROOT / 'scripts/one_shot_add_observateur_ebene.py'
TRIGGER = ROOT / '.pass50-integrate-observateur-ebene-v1'

CANDIDATE = {
    'id': 'census-observateur-ebene',
    'name': 'Observateur Ébène',
    'known_alias': 'Florent Amany / Kouakou Amani Florent / Observateur / @observateur_ebene',
    'entity_type': 'Personne',
    'zone': 'BOTH',
    'category': 'Humour / Acteur / Créateur digital / Influence / Divertissement',
    'birth_date': '1989-07-04',
    'census_status': 'Recensé confirmé — compte TikTok fourni par le propriétaire PASS50',
    'verification_priority': 'P0',
    'eligible': False,
    'classable': False,
    'official_socials': {
        'TikTok': 'https://www.tiktok.com/@observateur_ebene',
        'Instagram': 'https://www.instagram.com/observateur_ebene',
        'Snapchat': 'https://www.snapchat.com/add/obs_ebene'
    },
    'source': {
        'publisher': 'Compte TikTok direct fourni par le propriétaire PASS50',
        'date': '2026-08-14',
        'url': 'https://www.tiktok.com/@observateur_ebene'
    },
    'source_secondary': {
        'publisher': 'Site officiel Observateur Ébène',
        'url': 'https://observateurebene.com/'
    },
    'source_tertiary': {
        'publisher': 'Paul Digital',
        'url': 'https://paul-digital.com/portfolio/observateur-ebene/'
    },
    'notes': 'Ajout approuvé PASS50 le 14 août 2026. Observateur Ébène, également identifié publiquement comme Florent Amany / Kouakou Amani Florent, est un humoriste, acteur et créateur digital ivoirien. Date de naissance recoupée : 4 juillet 1989. Le TikTok direct @observateur_ebene est fourni par le propriétaire PASS50. Son site officiel confirme son identité publique et son activité, tandis que Paul Digital confirme notamment Instagram @observateur_ebene. Profil non classable jusqu’à validation des métriques par le pipeline PASS50.',
    'curated_social_sources': {
        'TikTok': {
            'url': 'https://www.tiktok.com/@observateur_ebene',
            'source_name': 'Compte TikTok direct fourni par le propriétaire PASS50',
            'source_url': 'https://www.tiktok.com/@observateur_ebene',
            'confidence': 100
        },
        'Instagram': {
            'url': 'https://www.instagram.com/observateur_ebene',
            'source_name': 'Paul Digital',
            'source_url': 'https://paul-digital.com/portfolio/observateur-ebene/',
            'confidence': 96
        },
        'Snapchat': {
            'url': 'https://www.snapchat.com/add/obs_ebene',
            'source_name': 'Site officiel Observateur Ébène',
            'source_url': 'https://observateurebene.com/',
            'confidence': 98
        }
    },
    'curated_facts': {
        'identity': {
            'value': 'Florent Amany / Kouakou Amani Florent, alias Observateur Ébène.',
            'source_name': 'Site officiel Observateur Ébène / Abidjan.net',
            'source_url': 'https://observateurebene.com/',
            'confidence': 96
        },
        'birth_date': {
            'value': '4 juillet 1989',
            'source_name': 'Famous Birthdays / Afrique-sur7',
            'source_url': 'https://fr.famousbirthdays.com/people/florent.html',
            'confidence': 94
        },
        'occupation': {
            'value': 'Humoriste, acteur et créateur digital ivoirien.',
            'source_name': 'Site officiel Observateur Ébène',
            'source_url': 'https://observateurebene.com/',
            'confidence': 99
        },
        'audience_signal': {
            'value': 'Présence publique à plus d’un million d’abonnés sur Instagram et large audience sur TikTok et Snapchat.',
            'source_name': 'Site officiel Observateur Ébène / Paul Digital',
            'source_url': 'https://observateurebene.com/',
            'confidence': 92
        }
    },
    'research_queries': [
        '"observateur_ebene" TikTok Instagram officiel',
        '"Observateur Ébène" YouTube Facebook officiel',
        '"Florent Amany" réseaux sociaux officiels'
    ]
}


def upsert():
    census = json.loads(CENSUS.read_text(encoding='utf-8'))
    keys = ('census-observateur-ebene', 'observateur_ebene', 'observateur ébène', 'observateur ebene')
    matches = []
    for item in census:
        blob = ' '.join([
            str(item.get('id', '')),
            str(item.get('name', '')),
            str(item.get('known_alias', '')),
            json.dumps(item.get('official_socials', {}), ensure_ascii=False)
        ]).lower()
        if any(key in blob for key in keys):
            matches.append(item)
    if len(matches) > 1:
        raise RuntimeError('Plusieurs entrées Observateur Ébène potentielles détectées.')
    if matches:
        census[census.index(matches[0])] = CANDIDATE
    else:
        census.append(CANDIDATE)
    CENSUS.write_text(json.dumps(census, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def replace_once(path, old, new):
    text = path.read_text(encoding='utf-8')
    if new in text:
        return
    if text.count(old) != 1:
        raise RuntimeError(f'Marqueur attendu non unique dans {path}: {old}')
    path.write_text(text.replace(old, new), encoding='utf-8')


def versions():
    replace_once(V9, "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.11';", "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.12';")
    replace_once(V9, "const CENSUS_VERSION='97-v28';", "const CENSUS_VERSION='98-v29';")
    replace_once(INDEX, './v9-tools.js?v=15.9', './v9-tools.js?v=15.10')
    replace_once(SW, './v9-tools.js?v=15.9', './v9-tools.js?v=15.10')
    replace_once(SW, './pass50_nouveaux_candidats_90_v19.json?v=22.11', './pass50_nouveaux_candidats_90_v19.json?v=22.12')
    for path in (ROOT / 'tests').glob('test_*.py'):
        text = path.read_text(encoding='utf-8')
        new = text.replace('pass50_nouveaux_candidats_90_v19.json?v=22.11', 'pass50_nouveaux_candidats_90_v19.json?v=22.12')
        new = new.replace("CENSUS_VERSION='97-v28'", "CENSUS_VERSION='98-v29'")
        new = new.replace('v9-tools.js?v=15.9', 'v9-tools.js?v=15.10')
        new = new.replace('"15.9"', '"15.10"')
        if new != text:
            path.write_text(new, encoding='utf-8')


def write_test():
    TEST.write_text('''import json\nimport unittest\nfrom pathlib import Path\n\nROOT=Path(__file__).resolve().parents[1]\nCENSUS=json.loads((ROOT/'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))\nV9=(ROOT/'v9-tools.js').read_text(encoding='utf-8')\n\nclass ObservateurEbeneCanonicalProfileTests(unittest.TestCase):\n    def profile(self):\n        matches=[x for x in CENSUS if x.get('id')=='census-observateur-ebene']\n        self.assertEqual(len(matches),1)\n        return matches[0]\n\n    def test_profile_identity_and_status(self):\n        p=self.profile()\n        self.assertEqual(p['name'],'Observateur Ébène')\n        self.assertEqual(p['zone'],'BOTH')\n        self.assertEqual(p['birth_date'],'1989-07-04')\n        self.assertFalse(p['eligible'])\n        self.assertFalse(p['classable'])\n        self.assertEqual(p['verification_priority'],'P0')\n\n    def test_official_socials(self):\n        p=self.profile()\n        self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@observateur_ebene')\n        self.assertEqual(p['official_socials']['Instagram'],'https://www.instagram.com/observateur_ebene')\n        self.assertEqual(p['official_socials']['Snapchat'],'https://www.snapchat.com/add/obs_ebene')\n\n    def test_revision(self):\n        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.12",V9)\n        self.assertIn("CENSUS_VERSION='98-v29'",V9)\n\nif __name__=='__main__': unittest.main()\n''', encoding='utf-8')


def cleanup():
    for path in (SCRIPT, TRIGGER):
        if path.exists():
            path.unlink()


if __name__ == '__main__':
    upsert()
    versions()
    write_test()
    cleanup()
