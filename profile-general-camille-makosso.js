(function(){
'use strict';

const PROFILE_ID='general-camille-makosso';
const TIKTOK_URL='https://www.tiktok.com/@generalcamillemakosso';
const INSTAGRAM_URL='https://www.instagram.com/generalcamillemakosso/';
const FACEBOOK_URL='https://www.facebook.com/Generalcamillemakosso/';
const FACEBOOK_SOURCE=FACEBOOK_URL;
const HEEPSY_SOURCE='https://www.heepsy.com/es/instagram-profile/generalcamillemakosso';
const PRESS_SOURCE='https://carrefourdesmetiers.fr/pratique/qui-est-vraiment-camille-makosso-le-pasteur-influenceur-de-cote-divoire/';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-07-28T00:00:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Général Camille Makosso',
    handle:'@generalcamillemakosso',
    initials:'CM',
    region:'CI',
    category:'Religion / Société / Divertissement / Débats',
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
      TikTok:verifiedLink('Compte TikTok publié dans la biographie Instagram publique de Général Camille Makosso.'),
      Instagram:verifiedLink('Compte Instagram public @generalcamillemakosso.'),
      Facebook:verifiedLink('Page Facebook publique Général Camille Makosso.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Page Facebook publique — Général Camille Makosso',date:'2026-07-28',url:FACEBOOK_SOURCE},
    sources:[
      {publisher:'Page Facebook publique — Général Camille Makosso',date:'2026-07-28',url:FACEBOOK_SOURCE},
      {publisher:'Heepsy — profil Instagram et réseaux déclarés',date:'2026-07',url:HEEPSY_SOURCE},
      {publisher:'Carrefour des Métiers — portrait numérique',date:'2023',url:PRESS_SOURCE}
    ],
    notes:'Camille Makosso, connu publiquement comme Général Camille Makosso, est un pasteur et créateur de contenus ivoirien très actif sur les sujets religieux, sociaux et médiatiques. Profil recensé avec liens personnels concordants. Non classable tant que les métriques récentes ne sont pas validées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name==='général camille makosso'||name==='general camille makosso'||name==='camille makosso'||name==='makosso camille'||handle==='@generalcamillemakosso'||handle==='@generalmakossocamille1'||handle==='generalcamillemakosso');
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','category','censusStatus','verificationPriority','source','sources','notes'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''||(Array.isArray(value)&&!value.length)){profile[key]=patch[key];changed=true;}
    });
    ['alive','eligible','classable','verifiedPass50','ageStatus','agePublic','photoStatus','photoNote','photoPosition'].forEach(key=>{if(profile[key]===undefined){profile[key]=patch[key];changed=true;}});
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    patch.platforms.forEach(platform=>{if(!profile.platforms.includes(platform)){profile.platforms.push(platform);changed=true;}});
    profile.links=profile.links||{};
    Object.entries(patch.links).forEach(([platform,url])=>{
      const current=String(profile.links[platform]||'');
      const deadTikTok=/generalmakossocamille1/i.test(current);
      if(!current||deadTikTok){profile.links[platform]=url;changed=true;}
    });
    if(String(profile.handle||'').toLowerCase()==='@generalmakossocamille1'){profile.handle=patch.handle;changed=true;}
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