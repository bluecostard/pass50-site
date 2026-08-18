(function(){
'use strict';

const PROFILE_ID='kim-makosso';
const TIKTOK_URL='https://www.tiktok.com/@kim.makosso_officielle';
const FAMOUS_SOURCE='https://www.famousbirthdays.com/people/kim-makosso.html';
const IDC_SOURCE='https://www.idcrawl.com/kim-mak';
let attempts=0;

function verifiedLink(message){return {status:'manual_verified',checkedAt:'2026-07-28T00:00:00.000Z',message};}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Kim Makosso',
    handle:'@kim.makosso_officielle',
    initials:'KM',
    region:'DIASPORA',
    country:'FR',
    category:'Lifestyle / Mode / Divertissement / Entrepreneuriat',
    platforms:['TikTok'],
    scores:{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0},
    delta:0,
    decline:0,
    alive:true,
    eligible:false,
    classable:false,
    ageStatus:'confirmed',
    birthDate:'2004-01-16',
    birthYear:2004,
    agePublic:true,
    birthManualLocked:true,
    birthManualUpdatedAt:'2026-07-28T00:00:00.000Z',
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
    linkChecks:{TikTok:verifiedLink('Compte @kim.makosso_officielle confirmé par plusieurs sources publiques concordantes.')},
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Famous Birthdays — profil Kim Makosso',date:'2026-07-28',url:FAMOUS_SOURCE},
    sources:[
      {publisher:'Famous Birthdays — profil Kim Makosso',date:'2026-07-28',url:FAMOUS_SOURCE},
      {publisher:'IDCrawl — relevé des comptes Kim Makosso',date:'2026-07',url:IDC_SOURCE}
    ],
    notes:'Kim Makosso est une créatrice de contenus franco-ivoirienne, connue pour ses vidéos lifestyle, mode et divertissement. Elle est la fille de Camille Makosso et développe également ses propres activités entrepreneuriales. Profil recensé avec TikTok vérifié. Non classable tant que les métriques récentes ne sont pas validées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name==='kim makosso'||handle==='@kim.makosso_officielle'||handle==='kim.makosso_officielle'||handle==='@kim.makosso_kmk');
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','country','category','censusStatus','verificationPriority','source','sources','notes'].forEach(key=>{const value=profile[key];if(value===undefined||value===null||value===''||(Array.isArray(value)&&!value.length)){profile[key]=patch[key];changed=true;}});
    ['alive','eligible','classable','verifiedPass50','ageStatus','birthDate','birthYear','agePublic','photoStatus','photoNote','photoPosition'].forEach(key=>{if(profile[key]===undefined||profile[key]===null){profile[key]=patch[key];changed=true;}});
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