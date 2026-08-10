import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = ROOT / 'pass50_nouveaux_candidats_90_v19.json'
V9 = ROOT / 'v9-tools.js'
INDEX = ROOT / 'index.html'
SW = ROOT / 'sw.js'
TEST = ROOT / 'tests/test_awao_canonical_profile.py'
SCRIPT = ROOT / 'scripts/one_shot_add_awao.py'
TRIGGER = ROOT / '.pass50-integrate-awao-v1'

CANDIDATE = {
    'id': 'census-awao-officiel',
    'name': 'Awao',
    'known_alias': 'Awao_officiel / @awao_officiel / @influamenta1',
    'entity_type': 'Personne',
    'zone': 'CI',
    'category': 'Actrice / Web-humour / Influence / Lifestyle / Divertissement',
    'census_status': 'Recensé confirmé — profil fourni par le propriétaire PASS50',
    'verification_priority': 'P0',
    'eligible': False,
    'classable': False,
    'official_socials': {
        'Facebook': 'https://www.facebook.com/awao_officiel',
        'Instagram': 'https://www.instagram.com/awao_officiel',
        'TikTok': 'https://www.tiktok.com/@influamenta1'
    },
    'source': {
        'publisher': 'Capture du profil Facebook vérifié fournie par le propriétaire PASS50',
        'date': '2026-08-10',
        'url': 'https://www.facebook.com/awao_officiel'
    },
    'source_secondary': {
        'publisher': 'Hafi — Top TikTok Côte d’Ivoire',
        'date': '2026-07-20',
        'url': 'https://hafi.pro/top/most-followed-tiktok/ivory-coast'
    },
    'source_tertiary': {
        'publisher': 'TikTok Awards Côte d’Ivoire — liste publique de candidats',
        'url': 'https://it.scribd.com/document/862368430/TIKTOK-AWARDS-CO-TE-D-IVOIRE'
    },
    'notes': 'Ajout approuvé PASS50 le 10 août 2026. La capture fournie montre une page Facebook vérifiée Awao_officiel, 897 k abonnés, la catégorie Acteur(ice), la localisation Abidjan et l’identifiant Instagram awao_officiel. Hafi rattache le compte TikTok @influamenta1 au nom Awao_officiel et à la Côte d’Ivoire. Profil non classable jusqu’à validation des métriques par le pipeline PASS50.',
    'curated_social_sources': {
        'Facebook': {
            'url': 'https://www.facebook.com/awao_officiel',
            'source_name': 'Capture du profil Facebook vérifié fournie par le propriétaire PASS50',
            'source_url': 'https://www.facebook.com/awao_officiel',
            'confidence': 100
        },
        'Instagram': {
            'url': 'https://www.instagram.com/awao_officiel',
            'source_name': 'Identifiant Instagram affiché sur le profil Facebook fourni',
            'source_url': 'https://www.facebook.com/awao_officiel',
            'confidence': 98
        },
        'TikTok': {
            'url': 'https://www.tiktok.com/@influamenta1',
            'source_name': 'Hafi — Top TikTok Côte d’Ivoire',
            'source_url': 'https://hafi.pro/top/most-followed-tiktok/ivory-coast',
            'confidence': 96
        }
    },
    'curated_facts': {
        'facebook_snapshot': {
            'value': '897 k abonnés, page vérifiée, 1,1 k publications, catégorie Acteur(ice), localisation Abidjan.',
            'source_name': 'Capture du profil fournie par le propriétaire PASS50',
            'source_url': 'https://www.facebook.com/awao_officiel',
            'confidence': 100
        },
        'tiktok_snapshot': {
            'value': 'Environ 2,8 M abonnés TikTok sur @influamenta1 au relevé Hafi du 20 juillet 2026.',
            'source_name': 'Hafi',
            'source_url': 'https://hafi.pro/top/most-followed-tiktok/ivory-coast',
            'confidence': 94
        },
        'web_humour_signal': {
            'value': 'Awao_officiel apparaît dans une liste publique TikTok Awards Côte d’Ivoire en catégorie web-humoriste.',
            'source_name': 'TikTok Awards Côte d’Ivoire — liste publique de candidats',
            'source_url': 'https://it.scribd.com/document/862368430/TIKTOK-AWARDS-CO-TE-D-IVOIRE',
            'confidence': 82
        }
    },
    'research_queries': [
        '"awao_officiel" Facebook Instagram Côte d’Ivoire',
        '"influamenta1" Awao officiel TikTok',
        '"Awao_officiel" vidéos récentes vues abonnés'
    ]
}


