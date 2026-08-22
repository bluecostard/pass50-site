(function(){
'use strict';

const PROFILE_ID='census-isouch';
const TIKTOK_URL='https://www.tiktok.com/@prince_du_pays';
const PRESS_AVENIR='https://www.lavenir.ci/culture/15847-en-tournee-nationale-isouch-vend-katiola-la-ville-de-la-maison-des-potieres';
const PRESS_FRATMAT='https://www.fratmat.info/article/2643659/culture/promotion-des-territoires-isouch-fait-escale-a-katiola-et-valorise-son-riche-heritage-culturel';
const PRESS_INFODROME='https://www.linfodrome.com/culture/124345-valorisation-du-patrimoine-culturel-ivoirien-isouch-decouvre-gbomi-konde-yaokro-et-gbomi-zambo';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-22T00:48:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Isouch',
    realName:'Nongbé Gethsémané Isaac',
    handle:'@prince_du_pays',
    initials:'IS',
    region:'CI',
    country:'CI',
    category:'Culture / Télévision / Promotion des territoires',
    occupation:'Chroniqueur télé et créateur de contenus, tournée nationale de valorisation des territoires ivoiriens',
    platforms:['TikTok'],
    scores:{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0},
    delta:0,
    decline:0,
    alive:true,
    eligible:false,
    classable:false,
    ageStatus:'unconfirmed',
    birthDate:null,
    birthYear:null,
    agePublic:true,
    birthManualLocked:false,
    birthManualUpdatedAt:null,
    photoUrl:'',
    photoCandidateUrl:'',
    photoStatus:'missing',
    photoSource:'',
    photoNote:'Photo officielle à valider depuis le compte TikTok @prince_du_pays.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{TikTok:verifiedLink('Compte TikTok officiel @prince_du_pays — Nongbé Gethsémané Isaac, dit Isouch.')},
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'L’Avenir.ci — Nongbé Gethsémané Isaac, dit Isouch',date:'2026-08-03',url:PRESS_AVENIR},
    sources:[
      {publisher:'L’Avenir.ci — chroniqueur télé Nongbé Gethsémané Isaac, dit Isouch',date:'2026-08-03',url:PRESS_AVENIR},
      {publisher:'FratMat — tournée nationale, escale à Katiola',date:'2026-08-03',url:PRESS_FRATMAT},
      {publisher:'L’Infodrome — Nongbé Gethsémané Isaac alias Isouch',date:'2026-08-04',url:PRESS_INFODROME}
    ],
    notes:'Nongbé Gethsémané Isaac, connu publiquement sous le nom Isouch, est chroniqueur télé et créateur de contenus ivoirien. Il mène une tournée nationale de promotion des territoires (Tiassalé, Divo, Adzopé, Katiola, Gbomi). Compte TikTok officiel @prince_du_pays, relié au Radar LIVE P0 PASS50. Distinct d’Ennemi des Djandjou (@ennemidesdjandjou). Date de recensement 22 août 2026, pas une date de naissance.'
  };
}

function matchesIsouch(item){
  if(!item)return false;
  if(item.id===PROFILE_ID)return true;
  if(item.id==='ennemi-des-djandjou')return false;
  const handle=String(item.handle||'').toLowerCase().replace(/^@/,'');
  const tiktok=String(item.links?.TikTok||'').toLowerCase();
  const name=String(item.name||'').toLowerCase();
  const real=String(item.realName||'').toLowerCase();
  return handle==='prince_du_pays'
    || tiktok.includes('prince_du_pays')
    || name==='isouch'
    || name.includes('nongbé gethsémané')
    || name.includes('nongbe gethsemane')
    || real.includes('nongbé gethsémané isaac')
    || real.includes('nongbe gethsemane isaac');
}

function attachLives(changed){
  (db.liveStreams||[]).forEach(live=>{
    const blob=[live.url,live.handle,live.profileName,live.title,live.metadata?.handle].map(value=>String(value||'').toLowerCase()).join(' ');
    if(blob.includes('prince_du_pays')||/\bisouch\b/.test(blob)){
      if(live.profileId!==PROFILE_ID){live.profileId=PROFILE_ID;changed=true;}
    }
  });
  return changed;
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  const patch=baseProfile();
  let profile=db.profiles.find(matchesIsouch);
  let changed=false;

  if(!profile){
    profile=patch;
    db.profiles.push(profile);
    changed=true;
  }else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','realName','handle','initials','region','country','category','occupation','censusStatus','source','sources','notes'].forEach(key=>{
      if(JSON.stringify(profile[key])!==JSON.stringify(patch[key])){profile[key]=patch[key];changed=true;}
    });
    if(profile.verificationPriority!=='P0'){profile.verificationPriority='P0';changed=true;}
    profile.alive=true;
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('TikTok')){profile.platforms.push('TikTok');changed=true;}
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(profile.linkChecks.TikTok?.status!=='manual_verified'||!String(profile.linkChecks.TikTok?.message||'').includes('Nongbé Gethsémané Isaac')){
      profile.linkChecks.TikTok=patch.linkChecks.TikTok;
      changed=true;
    }
    profile.scores=profile.scores||{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0};
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
    if(!profile.photoStatus)profile.photoStatus='missing';
  }

  changed=attachLives(changed);

  if(changed){
    try{
      if(typeof save==='function')save();
      else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));
    }catch{}
    try{if(typeof render==='function')render();}catch{}
  }
  return true;
}

function tick(){
  attempts++;
  const ready=applyProfile();
  if((ready&&window.__pass50CloudReady)||attempts>=240)clearInterval(timer);
}

const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
})();
