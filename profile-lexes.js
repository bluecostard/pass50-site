(function(){
'use strict';

const PROFILE_ID='census-lexes';
const TIKTOK_URL='https://www.tiktok.com/@stephanesacre';
const TIKTOK_LIVE_URL='https://www.tiktok.com/@stephanesacre/live';
const INSTAGRAM_URL='https://www.instagram.com/stephanesacre/';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-19T23:37:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'L\'Exès',
    handle:'@stephanesacre',
    initials:'SS',
    region:'CI',
    category:'Humour / Musique',
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
    photoNote:'Photo à valider depuis le compte TikTok @stephanesacre.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @stephanesacre confirmé — Stéphane Sacré (L\'Exès), signalé en direct le 19 août 2026.'),
      Instagram:verifiedLink('Compte Instagram @stephanesacre recensé.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Signalement PASS50 — TikTok stephanesacre (L\'Exès)',date:'2026-08-19',url:TIKTOK_URL},
    notes:'Stéphane Sacré, connu sous L\'Exès. Humoriste, chanteur et animateur ivoirien. TikTok @stephanesacre · Snapchat stephanesacre95. Direct signalé manuellement le 19 août 2026.'
  };
}

function closeStuckManualLive(){
  if(typeof db==='undefined')return false;
  db.liveStreams=Array.isArray(db.liveStreams)?db.liveStreams:[];
  const before=db.liveStreams.length;
  db.liveStreams=db.liveStreams.filter(stream=>{
    if(!stream||stream.profileId!==PROFILE_ID||stream.platform!=='TikTok')return true;
    if(stream.source==='manual')return false;
    const id=String(stream.id||'');
    return !id.startsWith('live_lexes_');
  });
  return db.liveStreams.length!==before;
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    const id=String(item&&item.id||'');
    return item&&(id===PROFILE_ID||id==='census-lexes'||name.includes('exès')||name.includes('exes')||name.includes('stéphane sacré')||name.includes('stephane sacre')||handle==='@stephanesacre'||handle==='stephanesacre');
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
    if(!profile.links.Instagram){profile.links.Instagram=INSTAGRAM_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(!profile.linkChecks.TikTok||profile.linkChecks.TikTok.status!=='manual_verified'){
      profile.linkChecks.TikTok=patch.linkChecks.TikTok;changed=true;
    }
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    ['TikTok','Instagram'].forEach(p=>{if(!profile.platforms.includes(p)){profile.platforms.push(p);changed=true;}});
  }
  if(closeStuckManualLive())changed=true;
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
