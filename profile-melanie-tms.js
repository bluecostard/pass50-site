(function(){
'use strict';

const PROFILE_ID='melanie-tms';
const TIKTOK_URL='https://www.tiktok.com/@melanie.tms';
const INSTAGRAM_URL='https://www.instagram.com/melanie.tms/';
const YOUTUBE_URL='https://www.youtube.com/channel/UCN90VdISLWTMynuMuVwUjDQ';
const SNAPCHAT_URL='https://www.snapchat.com/add/melanie_thomxs';
const X_URL='https://x.com/melanie__tms';
const HUB_SOURCE='https://msha.ke/melanie.tms/';
const STARNGAGE_SOURCE='https://starngage.pro/ranking/tiktok/C%C3%B4te%20d%27Ivoire/All';
const PRESS_SOURCE='https://www.igfm.sn/A-la-decouverte-de-melanie-thomas-la-star-montante-de-now-united';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-07-27T00:00:00.000Z',message:message};
}

function baseProfile(){
  return {
    id:PROFILE_ID,
    name:'Mélanie TMS',
    handle:'@melanie.tms',
    initials:'MT',
    region:'DIASPORA',
    country:'FR',
    category:'Musique / Danse / Divertissement',
    platforms:['TikTok','Instagram','YouTube','Snapchat','X'],
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
      Instagram:INSTAGRAM_URL,
      YouTube:YOUTUBE_URL,
      Snapchat:SNAPCHAT_URL,
      X:X_URL
    },
    linkChecks:{
      TikTok:verifiedLink('Lien confirmé par le hub public de Mélanie Thomas et par un classement concordant.'),
      Instagram:verifiedLink('Lien publié sur le hub public de Mélanie Thomas.'),
      YouTube:verifiedLink('Chaîne publiée sur le hub public de Mélanie Thomas.'),
      Snapchat:verifiedLink('Compte publié sur le hub public de Mélanie Thomas.'),
      X:verifiedLink('Compte publié sur le hub public de Mélanie Thomas.')
    },
    verifiedPass50:false,
    censusStatus:'Recensé confirmé',
    verificationPriority:'P0',
    source:{publisher:'Hub public de Mélanie Thomas',date:'2026-07-27',url:HUB_SOURCE},
    sources:[
      {publisher:'Hub public de Mélanie Thomas',date:'2026-07-27',url:HUB_SOURCE},
      {publisher:'StarNgage — classement TikTok Côte d’Ivoire',date:'2026-07',url:STARNGAGE_SOURCE},
      {publisher:'iGFM — portrait de Mélanie Thomas',date:'2022-09-02',url:PRESS_SOURCE}
    ],
    notes:'Mélanie Thomas, connue publiquement sous le nom Mélanie TMS, est une artiste et danseuse franco-ivoirienne née à Abidjan et active dans la diaspora en France. Elle est notamment connue pour sa participation à Now United. Profil recensé avec comptes sociaux concordants. Non classable tant que les métriques récentes ne sont pas validées par PASS50.'
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;

  let profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(
      item.id===PROFILE_ID||
      name==='mélanie tms'||
      name==='melanie tms'||
      name==='mélanie thomas'||
      name==='melanie thomas'||
      handle==='@melanie.tms'||
      handle==='melanie.tms'
    );
  });
  let changed=false;
  const patch=baseProfile();

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