(function(){
'use strict';

const MOBILE_MAX=680;
let currentProfileId='';
let touchStartX=0;
let touchStartY=0;
let touchStartedAt=0;
let navigating=false;

const css=`
.p50-fi-nav{position:absolute;top:50%;z-index:80;width:54px;height:54px;display:grid;place-items:center;border:1px solid rgba(183,255,0,.58);border-radius:50%;background:rgba(5,7,5,.94);color:#fff;font-size:34px;line-height:1;box-shadow:0 12px 36px rgba(0,0,0,.58);transform:translateY(-50%);transition:border-color .18s ease,color .18s ease,transform .18s ease,background .18s ease}
.p50-fi-nav:hover,.p50-fi-nav:focus-visible{border-color:#b7ff00;color:#050705;background:#b7ff00;transform:translateY(-50%) scale(1.06);outline:none}
.p50-fi-nav.prev{left:max(18px,calc(50vw - 580px))}.p50-fi-nav.next{right:max(18px,calc(50vw - 580px))}
#profileBody.p50-fi-switching{opacity:.32;transform:translateX(var(--p50-fi-shift,0));transition:opacity .12s ease,transform .12s ease}
@media(max-width:1180px) and (min-width:681px){.p50-fi-nav.prev{left:18px}.p50-fi-nav.next{right:18px}}
@media(max-width:680px){.p50-fi-nav{display:none!important}#profileBody{touch-action:pan-y}}
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

function period(){
  try{return ui.period||'2H'}catch{return '2H'}
}

function profileIdFromBody(){
  const body=document.getElementById('profileBody');
  if(!body)return '';
  const actionId=body.querySelector('.p50-fi-actions[data-profile-id]')?.dataset.profileId;
  if(actionId)return actionId;
  const handle=(body.textContent.match(/@[A-Za-z0-9._-]+/)||[])[0];
  return profiles().find(p=>p.handle===handle)?.id||currentProfileId||'';
}

function orderedIds(){
  const list=profiles();
  const p=period();
  const visible=[];
  document.querySelectorAll('[data-profile]').forEach(el=>{
    const id=el.dataset.profile;
    if(id&&!visible.includes(id)&&list.some(x=>x.id===id))visible.push(id);
  });
  const ranked=[...list].sort((a,b)=>{
    const as=Number(a.scores?.[p]??0),bs=Number(b.scores?.[p]??0);
    if(bs!==as)return bs-as;
    return String(a.name||'').localeCompare(String(b.name||''),'fr');
  }).map(x=>x.id);
  return [...visible,...ranked.filter(id=>!visible.includes(id))];
}

function updateUrl(id){
  try{
    const url=new URL(location.href);
    url.searchParams.set('profile',id);
    history.replaceState(history.state,'',url);
  }catch{}
}

function openProfileDirectly(id){
  if(typeof window.openProfile==='function'){
    window.openProfile(id);
    return true;
  }
  const existing=document.querySelector(`[data-profile="${CSS.escape(id)}"]`);
  if(existing){
    existing.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,view:window}));
    return true;
  }
  return false;
}

function navigate(direction){
  if(navigating||!modalIsOpen())return;
  const ids=orderedIds();
  const current=profileIdFromBody();
  const index=ids.indexOf(current);
  if(index<0||ids.length<2)return;
  const target=ids[(index+direction+ids.length)%ids.length];
  if(!target||target===current)return;

  navigating=true;
  const body=document.getElementById('profileBody');
  if(body){
    body.style.setProperty('--p50-fi-shift',direction>0?'-20px':'20px');
    body.classList.add('p50-fi-switching');
  }

  window.setTimeout(()=>{
    currentProfileId=target;
    const opened=openProfileDirectly(target);
    if(opened)updateUrl(target);
    window.setTimeout(()=>{
      body?.classList.remove('p50-fi-switching');
      body?.style.removeProperty('--p50-fi-shift');
      navigating=false;
      syncNavigation();
    },150);
  },90);
}

function ensureDesktopButtons(){
  const modal=document.getElementById('profileModal');
  if(!modal||modal.querySelector('.p50-fi-nav'))return;

  const prev=document.createElement('button');
  prev.type='button';
  prev.className='p50-fi-nav prev';
  prev.setAttribute('aria-label','Fiche précédente');
  prev.title='Fiche précédente';
  prev.textContent='‹';

  const next=document.createElement('button');
  next.type='button';
  next.className='p50-fi-nav next';
  next.setAttribute('aria-label','Fiche suivante');
  next.title='Fiche suivante';
  next.textContent='›';

  prev.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();navigate(-1)});
  next.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();navigate(1)});
  modal.append(prev,next);
}

function syncNavigation(){
  if(!modalIsOpen())return;
  currentProfileId=profileIdFromBody()||currentProfileId;
  if(currentProfileId)updateUrl(currentProfileId);
  ensureDesktopButtons();
}

function isEditable(target){
  return target instanceof HTMLElement&&Boolean(target.closest('input,textarea,select,[contenteditable="true"]'));
}

document.addEventListener('click',e=>{
  const trigger=e.target.closest('[data-profile]');
  if(trigger?.dataset.profile)currentProfileId=trigger.dataset.profile;
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
