(function(){
'use strict';

const PROFILE_ID='census-cahie-kunta';
const TIKTOK_URL='https://www.tiktok.com/@cahiekunta';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-19T23:20:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Cahié kunta',
    handle:'@cahiekunta',
    initials:'CK',
    region:'CI',
    category:'Politique / Société',
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
    photoNote:'Photo à valider depuis le compte TikTok @cahiekunta.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @cahiekunta confirmé — voix des Chapeaux Rouges, recensé le 19 août 2026.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P1',
    source:{publisher:'Signalement PASS50 — profil TikTok Cahié kunta',date:'2026-08-19',url:TIKTOK_URL},
    notes:'Influenceur ivoirien connu sous Cahié kunta (@cahiekunta). Président des Chapeaux Rouges, contenus politiques et société. Profil recensé et relié au Radar LIVE PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name.includes('cahié')||name.includes('cahie')||handle==='@cahiekunta'||handle==='cahiekunta');
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    ['name','handle','initials','region','category','censusStatus','verificationPriority','source','notes'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''){profile[key]=patch[key];changed=true;}
    });
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(!profile.linkChecks.TikTok){profile.linkChecks.TikTok=patch.linkChecks.TikTok;changed=true;}
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('TikTok')){profile.platforms.push('TikTok');changed=true;}
  }
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
