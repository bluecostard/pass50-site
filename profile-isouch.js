(function(){
'use strict';

const PROFILE_ID='census-isouch';
const TIKTOK_URL='https://www.tiktok.com/@prince_du_pays';
let attempts=0;

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Isouch',
    handle:'@prince_du_pays',
    initials:'IS',
    region:'CI',
    category:'Société / Divertissement',
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
    photoNote:'Photo à valider depuis le compte TikTok @prince_du_pays.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{TikTok:{status:'manual_verified',checkedAt:'2026-08-22T00:31:00.000Z',message:'Compte TikTok officiel @prince_du_pays — Isouch.'}},
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Compte TikTok officiel @prince_du_pays',date:'2026-08-22',url:TIKTOK_URL},
    notes:'Isouch, chroniqueur et créateur ivoirien. Compte TikTok officiel @prince_du_pays, relié au Radar LIVE P0 PASS50. Distinct d’Ennemi des Djandjou (@ennemidesdjandjou).'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  let profile=db.profiles.find(item=>{
    if(item.id===PROFILE_ID||item.id==='ennemi-des-djandjou')return item.id===PROFILE_ID;
    const handle=String(item.handle||'').toLowerCase();
    const tiktok=String(item.links?.TikTok||'').toLowerCase();
    const name=String(item.name||'').toLowerCase();
    return handle==='@prince_du_pays'||tiktok.includes('prince_du_pays')||name==='isouch';
  });
  let changed=false;

  if(!profile){
    profile=baseProfile();
    db.profiles.push(profile);
    changed=true;
  }else{
    const patch=baseProfile();
    const required={
      id:patch.id,
      name:patch.name,
      handle:patch.handle,
      initials:patch.initials,
      region:patch.region,
      category:profile.category||patch.category,
      alive:true,
      censusStatus:patch.censusStatus,
      verificationPriority:patch.verificationPriority,
      notes:patch.notes
    };
    Object.entries(required).forEach(([key,value])=>{
      if(profile[key]!==value){profile[key]=value;changed=true;}
    });
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('TikTok')){profile.platforms.push('TikTok');changed=true;}
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(profile.linkChecks.TikTok?.status!=='manual_verified'||!String(profile.linkChecks.TikTok?.message||'').includes('@prince_du_pays')){
      profile.linkChecks.TikTok={status:'manual_verified',checkedAt:'2026-08-22T00:31:00.000Z',message:'Compte TikTok officiel @prince_du_pays — Isouch.'};
      changed=true;
    }
    profile.scores=profile.scores||{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0};
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
    profile.photoStatus=profile.photoStatus||'missing';
    profile.photoNote=profile.photoNote||'Photo à valider depuis le compte TikTok @prince_du_pays.';
  }

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
