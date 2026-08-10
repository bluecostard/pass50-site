(function(){
'use strict';

const PROFILE_ID='apoutchou';
const TIKTOK_URL='https://www.tiktok.com/@apoutchou_national1';
const INSTAGRAM_URL='https://www.instagram.com/apoutchou_national_24/';
let attempts=0;

function verifiedLink(message){
  return {status:'manual_verified',checkedAt:'2026-08-11T00:00:00.000Z',message};
}

function basePatch(){
  return {
    id:PROFILE_ID,
    name:'Apoutchou National',
    handle:'@apoutchou_national1',
    links:{TikTok:TIKTOK_URL,Instagram:INSTAGRAM_URL},
    linkChecks:{
      TikTok:verifiedLink('Compte TikTok live vérifié @apoutchou_national1 (signalé en direct le 11 août 2026).'),
      Instagram:verifiedLink('Compte Instagram public @apoutchou_national_24.')
    }
  };
}

function applyProfile(){
  if(typeof db==='undefined'||!Array.isArray(db.profiles))return false;
  const patch=basePatch();
  const profile=db.profiles.find(item=>{
    const name=String(item&&item.name||'').toLowerCase();
    const handle=String(item&&item.handle||'').toLowerCase();
    return item&&(item.id===PROFILE_ID||name==='apoutchou national'||handle==='@apoutchounational'||handle==='@apoutchou_national1'||handle==='@apoutchou.225');
  });
  if(!profile)return false;
  let changed=false;
  profile.links=profile.links||{};
  const currentTikTok=String(profile.links.TikTok||'');
  const stale=/search\?q=|apoutchou\.225|apoutchounational(?!1)|\/@apoutchou(?!_national1)/i.test(currentTikTok)||!currentTikTok;
  if(stale||currentTikTok!==TIKTOK_URL){profile.links.TikTok=TIKTOK_URL;changed=true;}
  if(!profile.links.Instagram){profile.links.Instagram=INSTAGRAM_URL;changed=true;}
  profile.linkChecks=profile.linkChecks||{};
  if(!profile.linkChecks.TikTok||stale){profile.linkChecks.TikTok=patch.linkChecks.TikTok;changed=true;}
  if(String(profile.handle||'').toLowerCase()!=='@apoutchou_national1'){profile.handle=patch.handle;changed=true;}
  if(changed){try{if(typeof save==='function')save();else if(typeof APP_KEY!=='undefined')localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}try{if(typeof render==='function')render();}catch{}}
  return true;
}

function tick(){attempts++;const ready=applyProfile();if((ready&&window.__pass50CloudReady)||attempts>=240)clearInterval(timer);}
const timer=setInterval(tick,500);
document.addEventListener('DOMContentLoaded',tick);
window.addEventListener('load',tick,{once:true});
})();
