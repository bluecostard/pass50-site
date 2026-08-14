(function(){
'use strict';

const VERSION='1.0';
const TARGET_NAMES=new Set(['zagbalerequin','zeinabbance','samosamo']);
let renderWrapped=false;

function normName(value){
  return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'');
}
function targets(){
  try{return (db?.profiles||[]).filter(p=>TARGET_NAMES.has(normName(p?.name)));}catch{return [];}
}
function lockProfile(p,saveState=true){
  if(!p)return false;
  p.links=p.links||{};p.linkChecks=p.linkChecks||{};p.officialLinkLocks={...(p.officialLinkLocks||{})};p.platforms=Array.isArray(p.platforms)?p.platforms:[];
  let changed=false;
  Object.entries(p.links).forEach(([platform,raw])=>{
    const url=String(raw||'').trim();if(!url)return;
    if(p.officialLinkLocks[platform]!==url){p.officialLinkLocks[platform]=url;changed=true;}
    if(!p.platforms.includes(platform)){p.platforms.push(platform);changed=true;}
    const current=p.linkChecks[platform]||{};
    if(current.status!=='owner_verified'||current.locked!==true||!current.persistedServerSide){
      p.linkChecks[platform]={...current,status:'owner_verified',checkedAt:current.checkedAt||new Date().toISOString(),message:'Compte officiel confirmé et figé par le propriétaire PASS50',locked:true,persistedServerSide:true,protectedBy:'PASS50-OWNER-CURRENT-LINKS-LOCK-V1'};
      changed=true;
    }
  });
  if(changed&&saveState){try{if(typeof save==='function')save();}catch{}}
  return changed;
}
function decorate(){
  targets().forEach(p=>{
    const card=document.querySelector(`[data-link-profile="${CSS.escape(String(p.id))}"]`);if(!card)return;
    Object.entries(p.officialLinkLocks||{}).forEach(([platform,url])=>{
      if(!url)return;
      const input=card.querySelector(`[data-link-platform="${CSS.escape(platform)}"]`);if(!input)return;
      input.value=url;input.readOnly=true;input.dataset.officialLocked='1';input.title='Lien officiel figé par le propriétaire PASS50';
      const state=input.nextElementSibling;
      if(state&&state.classList.contains('link-state')){state.classList.add('ok');state.textContent='FIGÉ';state.title='Compte officiel protégé contre la suppression et le remplacement automatique';}
    });
  });
}
function enforce(){targets().forEach(p=>lockProfile(p,true));decorate();}
function wrapRender(){
  if(renderWrapped||typeof window.p50v9RenderLinks!=='function')return;
  const original=window.p50v9RenderLinks;
  window.p50v9RenderLinks=function(){targets().forEach(p=>lockProfile(p,false));const result=original.apply(this,arguments);queueMicrotask(decorate);return result;};
  renderWrapped=true;
}
document.addEventListener('click',e=>{
  const button=e.target?.closest?.('.save-links,.check-links');if(!button)return;
  const p=targets().find(item=>String(item.id)===String(button.dataset.id));if(!p)return;
  Object.entries(p.officialLinkLocks||{}).forEach(([platform,url])=>{const input=button.closest('[data-link-profile]')?.querySelector(`[data-link-platform="${CSS.escape(platform)}"]`);if(input)input.value=url;});
  lockProfile(p,true);
},true);
function install(){wrapRender();enforce();}
const timer=setInterval(install,1000);setTimeout(()=>clearInterval(timer),45000);
document.addEventListener('DOMContentLoaded',install);window.addEventListener('focus',install);
window.PASS50_OWNER_CURRENT_LINK_LOCKS={version:VERSION,names:[...TARGET_NAMES]};
})();
