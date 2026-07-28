(function(){
'use strict';

const PROFILE_ID='ismael-aka';
const TIKTOK_URL='https://www.tiktok.com/@ismael.aka.ddr';
const FACEBOOK_URL='https://www.facebook.com/profile.php?id=100052447782343';
const YOUTUBE_URL='https://www.youtube.com/channel/UCkNi90ORn66edC-hB5sBbnQ';
const INSTAGRAM_URL='https://www.instagram.com/la_ddr/';
const DDR_SOURCE='https://groupeddr.com/video.php';
const MOSAIQUE_SOURCE='https://mosaiqueguinee.com/2022/11/religion-oustag-diane-et-ismael-aka-posent-leur-valise-a-conakry/';
const CHARTEX_SOURCE='https://chartex.com/song/emmanuel-bouaffo-il-y-a-de-lespoir-pour-toi-622274';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-07-28T00:00:00.000Z',message:message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Ismaël Aka',
    handle:'@ismael.aka.ddr',
    initials:'IA',
    region:'CI',
    country:'CI',
    category:'Religion / Islam / Débats interreligieux',
    platforms:['TikTok','Facebook','YouTube','Instagram'],
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
    links:{
      TikTok:TIKTOK_URL,
      Facebook:FACEBOOK_URL,
      YouTube:YOUTUBE_URL,
      Instagram:INSTAGRAM_URL
    },
    linkChecks:{
      TikTok:verifiedLink('Compte @ismael.aka.ddr concordant avec les relevés publics de créateur ivoirien et l’identité DDR.'),
      Facebook:verifiedLink('Profil Facebook d’Ismaël Aka relié directement par le site officiel de la DDR.'),
      YouTube:verifiedLink('Chaîne collective DDR La Vraie Chaîne reliée par le site officiel de la DDR.'),
      Instagram:verifiedLink('Compte collectif @la_ddr relié par le site officiel de la DDR.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'DDR — site officiel et réseaux associés',date:'2026-07-28',url:DDR_SOURCE},
    sources:[
      {publisher:'DDR — site officiel et réseaux associés',date:'2026-07-28',url:DDR_SOURCE},
      {publisher:'Mosaique Guinée — déplacement religieux DDR',date:'2022-11-10',url:MOSAIQUE_SOURCE},
      {publisher:'Chartex — relevé public du compte TikTok',date:'2026-07-28',url:CHARTEX_SOURCE}
    ],
    notes:'Ismaël Aka est un prédicateur et créateur religieux ivoirien associé à la DDR. Ses contenus portent sur l’islam, l’apologétique et les débats interreligieux. Le TikTok et le profil Facebook sont personnels ; YouTube et Instagram sont des comptes collectifs DDR associés. Profil recensé, non classable tant que les métriques récentes ne sont pas validées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID||
      name==='ismaël aka'||
      name==='ismael aka'||
      name==='aka ismaël'||
      name==='aka ismael'||
      name==='ismaël aka ddr'||
      name==='ismael aka ddr'||
      handle==='@ismael.aka.ddr'||
      handle==='ismael.aka.ddr'
    );
  });

  const patch=baseProfile();
  let changed=false;

  if(!profile){
    db.profiles.push(patch);
    changed=true;
  }else{
    const fillFields=['name','handle','initials','region','country','category','censusStatus','verificationPriority','source','sources','notes'];
    fillFields.forEach(key=>{
      const current=profile[key];
      const empty=current===undefined||current===null||current===''||(Array.isArray(current)&&current.length===0);
      if(empty){profile[key]=patch[key];changed=true;}
    });

    ['alive','eligible','classable','verifiedPass50','ageStatus','agePublic','photoStatus','photoNote','photoPosition'].forEach(key=>{
      if(profile[key]===undefined){profile[key]=patch[key];changed=true;}
    });

    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    patch.platforms.forEach(platform=>{
      if(!profile.platforms.includes(platform)){profile.platforms.push(platform);changed=true;}
    });

    profile.links=profile.links||{};
    Object.entries(patch.links).forEach(([platform,url])=>{
      if(!profile.links[platform]){profile.links[platform]=url;changed=true;}
    });

    profile.linkChecks=profile.linkChecks||{};
    Object.entries(patch.linkChecks).forEach(([platform,check])=>{
      if(!profile.linkChecks[platform]){profile.linkChecks[platform]=check;changed=true;}
    });

    profile.scores=profile.scores||patch.scores;
    profile.badges=Array.isArray(profile.badges)?profile.badges:[];
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