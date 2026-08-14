import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CENSUS=ROOT/'pass50_nouveaux_candidats_90_v19.json'
V9=ROOT/'v9-tools.js'
SW=ROOT/'sw.js'
RADAR_SOURCE=ROOT/'api/live-radar-v4-source.php'
TEST_OBS=ROOT/'tests/test_observateur_ebene_canonical_profile.py'
TEST_EL=ROOT/'tests/test_el_profesor_live_radar.py'
SCRIPT=ROOT/'scripts/one_shot_add_observateur_ebene.py'
TRIGGER=ROOT/'.pass50-integrate-observateur-ebene-v1'

OBS={
 'id':'census-observateur-ebene','name':'Observateur Ébène','known_alias':'Florent Amany / Kouakou Amani Florent / Observateur / @observateur_ebene','entity_type':'Personne','zone':'BOTH','category':'Humour / Acteur / Créateur digital / Influence / Divertissement','birth_date':'1989-07-04','census_status':'Recensé confirmé — compte TikTok fourni par le propriétaire PASS50','verification_priority':'P0','eligible':False,'classable':False,
 'official_socials':{'TikTok':'https://www.tiktok.com/@observateur_ebene','Instagram':'https://www.instagram.com/observateur_ebene','Snapchat':'https://www.snapchat.com/add/obs_ebene'},
 'source':{'publisher':'Compte TikTok direct fourni par le propriétaire PASS50','date':'2026-08-14','url':'https://www.tiktok.com/@observateur_ebene'},
 'source_secondary':{'publisher':'Site officiel Observateur Ébène','url':'https://observateurebene.com/'},
 'source_tertiary':{'publisher':'Paul Digital','url':'https://paul-digital.com/portfolio/observateur-ebene/'},
 'notes':'Ajout approuvé PASS50 le 14 août 2026. Observateur Ébène, également identifié publiquement comme Florent Amany / Kouakou Amani Florent, est un humoriste, acteur et créateur digital ivoirien. Date de naissance recoupée : 4 juillet 1989. Profil non classable jusqu’à validation des métriques.',
 'curated_social_sources':{
  'TikTok':{'url':'https://www.tiktok.com/@observateur_ebene','source_name':'Compte TikTok direct fourni par le propriétaire PASS50','source_url':'https://www.tiktok.com/@observateur_ebene','confidence':100},
  'Instagram':{'url':'https://www.instagram.com/observateur_ebene','source_name':'Paul Digital','source_url':'https://paul-digital.com/portfolio/observateur-ebene/','confidence':96},
  'Snapchat':{'url':'https://www.snapchat.com/add/obs_ebene','source_name':'Site officiel Observateur Ébène','source_url':'https://observateurebene.com/','confidence':98}},
 'curated_facts':{
  'identity':{'value':'Florent Amany / Kouakou Amani Florent, alias Observateur Ébène.','source_name':'Site officiel Observateur Ébène / Abidjan.net','source_url':'https://observateurebene.com/','confidence':96},
  'birth_date':{'value':'4 juillet 1989','source_name':'Famous Birthdays / Afrique-sur7','source_url':'https://fr.famousbirthdays.com/people/florent.html','confidence':94}},
 'research_queries':['"observateur_ebene" TikTok Instagram officiel','"Observateur Ébène" YouTube Facebook officiel']
}

EL={
 'id':'census-el-profesor','name':'El Profesor','known_alias':'El Profesor / @elprofesor_off','entity_type':'Personne','zone':'CI','category':'TikTok / Live / Influence / Divertissement','census_status':'Recensé confirmé — compte TikTok fourni en direct par le propriétaire PASS50','verification_priority':'P0','eligible':False,'classable':False,
 'official_socials':{'TikTok':'https://www.tiktok.com/@elprofesor_off'},
 'source':{'publisher':'Compte TikTok direct fourni par le propriétaire PASS50','date':'2026-08-14','url':'https://www.tiktok.com/@elprofesor_off'},
 'notes':'Lien TikTok officiel confirmé le 14 août 2026 alors que le compte était en direct. Le radar LIVE doit surveiller @elprofesor_off lors des prochains balayages. Profil non classable jusqu’à validation des métriques.',
 'curated_social_sources':{'TikTok':{'url':'https://www.tiktok.com/@elprofesor_off','source_name':'Compte TikTok direct fourni par le propriétaire PASS50','source_url':'https://www.tiktok.com/@elprofesor_off','confidence':100}},
 'research_queries':['"elprofesor_off" TikTok officiel','"El Profesor" influenceur ivoirien réseaux sociaux']
}


def norm(v):return ''.join(ch for ch in str(v).lower() if ch.isalnum())

