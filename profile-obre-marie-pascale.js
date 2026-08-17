(function(){
'use strict';

const PROFILE_ID='obre-marie-pascale';
const LINKEDIN_URL='https://ci.linkedin.com/in/marie-pascale-obr%C3%A9-301a22257';
const PULSE_SOURCE='https://www.pulse.ci/article/canal-cote-divoire-presente-les-laureats-de-la-2e-edition-de-canal-creative-talents-2025062614472915317';
const FRATMAT_SOURCE='https://www.fratmat.info/article/2641381/culture/masa-2026-une-pluie-de-recompenses-pour-une-scene-africaine-en-pleine-effervescence';
let attempts=0;

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'OBRE',
    handle:'OBRE',
    initials:'OB',
    region:'CI',
    category:'Création de contenu / Journalisme / Storytelling',
    platforms:['LinkedIn'],
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
    links:{LinkedIn:LINKEDIN_URL},
    linkChecks:{
      LinkedIn:{
        status:'manual_verified',
        checkedAt:'2026-07-28T00:00:00.000Z',
        message:'Profil public Marie Pascale Obré à Abidjan, concordant avec les sources presse et les distinctions enregistrées.'
      }
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Pulse Côte d’Ivoire — CANAL+ Creative Talents',date:'2025-06-26',url:PULSE_SOURCE},
    sources:[
      {publisher:'Pulse Côte d’Ivoire — CANAL+ Creative Talents',date:'2025-06-26',url:PULSE_SOURCE},
      {publisher:'Fraternité Matin — MASA 2026',date:'2026-04-20',url:FRATMAT_SOURCE},
      {publisher:'LinkedIn — profil public Marie Pascale Obré',date:'2026-07-28',url:LINKEDIN_URL}
    ],
    notes:'Koné Obré Osange Marie Pascale, connue publiquement sous le nom OBRE, est une créatrice de contenu et journaliste ivoirienne basée à Abidjan. Deuxième lauréate de CANAL+ Creative Talents Côte d’Ivoire en 2025, elle a remporté le Grand Prix Jeunes Créateurs de Contenus du MASA en 2026. Profil recensé avec un lien LinkedIn vérifié. Les autres comptes sociaux, la photo et les métriques récentes doivent encore être validés avant tout classement.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  const tombstoned=typeof p50IsDeletedProfileId==='function'
    ?p50IsDeletedProfileId(PROFILE_ID)
    :((window.P50_TOMBSTONE_PROFILE_IDS||[]).concat(Array.isArray(db.deletedProfileIds)?db.deletedProfileIds:[]).map(id=>String(id||'').toLowerCase()).includes(PROFILE_ID));
  if(tombstoned){
    const before=db.profiles.length;
    db.profiles=db.profiles.filter(item=>item&&item.id!==PROFILE_ID);
    if(db.profiles.length!==before){
      try{
        if(typeof save==='function')save();
        else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));
      }catch{}
    }
    return true;
  }

  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID||
      name==='obre'||
      name==='marie pascale obré'||
      name==='marie-pascale obré'||
      name==='marie pascale obre'||
      name==='koné obré osange marie pascale'||
      name==='kone obre osange marie pascale'||
      handle==='obre'
    );
  });
  let changed=false;
  const patch=baseProfile();

  if(!profile){
    profile=patch;
    db.profiles.push(profile);
    changed=true;
  }else{
    const required={
      name:profile.name||patch.name,
      handle:profile.handle||patch.handle,
      initials:profile.initials||patch.initials,
      region:profile.region||patch.region,
      category:profile.category||patch.category,
      alive:true,
      censusStatus:patch.censusStatus,
      verificationPriority:patch.verificationPriority
    };
    Object.entries(required).forEach(([key,value])=>{
      if(profile[key]!==value){profile[key]=value;changed=true;}
    });

    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('LinkedIn')){profile.platforms.push('LinkedIn');changed=true;}

    profile.links=profile.links||{};
    if(!profile.links.LinkedIn){profile.links.LinkedIn=LINKEDIN_URL;changed=true;}

    profile.linkChecks=profile.linkChecks||{};
    if(!profile.linkChecks.LinkedIn){profile.linkChecks.LinkedIn=patch.linkChecks.LinkedIn;changed=true;}

    profile.scores=profile.scores||patch.scores;
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
    profile.sources=Array.isArray(profile.sources)&&profile.sources.length?profile.sources:patch.sources;
    profile.source=profile.source||patch.source;
    profile.photoStatus=profile.photoStatus||'missing';
    profile.photoNote=profile.photoNote||patch.photoNote;
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