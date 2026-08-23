(function(){
'use strict';

const PROFILE_ID='census-bb-sans-os-de-man';
const TIKTOK_URL='https://www.tiktok.com/@bebe.sans.os.de.m';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-23T00:23:00.000Z',message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'BB Sans Os de Man',
    realName:'BB Sans Os de Man',
    handle:'@bebe.sans.os.de.m',
    initials:'BB',
    region:'CI',
    country:'CI',
    category:'Danse / Musique / Divertissement',
    occupation:'Danseur et artiste ivoirien',
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
    photoNote:'Photo à valider depuis le compte TikTok @bebe.sans.os.de.m.',
    photoPosition:'50% 50%',
    photoManualLocked:false,
    photoManualUpdatedAt:null,
    badges:[],
    links:{TikTok:TIKTOK_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok officiel @bebe.sans.os.de.m — BB Sans Os de Man, recensé ; live P0 PASS50.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    knownAlias:'Bébé Sans Os de Man · BB Sans Os · Khadafi · Wrouwrou',
    source:{publisher:'Presse Côte d’Ivoire',date:'2023-06-06',url:'https://www.pressecotedivoire.fr/16972-tombe-sous-le-charme-dabidjan-bb-sans-os-de-man-refuse-meme-pour-de-largent-de-se-retourner-au-village'},
    notes:'Danseur et artiste ivoirien révélé par les réseaux sociaux (Presse Côte d’Ivoire, 6 juin 2023). Compte TikTok officiel actuel @bebe.sans.os.de.m (BÉBÉ SANS OS DE MAN OFFICIEL), relié au Radar LIVE P0 PASS50. Date de naissance non confirmée.'
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
      ||name==='bb sans os de man'
      ||name==='bebe sans os de man'
      ||name.includes('sans os de man')
      ||handle==='bebe.sans.os.de.m'
      ||handle==='bebe_sans_os'
      ||tiktok.includes('bebe.sans.os.de.m')
    );
  });
  let changed=false;
  if(!profile){profile=patch;db.profiles.push(profile);changed=true;}
  else{
    if(profile.id!==PROFILE_ID){profile.id=PROFILE_ID;changed=true;}
    ['name','realName','handle','initials','region','country','category','occupation','censusStatus','source','notes','knownAlias'].forEach(key=>{
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
