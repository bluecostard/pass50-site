(function(){
'use strict';

const PROFILE_ID='census-lionel-pcs';
const LINKTREE_URL='https://linktr.ee/lionelpcsofficiel';
const INSTAGRAM_URL='https://www.instagram.com/lionel_pcs/';
const FACEBOOK_URL='https://www.facebook.com/lionelpcs225';
const TIKTOK_URL='https://www.tiktok.com/@lionel_pcs';
const TELEGRAM_SOURCE='https://t.me/+nxm8Kwaw7IY1ZDQ0';
const GUARDIAN_SOURCE='https://guardian.ng/life/meet-lionel-pcs-africas-rising-star-in-lifestyle-and-culture/';
let attempts=0;

function verifiedLink(message){return {status:'manual_verified',checkedAt:'2026-07-30T00:00:00.000Z',message};}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Lionel PCS',
    handle:'@lionel_pcs',
    initials:'LP',
    region:'CI',
    category:'Football / Pronostics sportifs / Divertissement',
    platforms:['TikTok','Instagram','Facebook'],
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
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL,Facebook:FACEBOOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @lionel_pcs relié par le hub Linktree public lionelpcsofficiel.'),
      Instagram:verifiedLink('Compte Instagram @lionel_pcs relié par le hub Linktree public lionelpcsofficiel.'),
      Facebook:verifiedLink('Page Facebook lionelpcs225 reliée par le hub Linktree public lionelpcsofficiel.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé — intégration prioritaire',
    verificationPriority:'P0',
    entityType:'Personne',
    knownAlias:'Lionel Akobé / @lionel_pcs',
    source:{publisher:'Linktree public — LIONEL PCS',date:'2026-07-30',url:LINKTREE_URL},
    sources:[
      {publisher:'Linktree public — comptes de Lionel PCS',date:'2026-07-30',url:LINKTREE_URL},
      {publisher:'Telegram — canal LIONEL PCS relié au même Linktree',date:'2026-07-30',url:TELEGRAM_SOURCE},
      {publisher:'The Guardian Nigeria — portrait de Lionel PCS',date:'2024-02-21',url:GUARDIAN_SOURCE}
    ],
    notes:'Créateur ivoirien connu publiquement sous le nom Lionel PCS, actif autour du football, des pronostics sportifs et du divertissement. Ses comptes TikTok, Instagram et Facebook sont reliés par son hub public lionelpcsofficiel ; son canal Telegram renvoie au même hub. Profil recensé et non classable tant que PASS50 n’a pas validé ses métriques récentes.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const id=String(item&&item.id||'').toLowerCase();
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(id===PROFILE_ID||id==='lionel-pcs'||name==='lionel pcs'||name==='lionel akobé'||name==='lionel akobe'||handle==='@lionel_pcs'||handle==='lionel_pcs');
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','category','censusStatus','verificationPriority','entityType','knownAlias','source','sources','notes'].forEach(key=>{const value=profile[key];if(value===undefined||value===null||value===''||(Array.isArray(value)&&!value.length)){profile[key]=patch[key];changed=true;}});
    ['alive','eligible','classable','verifiedPass50','ageStatus','agePublic','photoStatus','photoNote','photoPosition'].forEach(key=>{if(profile[key]===undefined){profile[key]=patch[key];changed=true;}});
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    patch.platforms.forEach(platform=>{if(!profile.platforms.includes(platform)){profile.platforms.push(platform);changed=true;}});
    profile.links=profile.links||{};
    Object.entries(patch.links).forEach(([platform,url])=>{if(!profile.links[platform]){profile.links[platform]=url;changed=true;}});
    profile.linkChecks=profile.linkChecks||{};
    Object.entries(patch.linkChecks).forEach(([platform,check])=>{if(!profile.linkChecks[platform]){profile.linkChecks[platform]=check;changed=true;}});
    profile.scores=profile.scores||patch.scores;
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
  }
  if(changed){try{if(typeof save==='function')save();else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}try{if(typeof render==='function')render();}catch{}}
  return true;
}
function tick(){attempts++;const ready=applyProfile();if((ready&&window.__pass50CloudReady)||attempts>=240)clearInterval(timer);}
const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
})();
