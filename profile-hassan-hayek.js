(function(){
'use strict';

const PROFILE_ID='hassan';
const TIKTOK_URL='https://www.tiktok.com/@hassanhayekofficiel';
const INSTAGRAM_URL='https://www.instagram.com/hassanhayek/';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-23T16:07:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Hassan Hayek',
    realName:'Hassan Hayek',
    handle:'@hassanhayekofficiel',
    initials:'HH',
    region:'CI',
    country:'CI',
    category:'Société / Actions sociales',
    occupation:'Homme d’affaires et acteur social ivoirien',
    platforms:['TikTok','Instagram','Facebook'],
    scores:{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0},
    delta:0,
    decline:0,
    alive:true,
    eligible:true,
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
    photoNote:'Photo à valider depuis le compte TikTok @hassanhayekofficiel.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @hassanhayekofficiel — Hassan Hayek, live P0 PASS50.'),
      Instagram:verifiedLink('Compte Instagram @hassanhayek — Hassan Hayek.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    knownAlias:'Le vagabond de la charité',
    source:{publisher:'L’Avenir',date:'2026-08-18',url:'https://www.lavenir.ci/people/16025-sos-pour-papitou-hassan-hayek-pret-a-tout-prendre-en-charge'},
    notes:'Hassan Hayek, homme d’affaires et acteur social ivoirien, surnommé le vagabond de la charité. Live TikTok actuel @hassanhayekofficiel, relié au Radar LIVE P0. Instagram @hassanhayek. Date de naissance non confirmée.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase().replace(/^@/,'');
    const tiktok=String((item&&item.links&&item.links.TikTok)||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID
      ||name==='hassan hayek'
      ||handle==='hassanhayekofficiel'
      ||handle==='hassanhayek'
      ||tiktok.includes('hassanhayekofficiel')
      ||tiktok.includes('/@hassanhayek')
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','realName','initials','region','country','category','occupation','censusStatus','source','notes','knownAlias'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''){profile[key]=patch[key];changed=true;}
    });
    if(profile.handle!=='@hassanhayekofficiel'){profile.handle='@hassanhayekofficiel';changed=true;}
    if(profile.verificationPriority!=='P0'){profile.verificationPriority='P0';changed=true;}
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
    if(!profile.links.Instagram){profile.links.Instagram=INSTAGRAM_URL;changed=true;}
    profile.linkChecks=profile.linkChecks||{};
    if(!profile.linkChecks.TikTok||profile.linkChecks.TikTok.status!=='manual_verified'){
      profile.linkChecks.TikTok=patch.linkChecks.TikTok;changed=true;
    }
    profile.platforms=Array.isArray(profile.platforms)?profile.platforms:[];
    if(!profile.platforms.includes('TikTok')){profile.platforms.push('TikTok');changed=true;}
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