def upsert_all():
 census=json.loads(CENSUS.read_text(encoding='utf-8'))
 for candidate in (OBS,EL):
  handle=next(iter(candidate['official_socials'].values())).split('@')[-1]
  matches=[x for x in census if x.get('id')==candidate['id'] or norm(x.get('name'))==norm(candidate['name']) or norm(handle) in norm(x.get('known_alias')) or norm(handle) in norm(json.dumps(x.get('official_socials',{}),ensure_ascii=False))]
  if len(matches)>1:raise RuntimeError(f'Doublons potentiels pour {candidate["name"]}')
  if matches:census[census.index(matches[0])]=candidate
  else:census.append(candidate)
 CENSUS.write_text(json.dumps(census,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')


def replace_once(path,old,new):
 text=path.read_text(encoding='utf-8')
 if new in text:return
 if text.count(old)!=1:raise RuntimeError(f'Marqueur non unique dans {path}: {old}')
 path.write_text(text.replace(old,new),encoding='utf-8')


def replace_all(path,old,new):
 text=path.read_text(encoding='utf-8')
 if old not in text:
  if new in text:return
  raise RuntimeError(f'Marqueur absent dans {path}: {old}')
 path.write_text(text.replace(old,new),encoding='utf-8')


def bump_versions():
 replace_once(V9,"const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.11';","const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.13';")
 replace_once(V9,"const CENSUS_VERSION='97-v28';","const CENSUS_VERSION='99-v30';")
 replace_all(SW,'./v9-tools.js?v=15.10','./v9-tools.js?v=15.11')
 replace_all(SW,'./pass50_nouveaux_candidats_90_v19.json?v=22.11','./pass50_nouveaux_candidats_90_v19.json?v=22.13')
 for path in (ROOT/'tests').glob('test_*.py'):
  text=path.read_text(encoding='utf-8')
  new=text.replace('pass50_nouveaux_candidats_90_v19.json?v=22.11','pass50_nouveaux_candidats_90_v19.json?v=22.13').replace("CENSUS_VERSION='97-v28'","CENSUS_VERSION='99-v30'").replace('v9-tools.js?v=15.10','v9-tools.js?v=15.11').replace('v9-tools.js?v=15.9','v9-tools.js?v=15.11').replace('"15.10"','"15.11"').replace('"15.9"','"15.11"')
  if new!=text:path.write_text(new,encoding='utf-8')


def patch_radar():
 text=RADAR_SOURCE.read_text(encoding='utf-8')
 marker="    $manual=p50_live_v4_manual_priority_ids($state);$automatic=p50_live_v4_active_auto_ids();$health=p50_live_v4_health_map();"
 if 'elprofesor_off' not in text:
  patch="""    // Lien officiel confirmé manuellement par PASS50 : El Profesor.\n    // Ce fallback évite qu'un retard de synchronisation du registre empêche le radar de sonder son TikTok.\n    $elProfesorId='census-el-profesor';$elProfesorName='El Profesor';\n    foreach((array)($state['profiles']??[]) as $profile){\n        if(!is_array($profile)||empty($profile['id']))continue;\n        $name=strtolower(trim((string)($profile['name']??'')));\n        $handle=strtolower(trim((string)($profile['handle']??'')));\n        $tt=strtolower(trim((string)(($profile['links']??[])['TikTok']??'')));\n        if($name==='el profesor'||str_contains($handle,'elprofesor_off')||str_contains($tt,'@elprofesor_off')){\n            $elProfesorId=(string)$profile['id'];$elProfesorName=(string)($profile['name']??'El Profesor');break;\n        }\n    }\n    $elProfesorKey='TikTok|'.$elProfesorId;\n    if(!isset($seen[$elProfesorKey])){\n        $seen[$elProfesorKey]=true;$out[]=[\n            'profile_id'=>$elProfesorId,'public_name'=>$elProfesorName,'handle'=>'@elprofesor_off',\n            'platform'=>'TikTok','url'=>'https://www.tiktok.com/@elprofesor_off','confidence'=>100,\n            'verification_status'=>'manual_verified',\n        ];\n    }\n"""
  if marker not in text:raise RuntimeError('Point insertion radar introuvable')
  RADAR_SOURCE.write_text(text.replace(marker,patch+marker),encoding='utf-8')


def write_tests():
 TEST_OBS.write_text("""import json\nimport unittest\nfrom pathlib import Path\nROOT=Path(__file__).resolve().parents[1]\nCENSUS=json.loads((ROOT/'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))\nV9=(ROOT/'v9-tools.js').read_text(encoding='utf-8')\nclass ObservateurEbeneCanonicalProfileTests(unittest.TestCase):\n def test_profile(self):\n  p=next(x for x in CENSUS if x.get('id')=='census-observateur-ebene');self.assertEqual(p['birth_date'],'1989-07-04');self.assertFalse(p['classable']);self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@observateur_ebene')\n def test_revision(self):\n  self.assertIn(\"CENSUS_VERSION='99-v30'\",V9)\nif __name__=='__main__':unittest.main()\n""",encoding='utf-8')
 TEST_EL.write_text("""import json\nimport unittest\nfrom pathlib import Path\nROOT=Path(__file__).resolve().parents[1]\nCENSUS=json.loads((ROOT/'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))\nSOURCE=(ROOT/'api/live-radar-v4-source.php').read_text(encoding='utf-8')\nclass ElProfesorLiveRadarTests(unittest.TestCase):\n def test_profile_and_official_tiktok(self):\n  p=next(x for x in CENSUS if x.get('id')=='census-el-profesor');self.assertFalse(p['classable']);self.assertEqual(p['official_socials']['TikTok'],'https://www.tiktok.com/@elprofesor_off')\n def test_radar_fallback(self):\n  self.assertIn('elprofesor_off',SOURCE);self.assertIn(\"'verification_status'=>'manual_verified'\",SOURCE);self.assertIn(\"'confidence'=>100\",SOURCE)\nif __name__=='__main__':unittest.main()\n""",encoding='utf-8')


def cleanup():
 for path in (SCRIPT,TRIGGER):
  if path.exists():path.unlink()

if __name__=='__main__':
 upsert_all();bump_versions();patch_radar();write_tests();cleanup()
