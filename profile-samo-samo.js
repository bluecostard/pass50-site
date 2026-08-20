(function(){
'use strict';

const PROFILE_ID='samo-samo';
const TIKTOK_URL='https://www.tiktok.com/@kommandersamosamo';
const INSTAGRAM_URL='https://www.instagram.com/kommander_samo_samo/';
const JOURNAL_SOURCE='https://journaldabidjan.com/du-micro-a-loctogone-kommander-samo-rejoint-officiellement-pfl-africa-et-passe-au-mma-professionnel/';
const MONDIAL_SOURCE='https://mondialsport.ci/kommander-samo-samo-rejoint-pfl-africa-31773.sport';
const RFI_SOURCE='https://www.rfi.fr/fr/podcasts/afro-club-et-afro-club-deluxe/20260701-kommander-samo-samo-ambassadeur-du-logobi-et-du-gnaman-gnaman-ivoiriens';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-12T00:00:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Samo Samo',
    handle:'@kommandersamosamo',
    initials:'SS',
    region:'CI',
    country:'CI',
    category:'Musique / Sport / Divertissement',
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
      TikTok:verifiedLink('Compte TikTok public actif @kommandersamosamo (Kommander Samo Samo), rattaché à l’identité publique de Samo Samo.'),
      Instagram:verifiedLink('Compte Instagram public @kommander_samo_samo.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Journal d’Abidjan — Kommander Samo / PFL Africa',date:'2025',url:JOURNAL_SOURCE},
    sources:[
      {publisher:'Journal d’Abidjan — signature PFL Africa',date:'2025',url:JOURNAL_SOURCE},
      {publisher:'Mondial Sport — Kommander Samo Samo rejoint PFL Africa',date:'2025',url:MONDIAL_SOURCE},
      {publisher:'RFI Afro-Club — Kommander Samo Samo',date:'2026-07-01',url:RFI_SOURCE},
      {publisher:'TikTok public — @kommandersamosamo',date:'2026-08-12',url:TIKTOK_URL},
      {publisher:'Instagram public — @kommander_samo_samo',date:'2026-08-18',url:INSTAGRAM_URL}
    ],
    notes:'Zigui Dona Salim, connu publiquement comme Samo Samo / Kommander Samo Samo, est un artiste ivoirien (Team Paiya) et combattant MMA. Compte TikTok principal : @kommandersamosamo. Compte Instagram officiel : @kommander_samo_samo. Profil recensé, non classable tant que les métriques récentes ne sont pas consolidées par PASS50.'
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
      ||name==='samo samo'
      ||name==='kommander samo samo'
      ||name==='kommander samo'
      ||name==='zigui dona salim'
      ||handle==='@kommandersamosamo'
      ||handle==='kommandersamosamo'
      ||handle==='@kommander_samo_samo'
      ||handle==='kommander_samo_samo'
      ||handle==='@samosamo'
      ||handle==='samosamo'
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
    Object.entries(patch.links).forEach(([platform,url])=>{
      const current=String(profile.links[platform]||'');
      const staleInstagram=platform==='Instagram'&&/instagram\.com\/kommandersamosamo\/?$/i.test(current);
      if(!current||staleInstagram){
        profile.links[platform]=url;changed=true;
        if(staleInstagram&&patch.linkChecks[platform]){
          profile.linkChecks=profile.linkChecks||{};
          profile.linkChecks[platform]=patch.linkChecks[platform];
        }
      }
    });
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
