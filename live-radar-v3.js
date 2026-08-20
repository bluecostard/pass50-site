(function(){
'use strict';

const ENDPOINT='./api/live-status-v4.php';
const QUICK_INTERVAL=30_000;
const FULL_CYCLE_KEY='pass50_live_radar_v4_cycle';
const DEFAULT_TRUST_SECONDS={TikTok:480,Facebook:600,YouTube:720,Instagram:600};
const PLATFORM_PRIORITY=['TikTok','Facebook','YouTube','Instagram'];
const RADAR_BATCH_SIZE='14';
let runningMode='';
let lastData=null;
let autoTimer=null;
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

function trustSeconds(platform){
  const configured=Number(window.PASS50_LIVE_RADAR?.trustSeconds?.[platform]);
  return Number.isFinite(configured)&&configured>0?configured:Number(DEFAULT_TRUST_SECONDS[platform]||120);
}

function installLiveNormalizerV4(){
  if(window.__pass50LiveNormalizerV4)return;
  const normalizer=function(){
    if(typeof window.PASS50_LIVE_FILTER_PUBLIC==='function'){
      if(!Array.isArray(db.liveStreams))db.liveStreams=[];
      db.liveStreams=window.PASS50_LIVE_FILTER_PUBLIC(db.liveStreams);
    }else{
      if(!Array.isArray(db.liveStreams))db.liveStreams=[];
      const browserNow=Date.now();
      const serverParsed=new Date(window.PASS50_LIVE_RADAR?.serverNow||'').getTime();
      const now=Number.isFinite(serverParsed)?serverParsed:browserNow;
      const seen=new Set();
      db.liveStreams=db.liveStreams.filter(item=>{
        if(!item||item.status!=='live'||!item.profileId||!/^https?:\/\//i.test(String(item.url||'')))return false;
        if(item.source==='manual'){
          const endsAt=new Date(item.endsAt||'').getTime();
          if(!Number.isFinite(endsAt)||endsAt<=now)return false;
        }else{
          let confirmedAt=new Date(item.lastConfirmedAt||item.lastSeenAt||'').getTime();
          if(!Number.isFinite(confirmedAt))return false;
          const futureSkew=confirmedAt-now;
          if(futureSkew>5*60_000&&futureSkew<=6*60*60_000){
            confirmedAt=now;
            const fixed=new Date(now).toISOString();
            item.lastConfirmedAt=fixed;
            item.lastSeenAt=fixed;
          }else if(futureSkew>6*60*60_000){
            return false;
          }
          if(now-confirmedAt>trustSeconds(String(item.platform||''))*1000)return false;
        }
        const key=[item.profileId,item.platform||'',String(item.url).replace(/\/+$/,'')].map(value=>String(value).trim().toLowerCase()).join('|');
        if(seen.has(key))return false;
        seen.add(key);
        return true;
      });
    }
    if(Array.isArray(db.profiles))db.profiles.forEach(profile=>{
      profile.badges=(profile.badges||[]).filter(badge=>badge!=='LIVE');
      if(db.liveStreams.some(item=>item.profileId===profile.id&&item.status==='live'))profile.badges.unshift('LIVE');
    });
  };
  window.normalizeLiveStreams=normalizer;
  try{normalizeLiveStreams=normalizer;}catch{}
  window.__pass50LiveNormalizerV4=true;
  normalizer();
}

function liveCount(){
  try{return (db.liveStreams||[]).filter(item=>item.status==='live').length}catch{return 0}
}

function applyRadarData(data){
  if(!data?.ok)return;
  lastData=data;
  window.PASS50_LIVE_RADAR=data.radar||{};
  window.PASS50_LIVE_RADAR_LAST_DATA=data;
  installLiveNormalizerV4();
  if(Array.isArray(data.liveStreams)){
    db.liveStreams=data.liveStreams;
    if(typeof normalizeLiveStreams==='function')normalizeLiveStreams();
    try{localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
    if(typeof render==='function')render();
    if(document.getElementById('liveModal')?.classList.contains('show')&&typeof openLives==='function')openLives();
  }
  requestAnimationFrame(renderStatus);
}

function platformText(platforms={}){
  return PLATFORM_PRIORITY.map(name=>{
    const item=platforms[name]||{};
    const known=Number(item.known||0),scanned=Number(item.scanned||0),found=Number(item.found||0),candidates=Number(item.candidates||0),replays=Number(item.replays||0);
    return `${name} ${known}${scanned?` · ${scanned} testés`:''}${found?` · ${found} LIVE`:''}${candidates?` · ${candidates} à confirmer`:''}${replays?` · ${replays} replay`:''}`;
  }).join('<br>');
}

function healthText(health={}){
  const totals={live:0,offline:0,replay:0,unknown:0,unconfirmed:0,never_checked:0};
  Object.values(health||{}).forEach(item=>Object.keys(totals).forEach(key=>totals[key]+=Number(item?.[key]||0)));
  return `${totals.live} en direct confirmé · ${totals.unconfirmed} à confirmer · ${totals.replay} replay · ${totals.offline} hors ligne · ${totals.unknown} bloqués/inconnus · ${totals.never_checked} jamais contrôlés`;
}

function ensureStatusBox(){
  let box=document.getElementById('liveRadarV4Status');
  if(box)return box;
  const old=document.getElementById('liveRadarV3Status');
  if(old)old.remove();
  const button=document.getElementById('liveRadarRefresh');
  if(!button)return null;
  box=document.createElement('div');
  box.id='liveRadarV4Status';
  box.style.cssText='margin:10px 0 14px;padding:13px 14px;border:1px solid #293129;border-radius:14px;background:#0b0e0b;font-size:12px;line-height:1.55;color:#dce3d9';
  button.closest('.admin-toolbar,.tool-actions,div')?.insertAdjacentElement('afterend',box);
  return box;
}

function renderStatus(){
  const box=ensureStatusBox();
  if(!box)return;
  const radar=lastData?.radar||window.PASS50_LIVE_RADAR||{};
  const total=Number(radar.officialSourcesKnown??radar.cycleTotal??0),scanned=Number(radar.cycleScanned||0),coverage=Number(radar.coveragePercent||0);
  const classified=Number(radar.classifiedPercent||0),passCoverage=Number(radar.passCoveragePercent||0);
  const completed=radar.lastFullSweep?.completedAt||'';
  const diagnostics=Array.isArray(radar.diagnostics)?radar.diagnostics:[];
  const detected=diagnostics.filter(item=>item.state==='live').map(item=>esc(item.name)).filter(Boolean);
  const candidates=diagnostics.filter(item=>item.state==='probable').map(item=>esc(item.name)).filter(Boolean);
  const diagnosticRows=diagnostics.slice(-12).map(item=>{
    const probes=Object.entries(item.probes||{}).map(([name,probe])=>`${name}:${probe.status||probe.error||'—'}`).join(', ');
    return `${esc(item.name||'—')} · ${esc(item.platform||'—')} · ${esc(item.publicState||item.state||'unknown')} · confiance ${Number(item.confidence||0)} · ${esc(probes||'—')}`;
  }).join('<br>');
  const coverageLine=Number.isFinite(coverage)
    ? `Couverture 2h : ${coverage}% sondés · ${classified}% classifiés${passCoverage?` · passe ${passCoverage}%`:''}`
    : 'Surveillance rapide active';
  box.innerHTML=`<strong style="color:#b7ff00">RADAR LIVE V4</strong> · ${liveCount()} direct${liveCount()>1?'s':''} actif${liveCount()>1?'s':''}<br>`+
    `${total} lien${total>1?'s':''} officiel${total>1?'s':''} surveillé${total>1?'s':''}<br>`+
    `<span style="color:#aeb8aa">${platformText(radar.platforms)}</span><br>`+
    `${scanned>0?`Dernier balayage : ${scanned}/${Number(radar.cycleTotal||total)} · `:''}${coverageLine}<br>`+
    `${healthText(radar.health)}`+
    `${detected.length?`<br><strong style="color:#b7ff00">Confirmé : ${detected.join(', ')}</strong>`:''}`+
    `${candidates.length?`<br><span style="color:#f2d36b">À confirmer : ${candidates.join(', ')}</span>`:''}`+
    `${diagnosticRows?`<br><span style="color:#aeb8aa">${diagnosticRows}</span>`:''}`+
    `${completed?`<br>Balayage complet terminé : ${new Date(completed).toLocaleString('fr-FR')}`:''}`;
}

function setButton(text,disabled=true){
  const button=document.getElementById('liveRadarRefresh');if(!button)return;button.disabled=disabled;button.textContent=text;
}

async function fetchRadar(params){
  const query=new URLSearchParams({...params,t:String(Date.now())});
  const response=await fetch(`${ENDPOINT}?${query}`,{cache:'no-store',headers:{Accept:'application/json'}});
  const data=await response.json().catch(()=>null);
  if(!response.ok||!data?.ok)throw new Error(data?.error||'Radar LIVE indisponible');
  applyRadarData(data);return data;
}

async function runQuick(){
  // Poll cache-only : les scrapes massifs restent sur cron / bouton full / admin.
  if(runningMode||document.hidden)return null;
  runningMode='status';
  try{return await fetchRadar({mode:'status',batch:RADAR_BATCH_SIZE});}
  catch(error){console.warn('Radar LIVE statut',error);return null;}
  finally{runningMode='';}
}

function readCycle(){
  try{const saved=JSON.parse(localStorage.getItem(FULL_CYCLE_KEY)||'null');if(saved?.id&&Date.now()-Number(saved.at||0)<14*60_000)return saved.id;}catch{}
  return '';
}
function saveCycle(id){try{localStorage.setItem(FULL_CYCLE_KEY,JSON.stringify({id,at:Date.now()}))}catch{}}
function clearCycle(){try{localStorage.removeItem(FULL_CYCLE_KEY)}catch{}}

async function runFullSweep(){
  if(runningMode)return null;
  runningMode='full';const cycle=readCycle()||`web_${Date.now()}_${Math.random().toString(36).slice(2,8)}`;saveCycle(cycle);
  let data=null,calls=0,busyRetries=0;setButton('RADAR LIVE · PRÉPARATION…');
  try{
    do{
      data=await fetchRadar({mode:'full',force:'1',cycle,batch:RADAR_BATCH_SIZE});const radar=data.radar||{};
      if(radar.busy){busyRetries++;if(busyRetries>12)throw new Error('Le radar est déjà occupé');setButton('RADAR LIVE · ATTENTE DU SERVEUR…');await new Promise(resolve=>setTimeout(resolve,800));continue;}
      busyRetries=0;
      const scanned=Number(radar.cycleScanned||0),total=Number(radar.cycleTotal||0),confirmed=Number(radar.livesFoundInCycle||0),active=Number(radar.activeAutomaticConfirmed||0),candidates=Number(radar.candidatesFoundInCycle||0);
      setButton(`RADAR LIVE · ${scanned}/${total} · ${active} ACTIF${active>1?'S':''} · ${confirmed} CONFIRMATION${confirmed>1?'S':''}${candidates?` · ${candidates} À CONFIRMER`:''}`);calls++;
      if(radar.cycleComplete)break;await new Promise(resolve=>setTimeout(resolve,220));
    }while(calls<160);
    const radar=data?.radar||{};if(!radar.cycleComplete)throw new Error('Balayage interrompu avant la fin');clearCycle();
    const total=Number(radar.cycleTotal||0),confirmed=Number(radar.livesFoundInCycle||0),active=Number(radar.activeAutomaticConfirmed||0),candidates=Number(radar.candidatesFoundInCycle||0);
    if(typeof toast==='function')toast(`Radar LIVE : ${total} liens contrôlés · ${active} direct${active>1?'s':''} actif${active>1?'s':''} · ${confirmed} confirmation${confirmed>1?'s':''}${candidates?` · ${candidates} à confirmer`:''}`);
    return data;
  }catch(error){if(typeof toast==='function')toast(error?.message||'Radar LIVE indisponible');return null;}
  finally{runningMode='';setButton('🔴 LANCER LE RADAR COMPLET',false);renderStatus();}
}

async function verifyProfile(profileId){
  if(!profileId||runningMode)return null;runningMode='profile';
  try{return await fetchRadar({mode:'profile',force:'1',profileId:String(profileId),batch:RADAR_BATCH_SIZE});}
  catch(error){console.warn('Vérification LIVE ciblée',error);return null;}
  finally{runningMode='';renderStatus();}
}

function openExternal(url,live=null){
  if(typeof window.PASS50_OPEN_LIVE==='function'&&live)return window.PASS50_OPEN_LIVE(live,url);
  if(typeof window.PASS50_OPEN_LIVE==='function'&&url)return window.PASS50_OPEN_LIVE({url,platform:live?.platform||''},url);
  if(!/^https?:\/\//i.test(String(url||'')))return false;
  const mobile=/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'');
  if(mobile){window.location.href=String(url);return true;}
  const opened=window.open(String(url),'_blank');
  if(opened){try{opened.opener=null}catch{}return true;}
  const anchor=document.createElement('a');anchor.href=String(url);anchor.target='_blank';anchor.rel='noopener noreferrer';anchor.style.display='none';document.body.appendChild(anchor);anchor.click();anchor.remove();return true;
}

function badgeProfileId(badge){
  if(badge?.dataset?.liveProfile)return String(badge.dataset.liveProfile);
  const owner=badge.closest?.('[data-profile]');if(owner?.dataset?.profile)return String(owner.dataset.profile);
  const body=badge.closest?.('#profileBody');
  if(body?.dataset?.p50CurrentProfile)return String(body.dataset.p50CurrentProfile);
  if(body){
    const name=String(document.querySelector('#profileBody h2')?.textContent||'').trim().toLowerCase();
    const handle=String(document.querySelector('#profileBody .handle')?.textContent||'').trim().toLowerCase();
    try{
      const match=(db.profiles||[]).find(item=>{
        const itemName=String(item.name||'').trim().toLowerCase();
        const itemHandle=String(item.handle||'').trim().toLowerCase();
        return (name&&itemName===name)||(handle&&itemHandle===handle);
      });
      if(match?.id)return String(match.id);
    }catch{}
  }
  return '';
}

function ensureLiveTrustGate(){
  if(document.querySelector('script[data-pass50-live-trust-gate]'))return;
  const script=document.createElement('script');script.src='./live-trust-gate-v1.js?v=1.3';script.dataset.pass50LiveTrustGate='1.3';document.head.appendChild(script);
}
function ensureLiveExperience(){
  ensureLiveTrustGate();
  if(document.querySelector('script[data-pass50-live-experience-v41]'))return;
  const script=document.createElement('script');script.src='./live-experience-v4-1.js?v=1.7';script.dataset.pass50LiveExperienceV41='1.7';document.head.appendChild(script);
}

function bind(){
  installLiveNormalizerV4();ensureLiveExperience();
  const button=document.getElementById('liveRadarRefresh');
  if(button&&!button.dataset.liveRadarV4){button.dataset.liveRadarV4='1';button.textContent='🔴 LANCER LE RADAR COMPLET';}
  renderStatus();
}

if(typeof normalizeLiveStreams==='function')installLiveNormalizerV4();
document.addEventListener('click',event=>{
  const watchLink=event.target.closest?.('.live-watch-link[data-live-profile][data-live-platform]');
  if(watchLink){
    const profileId=String(watchLink.dataset.liveProfile||'');
    setTimeout(()=>{if(profileId)verifyProfile(profileId);},0);
    return;
  }
  const liveBadge=event.target.closest?.('.badge.live-badge');
  if(liveBadge){
    const profileId=badgeProfileId(liveBadge);
    let live=null;try{live=(db.liveStreams||[]).find(item=>String(item.profileId)===profileId&&item.status==='live')||null}catch{}
    if(live?.url){event.preventDefault();event.stopImmediatePropagation();openExternal(live.url,live);setTimeout(()=>verifyProfile(profileId),0);return;}
  }
  const radarButton=event.target.closest?.('#liveRadarRefresh');
  if(radarButton){event.preventDefault();event.stopImmediatePropagation();runFullSweep();return;}
  if(event.target.closest?.('#liveBtn'))setTimeout(runQuick,0);
},true);

document.addEventListener('p50:official-links-saved',event=>{const id=String(event.detail?.profileId||'');if(id)setTimeout(()=>verifyProfile(id),300);});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)runQuick();});
document.addEventListener('DOMContentLoaded',()=>{installLiveNormalizerV4();ensureLiveExperience();bind();setTimeout(runQuick,3000);autoTimer=setInterval(runQuick,QUICK_INTERVAL);try{refreshLiveStatus=runQuick}catch{}});
const observer=new MutationObserver(()=>requestAnimationFrame(bind));observer.observe(document.documentElement,{subtree:true,childList:true});
window.addEventListener('beforeunload',()=>{if(autoTimer)clearInterval(autoTimer);},{once:true});
window.PASS50_RUN_LIVE_RADAR=force=>force?runFullSweep():runQuick();
window.PASS50_VERIFY_LIVE_PROFILE=verifyProfile;
})();