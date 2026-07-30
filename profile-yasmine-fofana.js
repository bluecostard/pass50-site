(function(){
'use strict';

const PROFILE_ID='census-yasmine-fofana';
const WEBSITE_URL='https://afrofoodie.ci/';
const IGCAT_SOURCE='https://igcat.org/fr/team/yasmine-fofana-cote-divoire/';
const LEGACY_BLOG_SOURCE='https://www.afrofoodie.net/a-propos/';
const INSTAGRAM_URL='https://www.instagram.com/afrofoodie/';
const FACEBOOK_URL='https://www.facebook.com/Afrofoodie.ci/';
const YOUTUBE_URL='https://www.youtube.com/@YasmineAfrofoodie';
const LINKEDIN_URL='https://www.linkedin.com/in/yasminefofana/';
const X_URL='https://x.com/afro_foodie';
let attempts=0;

function verifiedLink(message){return {status:'manual_verified',checkedAt:'2026-07-30T00:00:00.000Z',message};}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Yasmine Fofana',
    handle:'@afrofoodie',
    initials:'YF',
    region:'CI',
    category:'Gastronomie / Tourisme culinaire / Lifestyle',
    platforms:['Instagram','Facebook','YouTube','LinkedIn','X'],
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
    links:{Instagram:INSTAGRAM_URL,Facebook:FACEBOOK_URL,YouTube:YOUTUBE_URL,LinkedIn:LINKEDIN_URL,X:X_URL},
    linkChecks:{
      Instagram:verifiedLink('Compte Instagram @afrofoodie relié depuis la fiche IGCAT de Yasmine Fofana.'),
      Facebook:verifiedLink('Page Facebook Afrofoodie.ci reliée depuis la fiche IGCAT de Yasmine Fofana.'),
      YouTube:verifiedLink('Chaîne YouTube @YasmineAfrofoodie reliée depuis la fiche IGCAT de Yasmine Fofana.'),
      LinkedIn:verifiedLink('Profil LinkedIn yasminefofana relié depuis la fiche IGCAT de Yasmine Fofana.'),
      X:verifiedLink('Compte X @afro_foodie relié depuis la fiche IGCAT de Yasmine Fofana.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé — intégration prioritaire',
    verificationPriority:'P1',
    entityType:'Personne',
    knownAlias:'Afrofoodie / @afrofoodie',
    source:{publisher:'Afrofoodie — site officiel de Yasmine Fofana',date:'2026-07-30',url:WEBSITE_URL},
    sources:[
      {publisher:'Afrofoodie — site officiel de Yasmine Fofana',date:'2026-07-30',url:WEBSITE_URL},
      {publisher:'IGCAT — Yasmine Fofana, fondatrice d’Afrofoodie',date:'2026-07-30',url:IGCAT_SOURCE},
      {publisher:'Afrofoodie.net — parcours de Yasmine Fofana',date:'2026-01',url:LEGACY_BLOG_SOURCE}
    ],
    notes:'Yasmine Fofana, connue publiquement sous le nom Afrofoodie, est une créatrice de contenu ivoirienne spécialisée dans la gastronomie africaine, le tourisme culinaire et le lifestyle. Son identité et ses réseaux sont concordants entre son site officiel, son blog historique et sa fiche IGCAT. Profil recensé et non classable tant que PASS50 n’a pas validé ses métriques récentes.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const id=String(item&&item.id||'').toLowerCase();
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(id===PROFILE_ID||id==='yasmine-fofana'||id==='afrofoodie'||name==='yasmine fofana'||name==='afrofoodie'||name==='yasmine fofana / afrofoodie'||handle==='@afrofoodie'||handle==='afrofoodie');
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
