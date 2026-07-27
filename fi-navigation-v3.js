(function(){
'use strict';

const MOBILE_QUERY='(max-width: 680px), (hover: none) and (pointer: coarse)';
let currentProfileId='';
let navigating=false;
let coreOpenProfile=null;
let gesture=null;
let lastPointerGestureAt=0;

const css=`
.p50-fi-nav-controls{display:flex;align-items:center;gap:8px;margin-left:auto;margin-right:8px}
.p50-fi-nav{width:42px;height:42px;display:grid;place-items:center;border:1px solid rgba(183,255,0,.55);border-radius:50%;background:#0a0d0a;color:#fff;font-size:22px;font-weight:950;line-height:1;box-shadow:0 8px 24px rgba(0,0,0,.35);transition:border-color .18s ease,color .18s ease,background .18s ease,transform .18s ease}
.p50-fi-nav:hover,.p50-fi-nav:focus-visible{border-color:#b7ff00;color:#050705;background:#b7ff00;transform:scale(1.05);outline:none}
#profileBody{overscroll-behavior-x:contain}
#profileBody.p50-fi-switching{opacity:.38;transform:translateX(var(--p50-fi-shift,0));transition:opacity .11s ease,transform .11s ease}
@media (max-width:680px),(hover:none) and (pointer:coarse){.p50-fi-nav-controls{display:none!important}#profileBody{touch-action:pan-y}}
`;
const style=document.createElement('style');
style.textContent=css;
document.head.appendChild(style);

function isMobileInteraction(){
  try{return matchMedia(MOBILE_QUERY).matches}catch{return innerWidth<=680}
}

function modalIsOpen(){
  return Boolean(document.getElementById('profileModal')?.classList.contains('show'));
}

function profiles(){
  try{return Array.isArray(db.profiles)?db.profiles:[]}catch{return []}
}

function profileExists(id){
  return Boolean(id&&profiles().some(p=>p.id===id));
}

function idFromRenderedBody(){
  const body=document.getElementById('profileBody');
  if(!body)return '';
  const stored=body.dataset.p50CurrentProfile;
  if(profileExists(stored))return stored;
  const handle=(body.textContent.match(/@[A-Za-z0-9._-]+/)||[])[0];
  return profiles().find(p=>p.handle===handle)?.id||'';
}

function orderedIds(){
  try{
    if(typeof completeRanking==='function'){
      const ids=completeRanking().map(p=>p.id).filter(profileExists);
      if(ids.length)return ids;
    }
  }catch{}
  const currentPeriod=(()=>{try{return ui.period||'2H'}catch{return '2H'}})();
  return [...profiles()].filter(p=>p.alive!==false).sort((a,b)=>{
    const as=Number(a.scores?.[currentPeriod]??0),bs=Number(b.scores?.[currentPeriod]??0);
    if(bs!==as)return bs-as;
    return String(a.name||'').localeCompare(String(b.name||''),'fr');
  }).map(p=>p.id);
}

function updateUrl(id){
  try{
    const url=new URL(location.href);
    url.searchParams.set('profile',id);
    history.replaceState(history.state,'',url);
  }catch{}
}

function afterProfileOpened(id){
  if(!profileExists(id))return;
  currentProfileId=id;
  const body=document.getElementById('profileBody');
  if(body)body.dataset.p50CurrentProfile=id;
  updateUrl(id);
  document.dispatchEvent(new CustomEvent('p50:profile-opened',{detail:{profileId:id}}));
  ensureDesktopButtons();
}

function installOpenProfileWrapper(){
  const candidate=window.openProfile;
  if(typeof candidate!=='function')return false;
  if(candidate.__p50NavigationV3){
    coreOpenProfile=candidate.__p50CoreOpenProfile||candidate;
    return true;
  }
  coreOpenProfile=candidate;
  const wrapped=function(id){
    const profileId=String(id||'');
    if(profileExists(profileId))currentProfileId=profileId;
    const result=coreOpenProfile.apply(this,arguments);
    requestAnimationFrame(()=>afterProfileOpened(profileId));
    return result;
  };
  Object.defineProperty(wrapped,'__p50NavigationV3',{value:true});
  Object.defineProperty(wrapped,'__p50CoreOpenProfile',{value:coreOpenProfile});
  window.openProfile=wrapped;
  return true;
}

function openProfile(id){
  if(!installOpenProfileWrapper())return false;
  window.openProfile(id);
  return true;
}

function navigate(direction){
  if(navigating||!modalIsOpen())return;
  const ids=orderedIds();
  if(ids.length<2)return;
  const current=profileExists(currentProfileId)?currentProfileId:idFromRenderedBody();
  const index=ids.indexOf(current);
  if(index<0)return;
  const target=ids[(index+direction+ids.length)%ids.length];
  if(!target||target===current)return;

  navigating=true;
  currentProfileId=target;
  const body=document.getElementById('profileBody');
  if(body){
    body.style.setProperty('--p50-fi-shift',direction>0?'-18px':'18px');
    body.classList.add('p50-fi-switching');
  }

  window.setTimeout(()=>{
    const opened=openProfile(target);
    if(!opened){navigating=false;body?.classList.remove('p50-fi-switching');return;}
    window.setTimeout(()=>{
      body?.classList.remove('p50-fi-switching');
      body?.style.removeProperty('--p50-fi-shift');
      navigating=false;
    },140);
  },70);
}

function ensureDesktopButtons(){
  const head=document.querySelector('#profileModal .modal-head');
  if(!head||head.querySelector('.p50-fi-nav-controls'))return;
  const controls=document.createElement('div');
  controls.className='p50-fi-nav-controls';
  controls.setAttribute('aria-label','Navigation entre les fiches');
  controls.innerHTML='<button type="button" class="p50-fi-nav prev" aria-label="Fiche précédente" title="Fiche précédente">←</button><button type="button" class="p50-fi-nav next" aria-label="Fiche suivante" title="Fiche suivante">→</button>';
  controls.querySelector('.prev').addEventListener('click',event=>{event.preventDefault();event.stopPropagation();navigate(-1)});
  controls.querySelector('.next').addEventListener('click',event=>{event.preventDefault();event.stopPropagation();navigate(1)});
  const close=head.querySelector('.close');
  if(close)head.insertBefore(controls,close);else head.appendChild(controls);
}

function finishGesture(x,y,startedAt){
  if(!gesture||!modalIsOpen())return;
  const dx=x-gesture.x,dy=y-gesture.y;
  const elapsed=performance.now()-startedAt;
  gesture=null;
  if(elapsed>1200||Math.abs(dx)<38||Math.abs(dx)<=Math.abs(dy)*1.08)return;
  navigate(dx<0?1:-1);
}

function bindPointerSwipe(surface){
  surface.addEventListener('pointerdown',event=>{
    if(!isMobileInteraction()||event.pointerType==='mouse'||event.isPrimary===false)return;
    gesture={pointerId:event.pointerId,x:event.clientX,y:event.clientY,startedAt:performance.now()};
    try{surface.setPointerCapture(event.pointerId)}catch{}
  });
  surface.addEventListener('pointermove',event=>{
    if(!gesture||gesture.pointerId!==event.pointerId)return;
    const dx=event.clientX-gesture.x,dy=event.clientY-gesture.y;
    if(Math.abs(dx)>10&&Math.abs(dx)>Math.abs(dy)*1.05)event.preventDefault();
  },{passive:false});
  surface.addEventListener('pointerup',event=>{
    if(!gesture||gesture.pointerId!==event.pointerId)return;
    lastPointerGestureAt=Date.now();
    finishGesture(event.clientX,event.clientY,gesture.startedAt);
  });
  surface.addEventListener('pointercancel',()=>{gesture=null});
}

function bindTouchFallback(surface){
  let touch=null;
  surface.addEventListener('touchstart',event=>{
    if(!isMobileInteraction()||event.touches.length!==1)return;
    const point=event.touches[0];
    touch={x:point.clientX,y:point.clientY,startedAt:performance.now()};
  },{passive:true});
  surface.addEventListener('touchmove',event=>{
    if(!touch||event.touches.length!==1)return;
    const point=event.touches[0],dx=point.clientX-touch.x,dy=point.clientY-touch.y;
    if(Math.abs(dx)>10&&Math.abs(dx)>Math.abs(dy)*1.05)event.preventDefault();
  },{passive:false});
  surface.addEventListener('touchend',event=>{
    if(!touch||event.changedTouches.length!==1)return;
    if(Date.now()-lastPointerGestureAt<500){touch=null;return;}
    const point=event.changedTouches[0];
    gesture={x:touch.x,y:touch.y,startedAt:touch.startedAt};
    finishGesture(point.clientX,point.clientY,touch.startedAt);
    touch=null;
  },{passive:true});
  surface.addEventListener('touchcancel',()=>{touch=null},{passive:true});
}

function bindSwipe(){
  const surface=document.getElementById('profileBody');
  if(!surface||surface.dataset.p50SwipeBound==='1')return;
  surface.dataset.p50SwipeBound='1';
  bindPointerSwipe(surface);
  bindTouchFallback(surface);
}

function initialise(){
  installOpenProfileWrapper();
  ensureDesktopButtons();
  bindSwipe();
  const rendered=idFromRenderedBody();
  if(rendered)afterProfileOpened(rendered);
}

document.addEventListener('click',event=>{
  const trigger=event.target.closest('[data-profile]');
  if(trigger?.dataset.profile&&profileExists(trigger.dataset.profile))currentProfileId=trigger.dataset.profile;
},true);

document.addEventListener('keydown',event=>{
  if(isMobileInteraction()||!modalIsOpen()||event.target.closest?.('input,textarea,select,[contenteditable="true"]'))return;
  if(event.key==='ArrowLeft'){event.preventDefault();navigate(-1)}
  if(event.key==='ArrowRight'){event.preventDefault();navigate(1)}
});

document.addEventListener('DOMContentLoaded',initialise);
window.addEventListener('load',initialise,{once:true});
const observer=new MutationObserver(()=>requestAnimationFrame(()=>{installOpenProfileWrapper();ensureDesktopButtons();bindSwipe();}));
observer.observe(document.documentElement,{subtree:true,childList:true});
})();
