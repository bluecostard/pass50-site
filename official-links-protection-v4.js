(function(){
  'use strict';

  const VERSION='PASS50-OFFICIAL-LINKS-PROTECTION-V4.1';
  const RESTORE_KEY='pass50_official_links_protection_v4_restore';
  let installed=false;
  let restoring=false;
  let installAttempts=0;

  function normalize(value=''){
    const url=String(value||'').trim();
    if(!url)return '';
    return /^https?:\/\//i.test(url)?url:'https://'+url.replace(/^\/\//,'');
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

  async function restoreVerifiedLinks(force=false){
    if(restoring||typeof window.apiFetch!=='function'||!window.__pass50CloudReady)return;
    let user=null;
    try{user=typeof currentUser==='function'?currentUser():null;}catch{}
    if(!user||!['owner','admin'].includes(String(user.role||'')))return;

    let previous=0;
    try{previous=Number(localStorage.getItem(RESTORE_KEY)||0);}catch{}
    if(!force&&Date.now()-previous<10*60*1000)return;

    restoring=true;
    try{
      const data=await apiFetch('official-links-bulk.php',{
        method:'POST',
        body:{action:'integrity_sync',profiles:[],clientVersion:'4.1'}
      });
      if(typeof CLOUD==='object'&&CLOUD&&Number.isFinite(Number(data.stateRevision)))CLOUD.stateRevision=Number(data.stateRevision);
      if(typeof loadCloudState==='function')await loadCloudState();
      const removed=sanitizeBrowser();
      persistBrowser();
      if(typeof ui==='object'&&ui?.adminTab==='links'&&typeof p50v9RenderLinks==='function')p50v9RenderLinks();
      const restored=Number(data.restoredCount||0);
      if((restored||removed)&&typeof toast==='function')toast(`✓ Liens protégés : ${restored} restauré(s), ${removed} recherche(s) retirée(s)`);
      try{localStorage.setItem(RESTORE_KEY,String(Date.now()));}catch{}
    }catch(error){
      console.error('PASS50 protection des liens officiels',error);
    }finally{
      restoring=false;
    }
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
      if(event.target.closest('[data-admin-tab="links"],#adminOpen'))setTimeout(()=>restoreVerifiedLinks(true),350);
    },true);

    const observer=new MutationObserver(()=>{
      if(document.querySelector('#linksCards,.links-v2'))restoreVerifiedLinks(false);
    });
    observer.observe(document.documentElement,{childList:true,subtree:true});

    const readyTimer=setInterval(()=>{
      if(window.__pass50CloudReady){clearInterval(readyTimer);restoreVerifiedLinks(true);}
    },500);
    setTimeout(()=>clearInterval(readyTimer),60000);

    window.PASS50_OFFICIAL_LINKS_PROTECTION_V4={version:VERSION,sanitize:sanitizeBrowser,restore:restoreVerifiedLinks};
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});
  else install();
})();
