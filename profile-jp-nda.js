(function(){
'use strict';

const PROFILE_ID='census-jp-nda';
const TIKTOK_URL='https://www.tiktok.com/@jpnda_1';
const TIKTOK_LIVE_URL='https://www.tiktok.com/@jpnda_1/live';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-19T23:20:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'JP N\'da',
    handle:'@jpnda_1',
    initials:'JP',
    region:'CI',
    category:'Humour / Show interactif',
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
    photoNote:'Photo à valider depuis le compte TikTok @jpnda_1.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @jpnda_1 confirmé — créateur ivoirien, signalé en direct le 19 août 2026.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Signalement PASS50 — profil TikTok JP N\'da',date:'2026-08-19',url:TIKTOK_URL},
    notes:'Créateur ivoirien (JP N\'da). Humour, charme et show interactif « Canapé de PIJ ». Compte TikTok @jpnda_1 relié au Radar LIVE ; direct signalé manuellement le 19 août 2026.'
  };
}

function ensureManualLive(){
  if(typeof db==='undefined')return false;
  db.liveStreams=Array.isArray(db.liveStreams)?db.liveStreams:[];
  const fresh=(stream)=>{
    if(!stream||stream.profileId!==PROFILE_ID||stream.platform!=='TikTok'||stream.status!=='live')return false;
    const ends=new Date(stream.endsAt||'').getTime();
    return Number.isFinite(ends)&&ends>Date.now();
  };
  if(db.liveStreams.some(fresh))return false;
  db.liveStreams=db.liveStreams.filter(s=>!(s.profileId===PROFILE_ID&&s.platform==='TikTok'&&s.status==='live'));
  db.liveStreams.push({
    id:'live_jpnda_'+Date.now(),
    profileId:PROFILE_ID,
    platform:'TikTok',
    url:TIKTOK_LIVE_URL,
    title:'Direct en cours — Canapé de PIJ',
    status:'live',
    source:'manual',
    startedAt:new Date().toISOString(),
    endsAt:new Date(Date.now()+180*60000).toISOString()
  });
  return true;
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name.includes('jp n')||handle==='@jpnda_1'||handle==='jpnda_1');
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
    if(!profile.linkChecks.TikTok||profile.linkChecks.TikTok.status!=='manual_verified'){
      profile.linkChecks.TikTok=patch.linkChecks.TikTok;changed=true;
    }
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('TikTok')){profile.platforms.push('TikTok');changed=true;}
  }
  if(ensureManualLive())changed=true;
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
