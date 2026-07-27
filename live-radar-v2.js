(function(){
'use strict';

let running=false;

async function runLiveRadar(force=false){
  if(running)return null;
  running=true;
  const button=document.getElementById('liveRadarRefresh');
  const previous=button?.textContent||'';
  if(button){button.disabled=true;button.textContent='ANALYSE YOUTUBE + TIKTOK…';}

  try{
    const query=force?'?force=1&t='+Date.now():'?t='+Date.now();
    const response=await fetch('./api/live-status.php'+query,{cache:'no-store'});
    const data=await response.json();
    if(!response.ok||!data?.ok)throw new Error(data?.error||'Radar indisponible');

    window.PASS50_LIVE_RADAR=data.radar||{};
    if(Array.isArray(data.liveStreams)){
      db.liveStreams=data.liveStreams;
      if(typeof normalizeLiveStreams==='function')normalizeLiveStreams();
      try{localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
      if(typeof render==='function')render();
    }

    const tiktok=data.radar?.platforms?.TikTok||{};
    const found=Number(data.radar?.livesFoundThisPass||0);
    if(typeof toast==='function')toast(`Radar TikTok : ${Number(tiktok.scanned||0)} FI analysée(s) · ${found} live(s) détecté(s)`);
    if(typeof renderAdminPane==='function'&&typeof ui==='object'&&ui.adminTab==='live')renderAdminPane();
    return data;
  }catch(error){
    if(typeof toast==='function')toast(error?.message||'Radar LIVE indisponible');
    return null;
  }finally{
    running=false;
    const current=document.getElementById('liveRadarRefresh');
    if(current){current.disabled=false;current.textContent=previous||'🔴 LANCER LE RADAR MAINTENANT';}
  }
}

document.addEventListener('click',event=>{
  const button=event.target.closest?.('#liveRadarRefresh');
  if(!button)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  runLiveRadar(true);
},true);

window.PASS50_RUN_LIVE_RADAR=runLiveRadar;
})();
