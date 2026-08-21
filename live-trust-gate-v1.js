(function(){
'use strict';

if(window.__pass50LiveTrustGateV1)return;
window.__pass50LiveTrustGateV1=true;

const VERSION='1.2.0';
const DEFAULT_TRUST_SECONDS={TikTok:1800,YouTube:1200,Instagram:600,Facebook:600};

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

function pruneEndedLive(live){
  const profileId=String(live?.profileId||'');
  const platform=String(live?.platform||'');
  if(!profileId)return;
  try{
    if(Array.isArray(db?.liveStreams)){
      db.liveStreams=db.liveStreams.filter(item=>!(String(item.profileId)===profileId&&String(item.platform)===platform));
      normalizeLiveStreams?.();
      localStorage.setItem(APP_KEY,JSON.stringify(db));
      render?.();
      if(document.getElementById('liveModal')?.classList.contains('show'))openLives?.();
    }
  }catch{}
}

/** Ouvre d’abord (geste utilisateur), puis vérifie en arrière-plan pour purger les fantômes. */
function openThenVerify(live,preferredUrl=''){
  const fallbackUrl=String(preferredUrl||live?.url||'');
  if(!fallbackUrl&&!live?.url)return false;

  let opened=false;
  if(typeof window.PASS50_OPEN_LIVE==='function')opened=!!window.PASS50_OPEN_LIVE(live,fallbackUrl);
  else if(fallbackUrl){window.location.href=fallbackUrl;opened=true;}

  if(live?.source==='manual')return opened;

  const profileId=String(live?.profileId||'');
  const platform=String(live?.platform||'');
  if(!profileId||typeof window.PASS50_VERIFY_LIVE_PROFILE!=='function')return opened;

  setTimeout(async()=>{
    try{
      const data=await window.PASS50_VERIFY_LIVE_PROFILE(profileId);
      const confirmed=(data?.liveStreams||[]).find(item=>String(item.profileId)===profileId&&String(item.platform)===platform&&item.status==='live'&&isFreshLive(item))||null;
      if(confirmed)return;
      pruneEndedLive(live);
      try{if(typeof toast==='function')toast('Ce direct est terminé ou n’est plus confirmé.');}catch{}
    }catch{}
  },0);

  return opened;
}

// Ne jamais intercepter .live-watch-link ici : preventDefault + await casse iOS / Universal Links.
// Le lien <a> (ou live-experience) ouvre immédiatement ; on purge seulement en arrière-plan.
window.PASS50_LIVE_TRUST_GATE=VERSION;
window.PASS50_LIVE_IS_FRESH=isFreshLive;
window.PASS50_LIVE_FILTER_PUBLIC=filterPublicLives;
window.PASS50_VERIFY_THEN_OPEN_LIVE=openThenVerify;
window.PASS50_OPEN_THEN_VERIFY_LIVE=openThenVerify;

document.addEventListener('DOMContentLoaded',installTrustNormalizer);
setTimeout(installTrustNormalizer,0);
})();
