(function(){
'use strict';

if(window.__pass50LiveExperienceV41)return;
window.__pass50LiveExperienceV41=true;

const VERSION='4.2.0';
let currentShare=null;
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

function safeUrl(value){
  try{
    const url=new URL(String(value||''),location.href);
    return ['http:','https:'].includes(url.protocol)?url.href:'';
  }catch{return ''}
}

function isAndroid(){return /Android/i.test(navigator.userAgent||'')}
function isIOS(){return /iPhone|iPad|iPod/i.test(navigator.userAgent||'')}
function isMobile(){
  return isAndroid()||isIOS()||((navigator.maxTouchPoints||0)>1&&Math.min(screen.width,screen.height)<920);
}

function lives(){
  try{return Array.isArray(db?.liveStreams)?db.liveStreams.filter(item=>item?.status==='live'):[]}catch{return []}
}

function liveFor(profileId,platform=''){
  const id=String(profileId||''),network=String(platform||'');
  return lives().find(item=>String(item.profileId)===id&&(!network||String(item.platform)===network))||null;
}

function profileName(profileId,live){
  try{return String(profile?.(profileId)?.name||live?.profileName||'Influenceur')}catch{return String(live?.profileName||'Influenceur')}
}

function initials(name){
  return String(name||'LIVE').trim().split(/\s+/).slice(0,2).map(part=>part[0]||'').join('').toUpperCase()||'LV';
}

function notify(message){
  try{if(typeof toast==='function')toast(message)}catch{}
}

function tiktokHandle(live,webUrl=''){
  const fromMeta=String(live?.handle||live?.metadata?.handle||'').replace(/^@/,'').trim();
  if(fromMeta)return fromMeta;
  const match=String(webUrl||live?.url||'').match(/tiktok\.com\/@([^/?#]+)/i);
  return match?decodeURIComponent(match[1]):'';
}

function androidIntent(hostPath,packageName,fallbackUrl){
  const path=String(hostPath||'').replace(/^https?:\/\//i,'').replace(/^\/+/,'');
  return `intent://${path}#Intent;scheme=https;package=${packageName};S.browser_fallback_url=${encodeURIComponent(fallbackUrl)};end`;
}

function appAwareLiveUrl(live,preferred=''){
  const web=safeUrl(preferred||live?.url);
  if(!web)return '';
  const platform=String(live?.platform||'');
  const roomId=String(live?.roomId||live?.metadata?.roomId||'').trim();
  const videoId=String(live?.videoId||live?.metadata?.videoId||'').trim();

  if(platform==='TikTok'){
    const handle=tiktokHandle(live,web);
    const httpsLive=handle?`https://www.tiktok.com/@${handle}/live`:web;
    if(isAndroid())return androidIntent(handle?`www.tiktok.com/@${handle}/live`:httpsLive.replace(/^https?:\/\//i,''),'com.zhiliaoapp.musically',httpsLive);
    if(isIOS()&&roomId)return `snssdk1233://live?room_id=${encodeURIComponent(roomId)}`;
    return httpsLive;
  }

  if(platform==='Instagram'){
    const handle=String(live?.handle||live?.metadata?.handle||'').replace(/^@/,'').trim()
      ||(web.match(/instagram\.com\/([^/?#]+)/i)||[])[1]
      ||'';
    const httpsProfile=handle?`https://www.instagram.com/${handle}/`:web;
    if(isAndroid()&&handle)return androidIntent(`www.instagram.com/${handle}/`,'com.instagram.android',httpsProfile);
    if(isIOS()&&handle)return `instagram://user?username=${encodeURIComponent(handle)}`;
    return httpsProfile;
  }

  if(platform==='YouTube'){
    let id=videoId;
    if(!id){
      try{id=new URL(web).searchParams.get('v')||'';}catch{id=''}
      if(!id)id=(web.match(/\/(?:live|shorts|embed)\/([A-Za-z0-9_-]{6,})/)||[])[1]||'';
    }
    const httpsWatch=id?`https://www.youtube.com/watch?v=${id}`:web;
    if(isAndroid()&&id)return androidIntent(`www.youtube.com/watch?v=${id}`,'com.google.android.youtube',httpsWatch);
    if(isIOS()&&id)return `vnd.youtube://${id}`;
    return httpsWatch;
  }

  if(platform==='Facebook'){
    if(isAndroid())return androidIntent(web.replace(/^https?:\/\//i,''),'com.facebook.katana',web);
    if(isIOS())return `fb://facewebmodal/f?href=${encodeURIComponent(web)}`;
    return web;
  }

  return web;
}

function openNewTab(value){
  const url=safeUrl(value);if(!url)return false;
  const opened=window.open(url,'_blank');
  if(opened){try{opened.opener=null}catch{}return true;}
  const anchor=document.createElement('a');anchor.href=url;anchor.target='_blank';anchor.rel='noopener noreferrer';anchor.style.display='none';document.body.appendChild(anchor);anchor.click();anchor.remove();
  return true;
}

function openLiveDestination(live,preferred=''){
  const web=safeUrl(preferred||live?.url);
  const destination=appAwareLiveUrl(live,web)||web;
  if(!destination)return false;

  if(isMobile()){
    // Navigation directe : Universal Links / Intents ouvrent mieux l’app que window.open.
    const started=Date.now();
    const usesCustomScheme=!/^https?:/i.test(destination);
    window.location.href=destination;
    if(usesCustomScheme&&web&&web!==destination){
      setTimeout(()=>{
        if(document.hidden||Date.now()-started>1800)return;
        window.location.href=web;
      },900);
    }
    return true;
  }

  return openNewTab(web);
}

function decorateWatchLink(watch,live){
  if(!(watch instanceof HTMLAnchorElement))return;
  const web=safeUrl(watch.getAttribute('href')||live?.url);
  if(!web)return;
  const payload=live||{profileId:watch.dataset.liveProfile,platform:watch.dataset.livePlatform,url:web,roomId:watch.dataset.liveRoom,videoId:watch.dataset.liveVideo,handle:watch.dataset.liveHandle};
  const destination=appAwareLiveUrl(payload,web)||web;
  watch.href=destination;
  watch.dataset.liveWebUrl=web;
  if(payload.roomId)watch.dataset.liveRoom=String(payload.roomId);
  if(payload.videoId)watch.dataset.liveVideo=String(payload.videoId);
  if(payload.handle)watch.dataset.liveHandle=String(payload.handle).replace(/^@/,'');
  if(isMobile()){
    watch.removeAttribute('target');
    watch.rel='noopener';
  }else{
    watch.target='_blank';
    watch.rel='noopener noreferrer';
    watch.href=web;
  }
}

function backgroundVerify(live){
  if(!live||live.source==='manual')return;
  setTimeout(async()=>{
    try{
      if(typeof window.PASS50_VERIFY_LIVE_PROFILE!=='function')return;
      const data=await window.PASS50_VERIFY_LIVE_PROFILE(String(live.profileId||''));
      if(!data)return;
      const stillLive=(data.liveStreams||[]).some(item=>String(item.profileId)===String(live.profileId)&&String(item.platform)===String(live.platform)&&item.status==='live');
      if(!stillLive)notify('Ce direct vient de se terminer.');
    }catch{}
  },0);
}

function shareMessage(live,name){
  return `🔴 ${name} est en direct sur ${live.platform||'son réseau'}.\nRegarde maintenant : ${safeUrl(live.url)}`;
}

function wrapLines(ctx,text,maxWidth){
  const words=String(text||'').split(/\s+/),lines=[];let line='';
  for(const word of words){const next=line?`${line} ${word}`:word;if(ctx.measureText(next).width<=maxWidth)line=next;else{if(line)lines.push(line);line=word;}}
  if(line)lines.push(line);return lines.slice(0,3);
}

function buildShareCanvas(live,name){
  const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1350;const ctx=canvas.getContext('2d');
  const gradient=ctx.createLinearGradient(0,0,1080,1350);gradient.addColorStop(0,'#101510');gradient.addColorStop(1,'#030503');ctx.fillStyle=gradient;ctx.fillRect(0,0,1080,1350);
  ctx.fillStyle='rgba(183,255,0,.09)';ctx.beginPath();ctx.arc(920,160,330,0,Math.PI*2);ctx.fill();ctx.fillStyle='rgba(255,75,75,.09)';ctx.beginPath();ctx.arc(120,1180,300,0,Math.PI*2);ctx.fill();
  ctx.fillStyle='#ffffff';ctx.font='900 76px Inter,Arial,sans-serif';ctx.fillText('PASS',70,110);ctx.fillStyle='#b7ff00';ctx.fillText('50',258,110);
  ctx.fillStyle='#ff4b4b';ctx.beginPath();ctx.roundRect(70,175,360,92,46);ctx.fill();ctx.fillStyle='#ffffff';ctx.font='900 40px Inter,Arial,sans-serif';ctx.fillText('●  EN DIRECT',108,235);
  ctx.fillStyle='#1a211a';ctx.beginPath();ctx.arc(540,520,190,0,Math.PI*2);ctx.fill();ctx.strokeStyle='#b7ff00';ctx.lineWidth=10;ctx.stroke();ctx.fillStyle='#ffffff';ctx.textAlign='center';ctx.textBaseline='middle';ctx.font='1000 120px Inter,Arial,sans-serif';ctx.fillText(initials(name),540,525);
  ctx.textBaseline='alphabetic';ctx.fillStyle='#ffffff';ctx.font='1000 78px Inter,Arial,sans-serif';const nameLines=wrapLines(ctx,name,900);nameLines.forEach((line,index)=>ctx.fillText(line,540,800+index*92));
  const afterName=800+(nameLines.length-1)*92;ctx.fillStyle='#9da79b';ctx.font='800 42px Inter,Arial,sans-serif';ctx.fillText(`SUR ${String(live.platform||'LE RÉSEAU').toUpperCase()}`,540,afterName+95);
  ctx.fillStyle='#b7ff00';ctx.beginPath();ctx.roundRect(110,afterName+155,860,135,44);ctx.fill();ctx.fillStyle='#050705';ctx.font='1000 48px Inter,Arial,sans-serif';ctx.fillText('REGARDE MAINTENANT  →',540,afterName+242);
  ctx.fillStyle='#9da79b';ctx.font='700 34px Inter,Arial,sans-serif';ctx.fillText('pass50.store',540,1270);ctx.textAlign='start';return canvas;
}

function canvasFile(live,name){
  return new Promise(resolve=>buildShareCanvas(live,name).toBlob(blob=>resolve(blob?new File([blob],`pass50-live-${String(name).toLowerCase().replace(/[^a-z0-9]+/g,'-')}.png`,{type:'image/png'}):null),'image/png',.94));
}

function ensureShareModal(){
  if(document.getElementById('p50LiveShareModal'))return;
  const style=document.createElement('style');style.id='p50LiveExperienceV41Style';style.textContent=`
  .badge.live-badge[data-live-clickable="1"]{cursor:pointer;user-select:none;transition:transform .16s ease,box-shadow .16s ease}.badge.live-badge[data-live-clickable="1"]:hover,.badge.live-badge[data-live-clickable="1"]:focus{transform:translateY(-1px) scale(1.04);box-shadow:0 0 22px rgba(255,75,75,.3);outline:none}
  .p50-live-share-overlay{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.8);backdrop-filter:blur(12px)}.p50-live-share-overlay.show{display:flex}
  .p50-live-share-box{width:min(430px,100%);border:1px solid #293129;border-radius:24px;background:#090c09;box-shadow:0 28px 90px rgba(0,0,0,.7);padding:14px}.p50-live-share-close{display:block;margin-left:auto;width:40px;height:40px;border-radius:50%;border:1px solid #293129;background:#111611;color:#fff;font-size:22px}
  .p50-live-share-card{min-height:430px;border-radius:20px;padding:24px;background:radial-gradient(circle at 100% 0,rgba(183,255,0,.16),transparent 34%),linear-gradient(145deg,#151b15,#040604);display:flex;flex-direction:column;align-items:center;text-align:center}.p50-live-share-brand{align-self:flex-start;font-size:24px;font-weight:1000}.p50-live-share-brand span{color:#b7ff00}.p50-live-share-pill{margin-top:24px;border-radius:999px;background:#ff4b4b;color:#fff;padding:9px 14px;font-size:13px;font-weight:1000}.p50-live-share-avatar{width:116px;height:116px;margin:26px 0 18px;border:4px solid #b7ff00;border-radius:50%;display:grid;place-items:center;background:#202820;font-size:40px;font-weight:1000}.p50-live-share-card h2{margin:0;font-size:30px;line-height:1.05}.p50-live-share-network{margin-top:9px;color:#9da79b;font-weight:900}.p50-live-share-cta{width:100%;margin-top:auto;padding:15px;border-radius:15px;background:#b7ff00;color:#050705;font-weight:1000}.p50-live-share-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:12px}.p50-live-share-actions button{min-height:52px;border:1px solid #293129;border-radius:14px;background:#101410;color:#fff;font-weight:950}.p50-live-share-actions button:first-child{background:#b7ff00;color:#050705;border-color:#b7ff00}
  @media(max-width:520px){.p50-live-share-overlay{padding:8px}.p50-live-share-box{border-radius:20px;padding:10px}.p50-live-share-card{min-height:390px;padding:20px}.p50-live-share-actions{grid-template-columns:1fr}.p50-live-share-actions button{min-height:48px}}
  `;document.head.appendChild(style);
  const modal=document.createElement('div');modal.id='p50LiveShareModal';modal.className='p50-live-share-overlay';modal.setAttribute('aria-hidden','true');modal.innerHTML=`<div class="p50-live-share-box" role="dialog" aria-modal="true" aria-label="Partager ce direct"><button class="p50-live-share-close" type="button" aria-label="Fermer">×</button><div class="p50-live-share-card"><div class="p50-live-share-brand">PASS<span>50</span></div><div class="p50-live-share-pill">● EN DIRECT</div><div class="p50-live-share-avatar">LV</div><h2>Influenceur</h2><div class="p50-live-share-network">SUR LE RÉSEAU</div><div class="p50-live-share-cta">REGARDE MAINTENANT →</div></div><div class="p50-live-share-actions"><button type="button" data-live-share-native>Partager</button><button type="button" data-live-share-whatsapp>WhatsApp</button><button type="button" data-live-share-copy>Copier</button></div></div>`;document.body.appendChild(modal);
}

function renderShareModal(live){
  ensureShareModal();const modal=document.getElementById('p50LiveShareModal'),name=profileName(live.profileId,live);currentShare={live,name};
  modal.querySelector('.p50-live-share-avatar').textContent=initials(name);modal.querySelector('h2').textContent=name;modal.querySelector('.p50-live-share-network').textContent=`SUR ${String(live.platform||'LE RÉSEAU').toUpperCase()}`;modal.classList.add('show');modal.setAttribute('aria-hidden','false');
}

function closeShare(){const modal=document.getElementById('p50LiveShareModal');if(modal){modal.classList.remove('show');modal.setAttribute('aria-hidden','true');}currentShare=null;}

async function nativeShare(){
  if(!currentShare)return;const {live,name}=currentShare,text=shareMessage(live,name),url=safeUrl(live.url);try{
    const file=await canvasFile(live,name);if(navigator.share){const payload={title:`${name} est en direct`,text,url};if(file&&navigator.canShare?.({files:[file]}))payload.files=[file];await navigator.share(payload);return;}
    await copyText(text);notify('Lien copié');
  }catch(error){if(error?.name!=='AbortError')notify('Partage indisponible');}
}

function whatsappShare(){if(!currentShare)return;const text=shareMessage(currentShare.live,currentShare.name);openNewTab(`https://wa.me/?text=${encodeURIComponent(text)}`);}

async function copyText(text){
  if(navigator.clipboard?.writeText)return navigator.clipboard.writeText(text);
  const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();
}

async function copyShare(){if(!currentShare)return;try{await copyText(shareMessage(currentShare.live,currentShare.name));notify('Lien du live copié');}catch{notify('Copie impossible');}}

function enhance(){
  ensureShareModal();
  document.querySelectorAll('#liveBody .live-card').forEach(card=>{
    const watch=card.querySelector('.live-watch-link');if(!watch)return;
    const live=liveFor(watch.dataset.liveProfile,watch.dataset.livePlatform);
    decorateWatchLink(watch,live);
    if(card.querySelector('.p50-share-live'))return;
    const button=document.createElement('button');button.type='button';button.className='btn p50-share-live';button.textContent='PARTAGER LE LIVE';button.dataset.liveProfile=watch.dataset.liveProfile||'';button.dataset.livePlatform=watch.dataset.livePlatform||'';watch.insertAdjacentElement('afterend',button);
  });
  document.querySelectorAll('.badge.live-badge').forEach(badge=>{
    const owner=badge.closest('[data-profile]'),live=owner?liveFor(owner.dataset.profile):null;if(!live)return;
    badge.dataset.liveClickable='1';badge.setAttribute('role','link');badge.tabIndex=0;badge.title=`Regarder le live de ${profileName(live.profileId,live)}`;badge.setAttribute('aria-label',badge.title);
  });
}

function pruneLocalTikTok(){
  try{
    if(typeof db==='undefined'||!Array.isArray(db.liveStreams))return;
    const grace=Number(window.PASS50_LIVE_RADAR?.graceMinutes?.TikTok||20);
    const limit=Math.max(3,grace)*60_000,now=Date.now(),before=db.liveStreams.length;
    db.liveStreams=db.liveStreams.filter(item=>{if(item?.source==='manual'||String(item?.platform)!=='TikTok')return true;const checked=new Date(item.lastConfirmedAt||item.lastSeenAt||'').getTime();return Number.isFinite(checked)&&now-checked<=limit;});
    if(db.liveStreams.length!==before){normalizeLiveStreams?.();localStorage.setItem(APP_KEY,JSON.stringify(db));render?.();if(document.getElementById('liveModal')?.classList.contains('show'))openLives?.();}
  }catch{}
}

window.addEventListener('click',event=>{
  const target=event.target instanceof Element?event.target:null;if(!target)return;
  const watch=target.closest('.live-watch-link');
  if(watch){
    const live=liveFor(watch.dataset.liveProfile,watch.dataset.livePlatform)||{
      profileId:watch.dataset.liveProfile,
      platform:watch.dataset.livePlatform,
      url:watch.dataset.liveWebUrl||watch.href,
      roomId:watch.dataset.liveRoom,
      videoId:watch.dataset.liveVideo,
      handle:watch.dataset.liveHandle,
    };
    decorateWatchLink(watch,live);
    // Mobile : navigation native du <a> (Universal Links / Intent). Desktop : laisser target=_blank.
    if(isMobile()){
      event.preventDefault();
      event.stopImmediatePropagation();
      openLiveDestination(live,watch.dataset.liveWebUrl||safeUrl(watch.href));
    }
    backgroundVerify(live);
    return;
  }
  const share=target.closest('.p50-share-live');
  if(share){
    event.preventDefault();event.stopImmediatePropagation();
    const profileId=share.dataset.liveProfile||share.dataset.id||'';
    const platform=share.dataset.livePlatform||share.dataset.platform||'';
    const live=liveFor(profileId,platform);
    if(window.PASS50_SHARE_CENTER?.openLive){window.PASS50_SHARE_CENTER.openLive({profileId,platform,directUrl:live?.url||''});}
    else if(live)renderShareModal(live);
    return;
  }
  const badge=target.closest('.badge.live-badge[data-live-clickable="1"]');
  if(badge){
    event.preventDefault();event.stopImmediatePropagation();
    const owner=badge.closest('[data-profile]'),live=owner?liveFor(owner.dataset.profile):null;
    if(live){openLiveDestination(live);backgroundVerify(live);}
    return;
  }
  if(target.closest('.p50-live-share-close')||target.id==='p50LiveShareModal'){event.preventDefault();closeShare();return;}
  if(target.closest('[data-live-share-native]')){event.preventDefault();nativeShare();return;}
  if(target.closest('[data-live-share-whatsapp]')){event.preventDefault();whatsappShare();return;}
  if(target.closest('[data-live-share-copy]')){event.preventDefault();copyShare();return;}
},true);

window.addEventListener('keydown',event=>{
  const target=event.target instanceof Element?event.target:null;if(!target)return;
  if((event.key==='Enter'||event.key===' ')&&target.matches('.badge.live-badge[data-live-clickable="1"]')){event.preventDefault();target.click();}
  if(event.key==='Escape')closeShare();
},true);

const observer=new MutationObserver(()=>requestAnimationFrame(enhance));observer.observe(document.documentElement,{subtree:true,childList:true});
document.addEventListener('DOMContentLoaded',()=>{ensureShareModal();enhance();pruneLocalTikTok();setInterval(pruneLocalTikTok,30_000);});
setTimeout(enhance,0);
window.PASS50_LIVE_EXPERIENCE_VERSION=VERSION;
window.PASS50_OPEN_LIVE=openLiveDestination;
window.PASS50_LIVE_APP_URL=appAwareLiveUrl;
})();
