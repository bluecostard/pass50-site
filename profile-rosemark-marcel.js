(function(){
'use strict';

const PROFILE_ID='census-rosemark-marcel';
const TIKTOK_URL='https://www.tiktok.com/@rosemarkmarcel';
const INSTAGRAM_URL='https://www.instagram.com/marcel_rosemark_officiel/';
const FACEBOOK_URL='https://www.facebook.com/p/Rosemark-Marcel-100064043561730/';
const YOUTUBE_URL='https://www.youtube.com/@RosemarkMarcelOfficiel';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-20T20:48:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Rosemark Marcel',
    handle:'@rosemarkmarcel',
    initials:'RM',
    region:'CI',
    category:'Humour / Divertissement',
    platforms:['TikTok','Instagram','Facebook','YouTube'],
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
    photoNote:'Photo à valider depuis le compte TikTok @rosemarkmarcel.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL,Facebook:FACEBOOK_URL,YouTube:YOUTUBE_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @rosemarkmarcel confirmé — humoriste ivoirien (~247 K abonnés), recensé le 20 août 2026.'),
      Instagram:verifiedLink('Compte Instagram @marcel_rosemark_officiel recensé (Rosemark Marcel).'),
      Facebook:verifiedLink('Page Facebook Rosemark Marcel (id 100064043561730) recensée.'),
      YouTube:verifiedLink('Chaîne YouTube @RosemarkMarcelOfficiel recensée.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Signalement PASS50 — profil TikTok Rosemark Marcel',date:'2026-08-20',url:TIKTOK_URL},
    notes:'Rosemark Marcel, créateur ivoirien (humour). TikTok @rosemarkmarcel · Instagram @marcel_rosemark_officiel · Facebook Rosemark Marcel · YouTube @RosemarkMarcelOfficiel. Comptes reliés au Radar LIVE P0 PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    const tiktok=String((item&&item.links&&item.links.TikTok)||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID
      ||name==='rosemark marcel'
      ||name.includes('rosemark marcel')
      ||handle==='@rosemarkmarcel'
      ||handle==='rosemarkmarcel'
      ||tiktok.includes('@rosemarkmarcel')
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','handle','initials','region','category','censusStatus','verificationPriority','source','notes'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''){profile[key]=patch[key];changed=true;}
    });
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    if(profile.links.Instagram!==INSTAGRAM_URL){profile.links.Instagram=INSTAGRAM_URL;changed=true;}
    if(profile.links.Facebook!==FACEBOOK_URL){profile.links.Facebook=FACEBOOK_URL;changed=true;}
    if(profile.links.YouTube!==YOUTUBE_URL){profile.links.YouTube=YOUTUBE_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    ['TikTok','Instagram','Facebook','YouTube'].forEach(p=>{
      if(!profile.linkChecks[p]||profile.linkChecks[p].status!=='manual_verified'){
        profile.linkChecks[p]=patch.linkChecks[p];changed=true;
      }
    });
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    ['TikTok','Instagram','Facebook','YouTube'].forEach(p=>{if(!profile.platforms.includes(p)){profile.platforms.push(p);changed=true;}});
  }
  if(typeof p50ClearImplausibleBirth==='function'){
    const before=JSON.stringify({d:profile.birthDate,y:profile.birthYear,s:profile.ageStatus});
    p50ClearImplausibleBirth(profile);
    if(JSON.stringify({d:profile.birthDate,y:profile.birthYear,s:profile.ageStatus})!==before)changed=true;
  }else if(Number(profile.birthYear)===2026||String(profile.birthDate||'').startsWith('2026')){
    profile.birthDate=null;profile.birthYear=null;profile.ageStatus='unconfirmed';profile.birthManualLocked=false;changed=true;
  }
  if(profile.verifiedPass50===undefined){profile.verifiedPass50=false;changed=true;}
  if(changed){
    try{if(typeof save==='function')save();else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
    try{if(typeof render==='function')render();}catch{}
  }
  return true;
}

function tick(){attempts++;const ready=applyProfile();if((ready&&window.__pass50CloudReady)||attempts>=240)clearInterval(timer);}
const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
})();
