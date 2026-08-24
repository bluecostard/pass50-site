(function(){
'use strict';

const VERSION='1.1';
let allowUntil=0;
let tabSwitchUntil=0;
let wrappedNames=Object.create(null);

function now(){return Date.now();}
function allowRebuild(ms=2500){allowUntil=now()+ms;}
function markTabSwitch(){tabSwitchUntil=now()+400;allowRebuild(400);}

function adminOpen(){
  return Boolean(document.querySelector('#adminModal.show'));
}

function pane(){
  return document.querySelector('#adminPane');
}

function currentTab(){
  try{return String(window.ui?.adminTab||'');}catch{return '';}
}

function rememberProfileSearch(value){
  try{
    if(typeof ui==='object'&&ui)ui.profileSearch=value;
  }catch{}
}

function restoreProfileSearch(){
  let stored='';
  try{stored=String(ui?.profileSearch||'');}catch{}
  const input=document.getElementById('profileSearch');
  if(input&&stored&&input.value!==stored)input.value=stored;
  const q=stored.trim().toLowerCase();
  if(!q)return;
  document.querySelectorAll('#profileAdminRows tr').forEach(row=>{
    row.style.display=String(row.dataset.adminProfileName||'').includes(q)?'':'none';
  });
}

function linksHaveDraft(root){
  const card=root.querySelector('.link-card.focused,.link-card');
  if(!card)return false;
  const id=card.getAttribute('data-link-profile')||'';
  let profileItem=null;
  try{profileItem=typeof profile==='function'?profile(id):null;}catch{}
  return [...card.querySelectorAll('[data-link-platform]')].some(input=>{
    const typed=String(input.value||'').trim();
    const saved=String(profileItem?.links?.[input.dataset.linkPlatform]||'').trim();
    return typed!==saved;
  });
}

function isEditingFi(){
  if(!adminOpen())return false;
  const root=pane();
  if(!root)return false;
  if(root.querySelector('#profileForm,#hubForm'))return true;
  if(linksHaveDraft(root))return true;
  const active=document.activeElement;
  const search=root.querySelector('#linksProfileSearch');
  if(active&&root.contains(active)&&active.matches('input,textarea')&&active!==search)return true;
  return false;
}

function shouldSkip(kind){
  if(now()<tabSwitchUntil)return false;
  if(now()<allowUntil)return false;
  if(!isEditingFi())return false;
  const tab=currentTab();
  if(kind==='links')return tab==='links'||!tab;
  if(kind==='pane'||kind==='admin'){
    if(pane()?.querySelector('#profileForm')&&(tab==='profiles'||!tab))return true;
    if(pane()?.querySelector('#hubForm')&&(tab==='hub'||!tab))return true;
    if(tab==='links')return true;
  }
  return false;
}

function restoreLinksSearch(){
  let stored='';
  try{stored=String(PASS50_V9?.linksSearch||'');}catch{}
  const input=document.getElementById('linksProfileSearch');
  if(!input||!stored)return;
  if(input.value!==stored)input.value=stored;
  input.dispatchEvent(new Event('input',{bubbles:true}));
}

function wrap(name,kind){
  const original=window[name];
  if(typeof original!=='function')return false;
  if(original.__p50FiEditPreserve===VERSION)return false;
  const wrapped=function(){
    if(shouldSkip(kind))return;
    const result=original.apply(this,arguments);
    if(kind==='pane'||kind==='admin')queueMicrotask(restoreProfileSearch);
    if(kind==='links'||kind==='pane'||kind==='admin')queueMicrotask(restoreLinksSearch);
    return result;
  };
  wrapped.__p50FiEditPreserve=VERSION;
  wrapped.__p50FiEditPreserveOriginal=original.__p50FiEditPreserveOriginal||original;
  window[name]=wrapped;
  wrappedNames[name]=kind;
  return true;
}

function installWrappers(){
  wrap('renderAdminPane','pane');
  wrap('renderAdmin','admin');
  wrap('p50v9RenderLinks','links');
}

document.addEventListener('click',event=>{
  const tab=event.target?.closest?.('[data-admin-tab],.admin-view-home,#adminHomeButton');
  if(tab){
    const next=String(tab.dataset?.adminTab||'adminhome');
    if(!(next===currentTab()&&isEditingFi()))markTabSwitch();
  }
  if(event.target?.closest?.('.save-links,.check-links,#recoverProfileLinks,#recoverAllLinks'))allowRebuild(2500);
},true);

document.addEventListener('submit',event=>{
  if(event.target?.id==='profileForm'||event.target?.id==='hubForm'||event.target?.id==='newsTriggerForm')allowRebuild(2500);
},true);

document.addEventListener('input',event=>{
  if(event.target?.id==='profileSearch')rememberProfileSearch(event.target.value);
  if(event.target?.id==='linksProfileSearch'){
    try{
      if(typeof PASS50_V9==='object'&&PASS50_V9)PASS50_V9.linksSearch=event.target.value;
    }catch{}
  }
});

function boot(){
  installWrappers();
  const timer=setInterval(installWrappers,400);
  setTimeout(()=>clearInterval(timer),60000);
  window.addEventListener('focus',installWrappers);
}

window.PASS50_FI_EDIT_PRESERVE={
  version:VERSION,
  busy:isEditingFi,
  shouldSkip,
  allowRebuild,
  restoreProfileSearch,
  restoreLinksSearch
};

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
else boot();
})();
