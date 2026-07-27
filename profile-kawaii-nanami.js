(function(){
'use strict';

const PROFILE_ID='kawaii-nanami';
const RFI_SOURCE='https://www.rfi.fr/fr/podcasts/reportage-afrique/20240429-kawa%C3%AF-nanami-la-tiktokeuse-qui-veut-r%C3%A9concilier-les-jeunes-ivoiriens-avec-leur-culture-ancestrale';
const PULSE_SOURCE='https://www.pulse.ci/article/canal-cote-divoire-presente-les-laureats-de-la-2e-edition-de-canal-creative-talents-2025062614472915317';
let attempts=0;

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Kawaii Nanami',
    handle:'Kawaï',
    initials:'KN',
    region:'CI',
    category:'Culture / Éducation / Storytelling',
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
    links:{},
    linkChecks:{},
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P1',
    source:{publisher:'RFI — Reportage Afrique',date:'2024-04-30',url:RFI_SOURCE},
    sources:[
      {publisher:'RFI — Reportage Afrique',date:'2024-04-30',url:RFI_SOURCE},
      {publisher:'Pulse Côte d’Ivoire — CANAL+ Creative Talents',date:'2025-06-26',url:PULSE_SOURCE}
    ],
    notes:'Ruth-Esther Yapobi, connue sous les noms Kawaii Nanami et Kawaï, crée des contenus consacrés aux cultures ivoiriennes, notamment la série Échos d’Ivoire. Profil recensé sur la base de sources concordantes. Les liens sociaux directs, la photo et les métriques doivent encore être vérifiés avant tout classement.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID||
      name==='kawaii nanami'||
      name==='kawaï nanami'||
      name==='kawai nanami'||
      handle==='kawaï'||
      handle==='kawaii nanami'
    );
  });
  let changed=false;

  if(!profile){
    profile=baseProfile();
    db.profiles.push(profile);
    changed=true;
  }else{
    const patch=baseProfile();
    const required={
      name:patch.name,
      handle:patch.handle,
      initials:patch.initials,
      region:patch.region,
      category:profile.category||patch.category,
      alive:true,
      eligible:false,
      classable:false,
      verifiedPass50:false,
      censusStatus:patch.censusStatus,
      verificationPriority:patch.verificationPriority
    };
    Object.entries(required).forEach(([key,value])=>{
      if(profile[key]!==value){profile[key]=value;changed=true;}
    });

    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    ['TikTok','Instagram'].forEach(platform=>{
      if(!profile.platforms.includes(platform)){profile.platforms.push(platform);changed=true;}
    });
    profile.links=profile.links||{};
    profile.linkChecks=profile.linkChecks||{};
    profile.scores=profile.scores||{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0};
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
    profile.sources=Array.isArray(profile.sources)?profile.sources:patch.sources;
    profile.source=profile.source||patch.source;
    profile.photoStatus=profile.photoStatus||'missing';
    profile.photoNote=profile.photoNote||'Photo officielle à ajouter et valider.';
    profile.notes=profile.notes||patch.notes;
  }

  if(changed){
    try{
      if(typeof save==='function')save();
      else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));
    }catch{}
    try{if(typeof render==='function')render();}catch{}
  }
  return true;
}

function tick(){
  attempts++;
  const ready=applyProfile();
  if((ready&&window.__pass50CloudReady)||attempts>=240)clearInterval(timer);
}

const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
})();
