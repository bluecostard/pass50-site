(function(){
'use strict';

if(window.PASS50_MOBILE_MODAL_VIDEO_PROGRESS)return;
const CONTRACT='PASS50-MOBILE-MODAL-VIDEO-PROGRESS-V1.0';
let videoBusy=false;

function ensureStyles(){
  if(document.getElementById('p50MobileModalVideoProgressStyles'))return;
  const style=document.createElement('style');
  style.id='p50MobileModalVideoProgressStyles';
  style.textContent=`
    @media(max-width:680px){
      body.p50-modal-active .p50-bottom-nav{display:none!important}
      body.p50-modal-active{padding-bottom:0!important}
      body.p50-modal-active .modal.show{z-index:180!important;padding-bottom:env(safe-area-inset-bottom)!important}
      body.p50-modal-active .modal.show .modal-box{max-height:100dvh!important;padding-bottom:calc(22px + env(safe-area-inset-bottom))!important}
      body.p50-modal-active .modal.show .modal-body{padding-bottom:calc(28px + env(safe-area-inset-bottom))!important}
    }
    .p50-video-progress{display:none;align-items:center;gap:11px;margin-top:12px;padding:13px 14px;border:1px solid rgba(183,255,0,.38);border-radius:14px;background:rgba(183,255,0,.08);color:#efffca;font-size:12px;font-weight:900;line-height:1.35}
    .p50-video-progress.show{display:flex}
    .p50-video-spinner{flex:0 0 auto;width:22px;height:22px;border:3px solid rgba(183,255,0,.22);border-top-color:var(--lime,#b7ff00);border-radius:50%;animation:p50VideoSpin .8s linear infinite}
    .p50-video-progress strong{display:block;color:var(--lime,#b7ff00);font-size:13px}
    #voteShareConfirmVideo.p50-video-busy{opacity:.72;cursor:wait;pointer-events:none}
    @keyframes p50VideoSpin{to{transform:rotate(360deg)}}
    @media(prefers-reduced-motion:reduce){.p50-video-spinner{animation-duration:1.6s}}
  `;
  document.head.appendChild(style);
}

function syncModalState(){
  const active=Boolean(document.querySelector('.modal.show'));
  document.body.classList.toggle('p50-modal-active',active);
}

function installModalObserver(){
  const observer=new MutationObserver(syncModalState);
  observer.observe(document.documentElement,{subtree:true,attributes:true,attributeFilter:['class'],childList:true});
  syncModalState();
}

function progressNode(create=true){
  const body=document.getElementById('voteShareBody');
  if(!body)return null;
  let node=body.querySelector('[data-p50-video-progress]');
  if(!node&&create){
    node=document.createElement('div');
    node.className='p50-video-progress';
    node.dataset.p50VideoProgress='1';
    node.setAttribute('role','status');
    node.setAttribute('aria-live','polite');
    const button=body.querySelector('#voteShareConfirmVideo');
    (button?.parentElement||body).insertAdjacentElement('beforeend',node);
  }
  return node;
}

function setVideoBusy(busy,message='Création de la vidéo en cours…'){
  videoBusy=Boolean(busy);
  const body=document.getElementById('voteShareBody');
  if(body)body.setAttribute('aria-busy',videoBusy?'true':'false');
  const button=document.getElementById('voteShareConfirmVideo');
  if(button){
    if(!button.dataset.p50OriginalLabel)button.dataset.p50OriginalLabel=button.textContent.trim()||'Créer la vidéo';
    button.disabled=videoBusy;
    button.classList.toggle('p50-video-busy',videoBusy);
    button.textContent=videoBusy?'Création en cours…':button.dataset.p50OriginalLabel;
  }
  const node=progressNode(videoBusy);
  if(!node)return;
  node.classList.toggle('show',videoBusy);
  node.innerHTML=videoBusy?`<span class="p50-video-spinner" aria-hidden="true"></span><span><strong>Création de la vidéo…</strong>${message}<br>Gardez cette fenêtre ouverte jusqu’à la confirmation.</span>`:'';
}

function installVideoBridge(){
  const current=window.generateVoteShareVideo;
  if(typeof current!=='function'||current.__p50ProgressWrapped)return false;
  const wrapped=async function(){
    if(videoBusy)return;
    setVideoBusy(true,'Le traitement peut prendre quelques secondes selon votre téléphone.');
    try{
      return await current.apply(this,arguments);
    }catch(error){
      console.error('Création vidéo PASS50',error);
      try{if(typeof toast==='function')toast('La création de la vidéo a échoué. Réessayez.');}catch{}
      throw error;
    }finally{
      setVideoBusy(false);
    }
  };
  wrapped.__p50ProgressWrapped=true;
  window.generateVoteShareVideo=wrapped;
  return true;
}

function installEvents(){
  document.addEventListener('click',event=>{
    const button=event.target.closest?.('#voteShareConfirmVideo');
    if(!button)return;
    if(videoBusy){event.preventDefault();event.stopImmediatePropagation();return;}
    setTimeout(()=>{if(!videoBusy)setVideoBusy(true,'Préparation du média et de la piste audio…');},0);
  },true);
}

function init(){
  ensureStyles();installModalObserver();installEvents();installVideoBridge();
  setInterval(()=>{
    installVideoBridge();
    syncModalState();
    if(videoBusy){
      const modal=document.getElementById('voteShareModal');
      if(!modal?.classList.contains('show'))setVideoBusy(false);
      else setVideoBusy(true,'Le traitement peut prendre quelques secondes selon votre téléphone.');
    }
  },250);
  window.PASS50_MOBILE_MODAL_VIDEO_PROGRESS=Object.freeze({contract:CONTRACT,setBusy:setVideoBusy});
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
