(function(){
'use strict';

const PROFILE_ID='ennemi-des-djandjou';
const TIKTOK_URL='https://www.tiktok.com/@ennemidesdjandjou';
let attempts=0;

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Ennemi des Djandjou',
    handle:'@ennemidesdjandjou',
    initials:'ED',
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
    photoNote:'Photo officielle à ajouter et valider.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{TikTok:{status:'manual_verified',checkedAt:new Date().toISOString(),message:'Lien TikTok direct transmis à PASS50.'}},
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P1',
    source:{publisher:'Lien TikTok transmis à PASS50',date:'2026-07-27',url:TIKTOK_URL},
    notes:'Profil recensé. Non classable tant que les métriques récentes et les autres informations publiques ne sont pas vérifiées.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  let profile=db.profiles.find(item=>item.id===PROFILE_ID||item.handle==='@ennemidesdjandjou');
  let changed=false;

  if(!profile){
    profile=baseProfile();
    db.profiles.push(profile);
    changed=true;
  }else{
    const patch=baseProfile();
    const required={
      name:patch.name,
      handle:patch.handle,
      initials:patch.initials,
      region:patch.region,
      category:profile.category||patch.category,
      alive:true,
      eligible:false,
      classable:false,
      verifiedPass50:false,
      censusStatus:patch.censusStatus,
      verificationPriority:patch.verificationPriority
    };
    Object.entries(required).forEach(([key,value])=>{
      if(profile[key]!==value){profile[key]=value;changed=true;}
    });
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('TikTok')){profile.platforms.push('TikTok');changed=true;}
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(profile.linkChecks.TikTok?.status!=='manual_verified'){
      profile.linkChecks.TikTok={status:'manual_verified',checkedAt:new Date().toISOString(),message:'Lien TikTok direct transmis à PASS50.'};
      changed=true;
    }
    profile.scores=profile.scores||{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0};
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
    profile.photoStatus=profile.photoStatus||'missing';
    profile.photoNote=profile.photoNote||'Photo officielle à ajouter et valider.';
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
