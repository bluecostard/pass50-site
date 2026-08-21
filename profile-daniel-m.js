(function(){
'use strict';

const PROFILE_ID='census-daniel-m';
const TIKTOK_URL='https://www.tiktok.com/@_michael_daniel';
const YOUTUBE_URL='https://www.youtube.com/@wisdombydaniel.m';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-21T23:50:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'DANIEL.M',
    handle:'@_michael_daniel',
    initials:'DM',
    region:'CI',
    category:'Storytelling / Conseil',
    platforms:['TikTok','YouTube'],
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
    photoNote:'Photo à valider depuis le compte TikTok @_michael_daniel.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,YouTube:YOUTUBE_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @_michael_daniel confirmé — DANIEL.M, recensé le 21 août 2026.'),
      YouTube:verifiedLink('Chaîne YouTube @wisdombydaniel.m recensée (lien bio TikTok).')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Signalement PASS50 — profil TikTok DANIEL.M',date:'2026-08-21',url:TIKTOK_URL},
    notes:'Créateur ivoirien DANIEL.M (@_michael_daniel). Storytelling, sagesse et conseil. TikTok officiel @_michael_daniel · YouTube @wisdombydaniel.m. Relié au Radar LIVE P0 PASS50. Date de recensement 21 août 2026, pas une date de naissance.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    const tiktok=String((item&&item.links&&item.links.TikTok)||'').toLowerCase();
    const youtube=String((item&&item.links&&item.links.YouTube)||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID
      ||name==='daniel.m'
      ||name==='daniel m'
      ||handle==='@_michael_daniel'
      ||handle==='_michael_daniel'
      ||tiktok.includes('@_michael_daniel')
      ||youtube.includes('wisdombydaniel.m')
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','handle','initials','region','category','censusStatus','source','notes'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''){profile[key]=patch[key];changed=true;}
    });
    if(profile.verificationPriority!=='P0'){profile.verificationPriority='P0';changed=true;}
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    if(profile.links.YouTube!==YOUTUBE_URL){profile.links.YouTube=YOUTUBE_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    ['TikTok','YouTube'].forEach(p=>{
      if(!profile.linkChecks[p]||profile.linkChecks[p].status!=='manual_verified'){
        profile.linkChecks[p]=patch.linkChecks[p];changed=true;
      }
    });
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    ['TikTok','YouTube'].forEach(p=>{if(!profile.platforms.includes(p)){profile.platforms.push(p);changed=true;}});
  }
  if(typeof p50ClearImplausibleBirth==='function'){
    const before=JSON.stringify({d:profile.birthDate,y:profile.birthYear,s:profile.ageStatus});
    p50ClearImplausibleBirth(profile);
    if(JSON.stringify({d:profile.birthDate,y:profile.birthYear,s:profile.ageStatus})!==before)changed=true;
  }else if(Number(profile.birthYear)===2026||String(profile.birthDate||'').startsWith('2026')){
    profile.birthDate=null;profile.birthYear=null;profile.ageStatus='unconfirmed';profile.birthManualLocked=false;changed=true;
  }
  if(profile.verifiedPass50===undefined){profile.verifiedPass50=false;changed=true;}
  if(changed){
    try{if(typeof save==='function')save();else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
    try{if(typeof render==='function')render();}catch{}
  }
  return true;
}

function tick(){attempts++;const ready=applyProfile();if((ready&&window.__pass50CloudReady)||attempts>=240)clearInterval(timer);}
const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
})();
