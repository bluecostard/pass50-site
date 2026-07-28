(function(){
'use strict';

const PROFILE_ID='lolo-beaute';
const INSTAGRAM_URL='https://www.instagram.com/lolobeaute_officiel/';
const FACEBOOK_URL='https://www.facebook.com/Lolobeauteofficiel/';
const PRESS_SOURCE='https://www.afrique-sur7.fr/447023-charbon-makosso-louise-lolo-beaute';
const HACA_SOURCE='https://news.abidjan.net/articles/718381/affaire-lolo-beaute-exhibe-son-intimite-sur-facebook-la-haca-restreint-son-compte-meta-pour-30-jours';
let attempts=0;

function verifiedLink(message){return {status:'manual_verified',checkedAt:'2026-07-28T00:00:00.000Z',message};}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Lolo Beauté',
    handle:'@lolobeaute_officiel',
    initials:'LB',
    region:'DIASPORA',
    country:'FR',
    category:'Lifestyle / Beauté / Divertissement / Société',
    platforms:['Instagram','Facebook'],
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
    links:{Instagram:INSTAGRAM_URL,Facebook:FACEBOOK_URL},
    linkChecks:{
      Instagram:verifiedLink('Compte Instagram @lolobeaute_officiel publié par une source de presse consacrée à Louise Makosso.'),
      Facebook:verifiedLink('Page Facebook Lolobeauteofficiel publiée comme compte de l’influenceuse.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Afrique-sur7 — portrait et réseaux officiels de Lolo Beauté',date:'2021-02-23',url:PRESS_SOURCE},
    sources:[
      {publisher:'Afrique-sur7 — portrait et réseaux officiels de Lolo Beauté',date:'2021-02-23',url:PRESS_SOURCE},
      {publisher:'Abidjan.net — compte Meta de Lolo Beauté',date:'2023-02-28',url:HACA_SOURCE}
    ],
    notes:'Louise Makosso, connue publiquement comme Lolo Beauté, est une influenceuse ivoirienne de la diaspora active dans la beauté, le lifestyle et le divertissement. Elle est la sœur de Camille Makosso. Profil recensé avec Facebook et Instagram vérifiés. Non classable tant que les métriques récentes ne sont pas validées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name==='lolo beauté'||name==='lolo beaute'||name==='louise makosso'||name==='makosso louise'||handle==='@lolobeaute_officiel'||handle==='lolobeaute_officiel');
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