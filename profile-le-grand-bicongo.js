(function(){
'use strict';

const PROFILE_ID='census-le-grand-bicongo';
const TIKTOK_URL='https://www.tiktok.com/@legrandbicongo';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-23T16:18:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Le grand Bicongo',
    handle:'@legrandbicongo',
    initials:'LG',
    region:'CI',
    country:'CI',
    category:'TikTok / Live / Divertissement',
    occupation:'Créateur TikTok ; manager de Chocolat Show',
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
    photoNote:'Photo à valider depuis le compte TikTok @legrandbicongo.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @legrandbicongo confirmé — Le grand Bicongo, recensé le 23 août 2026.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    knownAlias:'Le grand Bicongo / @legrandbicongo',
    source:{publisher:'Signalement PASS50 — profil TikTok Le grand Bicongo',date:'2026-08-23',url:TIKTOK_URL},
    notes:'Créateur TikTok. Compte officiel @legrandbicongo. La bio publique de Chocolat show officiel le cite comme manager — deux personnes distinctes. Identité civile et date de naissance non confirmées.'
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
      ||name==='le grand bicongo'
      ||handle==='legrandbicongo'
      ||tiktok.includes('@legrandbicongo')
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','handle','initials','region','country','category','occupation','censusStatus','source','notes','knownAlias'].forEach(key=>{
      const value=profile[key];
      if(value===undefined||value===null||value===''){profile[key]=patch[key];changed=true;}
    });
    if(profile.verificationPriority!=='P0'){profile.verificationPriority='P0';changed=true;}
    profile.links=profile.links||{};
    if(profile.links.TikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
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
