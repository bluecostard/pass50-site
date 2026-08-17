import json
import re
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CENSUS=ROOT/'pass50_nouveaux_candidats_90_v19.json'
V9=ROOT/'v9-tools.js'
INDEX=ROOT/'index.html'
SW=ROOT/'sw.js'

class AwaoCanonicalProfileTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.census=json.loads(CENSUS.read_text(encoding='utf-8'))
        cls.v9=V9.read_text(encoding='utf-8')
        cls.index=INDEX.read_text(encoding='utf-8')
        cls.sw=SW.read_text(encoding='utf-8')

    def profile(self):
        return next(x for x in self.census if x.get('id')=='census-awao-officiel')

    def test_unique(self):
        matches=[x for x in self.census if x.get('id')=='census-awao-officiel' or x.get('name')=='Awao' or 'influamenta1' in str(x.get('official_socials',{}))]
        self.assertEqual(len(matches),1)

    def test_identity_and_status(self):
        p=self.profile()
        self.assertEqual(p['zone'],'CI')
        self.assertEqual(p['verification_priority'],'P0')
        self.assertFalse(p['eligible'])
        self.assertFalse(p['classable'])
        self.assertIn('Actrice',p['category'])

    def test_socials(self):
        p=self.profile()
        self.assertEqual(p['official_socials']['Facebook'],'https://www.facebook.com/awao_officiel')
        self.assertEqual(p['official_socials']['Instagram'],'https://www.instagram.com/awao_officiel')
        self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@influamenta1')

    def test_snapshot(self):
        p=self.profile()
        self.assertIn('897 k',p['curated_facts']['facebook_snapshot']['value'])
        self.assertIn('2,8 M',p['curated_facts']['tiktok_snapshot']['value'])

    def test_versions(self):
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.15",self.v9)
        self.assertIn("CENSUS_VERSION='99-v32'",self.v9)
        self.assertIn('v9-tools.js?v=15.20',self.index)
        self.assertIn('v9-tools.js?v=15.20',self.sw)
        self.assertIn('pass50_nouveaux_candidats_90_v19.json?v=22.15',self.sw)

if __name__=='__main__': unittest.main()
