(function(){
'use strict';

const ENDPOINT='./api/live-status-v3.php';
const QUICK_INTERVAL=45_000;
const FULL_CYCLE_KEY='pass50_live_radar_v3_cycle';
let runningMode='';
let lastData=null;
let autoTimer=null;
const liveRadarEsc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

function liveCount(){
  try{return (db.liveStreams||[]).filter(item=>item.status==='live').length}catch{return 0}
}

function applyRadarData(data){
  if(!data?.ok)return;
  lastData=data;
  window.PASS50_LIVE_RADAR=data.radar||{};
  window.PASS50_LIVE_RADAR_LAST_DATA=data;
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
  return ['TikTok','YouTube','Instagram','Facebook']
    .map(name=>{
      const item=platforms[name]||{};
      const known=Number(item.known||0),scanned=Number(item.scanned||0),found=Number(item.found||0);
      return `${name} ${known}${scanned?` · ${scanned} testés`:''}${found?` · ${found} LIVE`:''}`;
    })
    .join('<br>');
}

function healthText(health={}){
  const totals={live:0,offline:0,unknown:0,unconfirmed:0,never_checked:0};
  Object.values(health||{}).forEach(item=>Object.keys(totals).forEach(key=>totals[key]+=Number(item?.[key]||0)));
  return `${totals.live} en direct confirmé · ${totals.offline} terminés · ${totals.unconfirmed} non confirmés · ${totals.unknown} bloqués/inconnus · ${totals.never_checked} jamais contrôlés`;
}

function ensureStatusBox(){
  let box=document.getElementById('liveRadarV3Status');
  if(box)return box;
  const button=document.getElementById('liveRadarRefresh');
  if(!button)return null;
  box=document.createElement('div');
  box.id='liveRadarV3Status';
  box.style.cssText='margin:10px 0 14px;padding:13px 14px;border:1px solid #293129;border-radius:14px;background:#0b0e0b;font-size:12px;line-height:1.55;color:#dce3d9';
  button.closest('.admin-toolbar,.tool-actions,div')?.insertAdjacentElement('afterend',box);
  return box;
}

function renderStatus(){
  const box=ensureStatusBox();
  if(!box)return;
  const radar=lastData?.radar||window.PASS50_LIVE_RADAR||{};
  const total=Number(radar.officialSourcesKnown??radar.cycleTotal??0);
  const scanned=Number(radar.cycleScanned||0);
  const coverage=Number(radar.coveragePercent||0);
  const completed=radar.lastFullSweep?.completedAt||'';
  const diagnostics=Array.isArray(radar.diagnostics)?radar.diagnostics:[];
  const detected=diagnostics.filter(item=>item.state==='live').map(item=>liveRadarEsc(item.name)).filter(Boolean);
  const diagnosticRows=diagnostics.slice(-12).map(item=>{
    const probes=Object.values(item.probes||{}).map(probe=>String(probe.status||probe.error||'—')).join(', ');
    return `${liveRadarEsc(item.platform||'—')} · ${liveRadarEsc(item.publicState||item.state||'unknown')} · vérifié ${item.lastCheckedAt?new Date(item.lastCheckedAt).toLocaleTimeString('fr-FR'):'—'} · confirmé ${item.lastConfirmedAt?new Date(item.lastConfirmedAt).toLocaleTimeString('fr-FR'):'—'} · retrait ${liveRadarEsc(item.withdrawalReason||'—')} · confiance ${Number(item.confidence||0)} · HTTP ${liveRadarEsc(probes||'—')}`;
  }).join('<br>');
  box.innerHTML=`<strong style="color:#b7ff00">RADAR LIVE V3</strong> · ${liveCount()} direct${liveCount()>1?'s':''} actif${liveCount()>1?'s':''}<br>`+
    `${total} lien${total>1?'s':''} officiel${total>1?'s':''} surveillé${total>1?'s':''}<br>`+
    `<span style="color:#aeb8aa">${platformText(radar.platforms)}</span><br>`+
    `${scanned>0?`Dernier balayage : ${scanned}/${Number(radar.cycleTotal||total)} · ${coverage}%`:'Surveillance rapide active toutes les 45 secondes'}<br>`+
    `${healthText(radar.health)}`+
    `${detected.length?`<br><strong style="color:#b7ff00">Détecté : ${detected.join(', ')}</strong>`:''}`+
    `${diagnosticRows?`<br><span style="color:#aeb8aa">${diagnosticRows}</span>`:''}`+
    `${completed?`<br>Balayage complet terminé : ${new Date(completed).toLocaleString('fr-FR')}`:''}`;
}

function setButton(text,disabled=true){
  const button=document.getElementById('liveRadarRefresh');
  if(!button)return;
  button.disabled=disabled;
  button.textContent=text;
}

async function fetchRadar(params){
  const query=new URLSearchParams({...params,t:String(Date.now())});
  const response=await fetch(`${ENDPOINT}?${query}`,{cache:'no-store',headers:{Accept:'application/json'}});
  const data=await response.json().catch(()=>null);
  if(!response.ok||!data?.ok)throw new Error(data?.error||'Radar LIVE indisponible');
  applyRadarData(data);
  return data;
}

async function runQuick(){
  if(runningMode||document.hidden)return null;
  runningMode='quick';
  try{return await fetchRadar({mode:'quick',batch:'8'});}
  catch(error){console.warn('Radar LIVE rapide',error);return null;}
  finally{runningMode='';}
}

function readCycle(){
  try{
    const saved=JSON.parse(localStorage.getItem(FULL_CYCLE_KEY)||'null');
    if(saved?.id&&Date.now()-Number(saved.at||0)<14*60_000)return saved.id;
  }catch{}
  return '';
}

function saveCycle(id){
  try{localStorage.setItem(FULL_CYCLE_KEY,JSON.stringify({id,at:Date.now()}))}catch{}
}

function clearCycle(){
  try{localStorage.removeItem(FULL_CYCLE_KEY)}catch{}
}

async function runFullSweep(){
  if(runningMode)return null;
  runningMode='full';
  const cycle=readCycle()||`web_${Date.now()}_${Math.random().toString(36).slice(2,8)}`;
  saveCycle(cycle);
  let data=null;
  let calls=0;
  let busyRetries=0;
  setButton('RADAR LIVE · PRÉPARATION…');

  try{
    do{
      data=await fetchRadar({mode:'full',force:'1',cycle,batch:'8'});
      const radar=data.radar||{};
      if(radar.busy){
        busyRetries++;
        if(busyRetries>12)throw new Error('Le radar est déjà occupé');
        setButton('RADAR LIVE · ATTENTE DU SERVEUR…');
        await new Promise(resolve=>setTimeout(resolve,800));
        continue;
      }
      busyRetries=0;
      const scanned=Number(radar.cycleScanned||0),total=Number(radar.cycleTotal||0),found=Number(radar.livesFoundInCycle||0);
      setButton(`RADAR LIVE · ${scanned}/${total} · ${found} LIVE${found>1?'S':''}`);
      calls++;
      if(radar.cycleComplete)break;
      await new Promise(resolve=>setTimeout(resolve,220));
    }while(calls<160);

    const radar=data?.radar||{};
    if(!radar.cycleComplete)throw new Error('Balayage interrompu avant la fin');
    clearCycle();
    const total=Number(radar.cycleTotal||0),found=Number(radar.livesFoundInCycle||0);
    if(typeof toast==='function')toast(`Radar LIVE : ${total} liens officiels contrôlés · ${found} direct${found>1?'s':''} détecté${found>1?'s':''}`);
    return data;
  }catch(error){
    if(typeof toast==='function')toast(error?.message||'Radar LIVE indisponible');
    return null;
  }finally{
    runningMode='';
    setButton('🔴 LANCER LE RADAR COMPLET',false);
    renderStatus();
  }
}

async function verifyProfile(profileId){
  if(!profileId||runningMode)return null;
  runningMode='profile';
  try{return await fetchRadar({mode:'profile',force:'1',profileId:String(profileId),batch:'8'});}
  catch(error){console.warn('Vérification LIVE ciblée',error);return null;}
  finally{runningMode='';renderStatus();}
}

async function verifyWatchLink(link){
  const profileId=String(link.dataset.liveProfile||''),platform=String(link.dataset.livePlatform||'');
  const current=(db.liveStreams||[]).find(item=>item.profileId===profileId&&item.platform===platform&&item.status==='live');
  if(current?.source==='manual'){window.open(link.href,'_blank','noopener');return;}
  const confirmedAt=new Date(link.dataset.liveConfirmedAt||'').getTime();
  const maxAge=platform==='YouTube'?10*60_000:3*60_000;
  if(platform==='YouTube'&&Number.isFinite(confirmedAt)&&Date.now()-confirmedAt<=maxAge){window.open(link.href,'_blank','noopener');return;}
  const data=await verifyProfile(profileId);
  const confirmed=(data?.liveStreams||[]).find(item=>item.profileId===profileId&&item.platform===platform&&item.status==='live');
  const freshAt=new Date(confirmed?.lastConfirmedAt||confirmed?.lastSeenAt||'').getTime();
  if(confirmed&&Number.isFinite(freshAt)&&Date.now()-freshAt<=maxAge){
    window.open(String(confirmed.url||link.href),'_blank','noopener');
    return;
  }
  if(typeof openLives==='function')openLives();
  if(typeof toast==='function')toast('Ce direct vient de se terminer ou ne peut plus être confirmé.');
}

function bind(){
  const button=document.getElementById('liveRadarRefresh');
  if(button&&!button.dataset.liveRadarV3){
    button.dataset.liveRadarV3='1';
    button.textContent='🔴 LANCER LE RADAR COMPLET';
  }
  renderStatus();
}

document.addEventListener('click',event=>{
  const watchLink=event.target.closest?.('.live-watch-link[data-live-profile][data-live-platform]');
  if(watchLink){
    event.preventDefault();
    event.stopImmediatePropagation();
    verifyWatchLink(watchLink);
    return;
  }
  const radarButton=event.target.closest?.('#liveRadarRefresh');
  if(radarButton){
    event.preventDefault();
    event.stopImmediatePropagation();
    runFullSweep();
    return;
  }
  if(event.target.closest?.('#liveBtn'))setTimeout(runQuick,0);
},true);

document.addEventListener('p50:official-links-saved',event=>{
  const id=String(event.detail?.profileId||'');
  if(id)setTimeout(()=>verifyProfile(id),300);
});

document.addEventListener('visibilitychange',()=>{if(!document.hidden)runQuick();});
document.addEventListener('DOMContentLoaded',()=>{
  bind();
  setTimeout(runQuick,3_000);
  autoTimer=setInterval(runQuick,QUICK_INTERVAL);
  try{refreshLiveStatus=runQuick}catch{}
});

const observer=new MutationObserver(()=>requestAnimationFrame(bind));
observer.observe(document.documentElement,{subtree:true,childList:true});
window.addEventListener('beforeunload',()=>{if(autoTimer)clearInterval(autoTimer);},{once:true});

window.PASS50_RUN_LIVE_RADAR=force=>force?runFullSweep():runQuick();
window.PASS50_VERIFY_LIVE_PROFILE=verifyProfile;
})();
