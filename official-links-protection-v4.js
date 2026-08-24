(function(){
  'use strict';

  const VERSION='PASS50-OFFICIAL-LINKS-PROTECTION-V4.6';
  const RESTORE_KEY='pass50_official_links_protection_v4_restore';
  const OWNER_LOCK_KEY='pass50_owner_locked_profiles_v1';
  const OFFICIAL_LINK_FIELDS=['TikTok','Instagram','Facebook','YouTube','X','Snapchat'];
  const OWNER_LOCK_EXACT={
    zagba:{
      TikTok:'https://tiktok.com/@zagbalerekin',
      Instagram:'https://instagram.com/zagbalerequin',
      Facebook:'https://facebook.com/ZagbaLeRequin',
      YouTube:'https://youtube.com/channel/UCuSwqnO-AnSaZwk3JCHqyFg'
    },
    zeinab:{
      TikTok:'https://www.tiktok.com/@cheffezeinabbance',
      Instagram:'https://www.instagram.com/zeinabbance/',
      Facebook:'https://www.facebook.com/p/Zeinab-BANCE-WRG-61568549139334/'
    },
    samo:{
      TikTok:'https://www.tiktok.com/@kommandersamosamo',
      Instagram:'https://www.instagram.com/kommander_samo_samo/'
    }
  };
  let installed=false;
  let restoring=false;
  let pinning=false;
  let installAttempts=0;

  function normalize(value=''){
    const url=String(value||'').trim();
    if(!url)return '';
    return /^https?:\/\//i.test(url)?url:'https://'+url.replace(/^\/\//,'');
  }

  function fold(value=''){
    return String(value||'')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g,'')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g,'');
  }

  function searchKey(value=''){
    return String(value||'')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g,'')
      .toLowerCase()
      .replace(/[^a-z0-9@._-]+/g,' ')
      .trim();
  }

  function ownerLockProfileKey(profile){
    if(!profile||typeof profile!=='object')return '';
    const values=[profile.id,profile.name,profile.displayName,profile.handle,profile.username,profile.slug]
      .map(fold)
      .filter(Boolean);
    if(values.some(value=>['zagbalerequin','zagbalerekin'].includes(value)))return 'zagba';
    if(values.some(value=>['zeinabbance','zeinabbancewrg'].includes(value)))return 'zeinab';
    if(values.some(value=>value==='samosamo'||value.startsWith('samosamo')))return 'samo';
    return '';
  }

  function isSearchLike(url=''){
    try{
      const parsed=new URL(normalize(url));
      const path=parsed.pathname.replace(/^\/+|\/+$/g,'').toLowerCase();
      return !path
        || /(^|\/)(search|results)(\/|$)/i.test(path)
        || /(^|\/)explore\/search(\/|$)/i.test(path)
        || parsed.searchParams.has('search_query')
        || (parsed.searchParams.has('q')&&/(search|explore)/i.test(path));
    }catch{return true;}
  }

  function isDirect(platform,url=''){
    if(platform==='Web'){
      try{
        const parsed=new URL(normalize(url));
        const path=parsed.pathname.replace(/^\/+|\/+$/g,'').toLowerCase();
        return /^https?:$/.test(parsed.protocol)
          && Boolean(parsed.hostname)
          && !/(^|\/)(search|results)(\/|$)/i.test(path)
          && !/(^|\/)explore\/search(\/|$)/i.test(path)
          && !parsed.searchParams.has('search_query');
      }catch{return false;}
    }
    try{
      if(typeof window.p50v9IsDirectPlatformLink==='function')return window.p50v9IsDirectPlatformLink(platform,url);
      if(typeof p50v9IsDirectPlatformLink==='function')return p50v9IsDirectPlatformLink(platform,url);
    }catch{}
    if(isSearchLike(url))return false;
    try{
      const parsed=new URL(normalize(url));
      const host=parsed.hostname.toLowerCase().replace(/^www\./,'');
      const path=parsed.pathname.replace(/\/+$/,'')||'/';
      const segments=path.split('/').filter(Boolean);
      const first=(segments[0]||'').toLowerCase();
      if(platform==='Instagram')return host.endsWith('instagram.com')&&segments.length===1&&!['explore','accounts','reel','reels','p','stories'].includes(first);
      if(platform==='TikTok')return host.endsWith('tiktok.com')&&segments.length===1&&segments[0].startsWith('@');
      if(platform==='Facebook')return host.endsWith('facebook.com')&&segments.length>=1&&!['search','login','watch','reel','reels'].includes(first);
      if(platform==='YouTube')return host.endsWith('youtube.com')&&(/^\/@/i.test(path)||/^\/(channel|c|user)\//i.test(path));
      if(platform==='X')return (host==='x.com'||host==='twitter.com')&&segments.length===1&&!['search','home','explore'].includes(first);
      if(platform==='LinkedIn')return host.endsWith('linkedin.com')&&/^\/(in|company)\//i.test(path);
      if(platform==='Snapchat')return host.endsWith('snapchat.com')&&/^\/add\//i.test(path);
      return false;
    }catch{return false;}
  }

  function ownerLockedLinks(profile,key){
    const current=profile&&profile.links&&typeof profile.links==='object'?profile.links:{};
    const source={...current,...(OWNER_LOCK_EXACT[key]||{})};
    const result={};
    Object.entries(source).forEach(([platform,url])=>{
      const normalized=normalize(url);
      if(normalized&&isDirect(platform,normalized))result[platform]=normalized;
    });
    return result;
  }

  function linkStateClass(status='pending'){
    try{if(typeof p50v9StatusClass==='function')return p50v9StatusClass(status);}catch{}
    return ['ok','owner_verified','manual_verified','blocked_but_exists'].includes(status)?'ok':status==='pending'?'pending':'bad';
  }

  function linkStateText(status='pending'){
    try{if(typeof p50v9StatusText==='function')return p50v9StatusText(status);}catch{}
    const labels={ok:'OFFICIEL',owner_verified:'OFFICIEL',manual_verified:'OFFICIEL',blocked_but_exists:'À CONFIRMER',pending:'NON TESTÉ',search_not_official:'RECHERCHE',generic_or_content:'LIEN GÉNÉRIQUE',wrong_platform:'MAUVAIS RÉSEAU',broken:'CASSÉ',invalid:'INVALIDE'};
    return labels[status]||String(status||'NON TESTÉ').toUpperCase();
  }

  function ensureSixOfficialFields(){
    const cards=[...document.querySelectorAll('#linksCards [data-link-profile]')];
    cards.forEach(card=>{
      const id=card.getAttribute('data-link-profile')||'';
      let p=null;
      try{p=typeof profile==='function'?profile(id):null;}catch{}
      if(!p)return;
      const grid=card.querySelector('.link-grid');
      if(!grid)return;
      const currentInputs=[...grid.querySelectorAll('[data-link-platform]')];
      const currentOrder=currentInputs.map(input=>input.dataset.linkPlatform||'');
      if(currentOrder.length===OFFICIAL_LINK_FIELDS.length&&currentOrder.every((value,index)=>value===OFFICIAL_LINK_FIELDS[index]))return;

      const typedValues={};
      currentInputs.forEach(input=>{typedValues[input.dataset.linkPlatform]=input.value;});
      const fragment=document.createDocumentFragment();
      OFFICIAL_LINK_FIELDS.forEach(platform=>{
        const check=p.linkChecks?.[platform]||{status:'pending'};
        const label=document.createElement('strong');
        label.textContent=platform;
        const input=document.createElement('input');
        input.dataset.linkPlatform=platform;
        input.value=Object.prototype.hasOwnProperty.call(typedValues,platform)?typedValues[platform]:(p.links?.[platform]||'');
        input.placeholder='URL officielle exacte';
        const state=document.createElement('span');
        state.className='link-state '+linkStateClass(check.status);
        state.title=String(check.message||'');
        state.textContent=linkStateText(check.status);
        fragment.append(label,input,state);
      });
      grid.replaceChildren(fragment);
      card.dataset.sixOfficialFields='1';
    });
  }

  function allOfficialLinkProfiles(){
    try{if(typeof p50AllProfiles==='function')return p50AllProfiles();}catch{}
    try{return [...(db.profiles||[])];}catch{return [];}
  }

  function profileSearchHaystack(p){
    return searchKey([
      p?.name,p?.handle,p?.id,p?.category,p?.knownAlias,p?.realName,p?.occupation,
      ...OFFICIAL_LINK_FIELDS,
      ...(p?.platforms||[]),
      ...Object.keys(p?.links||{}),
      ...Object.values(p?.links||{})
    ].filter(Boolean).join(' '));
  }

  function applyOfficialLinksSearch(){
    const input=document.getElementById('linksProfileSearch');
    if(!input)return;
    const q=searchKey(input.value);
    const profiles=allOfficialLinkProfiles();
    const matches=profiles.filter(p=>!q||profileSearchHaystack(p).includes(q));
    const select=document.getElementById('linksProfileSelect');
    const cards=[...document.querySelectorAll('#linksCards [data-link-profile]')];
    if(select){
      const current=(typeof PASS50_V9==='object'&&PASS50_V9.linksProfileId)||select.value||'';
      select.innerHTML=matches.length?matches.map(p=>{
        let label=p.name||p.id;
        try{if(typeof p50ProfileOption==='function')label=p50ProfileOption(p);}catch{}
        return `<option value="${String(p.id).replace(/"/g,'&quot;')}" ${p.id===current?'selected':''}>${String(label).replace(/</g,'&lt;')}</option>`;
      }).join(''):'<option value="">Aucune fiche trouvée</option>';
      if(matches.length&&!matches.some(p=>p.id===current)){
        try{if(typeof PASS50_V9==='object'&&PASS50_V9)PASS50_V9.linksProfileId=matches[0].id;}catch{}
        select.value=matches[0].id;
        if(!window.__pass50OfficialLinksSearchRendering&&typeof p50v9RenderLinks==='function'){
          window.__pass50OfficialLinksSearchRendering=true;
          try{p50v9RenderLinks();}finally{window.__pass50OfficialLinksSearchRendering=false;}
        }
      }
    }
    const visibleIds=new Set(matches.map(p=>String(p.id)));
    let visibleCards=0;
    cards.forEach(card=>{
      const match=!q||visibleIds.has(String(card.getAttribute('data-link-profile')||''));
      const nextDisplay=match?'':'none';
      if(card.style.display!==nextDisplay)card.style.display=nextDisplay;
      if(match)visibleCards++;
    });
    const count=document.getElementById('linksSearchCount');
    const shown=select?matches.length:(cards.length?visibleCards:matches.length);
    const nextCount=q?`${shown} résultat${shown>1?'s':''}`:`${profiles.length} fiches`;
    if(count&&count.textContent!==nextCount)count.textContent=nextCount;
  }

  function ensureOfficialLinksSearch(){
    if(window.PASS50_FI_EDIT_PRESERVE?.busy?.()){
      applyOfficialLinksSearch();
      return Boolean(document.getElementById('linksCards')||document.getElementById('linksProfileSearch')||document.getElementById('linksProfileSelect'));
    }
    const cards=document.getElementById('linksCards');
    const v2=document.querySelector('.links-v2');
    if(!cards&&!v2)return false;
    ensureSixOfficialFields();

    let input=document.getElementById('linksProfileSearch');
    if(!input){
      const box=document.createElement('div');
      box.className='media-search-box links-search-box';
      box.setAttribute('data-pass50-links-search','v1');
      box.innerHTML='<input id="linksProfileSearch" type="search" autocomplete="off" placeholder="Rechercher un influenceur par nom, pseudo ou réseau…"><span id="linksSearchCount"></span>';
      if(cards){
        const toolbar=cards.parentElement?.querySelector('.admin-toolbar');
        if(toolbar)toolbar.insertAdjacentElement('beforebegin',box);
        else cards.insertAdjacentElement('beforebegin',box);
      }else{
        const hint=v2.querySelector('.media-hint');
        if(hint)hint.insertAdjacentElement('afterend',box);
        else v2.prepend(box);
      }
      input=box.querySelector('#linksProfileSearch');
      input?.addEventListener('input',applyOfficialLinksSearch);
      input?.addEventListener('search',applyOfficialLinksSearch);
    }
    applyOfficialLinksSearch();
    return true;
  }

  function sanitizeState(state){
    if(!state||!Array.isArray(state.profiles))return 0;
    let removed=0;
    state.profiles.forEach(profile=>{
      if(!profile||typeof profile!=='object')return;
      const links=profile.links&&typeof profile.links==='object'?profile.links:{};
      const checks=profile.linkChecks&&typeof profile.linkChecks==='object'?profile.linkChecks:{};
      Object.entries({...links}).forEach(([platform,url])=>{
        if(isDirect(platform,url))return;
        delete links[platform];
        delete checks[platform];
        removed++;
      });
      profile.links=links;
      profile.linkChecks=checks;
    });
    return removed;
  }

  function persistBrowser(){
    try{
      if(typeof db==='object'&&db&&typeof APP_KEY==='string')localStorage.setItem(APP_KEY,JSON.stringify(db));
    }catch{}
  }

  function sanitizeBrowser(){
    try{
      if(window.PASS50_FI_EDIT_PRESERVE?.busy?.())return 0;
      if(typeof db!=='object'||!db)return 0;
      const removed=sanitizeState(db);
      if(removed)persistBrowser();
      return removed;
    }catch{return 0;}
  }

  function wrapGlobal(name,wrapper){
    const original=window[name];
    if(typeof original!=='function'||original.__p50OfficialLinksV4)return false;
    const wrapped=wrapper(original);
    wrapped.__p50OfficialLinksV4=true;
    wrapped.__p50OfficialLinksV4Original=original;
    window[name]=wrapped;
    return true;
  }

  async function pinOwnerLockedProfiles(force=false){
    if(pinning||typeof window.apiFetch!=='function'||!window.__pass50CloudReady)return 0;
    let user=null;
    try{user=typeof currentUser==='function'?currentUser():null;}catch{}
    if(!user||!['owner','admin'].includes(String(user.role||'')))return 0;
    if(typeof db!=='object'||!db||!Array.isArray(db.profiles))return 0;
    let previous=0;
    try{previous=Number(localStorage.getItem(OWNER_LOCK_KEY)||0);}catch{}
    if(!force&&Date.now()-previous<5*60*1000)return 0;
    pinning=true;
    let pinned=0;
    try{
      for(const profile of db.profiles){
        const key=ownerLockProfileKey(profile);
        if(!key||!profile?.id)continue;
        const links=ownerLockedLinks(profile,key);
        if(!Object.keys(links).length)continue;
        const data=await apiFetch('official-links-bulk.php',{
          method:'POST',
          body:{action:'save_profile',profileId:String(profile.id),links,confirmedOfficial:true,clientVersion:'4.2-six-fields'}
        });
        if(typeof CLOUD==='object'&&CLOUD&&Number.isFinite(Number(data.stateRevision)))CLOUD.stateRevision=Number(data.stateRevision);
        pinned+=Number(data.linksProcessed||Object.keys(links).length||0);
      }
      try{localStorage.setItem(OWNER_LOCK_KEY,String(Date.now()));}catch{}
      return pinned;
    }catch(error){
      console.error('PASS50 verrouillage propriétaire des liens officiels',error);
      return pinned;
    }finally{pinning=false;}
  }

  async function restoreVerifiedLinks(force=false){
    if(window.PASS50_FI_EDIT_PRESERVE?.busy?.())return;
    if(restoring||typeof window.apiFetch!=='function'||!window.__pass50CloudReady)return;
    let user=null;
    try{user=typeof currentUser==='function'?currentUser():null;}catch{}
    if(!user||!['owner','admin'].includes(String(user.role||'')))return;
    let previous=0;
    try{previous=Number(localStorage.getItem(RESTORE_KEY)||0);}catch{}
    if(!force&&Date.now()-previous<10*60*1000)return;
    restoring=true;
    try{
      const pinned=await pinOwnerLockedProfiles(force);
      const data=await apiFetch('official-links-bulk.php',{method:'POST',body:{action:'integrity_sync',profiles:[],clientVersion:'4.2-six-fields'}});
      if(typeof CLOUD==='object'&&CLOUD&&Number.isFinite(Number(data.stateRevision)))CLOUD.stateRevision=Number(data.stateRevision);
      if(typeof loadCloudState==='function')await loadCloudState();
      const removed=sanitizeBrowser();
      persistBrowser();
      if(typeof ui==='object'&&ui?.adminTab==='links'&&typeof p50v9RenderLinks==='function'&&!window.PASS50_FI_EDIT_PRESERVE?.busy?.())p50v9RenderLinks();
      setTimeout(ensureOfficialLinksSearch,0);
      const restored=Number(data.restoredCount||0);
      if((pinned||restored||removed)&&typeof toast==='function')toast(`✓ Liens protégés : ${pinned} figé(s), ${restored} restauré(s), ${removed} recherche(s) retirée(s)`);
      try{localStorage.setItem(RESTORE_KEY,String(Date.now()));}catch{}
    }catch(error){console.error('PASS50 protection des liens officiels',error);}
    finally{restoring=false;}
  }

  function install(){
    if(installed)return;
    installAttempts++;
    if(typeof window.save!=='function'||typeof window.cloudSafeState!=='function'||typeof window.loadCloudState!=='function'){
      if(installAttempts<160)setTimeout(install,250);
      return;
    }
    wrapGlobal('save',original=>function(){sanitizeBrowser();return original.apply(this,arguments);});
    wrapGlobal('cloudSafeState',original=>function(){const copy=original.apply(this,arguments);sanitizeState(copy);return copy;});
    wrapGlobal('loadCloudState',original=>async function(){const result=await original.apply(this,arguments);sanitizeBrowser();return result;});
    installed=true;
    const removed=sanitizeBrowser();
    if(removed)persistBrowser();
    document.addEventListener('click',event=>{
      if(event.target.closest('[data-admin-tab="links"],#adminOpen')){
        setTimeout(ensureOfficialLinksSearch,60);
        setTimeout(()=>restoreVerifiedLinks(true),350);
      }
    },true);
    const observer=new MutationObserver(()=>{
      if(document.getElementById('linksCards')||document.getElementById('linksProfileSelect')||document.querySelector('.links-v2')){
        ensureOfficialLinksSearch();
        restoreVerifiedLinks(false);
      }
    });
    observer.observe(document.documentElement,{childList:true,subtree:true});
    const readyTimer=setInterval(()=>{
      if(window.__pass50CloudReady){clearInterval(readyTimer);ensureOfficialLinksSearch();restoreVerifiedLinks(true);}
    },500);
    setTimeout(()=>clearInterval(readyTimer),60000);
    window.PASS50_OFFICIAL_LINKS_PROTECTION_V4={version:VERSION,sanitize:sanitizeBrowser,restore:restoreVerifiedLinks,pinOwnerLockedProfiles,ensureOfficialLinksSearch,applyOfficialLinksSearch,ensureSixOfficialFields,fields:[...OFFICIAL_LINK_FIELDS]};
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});
  else install();
})();
