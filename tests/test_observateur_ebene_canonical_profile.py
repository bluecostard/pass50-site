import json
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CENSUS=json.loads((ROOT/'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))
V9=(ROOT/'v9-tools.js').read_text(encoding='utf-8')
class ObservateurEbeneCanonicalProfileTests(unittest.TestCase):
 def test_profile(self):
  p=next(x for x in CENSUS if x.get('id')=='census-observateur-ebene');self.assertEqual(p['birth_date'],'1989-07-04');self.assertFalse(p['classable']);self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@observateur_ebene')
 def test_revision(self):
  self.assertIn("CENSUS_VERSION='99-v30'",V9)
if __name__=='__main__':unittest.main()
