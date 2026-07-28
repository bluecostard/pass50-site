(function(){
'use strict';

const PROFILE_ID='atoule';
const TIKTOK_URL='https://www.tiktok.com/@atouleee.officiel';
const ABIDJANSHOW_SOURCE='https://www.abidjanshow.com/news/actu-for-men/278187-people-onde-de-choc-suite-au-depart-datoule-des-reseaux-sociaux';
const ABIDJANTV_SOURCE='https://abidjantv.net/showbiz/atoule-je-tourne-la-page-des-reseaux-sociaux-aujourdhui/';
let attempts=0;

function verifiedLink(message){return {status:'manual_verified',checkedAt:'2026-07-28T00:00:00.000Z',message};}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Atoulé',
    handle:'@atouleee.officiel',
    initials:'AT',
    region:'CI',
    category:'Sport / Découverte / Lifestyle / Divertissement',
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
    linkChecks:{TikTok:verifiedLink('Compte TikTok @atouleee.officiel transmis directement à PASS50 et rattaché à l’identité publique d’Atoulé.')},
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'AbidjanShow — portrait d’Atoulé',date:'2025-07',url:ABIDJANSHOW_SOURCE},
    sources:[
      {publisher:'AbidjanShow — portrait d’Atoulé',date:'2025-07',url:ABIDJANSHOW_SOURCE},
      {publisher:'AbidjanTV — Hadi Ghadboun alias Atoulé',date:'2025-07-11',url:ABIDJANTV_SOURCE},
      {publisher:'Lien TikTok transmis directement à PASS50',date:'2026-07-28',url:TIKTOK_URL}
    ],
    notes:'Hadi Ghadboun, connu publiquement sous le nom Atoulé, est un vidéaste et créateur de contenus vivant à Abidjan, apprécié pour ses contenus liés au sport, aux découvertes, au lifestyle et à la Côte d’Ivoire. Après avoir annoncé une pause des réseaux sociaux en 2025, un compte TikTok @atouleee.officiel a été signalé à PASS50 en 2026. Profil recensé, non classable tant que l’activité du compte et les métriques récentes ne sont pas consolidées.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name==='atoulé'||name==='atoule'||name==='hadi ghadboun'||handle==='@atouleee.officiel'||handle==='atouleee.officiel');
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','category','censusStatus','verificationPriority','source','sources','notes'].forEach(key=>{const value=profile[key];if(value===undefined||value===null||value===''||(Array.isArray(value)&&!value.length)){profile[key]=patch[key];changed=true;}});
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