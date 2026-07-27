(function(){
'use strict';

const MOBILE_MAX=680;
let currentProfileId='';
let touchStartX=0;
let touchStartY=0;
let touchStartedAt=0;
let navigating=false;
let pendingProfileId='';

const css=`
.p50-fi-nav-controls{display:flex;align-items:center;gap:8px;margin-left:auto;margin-right:8px}
.p50-fi-nav{width:42px;height:42px;display:grid;place-items:center;border:1px solid rgba(183,255,0,.55);border-radius:50%;background:#0a0d0a;color:#fff;font-size:24px;font-weight:900;line-height:1;box-shadow:0 8px 24px rgba(0,0,0,.35);transition:border-color .18s ease,color .18s ease,background .18s ease,transform .18s ease}
.p50-fi-nav:hover,.p50-fi-nav:focus-visible{border-color:#b7ff00;color:#050705;background:#b7ff00;transform:scale(1.05);outline:none}
#profileBody.p50-fi-switching{opacity:.34;transform:translateX(var(--p50-fi-shift,0));transition:opacity .12s ease,transform .12s ease}
@media(max-width:680px){.p50-fi-nav-controls{display:none!important}#profileBody{touch-action:pan-y}}
`;
const style=document.createElement('style');
style.textContent=css;
document.head.appendChild(style);

function modalIsOpen(){
  const modal=document.getElementById('profileModal');
  return Boolean(modal&&modal.classList.contains('show'));
}

function profiles(){
  try{return Array.isArray(db.profiles)?db.profiles:[]}catch{return []}
}

function bodyProfileId(){
  const body=document.getElementById('profileBody');
  if(!body)return '';
  const handle=(body.textContent.match(/@[A-Za-z0-9._-]+/)||[])[0];
  if(!handle)return '';
  return profiles().find(p=>p.handle===handle)?.id||'';
}

function orderedIds(){
  try{
    if(typeof completeRanking==='function'){
      const ids=completeRanking().map(p=>p.id).filter(Boolean);
      if(ids.length)return ids;
    }
  }catch{}
  const p=(()=>{try{return ui.period||'2H'}catch{return '2H'}})();
  return [...profiles()].sort((a,b)=>{
    const as=Number(a.scores?.[p]??0),bs=Number(b.scores?.[p]??0);
    if(bs!==as)return bs-as;
    return String(a.name||'').localeCompare(String(b.name||''),'fr');
  }).map(x=>x.id);
}

function updateUrl(id){
  try{
    const url=new URL(location.href);
    url.searchParams.set('profile',id);
    history.replaceState(history.state,'',url);
  }catch{}
}

function notifyProfileChange(id){
  document.dispatchEvent(new CustomEvent('p50:profile-change',{detail:{profileId:id}}));
}

function openProfileThroughApp(id){
  if(typeof window.openProfile==='function'){
    window.openProfile(id);
    return true;
  }
  const trigger=document.createElement('div');
  trigger.dataset.profile=id;
  trigger.hidden=true;
  document.body.appendChild(trigger);
  trigger.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,view:window}));
  trigger.remove();
  return true;
}

function navigate(direction){
  if(navigating||!modalIsOpen())return;
  const ids=orderedIds();
  const actual=bodyProfileId();
  const current=currentProfileId||actual;
  const index=ids.indexOf(current);
  if(index<0||ids.length<2)return;
  const target=ids[(index+direction+ids.length)%ids.length];
  if(!target||target===current)return;

  navigating=true;
  pendingProfileId=target;
  currentProfileId=target;
  notifyProfileChange(target);

  const body=document.getElementById('profileBody');
  if(body){
    body.style.setProperty('--p50-fi-shift',direction>0?'-20px':'20px');
    body.classList.add('p50-fi-switching');
  }

  window.setTimeout(()=>{
    openProfileThroughApp(target);
    updateUrl(target);
    window.setTimeout(()=>{
      body?.classList.remove('p50-fi-switching');
      body?.style.removeProperty('--p50-fi-shift');
      navigating=false;
      syncNavigation();
    },170);
  },80);
}

function ensureDesktopButtons(){
  const head=document.querySelector('#profileModal .modal-head');
  if(!head||head.querySelector('.p50-fi-nav-controls'))return;

  const controls=document.createElement('div');
  controls.className='p50-fi-nav-controls';
  controls.setAttribute('aria-label','Navigation entre les fiches');
  controls.innerHTML='<button type="button" class="p50-fi-nav prev" aria-label="Fiche précédente" title="Fiche précédente">←</button><button type="button" class="p50-fi-nav next" aria-label="Fiche suivante" title="Fiche suivante">→</button>';
  controls.querySelector('.prev').addEventListener('click',e=>{e.preventDefault();e.stopPropagation();navigate(-1)});
  controls.querySelector('.next').addEventListener('click',e=>{e.preventDefault();e.stopPropagation();navigate(1)});

  const close=head.querySelector('.close');
  if(close)head.insertBefore(controls,close);else head.appendChild(controls);
}

function syncNavigation(){
  ensureDesktopButtons();
  if(!modalIsOpen())return;
  const actual=bodyProfileId();
  if(actual){
    if(!pendingProfileId||actual===pendingProfileId){
      currentProfileId=actual;
      pendingProfileId='';
      updateUrl(actual);
    }
  }
}

function isEditable(target){
  return target instanceof HTMLElement&&Boolean(target.closest('input,textarea,select,[contenteditable="true"]'));
}

document.addEventListener('click',e=>{
  const trigger=e.target.closest('[data-profile]');
  if(trigger?.dataset.profile){
    currentProfileId=trigger.dataset.profile;
    pendingProfileId='';
    notifyProfileChange(currentProfileId);
  }
},true);

document.addEventListener('keydown',e=>{
  if(innerWidth<=MOBILE_MAX||!modalIsOpen()||isEditable(e.target))return;
  if(e.key==='ArrowLeft'){e.preventDefault();navigate(-1)}
  if(e.key==='ArrowRight'){e.preventDefault();navigate(1)}
});

const modal=document.getElementById('profileModal');
if(modal){
  modal.addEventListener('touchstart',e=>{
    if(innerWidth>MOBILE_MAX||e.touches.length!==1)return;
    touchStartX=e.touches[0].clientX;
    touchStartY=e.touches[0].clientY;
    touchStartedAt=Date.now();
  },{passive:true});

  modal.addEventListener('touchend',e=>{
    if(innerWidth>MOBILE_MAX||!touchStartedAt||e.changedTouches.length!==1)return;
    const dx=e.changedTouches[0].clientX-touchStartX;
    const dy=e.changedTouches[0].clientY-touchStartY;
    const elapsed=Date.now()-touchStartedAt;
    touchStartedAt=0;
    if(elapsed>700||Math.abs(dx)<60||Math.abs(dx)<=Math.abs(dy)*1.25)return;
    navigate(dx<0?1:-1);
  },{passive:true});
}

const observer=new MutationObserver(()=>requestAnimationFrame(syncNavigation));
observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['class','style']});
document.addEventListener('DOMContentLoaded',syncNavigation);
})();
