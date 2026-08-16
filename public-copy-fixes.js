(function(){
  'use strict';

  const PASS50_PUBLIC_RUNTIME='PASS50-PUBLIC-RUNTIME-V91';
  const DUEL_SHARE_HOTFIX='PASS50-DUEL-AUDIO-SHARE-HOTFIX-V1';
  const INTERNAL_TEXT='Lien original à valider dans Administration → Actualité';
  const PUBLIC_TEXT='Source en cours de validation';
  const LEGACY_CONTEXT_SHARE_DISABLED='./context-share-v1.js?v=1.0';
  const FACEBOOK_VIEWER_DEPLOY_TRIGGER='V1.2-20260805';
  void PASS50_PUBLIC_RUNTIME;
  void DUEL_SHARE_HOTFIX;
  void LEGACY_CONTEXT_SHARE_DISABLED;
  void FACEBOOK_VIEWER_DEPLOY_TRIGGER;

  function replaceInternalCopy(root=document){
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),nodes=[];
    while(walker.nextNode())nodes.push(walker.currentNode);
    nodes.forEach(node=>{
      if(node.nodeValue&&node.nodeValue.includes(INTERNAL_TEXT)){
        node.nodeValue=node.nodeValue.replace(INTERNAL_TEXT,PUBLIC_TEXT);
      }
    });
  }

  function installLegalLinks(){
    const footer=document.querySelector('.footer');
    if(!footer||footer.querySelector('[data-pass50-legal-links]'))return;
    const links=document.createElement('div');
    links.dataset.pass50LegalLinks='1';
    links.style.cssText='display:flex;gap:12px;flex-wrap:wrap;justify-content:center;font-size:11px;color:#9da79b';
    links.innerHTML='<a href="./privacy.html">Confidentialité</a><a href="./data-deletion.html">Suppression des données</a><a href="./terms.html">Conditions d’utilisation</a>';
    footer.appendChild(links);
  }

  function removeLegacyShareUi(){
    document.getElementById('shareBtn')?.remove();
    document.querySelectorAll('[data-p50-context-share="ranking"]').forEach(node=>node.remove());
    document.getElementById('p50ContextShareModal')?.remove();
    document.getElementById('p50ContextShareStyles')?.remove();
  }

  function removePublicTrendScores(root=document){
    const sections=[...root.querySelectorAll('section,.section,[class*="trend"],[id*="trend"]')];
    sections.forEach(section=>{
      const heading=String(section.textContent||'').replace(/\s+/g,' ').toUpperCase();
      if(!heading.includes('TOP 5')||!heading.includes('TENDANCE'))return;
      [...section.querySelectorAll('*')].forEach(node=>{
        const text=String(node.textContent||'').replace(/\s+/g,' ').trim();
        if(/^Score\s+(?:tendance\s*:\s*)?\d+(?:[.,]\d+)?\s*\/\s*100$/i.test(text)&&node.children.length===0){
          const parent=node.parentElement;
          node.remove();
          if(parent&&parent.children.length===0&&!String(parent.textContent||'').trim())parent.remove();
        }
      });
    });
  }

  function watchTrendScores(){
    if(window.__pass50TrendScoreObserver)return;
    const observer=new MutationObserver(()=>removePublicTrendScores(document));
    observer.observe(document.documentElement,{childList:true,subtree:true,characterData:true});
    window.__pass50TrendScoreObserver=observer;
  }

  async function disableServiceWorkers(){
    if(!('serviceWorker' in navigator)||!location.protocol.startsWith('http'))return;
    try{
      const registrations=await navigator.serviceWorker.getRegistrations();
      await Promise.all(registrations.map(registration=>registration.unregister()));
    }catch(error){console.warn('PASS50 service worker unregister',error);}
    if('caches' in window){
      try{
        const keys=await caches.keys();
        await Promise.all(keys.filter(key=>key.startsWith('pass50-')).map(key=>caches.delete(key)));
      }catch(error){console.warn('PASS50 cache cleanup',error);}
    }
  }

  function installDuelAudioShareHotfix(){
    if(window.PASS50_DUEL_AUDIO_SHARE_HOTFIX)return;
    const proto=window.Navigator&&Navigator.prototype;
    const original=proto&&typeof proto.share==='function'?proto.share:null;
    if(!original)return;
    try{
      proto.share=function(data){
        try{
          const rawUrl=String(data&&data.url||'');
          const parsed=new URL(rawUrl,location.href);
          const isDuel=parsed.searchParams.get('type')==='duel-audio';
          const audio=String(parsed.searchParams.get('audio')||'');
          if(isDuel&&/^[A-Za-z0-9._-]{12,180}$/.test(audio)){
            const key=audio.slice(0,12).toLowerCase();
            const shortUrl=new URL('./a.php',location.href);
            shortUrl.searchParams.set('k',key);
            const cleanText=String(data&&data.text||'')
              .replace(/https?:\/\/\S+/gi,' ')
              .replace(/\s+/g,' ')
              .trim();
            const transformed={
              title:String(data&&data.title||'PASS50 · Les Coulés'),
              text:cleanText||'🎙 Écoutez ce commentaire audio sur PASS50',
              url:shortUrl.href
            };
            return original.call(this,transformed);
          }
        }catch(error){console.warn('PASS50 duel share hotfix',error);}
        return original.call(this,data);
      };
      window.PASS50_DUEL_AUDIO_SHARE_HOTFIX=Object.freeze({contract:DUEL_SHARE_HOTFIX,mode:'single-short-link-no-attachments'});
    }catch(error){console.warn('PASS50 duel share install',error);}
  }

  function runPublicFixes(){
    replaceInternalCopy(document);
    installLegalLinks();
    removeLegacyShareUi();
    removePublicTrendScores(document);
  }

  function loadScript(selector,src,datasetKey,datasetValue,asyncValue){
    if(document.querySelector(selector))return;
    const script=document.createElement('script');
    script.src=src;
    if(asyncValue===false)script.async=false;
    script.dataset[datasetKey]=datasetValue;
    document.head.appendChild(script);
  }

  function loadContextShareV2(){
    if(window.PASS50_CONTEXT_SHARE_V2||document.querySelector('script[data-pass50-context-share-v2]'))return;
    const script=document.createElement('script');
    script.src='./context-share-v2.js?v=2.6';
    script.async=false;
    script.dataset.pass50ContextShare='2.1';
    script.dataset.pass50ContextShareV2='2.1';
    document.head.appendChild(script);
  }

  function boot(){
    installDuelAudioShareHotfix();
    runPublicFixes();
    watchTrendScores();
    disableServiceWorkers();
    loadScript('script[data-pass50-duel-audio-share-intercept]','./duel-audio-share-intercept-v1.js?v=1.0','pass50DuelAudioShareIntercept','1.0',false);
    loadContextShareV2();
    setTimeout(runPublicFixes,250);
    setTimeout(runPublicFixes,1200);
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();

  loadScript('script[data-pass50-connector-sections]','./connector-sections-v1.js?v=1.1','pass50ConnectorSections','1.1');
  loadScript('script[data-pass50-youtube-oauth-ui]','./youtube-oauth-ui-v1.js?v=1.0','pass50YoutubeOauthUi','1.0');
  loadScript('script[data-pass50-youtube-click-hotfix-v3]','./youtube-oauth-click-hotfix-v2.js?v=3.0','pass50YoutubeClickHotfixV3','3.0');
  loadScript('script[data-pass50-youtube-analytics-ui]','./youtube-analytics-ui-v1.js?v=1.0','pass50YoutubeAnalyticsUi','1.0');
  loadScript('script[data-pass50-meta-oauth-ui]','./meta-oauth-ui-v1.js?v=1.6','pass50MetaOauthUi','1.6');
  loadScript('script[data-pass50-tiktok-oauth-ui]','./tiktok-oauth-ui-v1.js?v=1.0','pass50TiktokOauthUi','1.0');
  loadScript('script[data-pass50-live-trust-gate]','./live-trust-gate-v1.js?v=1.2','pass50LiveTrustGate','1.2');
  loadScript('script[data-pass50-live-experience-v41]','./live-experience-v4-1.js?v=1.7','pass50LiveExperienceV41','1.7');
  loadScript('script[data-pass50-live-dismiss-ui]','./live-dismiss-ui-v1.js?v=1.0','pass50LiveDismissUi','1.0');
  loadScript('script[data-pass50-profile-lionel-pcs]','./profile-lionel-pcs.js?v=1.0','pass50ProfileLionelPcs','1.0');
  loadScript('script[data-pass50-profile-yasmine-fofana]','./profile-yasmine-fofana.js?v=1.0','pass50ProfileYasmineFofana','1.0');
  loadScript('script[data-pass50-fictive-ranking-admin]','./admin-fictive-ranking-v1.js?v=1.0','pass50FictiveRankingAdmin','1.0');
  loadScript('script[data-pass50-classability-sync]','./classability-sync-v1.js?v=1.5','pass50ClassabilitySync','1.5',false);
  loadScript('script[data-pass50-mobile-bottom-nav]','./mobile-bottom-nav-v1.js?v=1.7','pass50MobileBottomNav','1.7',false);
  loadScript('script[data-pass50-duel-audio-feed]','./duel-audio-feed-v1.js?v=1.1','pass50DuelAudioFeed','1.1',false);
  loadScript('script[data-pass50-mobile-modal-video-progress]','./mobile-modal-video-progress-v1.js?v=1.0','pass50MobileModalVideoProgress','1.0',false);
  loadScript('script[data-pass50-facebook-video-player]','./facebook-video-player-v1.js?v=1.2','pass50FacebookVideoPlayer','1.2',false);
  loadScript('script[data-pass50-coules-admin]','./coules-admin-v1.js?v=1.0','pass50CoulesAdmin','1.0',false);
  loadScript('script[data-pass50-admin-profile-alphabetical]','./admin-profile-alphabetical-v1.js?v=1.6','pass50AdminProfileAlphabetical','1.6',false);
  loadScript('script[data-pass50-admin-news-hotfix]','./admin-news-hotfix-v1.js?v=1.2','pass50AdminNewsHotfix','1.2',false);
  loadScript('script[data-pass50-intelligence-signals-ui]','./intelligence-signals-ui-v1.js?v=1.1','pass50IntelligenceSignalsUi','1.1',false);
  loadScript('script[data-pass50-intelligence-signals-diagnostic]','./intelligence-signals-diagnostic-v1.js?v=1.0','pass50IntelligenceSignalsDiagnostic','1.0',false);
  loadScript('script[data-pass50-official-links-protection-v4]','./official-links-protection-v4.js?v=4.3','pass50OfficialLinksProtectionV4','4.3',false);
})();
