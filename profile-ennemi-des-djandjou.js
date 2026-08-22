(function(){
'use strict';

const PROFILE_ID='ennemi-des-djandjou';
const TIKTOK_URL='https://www.tiktok.com/@ennemidesdjandjou';
const FACEBOOK_URL='https://www.facebook.com/profile.php?id=61582125968813';
let attempts=0;

function verifiedLink(message,status){
  return {status:status||'manual_verified',checkedAt:'2026-08-22T00:48:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Ennemi des Djandjou',
    realName:'Ennemi des Djandjou',
    handle:'@ennemidesdjandjou',
    initials:'ED',
    region:'CI',
    country:'CI',
    category:'Lives / Débats / Société',
    occupation:'Créateur de lives TikTok, débats et société',
    platforms:['TikTok','Facebook'],
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
    photoNote:'Photo officielle à ajouter et valider.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,Facebook:FACEBOOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok officiel @ennemidesdjandjou — Ennemi des Djandjou.'),
      Facebook:verifiedLink('Page Facebook officielle confirmée visuellement par le propriétaire PASS50.','owner_verified')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Compte TikTok officiel @ennemidesdjandjou',date:'2026-07-27',url:TIKTOK_URL},
    sources:[
      {publisher:'Compte TikTok officiel @ennemidesdjandjou',date:'2026-07-27',url:TIKTOK_URL},
      {publisher:'Page Facebook officielle confirmée PASS50',date:'2026-08-22',url:FACEBOOK_URL}
    ],
    notes:'Ennemi des Djandjou est le nom public complet de ce créateur ivoirien de lives, débats et société. Compte TikTok officiel @ennemidesdjandjou · page Facebook officielle confirmée. Relié au Radar LIVE P0 PASS50. Distinct d’Isouch / Nongbé Gethsémané Isaac (@prince_du_pays). Aucun autre nom civil public n’est retenu tant qu’une source officielle ne le confirme.'
  };
}

function matchesEnnemi(item){
  if(!item)return false;
  if(item.id===PROFILE_ID)return true;
  if(item.id==='census-isouch')return false;
  const handle=String(item.handle||'').toLowerCase().replace(/^@/,'');
  const tiktok=String(item.links?.TikTok||'').toLowerCase();
  const facebook=String(item.links?.Facebook||'').toLowerCase();
  const name=String(item.name||'').toLowerCase();
  return handle==='ennemidesdjandjou'
    || tiktok.includes('ennemidesdjandjou')
    || facebook.includes('61582125968813')
    || name==='ennemi des djandjou';
}

function attachLives(changed){
  (db.liveStreams||[]).forEach(live=>{
    const blob=[live.url,live.handle,live.profileName,live.title,live.metadata?.handle].map(value=>String(value||'').toLowerCase()).join(' ');
    if(blob.includes('ennemidesdjandjou')||blob.includes('ennemi des djandjou')){
      if(live.profileId!==PROFILE_ID){live.profileId=PROFILE_ID;changed=true;}
    }
  });
  return changed;
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  const patch=baseProfile();
  let profile=db.profiles.find(matchesEnnemi);
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
    ['TikTok','Facebook'].forEach(platform=>{
      if(!profile.platforms.includes(platform)){profile.platforms.push(platform);changed=true;}
    });
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    if(profile.links.Facebook!==FACEBOOK_URL){profile.links.Facebook=FACEBOOK_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(profile.linkChecks.TikTok?.status!=='manual_verified'||!String(profile.linkChecks.TikTok?.message||'').includes('@ennemidesdjandjou')){
      profile.linkChecks.TikTok=patch.linkChecks.TikTok;
      changed=true;
    }
    if(profile.linkChecks.Facebook?.status!=='owner_verified'){
      profile.linkChecks.Facebook=patch.linkChecks.Facebook;
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