def upsert():
    census = json.loads(CENSUS.read_text(encoding='utf-8'))
    keys = {'census-awao-officiel', 'awao', 'awaoofficiel', 'influamenta1'}
    matches = []
    for item in census:
        blob = ' '.join([
            str(item.get('id', '')),
            str(item.get('name', '')),
            str(item.get('known_alias', '')),
            json.dumps(item.get('official_socials', {}), ensure_ascii=False)
        ]).lower().replace('_', '').replace('-', '').replace(' ', '')
        if any(k.replace('_','').replace('-','') in blob for k in keys):
            matches.append(item)
    if len(matches) > 1:
        raise RuntimeError('Plusieurs entrées Awao potentielles détectées.')
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
    replace_once(V9, "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.10';", "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.11';")
    replace_once(V9, "const CENSUS_VERSION='96-v27';", "const CENSUS_VERSION='97-v28';")
    replace_once(INDEX, './v9-tools.js?v=15.8', './v9-tools.js?v=15.9')
    replace_once(SW, './v9-tools.js?v=15.8', './v9-tools.js?v=15.9')
    replace_once(SW, './pass50_nouveaux_candidats_90_v19.json?v=22.10', './pass50_nouveaux_candidats_90_v19.json?v=22.11')
    for path in (ROOT / 'tests').glob('test_*.py'):
        text = path.read_text(encoding='utf-8')
        new = text.replace('pass50_nouveaux_candidats_90_v19.json?v=22.10', 'pass50_nouveaux_candidats_90_v19.json?v=22.11')
        new = new.replace("CENSUS_VERSION='96-v27'", "CENSUS_VERSION='97-v28'")
        new = new.replace('v9-tools.js?v=15.8', 'v9-tools.js?v=15.9')
        new = new.replace('"15.8"', '"15.9"')
        if new != text:
            path.write_text(new, encoding='utf-8')


def write_test():
    TEST.write_text('''import json\nimport re\nimport unittest\nfrom pathlib import Path\n\nROOT=Path(__file__).resolve().parents[1]\nCENSUS=ROOT/'pass50_nouveaux_candidats_90_v19.json'\nV9=ROOT/'v9-tools.js'\nINDEX=ROOT/'index.html'\nSW=ROOT/'sw.js'\n\nclass AwaoCanonicalProfileTests(unittest.TestCase):\n    @classmethod\n    def setUpClass(cls):\n        cls.census=json.loads(CENSUS.read_text(encoding='utf-8'))\n        cls.v9=V9.read_text(encoding='utf-8')\n        cls.index=INDEX.read_text(encoding='utf-8')\n        cls.sw=SW.read_text(encoding='utf-8')\n\n    def profile(self):\n        return next(x for x in self.census if x.get('id')=='census-awao-officiel')\n\n    def test_unique(self):\n        matches=[x for x in self.census if x.get('id')=='census-awao-officiel' or x.get('name')=='Awao' or 'influamenta1' in str(x.get('official_socials',{}))]\n        self.assertEqual(len(matches),1)\n\n    def test_identity_and_status(self):\n        p=self.profile()\n        self.assertEqual(p['zone'],'CI')\n        self.assertEqual(p['verification_priority'],'P0')\n        self.assertFalse(p['eligible'])\n        self.assertFalse(p['classable'])\n        self.assertIn('Actrice',p['category'])\n\n    def test_socials(self):\n        p=self.profile()\n        self.assertEqual(p['official_socials']['Facebook'],'https://www.facebook.com/awao_officiel')\n        self.assertEqual(p['official_socials']['Instagram'],'https://www.instagram.com/awao_officiel')\n        self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@influamenta1')\n\n    def test_snapshot(self):\n        p=self.profile()\n        self.assertIn('897 k',p['curated_facts']['facebook_snapshot']['value'])\n        self.assertIn('2,8 M',p['curated_facts']['tiktok_snapshot']['value'])\n\n    def test_versions(self):\n        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.11",self.v9)\n        self.assertIn("CENSUS_VERSION='97-v28'",self.v9)\n        self.assertIn('v9-tools.js?v=15.9',self.index)\n        self.assertIn('v9-tools.js?v=15.9',self.sw)\n        self.assertIn('pass50_nouveaux_candidats_90_v19.json?v=22.11',self.sw)\n\nif __name__=='__main__': unittest.main()\n''', encoding='utf-8')


def cleanup():
    if SCRIPT.exists(): SCRIPT.unlink()
    if TRIGGER.exists(): TRIGGER.unlink()

if __name__ == '__main__':
    upsert(); versions(); write_test(); cleanup()
