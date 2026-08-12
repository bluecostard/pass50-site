(function(){
'use strict';

const PROFILE_ID='zagba-le-requin';
const TIKTOK_URL='https://www.tiktok.com/@zagbalerekin';
const INSTAGRAM_URL='https://www.instagram.com/zagbalerequin/';
const FACEBOOK_URL='https://www.facebook.com/ZagbaLeRequin';
const AFRO_SOURCE='https://www.afro.video/artist/zagba-le-requin/';
const CULTURE_SOURCE='https://www.100pour100culture.com/musique/zagba-le-requin-lascension-dun-rappeur-ivoirien-depuis-lombre-de-la-crise/';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-12T00:00:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Zagba le Requin',
    handle:'@zagbalerekin',
    initials:'ZR',
    region:'CI',
    country:'CI',
    category:'Musique / Rap / Divertissement',
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
      TikTok:verifiedLink('Compte TikTok public actif @zagbalerekin (PR ZAGBA LEREKIN), rattaché à l’identité publique de Zagba le Requin.'),
      Instagram:verifiedLink('Compte Instagram public @zagbalerequin.'),
      Facebook:verifiedLink('Page Facebook publique Zagba le Requin.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Afro.video — biographie Zagba Le Requin',date:'2024',url:AFRO_SOURCE},
    sources:[
      {publisher:'Afro.video — biographie Zagba Le Requin (Oskane Dokoui)',date:'2024',url:AFRO_SOURCE},
      {publisher:'100%Culture — portrait Zagba le Requin / Team Paiya',date:'2024',url:CULTURE_SOURCE},
      {publisher:'TikTok public — @zagbalerekin',date:'2026-08-12',url:TIKTOK_URL},
      {publisher:'Instagram public — @zagbalerequin',date:'2026-08-12',url:INSTAGRAM_URL}
    ],
    notes:'Oskane Dokoui, connu publiquement comme Zagba le Requin, est un artiste et producteur ivoirien, membre de la Team Paiya. Compte TikTok principal : @zagbalerekin. Profil recensé, non classable tant que les métriques récentes ne sont pas consolidées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID
      ||name==='zagba le requin'
      ||name==='zagba lerekin'
      ||name==='zagbalerequin'
      ||name==='oskane dokoui'
      ||name==='oskane dokui'
      ||handle==='@zagbalerekin'
      ||handle==='zagbalerekin'
      ||handle==='@zagbalerequin'
      ||handle==='zagbalerequin'
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','country','category','censusStatus','verificationPriority','source','sources','notes'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''||(Array.isArray(value)&&!value.length)||key==='handle'||key==='notes'||key==='sources'){
        if(profile[key]!==patch[key]){profile[key]=patch[key];changed=true;}
      }
    });
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
