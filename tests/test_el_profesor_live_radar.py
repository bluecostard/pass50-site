import json
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CENSUS=json.loads((ROOT/'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))
SOURCE=(ROOT/'api/live-radar-v4-source.php').read_text(encoding='utf-8')
class ElProfesorLiveRadarTests(unittest.TestCase):
 def test_profile_and_official_tiktok(self):
  p=next(x for x in CENSUS if x.get('id')=='census-el-profesor');self.assertFalse(p['classable']);self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@elprofesor_off')
 def test_radar_fallback(self):
  self.assertIn('elprofesor_off',SOURCE);self.assertIn("'verification_status'=>'manual_verified'",SOURCE);self.assertIn("'confidence'=>100",SOURCE)
if __name__=='__main__':unittest.main()
