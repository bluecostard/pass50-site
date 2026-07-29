(function(){
'use strict';

const ENDPOINT='./api/live-status-v4.php';
const QUICK_INTERVAL=45_000;
const FULL_CYCLE_KEY='pass50_live_radar_v4_cycle';
const DEFAULT_GRACE_MINUTES={TikTok:20,YouTube:15,Instagram:15,Facebook:15};
let runningMode='';
let lastData=null;
let autoTimer=null;
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

function graceMinutes(platform){
  const configured=Number(window.PASS50_LIVE_RADAR?.graceMinutes?.[platform]);
  return Number.isFinite(configured)&&configured>0?configured:Number(DEFAULT_GRACE_MINUTES[platform]||15);
}

function installLiveNormalizerV4(){
  if(window.__pass50LiveNormalizerV4)return;
  const normalizer=function(){
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
        // Anciennes lignes IONOS pouvaient être sérialisées avec le fuseau local
        // mais marquées UTC. Une avance raisonnable est ramenée à l'heure serveur.
        if(futureSkew>5*60_000&&futureSkew<=6*60*60_000){
          confirmedAt=now;
          const fixed=new Date(now).toISOString();
          item.lastConfirmedAt=fixed;
          item.lastSeenAt=fixed;
        }else if(futureSkew>6*60*60_000){
          return false;
        }
        const maxAge=(graceMinutes(String(item.platform||''))+2)*60_000;
        if(now-confirmedAt>maxAge)return false;
      }
      const key=[item.profileId,item.platform||'',String(item.url).replace(/\/+$/,'')].map(value=>String(value).trim().toLowerCase()).join('|');
      if(seen.has(key))return false;
      seen.add(key);
      return true;
    });
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
  return ['TikTok','YouTube','Instagram','Facebook'].map(name=>{
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
  const completed=radar.lastFullSweep?.completedAt||'';
  const diagnostics=Array.isArray(radar.diagnostics)?radar.diagnostics:[];
  const detected=diagnostics.filter(item=>item.state==='live').map(item=>esc(item.name)).filter(Boolean);
  const candidates=diagnostics.filter(item=>item.state==='probable').map(item=>esc(item.name)).filter(Boolean);
  const diagnosticRows=diagnostics.slice(-12).map(item=>{
    const probes=Object.entries(item.probes||{}).map(([name,probe])=>`${name}:${probe.status||probe.error||'—'}`).join(', ');
    return `${esc(item.name||'—')} · ${esc(item.platform||'—')} · ${esc(item.publicState||item.state||'unknown')} · confiance ${Number(item.confidence||0)} · ${esc(probes||'—')}`;
  }).join('<br>');
  box.innerHTML=`<strong style="color:#b7ff00">RADAR LIVE V4</strong> · ${liveCount()} direct${liveCount()>1?'s':''} actif${liveCount()>1?'s':''}<br>`+
    `${total} lien${total>1?'s':''} officiel${total>1?'s':''} surveillé${total>1?'s':''}<br>`+
    `<span style="color:#aeb8aa">${platformText(radar.platforms)}</span><br>`+
    `${scanned>0?`Dernier balayage : ${scanned}/${Number(radar.cycleTotal||total)} · ${coverage}%`:'Surveillance rapide active toutes les 45 secondes'}<br>`+
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
  if(runningMode||document.hidden)return null;
  runningMode='quick';
  try{return await fetchRadar({mode:'quick',batch:'8'});}
  catch(error){console.warn('Radar LIVE rapide',error);return null;}
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
      data=await fetchRadar({mode:'full',force:'1',cycle,batch:'8'});const radar=data.radar||{};
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
  try{return await fetchRadar({mode:'profile',force:'1',profileId:String(profileId),batch:'8'});}
  catch(error){console.warn('Vérification LIVE ciblée',error);return null;}
  finally{runningMode='';renderStatus();}
}

async function waitForRadarIdle(maxWait=3000){
  const deadline=Date.now()+maxWait;while(runningMode&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,100));return !runningMode;
}

async function verifyWatchLink(link){
  const profileId=String(link.dataset.liveProfile||''),platform=String(link.dataset.livePlatform||'');
  const current=(db.liveStreams||[]).find(item=>item.profileId===profileId&&item.platform===platform&&item.status==='live');
  if(current?.source==='manual'){window.open(link.href,'_blank','noopener');return;}
  const pendingWindow=window.open('about:blank','_blank');if(pendingWindow)pendingWindow.opener=null;
  const confirmedAt=new Date(link.dataset.liveConfirmedAt||'').getTime();const grace=graceMinutes(platform)*60_000;
  if(Number.isFinite(confirmedAt)&&Date.now()-confirmedAt<=grace){if(pendingWindow)pendingWindow.location.replace(link.href);return;}
  if(!await waitForRadarIdle()){if(pendingWindow)pendingWindow.close();if(typeof toast==='function')toast('Vérification du direct en cours. Réessayez dans un instant.');return;}
  const data=await verifyProfile(profileId);const confirmed=(data?.liveStreams||[]).find(item=>item.profileId===profileId&&item.platform===platform&&item.status==='live');
  if(confirmed&&pendingWindow){pendingWindow.location.replace(String(confirmed.url||link.href));return;}
  if(pendingWindow)pendingWindow.close();if(typeof openLives==='function')openLives();if(typeof toast==='function')toast('Ce direct vient de se terminer ou ne peut plus être confirmé.');
}

function bind(){
  installLiveNormalizerV4();
  const button=document.getElementById('liveRadarRefresh');
  if(button&&!button.dataset.liveRadarV4){button.dataset.liveRadarV4='1';button.textContent='🔴 LANCER LE RADAR COMPLET';}
  renderStatus();
}

if(typeof normalizeLiveStreams==='function')installLiveNormalizerV4();
document.addEventListener('click',event=>{
  const watchLink=event.target.closest?.('.live-watch-link[data-live-profile][data-live-platform]');
  if(watchLink){event.preventDefault();event.stopImmediatePropagation();verifyWatchLink(watchLink);return;}
  const radarButton=event.target.closest?.('#liveRadarRefresh');
  if(radarButton){event.preventDefault();event.stopImmediatePropagation();runFullSweep();return;}
  if(event.target.closest?.('#liveBtn'))setTimeout(runQuick,0);
},true);

document.addEventListener('p50:official-links-saved',event=>{const id=String(event.detail?.profileId||'');if(id)setTimeout(()=>verifyProfile(id),300);});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)runQuick();});
document.addEventListener('DOMContentLoaded',()=>{installLiveNormalizerV4();bind();setTimeout(runQuick,3000);autoTimer=setInterval(runQuick,QUICK_INTERVAL);try{refreshLiveStatus=runQuick}catch{}});
const observer=new MutationObserver(()=>requestAnimationFrame(bind));observer.observe(document.documentElement,{subtree:true,childList:true});
window.addEventListener('beforeunload',()=>{if(autoTimer)clearInterval(autoTimer);},{once:true});
window.PASS50_RUN_LIVE_RADAR=force=>force?runFullSweep():runQuick();
window.PASS50_VERIFY_LIVE_PROFILE=verifyProfile;
})();
