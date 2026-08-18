(function(){
'use strict';

const VERSION='3.4';
const INTEGRITY_KEY='pass50_official_links_integrity_v3';
let installed=false;
let integrityRunning=false;
let installTimer=null;
let integrityTimer=null;
let readinessTimer=null;

function profileById(id){
  try{return profile(id)||db.profiles.find(item=>item.id===id)}catch{return null}
}

function normalizeLink(platform,url){
  try{
    if(typeof p50v9NormalizeOfficialLink==='function')return p50v9NormalizeOfficialLink(platform,url)||String(url||'').trim();
  }catch{}
  const value=String(url||'').trim();
  if(!value)return '';
  return /^https?:\/\//i.test(value)?value:'https://'+value.replace(/^\/\//,'');
}

function directLink(platform,url){
  try{return typeof p50v9IsDirectPlatformLink==='function'&&p50v9IsDirectPlatformLink(platform,url)}catch{return false}
}

function isSearchLikeUrl(url=''){
  try{
    const u=new URL(normalizeLink('Web',url)||url);
    const path=u.pathname.replace(/^\/+|\/+$/g,'').toLowerCase();
    return /(^|\/)(search|results|explore\/search)(\/|$)/i.test(path)||u.searchParams.has('search_query')||(u.searchParams.has('q')&&/(search|explore)/i.test(path));
  }catch{return false}
}

function persistLocal(){
  try{localStorage.setItem(APP_KEY,JSON.stringify(db))}catch{}
}

function setCloudRevision(value){
  const revision=Number(value);
  if(!Number.isFinite(revision))return;
  try{if(typeof CLOUD!=='undefined')CLOUD.stateRevision=revision}catch{}
}

function stopPendingCloudWrite(){
  window.PASS50_LINK_SAVE_RUNNING=true;
  try{
    if(typeof CLOUD!=='undefined'&&CLOUD.syncTimer){clearTimeout(CLOUD.syncTimer);CLOUD.syncTimer=null;}
  }catch{}
}

function resumeCloudWrite(){
  window.PASS50_LINK_SAVE_RUNNING=false;
}

function collectCardLinks(card,profileItem){
  const links={};
  const invalid=[];
  card.querySelectorAll('[data-link-platform]').forEach(input=>{
    const platform=String(input.dataset.linkPlatform||'').trim();
    const raw=String(input.value||'').trim();
    if(!platform||!raw)return; // Un champ vide ne supprime plus une donnée enregistrée.
    const url=normalizeLink(platform,raw);
    if(url&&url!==raw)input.value=url;
    // Toujours conserver la saisie visible, même si elle n'est pas encore officielle.
    if(profileItem){
      profileItem.links=profileItem.links||{};
      profileItem.linkChecks=profileItem.linkChecks||{};
      profileItem.links[platform]=url;
      profileItem.platforms=Array.isArray(profileItem.platforms)
        ?[...new Set([...profileItem.platforms,platform])]
        :[platform];
    }
    if(directLink(platform,url)){
      links[platform]=url;
      return;
    }
    invalid.push(platform);
    if(profileItem){
      profileItem.linkChecks[platform]={
        status:isSearchLikeUrl(url)?'search_not_official':'generic_or_content',
        checkedAt:new Date().toISOString(),
        message:isSearchLikeUrl(url)
          ?'Page de recherche détectée : colle l’URL exacte du profil (ex. tiktok.com/@compte).'
          :'Lien non reconnu comme profil direct. Vérifie la plateforme et l’URL exacte du compte.',
      };
    }
  });
  persistLocal();
  return {links,invalid};
}

function keepDraft(profileItem,links,confirmed){
  profileItem.links=profileItem.links||{};
  profileItem.linkChecks=profileItem.linkChecks||{};
  profileItem.platforms=Array.isArray(profileItem.platforms)?profileItem.platforms:[];
  const checkedAt=new Date().toISOString();
  Object.entries(links).forEach(([platform,url])=>{
    profileItem.links[platform]=url;
    profileItem.platforms=[...new Set([...profileItem.platforms,platform])];
    profileItem.linkChecks[platform]={
      status:confirmed?'owner_verified':'pending',
      checkedAt,
      message:confirmed?'Enregistrement officiel en cours sur le serveur PASS50':'Lien conservé en brouillon avant enregistrement serveur',
    };
  });
  persistLocal();
}

function applyServerUpdate(profileItem,update,confirmed){
  if(!profileItem||!update)return;
  profileItem.links={...(profileItem.links||{}),...(update.links||{})};
  profileItem.linkChecks={...(profileItem.linkChecks||{}),...(update.linkChecks||{})};
  profileItem.platforms=[...new Set([...(profileItem.platforms||[]),...(update.platforms||[]),...Object.keys(update.links||{})])];
  const now=new Date().toISOString();
  Object.entries(update.links||{}).forEach(([platform])=>{
    if(!profileItem.linkChecks[platform])profileItem.linkChecks[platform]={
      status:confirmed?'owner_verified':'pending',checkedAt:now,
      message:confirmed?'Compte officiel enregistré durablement sur le serveur PASS50':'Lien enregistré durablement sur le serveur PASS50',
      persistedServerSide:true,
    };
  });
}

function setButtons(card,working,label){
  card.querySelectorAll('.save-links,.check-links').forEach(button=>{
    if(!button.dataset.originalLabel)button.dataset.originalLabel=button.textContent||'';
    button.disabled=working;
  });
  const target=card.querySelector('.save-links');
  if(target)target.textContent=working?label:(target.dataset.originalLabel||'Enregistrer');
}

function simpleHash(value){
  let hash=2166136261;
  for(let i=0;i<value.length;i++){hash^=value.charCodeAt(i);hash=Math.imul(hash,16777619);}
  return (hash>>>0).toString(16);
}

function browserIntegrityPayload(){
  const profiles=[];
  (db.profiles||[]).forEach(profileItem=>{
    const links={};
    Object.entries(profileItem.links||{}).forEach(([platform,url])=>{
      if(!directLink(platform,url))return;
      const check=profileItem.linkChecks?.[platform]||{};
      links[platform]={url,status:String(check.status||'pending'),checkedAt:String(check.checkedAt||'')};
    });
    if(Object.keys(links).length)profiles.push({profileId:profileItem.id,links});
  });
  profiles.sort((a,b)=>String(a.profileId).localeCompare(String(b.profileId)));
  return profiles;
}

function confirmedStatus(status=''){
  return ['verified','owner_verified','manual_verified','ok'].includes(String(status));
}

function integritySignaturePayload(profiles){
  return profiles.map(item=>{
    const links={};
    Object.entries(item.links||{})
      .sort(([a],[b])=>String(a).localeCompare(String(b)))
      .forEach(([platform,link])=>{
        links[platform]={url:String(link?.url||''),confirmed:confirmedStatus(link?.status)};
      });
    return {profileId:String(item.profileId||''),links};
  });
}

function currentIntegritySignature(){
  return simpleHash(JSON.stringify(integritySignaturePayload(browserIntegrityPayload())));
}

function rememberIntegritySignature(restoredCount=0){
  try{localStorage.setItem(INTEGRITY_KEY,JSON.stringify({signature:currentIntegritySignature(),at:Date.now(),restored:Number(restoredCount||0)}))}catch{}
}

function refreshLinksPanel(options={}){
  persistLocal();
  // Éviter render() global quand une validation est en cours : il reconstruisait
  // le panneau et donnait l'impression que le lien venait d'être effacé.
  if(window.PASS50_FI_EDIT_PRESERVE?.busy?.()&&!options.fullRender)return;
  if(options.fullRender&&typeof render==='function')render();
  if(typeof p50v9RenderLinks==='function'&&typeof ui==='object'&&ui.adminTab==='links')p50v9RenderLinks();
}

function skippedSearchToast(invalid){
  if(!invalid.length||typeof toast!=='function')return;
  toast(`Non validé : ${invalid.join(', ')}. Garde l’URL du profil exact (avec https://), pas une page de recherche.`);
}

async function durableSaveLinks(id,card,options={}){
  const profileItem=profileById(id);
  if(!profileItem||!card)return null;
  const confirmed=options.confirmedOverride??Boolean(card.querySelector('.confirm-all-links')?.checked);
  const {links,invalid}=collectCardLinks(card,profileItem);

  if(!Object.keys(links).length){
    refreshLinksPanel();
    if(!options.silent&&typeof toast==='function'){
      toast(invalid.length
        ?`Aucun profil officiel direct à ${confirmed?'valider':'enregistrer'}. Les URLs saisies restent affichées — utilise https://…/@compte (pas une page RECHERCHE).`
        :'Aucun nouveau lien à enregistrer. Les champs vides ne suppriment plus les anciens liens.');
    }
    return null;
  }

  keepDraft(profileItem,links,confirmed);
  stopPendingCloudWrite();
  setButtons(card,true,confirmed?'VALIDATION SERVEUR…':'ENREGISTREMENT SERVEUR…');

  try{
    const data=await apiFetch('official-links-bulk.php',{
      method:'POST',
      body:{action:'save_profile',profileId:id,links,confirmedOfficial:Boolean(confirmed),clientVersion:VERSION}
    });
    setCloudRevision(data.stateRevision);
    applyServerUpdate(profileItem,data.updates?.[id],confirmed);
    rememberIntegritySignature();
    try{PASS50_V9.socialHydrated.delete(id)}catch{}
    refreshLinksPanel({fullRender:true});
    if(!options.silent&&typeof toast==='function'){
      const base=confirmed?'✓ Liens officiels validés et enregistrés sur le serveur':'✓ Liens enregistrés durablement sur le serveur';
      toast(invalid.length?`${base} · ${invalid.length} page(s) de recherche ignorée(s)`:base);
    }else if(options.silent)skippedSearchToast(invalid);
    return data;
  }catch(error){
    Object.keys(links).forEach(platform=>{
      profileItem.linkChecks[platform]={status:'pending',checkedAt:new Date().toISOString(),message:'Brouillon conservé dans ce navigateur. Enregistrement serveur à relancer.'};
    });
    refreshLinksPanel({fullRender:true});
    if(!options.silent&&typeof toast==='function')toast(error?.message||'Serveur indisponible : les liens restent conservés dans ce navigateur');
    console.error('Sauvegarde durable des liens officiels',error);
    return null;
  }finally{
    resumeCloudWrite();
    setButtons(card,false,'');
  }
}

async function durableCheckLinks(id,card){
  const profileItem=profileById(id);
  if(!profileItem||!card)return null;
  const confirmed=Boolean(card.querySelector('.confirm-all-links')?.checked);
  if(confirmed)return durableSaveLinks(id,card,{confirmedOverride:true});

  const {links,invalid}=collectCardLinks(card,profileItem);
  if(!Object.keys(links).length){
    refreshLinksPanel();
    if(typeof toast==='function'){
      toast(invalid.length
        ?`Aucun profil direct à vérifier pour l’instant. Les URLs saisies sont conservées — vérifie https:// et le format @compte.`
        :'Aucun lien à vérifier');
    }
    return null;
  }

  const button=card.querySelector('.check-links');
  if(button){button.disabled=true;button.textContent='VÉRIFICATION…';}
  try{
    const checked=await apiFetch('link-check.php',{method:'POST',body:{links}});
    Object.entries(checked.results||{}).forEach(([platform,result])=>{
      const status=['ok','blocked_but_exists'].includes(result.status)?result.status:(result.status==='broken'?'blocked_but_exists':result.status);
      profileItem.linkChecks[platform]={...result,status,checkedAt:checked.checkedAt||new Date().toISOString()};
    });
    persistLocal();
    const saved=await durableSaveLinks(id,card,{confirmedOverride:false,silent:true});
    if(saved&&typeof toast==='function'){
      const base='Liens vérifiés et enregistrés sur le serveur. Coche la confirmation pour les publier comme officiels.';
      toast(invalid.length?`${base} · ${invalid.length} page(s) de recherche ignorée(s)`:base);
    }else if(!saved)skippedSearchToast(invalid);
    return saved;
  }catch(error){
    if(typeof toast==='function')toast(error?.message||'Vérification indisponible');
    return null;
  }finally{
    if(button){button.disabled=false;button.textContent=button.dataset.originalLabel||'Vérifier';}
  }
}

async function runIntegritySync(){
  if(integrityRunning||!window.__pass50CloudReady)return;
  let user=null;
  try{user=currentUser()}catch{}
  if(!user||!['owner','admin'].includes(user.role))return;

  const profiles=browserIntegrityPayload();
  const signature=simpleHash(JSON.stringify(integritySignaturePayload(profiles)));
  let previous={};
  try{previous=JSON.parse(localStorage.getItem(INTEGRITY_KEY)||'{}')}catch{}
  const lastAt=Number(previous.at||0);
  if(previous.signature===signature&&Date.now()-lastAt<24*60*60*1000)return;

  integrityRunning=true;
  stopPendingCloudWrite();
  try{
    const data=await apiFetch('official-links-bulk.php',{
      method:'POST',body:{action:'integrity_sync',profiles,clientVersion:VERSION}
    });
    setCloudRevision(data.stateRevision);
    const restoredCount=Number(data.restoredCount||0);
    if(restoredCount>0){
      if(window.PASS50_FI_EDIT_PRESERVE?.busy?.()){
        rememberIntegritySignature(restoredCount);
        return;
      }
      if(typeof loadCloudState==='function')await loadCloudState();
      persistLocal();
      if(typeof render==='function')render();
      if(typeof p50v9RenderLinks==='function'&&typeof ui==='object'&&ui.adminTab==='links')p50v9RenderLinks();
      if(typeof toast==='function')toast(`✓ ${restoredCount} lien(s) officiel(s) restauré(s) automatiquement`);
    }
    rememberIntegritySignature(restoredCount);
  }catch(error){
    console.error('Restauration automatique des liens officiels',error);
  }finally{
    resumeCloudWrite();
    integrityRunning=false;
  }
}

function scheduleIntegritySync(delay=350){
  clearTimeout(integrityTimer);
  integrityTimer=setTimeout(runIntegritySync,delay);
}

function waitForCloudIntegrity(){
  if(window.__pass50CloudReady){scheduleIntegritySync(200);return;}
  if(readinessTimer)return;
  let attempts=0;
  readinessTimer=setInterval(()=>{
    attempts++;
    if(window.__pass50CloudReady){
      clearInterval(readinessTimer);
      readinessTimer=null;
      scheduleIntegritySync(200);
    }else if(attempts>=80){
      clearInterval(readinessTimer);
      readinessTimer=null;
    }
  },500);
}

function addPersistenceNotice(){
  const root=document.querySelector('.links-v2');
  if(!root||root.querySelector('[data-links-persistence-v3]'))return;
  const notice=document.createElement('div');
  notice.dataset.linksPersistenceV3='1';
  notice.style.cssText='margin:10px 0;padding:10px 12px;border:1px solid rgba(183,255,0,.45);border-radius:12px;background:rgba(183,255,0,.07);color:#dfffb0;font-size:12px;font-weight:800';
  notice.textContent='✓ Sauvegarde serveur transactionnelle active · un champ vide ne supprime plus un ancien lien.';
  root.prepend(notice);
}

function mutationAddsLinksPanel(records){
  return records.some(record=>[...record.addedNodes].some(node=>
    node?.nodeType===1&&(node.matches?.('.links-v2')||node.querySelector?.('.links-v2'))
  ));
}

function install(){
  if(installed)return true;
  if(typeof p50v9SaveLinks!=='function'||typeof p50v9CheckLinks!=='function'||typeof apiFetch!=='function'||typeof db==='undefined')return false;

  const originalSchedule=typeof scheduleCloudSync==='function'?scheduleCloudSync:null;
  if(originalSchedule&&!originalSchedule.__linksPersistenceV3){
    const guarded=function(){if(window.PASS50_LINK_SAVE_RUNNING)return;return originalSchedule.apply(this,arguments)};
    guarded.__linksPersistenceV3=true;
    scheduleCloudSync=guarded;
  }

  p50v9SaveLinks=durableSaveLinks;
  p50v9CheckLinks=durableCheckLinks;
  installed=true;
  addPersistenceNotice();
  waitForCloudIntegrity();
  return true;
}

installTimer=setInterval(()=>{
  if(install())clearInterval(installTimer);
},250);
setTimeout(()=>{if(installTimer)clearInterval(installTimer)},30000);

document.addEventListener('DOMContentLoaded',()=>{install();waitForCloudIntegrity()});
const observer=new MutationObserver(records=>{
  if(!mutationAddsLinksPanel(records))return;
  requestAnimationFrame(addPersistenceNotice);
});
observer.observe(document.documentElement,{subtree:true,childList:true});
})();
