(function(){
'use strict';

const PROFILE_ID='census-ange-boli';
const TIKTOK_URL='https://www.tiktok.com/@angeboli7';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-24T18:00:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Ange Boli',
    handle:'@angeboli7',
    initials:'AB',
    region:'CI',
    country:'CI',
    category:'Décoration / Humour / TikTok',
    occupation:'Décorateur / Créateur TikTok',
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
    photoNote:'Photo à valider depuis le compte TikTok @angeboli7.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok @angeboli7 confirmé — Ange Boli, recensé le 24 août 2026.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    knownAlias:'Ange Boli / LA VAR / AB décor / @angeboli7',
    source:{publisher:'Signalement PASS50 — profil TikTok Ange Boli',date:'2026-08-24',url:TIKTOK_URL},
    notes:'Créateur TikTok et décorateur à Daloa. Compte officiel @angeboli7. Distinct d’Ange Arthur, Ange Freddy et Ange Morel. Identité civile et date de naissance non confirmées.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=baseProfile();
  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const compact=name.replace(/[^a-z0-9]+/g,'');
    const handle=String(item&&item.handle||'').toLowerCase().replace(/^@/,'');
    const tiktok=String((item&&item.links&&item.links.TikTok)||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID
      ||name==='ange boli'
      ||compact==='angeboli'
      ||compact==='angebolilavar'
      ||handle==='angeboli7'
      ||tiktok.includes('@angeboli7')
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

function tick(){
  attempts++;
  const ready=applyProfile();
  if(attempts>=240){clearInterval(timer);return;}
  if(ready&&window.__pass50CloudReady){
    clearInterval(timer);
    setTimeout(applyProfile,800);
  }
}
const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
window.addEventListener('pass50:cloud-ready',function(){
  applyProfile();
  setTimeout(applyProfile,800);
});
})();
