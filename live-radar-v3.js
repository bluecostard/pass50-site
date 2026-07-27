(function(){
'use strict';

const ENDPOINT='./api/live-status-v3.php';
const QUICK_INTERVAL=60_000;
let running=false;
let lastData=null;
let autoTimer=null;

function liveCount(){
  try{return (db.liveStreams||[]).filter(item=>item.status==='live').length}catch{return 0}
}

function applyRadarData(data){
  if(!data?.ok)return;
  lastData=data;
  window.PASS50_LIVE_RADAR=data.radar||{};
  if(Array.isArray(data.liveStreams)){
    db.liveStreams=data.liveStreams;
    if(typeof normalizeLiveStreams==='function')normalizeLiveStreams();
    try{localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
    if(typeof render==='function')render();
  }
  requestAnimationFrame(renderStatus);
}

function platformText(platforms={}){
  return ['TikTok','YouTube','Instagram','Facebook']
    .map(name=>{
      const item=platforms[name]||{};
      return `${name} ${Number(item.known||0)}`;
    })
    .join(' · ');
}

function ensureStatusBox(){
  let box=document.getElementById('liveRadarV3Status');
  if(box)return box;
  const button=document.getElementById('liveRadarRefresh');
  if(!button)return null;
  box=document.createElement('div');
  box.id='liveRadarV3Status';
  box.style.cssText='margin:10px 0 14px;padding:12px 14px;border:1px solid #293129;border-radius:14px;background:#0b0e0b;font-size:12px;line-height:1.55;color:#dce3d9';
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
  box.innerHTML=`<strong style="color:#b7ff00">RADAR LIVE V3</strong> · ${liveCount()} direct${liveCount()>1?'s':''} actif${liveCount()>1?'s':''}<br>`+
    `${total} lien${total>1?'s':''} officiel${total>1?'s':''} surveillé${total>1?'s':''} · ${platformText(radar.platforms)}<br>`+
    `${scanned>0?`Dernier balayage : ${scanned}/${Number(radar.cycleTotal||total)} · ${coverage}%`:'Surveillance rapide active toutes les 60 secondes'}`+
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
  if(running||document.hidden)return null;
  running=true;
  try{return await fetchRadar({mode:'quick',batch:'6'});}
  catch(error){console.warn('Radar LIVE rapide',error);return null;}
  finally{running=false;}
}

async function runFullSweep(){
  if(running)return null;
  running=true;
  const cycle=`web_${Date.now()}_${Math.random().toString(36).slice(2,8)}`;
  let data=null;
  let calls=0;
  let busyRetries=0;
  setButton('RADAR LIVE · PRÉPARATION…');

  try{
    do{
      data=await fetchRadar({mode:'full',force:'1',cycle,batch:'6'});
      const radar=data.radar||{};
      if(radar.busy){
        busyRetries++;
        if(busyRetries>8)throw new Error('Le radar est déjà occupé');
        setButton('RADAR LIVE · ATTENTE DU SERVEUR…');
        await new Promise(resolve=>setTimeout(resolve,700));
        continue;
      }
      busyRetries=0;
      const scanned=Number(radar.cycleScanned||0),total=Number(radar.cycleTotal||0),found=Number(radar.livesFoundInCycle||0);
      setButton(`RADAR LIVE · ${scanned}/${total} · ${found} LIVE${found>1?'S':''}`);
      calls++;
      if(radar.cycleComplete)break;
      await new Promise(resolve=>setTimeout(resolve,180));
    }while(calls<80);

    const radar=data?.radar||{};
    if(!radar.cycleComplete)throw new Error('Balayage interrompu avant la fin');
    const total=Number(radar.cycleTotal||0),found=Number(radar.livesFoundInCycle||0);
    if(typeof toast==='function')toast(`Radar LIVE : ${total} liens officiels contrôlés · ${found} direct${found>1?'s':''} détecté${found>1?'s':''}`);
    return data;
  }catch(error){
    if(typeof toast==='function')toast(error?.message||'Radar LIVE indisponible');
    return null;
  }finally{
    running=false;
    setButton('🔴 LANCER LE RADAR COMPLET',false);
    renderStatus();
  }
}

async function verifyProfile(profileId){
  if(!profileId||running)return null;
  running=true;
  try{
    const data=await fetchRadar({mode:'profile',force:'1',profileId:String(profileId),batch:'4'});
    return data;
  }catch(error){
    console.warn('Vérification LIVE ciblée',error);
    return null;
  }finally{running=false;renderStatus();}
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
  const button=event.target.closest?.('#liveRadarRefresh');
  if(!button)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  runFullSweep();
},true);

document.addEventListener('visibilitychange',()=>{if(!document.hidden)runQuick();});
document.addEventListener('DOMContentLoaded',()=>{
  bind();
  setTimeout(runQuick,4_000);
  autoTimer=setInterval(runQuick,QUICK_INTERVAL);
});

const observer=new MutationObserver(()=>requestAnimationFrame(bind));
observer.observe(document.documentElement,{subtree:true,childList:true});
window.addEventListener('beforeunload',()=>{if(autoTimer)clearInterval(autoTimer);},{once:true});

window.PASS50_RUN_LIVE_RADAR=force=>force?runFullSweep():runQuick();
window.PASS50_VERIFY_LIVE_PROFILE=verifyProfile;
})();
