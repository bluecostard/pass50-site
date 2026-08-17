(function(){
'use strict';
if(!document.querySelector('script[data-pass50-owner-current-links-lock]')){
  const script=document.createElement('script');
  script.src='./owner-current-links-lock-v1.js?v=1.0';
  script.async=false;
  script.dataset.pass50OwnerCurrentLinksLock='1.0';
  document.head.appendChild(script);
}
})();

(function(){
'use strict';

const VERSION='1.1';
const PROFILE_ID='census-observateur-ebene';
const LOCKS={
  YouTube:'https://www.youtube.com/@Observateur',
  Facebook:'https://www.facebook.com/observateurofficiel/',
  X:'https://x.com/FlorentAMANY'
};
let serverSeeded=false;
let renderWrapped=false;

function getProfile(){
  try{
    if(typeof profile==='function'){
      const direct=profile(PROFILE_ID);
      if(direct)return direct;
    }
  }catch{}
  try{
    return (db?.profiles||[]).find(p=>p.id===PROFILE_ID||String(p.name||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').includes('observateur ebene'))||null;
  }catch{return null;}
}

function normalize(platform,url){
  try{
    if(typeof p50v9NormalizeOfficialLink==='function')return p50v9NormalizeOfficialLink(platform,url)||url;
  }catch{}
  return String(url||'').replace(/\/$/,'');
}

function same(platform,a,b){return normalize(platform,a)===normalize(platform,b);}

function enforceLocks(saveState=true){
  const p=getProfile();
  if(!p)return false;
  p.links=p.links||{};
  p.linkChecks=p.linkChecks||{};
  p.platforms=Array.isArray(p.platforms)?p.platforms:[];
  p.officialLinkLocks={...(p.officialLinkLocks||{})};
  let changed=false;
  Object.entries(LOCKS).forEach(([platform,url])=>{
    if(!same(platform,p.links[platform],url)){p.links[platform]=url;changed=true;}
    if(!p.platforms.includes(platform)){p.platforms.push(platform);changed=true;}
    if(!same(platform,p.officialLinkLocks[platform],url)){p.officialLinkLocks[platform]=url;changed=true;}
    const current=p.linkChecks[platform]||{};
    if(current.status!=='owner_verified'||current.locked!==true||!current.persistedServerSide){
      p.linkChecks[platform]={
        ...current,
        status:'owner_verified',
        checkedAt:current.checkedAt||new Date().toISOString(),
        message:'Compte officiel confirmé',
        locked:true,
        persistedServerSide:true
      };
      changed=true;
    }
  });
  if(changed&&saveState){try{if(typeof save==='function')save();}catch{}}
  return changed;
}

function decorateLockedFields(){
  const card=document.querySelector(`[data-link-profile="${PROFILE_ID}"]`);
  if(!card)return;
  Object.entries(LOCKS).forEach(([platform,url])=>{
    const input=card.querySelector(`[data-link-platform="${platform}"]`);
    if(!input)return;
    input.value=url;
    input.readOnly=true;
    input.dataset.officialLocked='1';
    input.title='Lien officiel confirmé';
    input.setAttribute('aria-label',`${platform} officiel figé`);
    const state=input.nextElementSibling;
    if(state&&state.classList.contains('link-state')){
      state.classList.add('ok');
      state.textContent='FIGÉ';
      state.title='Compte officiel confirmé et protégé contre les remplacements automatiques';
    }
  });
}

function allProfiles(){
  try{
    if(typeof p50AllProfiles==='function')return p50AllProfiles();
  }catch{}
  try{return [...(db?.profiles||[])];}catch{return [];}
}

function searchHaystack(p){
  return [p?.name,p?.handle,p?.id,p?.category,...Object.keys(p?.links||{}),...Object.values(p?.links||{})]
    .filter(Boolean).join(' ').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('fr');
}

function enhanceSearch(){
  const root=document.querySelector('.links-v2');
  if(!root)return;
  let input=document.getElementById('linksProfileSearch');
  if(!input){
    const section=document.createElement('section');
    section.className='news-search-box';
    section.style.marginBottom='14px';
    section.innerHTML='<div class="section-title" style="margin-bottom:10px">Rechercher une fiche</div><div class="links-v2-toolbar"><label>Nom, pseudo, identifiant ou réseau<input id="linksProfileSearch" type="search" autocomplete="off" placeholder="Ex. Observateur, @pseudo, YouTube…"></label></div><div class="muted" id="linksSearchCount" style="margin-top:8px"></div>';
    const hint=root.querySelector('.media-hint');
    if(hint)hint.insertAdjacentElement('afterend',section);else root.prepend(section);
    input=document.getElementById('linksProfileSearch');
  }
  if(input){
    input.placeholder='Rechercher par nom, pseudo, identifiant ou URL sociale…';
    input.dataset.pass50ExtendedSearch='1';
  }
}

function applyExtendedSearch(value){
  const select=document.getElementById('linksProfileSelect');
  const count=document.getElementById('linksSearchCount');
  if(!select)return;
  const q=String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLocaleLowerCase('fr');
  const matches=allProfiles().filter(p=>!q||searchHaystack(p).includes(q));
  const current=(typeof PASS50_V9==='object'&&PASS50_V9.linksProfileId)||select.value||'';
  select.innerHTML=matches.length?matches.map(p=>{
    let label=p.name||p.id;
    try{if(typeof p50ProfileOption==='function')label=p50ProfileOption(p);}catch{}
    return `<option value="${String(p.id).replace(/"/g,'&quot;')}" ${p.id===current?'selected':''}>${String(label).replace(/</g,'&lt;').replace(/>/g,'&gt;')}</option>`;
  }).join(''):'<option value="">Aucune fiche trouvée</option>';
  if(count)count.textContent=`${matches.length} fiche${matches.length>1?'s':''} trouvée${matches.length>1?'s':''}`;
}

async function seedServerLocks(){
  if(serverSeeded||!window.__pass50CloudReady||typeof apiFetch!=='function')return;
  const p=getProfile();
  if(!p)return;
  serverSeeded=true;
  const jobs=Object.entries(LOCKS).map(([platform,url])=>apiFetch('social-links.php',{
    method:'POST',
    body:{action:'save',profileId:p.id,platform,url,confirmedOfficial:true,replaceExisting:true,lockedOfficial:true}
  }));
  const results=await Promise.allSettled(jobs);
  if(results.some(r=>r.status==='rejected')){
    serverSeeded=false;
    console.warn('PASS50 · synchronisation des liens figés Observateur à relancer');
  }
  enforceLocks(true);
  decorateLockedFields();
}

function wrapRender(){
  if(renderWrapped||typeof window.p50v9RenderLinks!=='function')return;
  const original=window.p50v9RenderLinks;
  window.p50v9RenderLinks=function(){
    enforceLocks(false);
    const result=original.apply(this,arguments);
    queueMicrotask(()=>{enhanceSearch();decorateLockedFields();});
    return result;
  };
  renderWrapped=true;
}

function install(){
  if(typeof window.db==='undefined'&&typeof db==='undefined')return false;
  enforceLocks(true);
  wrapRender();
  enhanceSearch();
  decorateLockedFields();
  seedServerLocks().catch(()=>null);
  return true;
}

document.addEventListener('input',function(e){
  if(e.target?.id==='linksProfileSearch')setTimeout(()=>applyExtendedSearch(e.target.value),0);
});

document.addEventListener('click',function(e){
  const button=e.target?.closest?.('.save-links,.check-links');
  if(!button||button.dataset.id!==PROFILE_ID)return;
  const card=button.closest('[data-link-profile]');
  Object.entries(LOCKS).forEach(([platform,url])=>{
    const input=card?.querySelector(`[data-link-platform="${platform}"]`);
    if(input)input.value=url;
  });
  enforceLocks(true);
},true);

const timer=setInterval(()=>{
  install();
  if(window.__pass50CloudReady)seedServerLocks().catch(()=>null);
},1200);
setTimeout(()=>clearInterval(timer),45000);
window.addEventListener('focus',()=>{install();seedServerLocks().catch(()=>null);});

window.PASS50_OBSERVATEUR_LINK_LOCKS={version:VERSION,profileId:PROFILE_ID,links:{...LOCKS}};
})();
