(function(){
'use strict';

const PROFILE_ID='dez-cocrane225';
const TIKTOK_URL='https://www.tiktok.com/@dezcocrane.225';
const INSTAGRAM_URL='https://www.instagram.com/dez.cocrane225/';
const FACTCHECK_SOURCE='https://factcheck-congo.org/2025/12/04/legislatives-en-cote-divoire-les-libanais-naturalises-cibles-par-des-discours-de-haines-en-ligne/';
const STARNGAGE_SOURCE='https://starngage.pro/profiles/public/instagram/dez.cocrane225';
let attempts=0;

function verifiedLink(message){return {status:'manual_verified',checkedAt:'2026-07-28T00:00:00.000Z',message};}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Dez Cocrane 225',
    handle:'@dezcocrane.225',
    initials:'DC',
    region:'DIASPORA',
    country:'FR',
    category:'Société / Opinion / Humour / Divertissement',
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
    photoNote:'Photo officielle à ajouter et valider.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @dezcocrane.225 recoupé par des relevés publics ; un direct a également été signalé manuellement à PASS50 le 28 juillet 2026.'),
      Instagram:verifiedLink('Compte Instagram dez.cocrane225 documenté par StarNgage.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Fact-check Congo — profil Dez.cocrane225',date:'2025-12-04',url:FACTCHECK_SOURCE},
    sources:[
      {publisher:'Fact-check Congo — profil Dez.cocrane225',date:'2025-12-04',url:FACTCHECK_SOURCE},
      {publisher:'StarNgage — profil Instagram dez.cocrane225',date:'2026-01',url:STARNGAGE_SOURCE},
      {publisher:'Signalement direct PASS50 — live TikTok observé',date:'2026-07-28',url:TIKTOK_URL}
    ],
    notes:'Créateur ivoirien de la diaspora, connu sous les appellations Dez Cocrane 225 et Dez.cocrane225. Ses contenus mêlent humour, commentaires de société, opinion et divertissement. Le nom d’affichage signalé par l’utilisateur est dez.cocrane225 ; le compte TikTok public recoupé utilise @dezcocrane.225. Profil recensé et relié au Radar LIVE, sans statut live figé. Non classable tant que les métriques récentes ne sont pas validées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name==='dez cocrane 225'||name==='dez.cocrane225'||name==='dez cocrane225'||handle==='@dezcocrane.225'||handle==='dezcocrane.225'||handle==='@dez.cocrane225'||handle==='dez.cocrane225');
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','country','category','censusStatus','verificationPriority','source','sources','notes'].forEach(key=>{const value=profile[key];if(value===undefined||value===null||value===''||(Array.isArray(value)&&!value.length)){profile[key]=patch[key];changed=true;}});
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