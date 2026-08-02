(function(){
'use strict';

if(window.__pass50LiveTrustGateV1)return;
window.__pass50LiveTrustGateV1=true;

const VERSION='1.0.0';
const DEFAULT_TRUST_SECONDS={TikTok:90,YouTube:240,Instagram:120,Facebook:120};

function trustSeconds(platform){
  const configured=Number(window.PASS50_LIVE_RADAR?.trustSeconds?.[platform]);
  if(Number.isFinite(configured)&&configured>0)return configured;
  return Number(DEFAULT_TRUST_SECONDS[platform]||120);
}

function serverNow(){
  const parsed=new Date(window.PASS50_LIVE_RADAR?.serverNow||'').getTime();
  return Number.isFinite(parsed)?parsed:Date.now();
}

function isFreshLive(item,now=serverNow()){
  if(!item||item.status!=='live'||!item.profileId||!/^https?:\/\//i.test(String(item.url||'')))return false;
  if(item.source==='manual'){
    const endsAt=new Date(item.endsAt||'').getTime();
    return Number.isFinite(endsAt)&&endsAt>now;
  }
  let confirmedAt=new Date(item.lastConfirmedAt||item.lastSeenAt||'').getTime();
  if(!Number.isFinite(confirmedAt))return false;
  const skew=confirmedAt-now;
  if(skew>5*60_000&&skew<=6*60*60_000)confirmedAt=now;
  else if(skew>6*60*60_000)return false;
  if(String(item.lastCheckState||'live')!=='live'&&item.source!=='manual')return false;
  return (now-confirmedAt)<=trustSeconds(String(item.platform||''))*1000;
}

function filterPublicLives(list){
  const now=serverNow(),seen=new Set(),out=[];
  (Array.isArray(list)?list:[]).forEach(item=>{
    if(!isFreshLive(item,now))return;
    const key=[item.profileId,item.platform||'',String(item.url||'').replace(/\/+$/,'')].map(v=>String(v).trim().toLowerCase()).join('|');
    if(seen.has(key))return;
    seen.add(key);out.push(item);
  });
  return out;
}

function installTrustNormalizer(){
  const previous=typeof window.normalizeLiveStreams==='function'?window.normalizeLiveStreams:null;
  const normalizer=function(){
    if(!Array.isArray(db.liveStreams))db.liveStreams=[];
    db.liveStreams=filterPublicLives(db.liveStreams);
    if(Array.isArray(db.profiles))db.profiles.forEach(profile=>{
      profile.badges=(profile.badges||[]).filter(badge=>badge!=='LIVE');
      if(db.liveStreams.some(item=>item.profileId===profile.id&&item.status==='live'))profile.badges.unshift('LIVE');
    });
    if(typeof previous==='function'&&previous!==normalizer){
      // Already replaced; keep badges/liveStreams as filtered.
    }
  };
  window.normalizeLiveStreams=normalizer;
  try{normalizeLiveStreams=normalizer;}catch{}
  normalizer();
}

async function verifyThenOpen(live,preferredUrl=''){
  const profileId=String(live?.profileId||'');
  const platform=String(live?.platform||'');
  const fallbackUrl=String(preferredUrl||live?.url||'');
  if(!profileId||!fallbackUrl)return false;

  if(live?.source==='manual'){
    if(typeof window.PASS50_OPEN_LIVE==='function')return window.PASS50_OPEN_LIVE(live,fallbackUrl);
    window.location.href=fallbackUrl;return true;
  }

  try{if(typeof toast==='function')toast('Vérification du direct…');}catch{}

  let confirmed=null;
  try{
    if(typeof window.PASS50_VERIFY_LIVE_PROFILE==='function'){
      const data=await window.PASS50_VERIFY_LIVE_PROFILE(profileId);
      confirmed=(data?.liveStreams||[]).find(item=>String(item.profileId)===profileId&&String(item.platform)===platform&&item.status==='live'&&isFreshLive(item))||null;
    }
  }catch{}

  if(!confirmed){
    try{
      if(Array.isArray(db?.liveStreams)){
        db.liveStreams=db.liveStreams.filter(item=>!(String(item.profileId)===profileId&&String(item.platform)===platform));
        normalizeLiveStreams?.();
        localStorage.setItem(APP_KEY,JSON.stringify(db));
        render?.();
        if(document.getElementById('liveModal')?.classList.contains('show'))openLives?.();
      }
    }catch{}
    try{if(typeof toast==='function')toast('Ce direct est terminé ou n’est plus confirmé.');}catch{}
    return false;
  }

  if(typeof window.PASS50_OPEN_LIVE==='function')return window.PASS50_OPEN_LIVE(confirmed,confirmed.url||fallbackUrl);
  window.location.href=String(confirmed.url||fallbackUrl);
  return true;
}

window.addEventListener('click',event=>{
  const target=event.target instanceof Element?event.target:null;if(!target)return;
  const watch=target.closest('.live-watch-link[data-live-profile]');
  if(!watch)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const live={
    profileId:watch.dataset.liveProfile,
    platform:watch.dataset.livePlatform,
    url:watch.dataset.liveWebUrl||watch.href,
    roomId:watch.dataset.liveRoom,
    videoId:watch.dataset.liveVideo,
    handle:watch.dataset.liveHandle,
    source:'automatic',
    status:'live',
    lastConfirmedAt:watch.dataset.liveConfirmedAt,
    lastCheckState:'live',
  };
  verifyThenOpen(live,watch.dataset.liveWebUrl||watch.href);
},true);

document.addEventListener('DOMContentLoaded',installTrustNormalizer);
setTimeout(installTrustNormalizer,0);
window.PASS50_LIVE_TRUST_GATE=VERSION;
window.PASS50_LIVE_IS_FRESH=isFreshLive;
window.PASS50_LIVE_FILTER_PUBLIC=filterPublicLives;
window.PASS50_VERIFY_THEN_OPEN_LIVE=verifyThenOpen;
})();
