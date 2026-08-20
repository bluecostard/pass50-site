(function(){
'use strict';

const PROFILE_ID='census-samuella-kouassi';
const TIKTOK_URL='https://www.tiktok.com/@samuellakouassiofficiel';
const INSTAGRAM_URL='https://www.instagram.com/samuellakouassiofficiel/';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-20T21:46:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Samuella Kouassi',
    handle:'@samuellakouassiofficiel',
    initials:'SK',
    region:'CI',
    category:'Lifestyle / Mode / Divertissement',
    platforms:['TikTok','Instagram'],
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
    photoNote:'Photo à valider depuis le compte TikTok @samuellakouassiofficiel.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @samuellakouassiofficiel confirmé — créatrice ivoirienne (Samuella Kouassi), recensé le 20 août 2026.'),
      Instagram:verifiedLink('Compte Instagram @samuellakouassiofficiel recensé (Samuella Kouassi).')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Signalement PASS50 — profil TikTok Samuella Kouassi',date:'2026-08-20',url:TIKTOK_URL},
    notes:'Samuella Kouassi, créatrice ivoirienne (lifestyle / mode). TikTok @samuellakouassiofficiel · Instagram @samuellakouassiofficiel. Comptes reliés au Radar LIVE P0 PASS50. Date de recensement, pas une date de naissance.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    const tiktok=String((item&&item.links&&item.links.TikTok)||'').toLowerCase();
    const instagram=String((item&&item.links&&item.links.Instagram)||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID
      ||name==='samuella kouassi'
      ||name.includes('samuella kouassi')
      ||handle==='@samuellakouassiofficiel'
      ||handle==='samuellakouassiofficiel'
      ||tiktok.includes('@samuellakouassiofficiel')
      ||instagram.includes('samuellakouassiofficiel')
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','handle','initials','region','category','censusStatus','verificationPriority','source','notes'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''){profile[key]=patch[key];changed=true;}
    });
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    if(profile.links.Instagram!==INSTAGRAM_URL){profile.links.Instagram=INSTAGRAM_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    ['TikTok','Instagram'].forEach(p=>{
      if(!profile.linkChecks[p]||profile.linkChecks[p].status!=='manual_verified'){
        profile.linkChecks[p]=patch.linkChecks[p];changed=true;
      }
    });
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    ['TikTok','Instagram'].forEach(p=>{if(!profile.platforms.includes(p)){profile.platforms.push(p);changed=true;}});
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
