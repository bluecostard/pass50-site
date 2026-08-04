(function(){
  'use strict';
  const INTERNAL_TEXT='Lien original à valider dans Administration → Actualité';
  const PUBLIC_TEXT='Source en cours de validation';
  function replaceInternalCopy(root=document){const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);nodes.forEach(node=>{if(node.nodeValue&&node.nodeValue.includes(INTERNAL_TEXT))node.nodeValue=node.nodeValue.replace(INTERNAL_TEXT,PUBLIC_TEXT);});}
  function installLegalLinks(){
    const footer=document.querySelector('.footer');if(!footer||footer.querySelector('[data-pass50-legal-links]'))return;
    const links=document.createElement('div');links.dataset.pass50LegalLinks='1';links.style.cssText='display:flex;gap:12px;flex-wrap:wrap;justify-content:center;font-size:11px;color:#9da79b';
    links.innerHTML='<a href="./privacy.html">Confidentialité</a><a href="./data-deletion.html">Suppression des données</a><a href="./terms.html">Conditions d’utilisation</a>';
    footer.appendChild(links);
  }
  const observer=new MutationObserver(()=>{replaceInternalCopy(document);installLegalLinks();});observer.observe(document.documentElement,{subtree:true,childList:true,characterData:true});
  document.addEventListener('DOMContentLoaded',()=>{replaceInternalCopy(document);installLegalLinks();});
  if(!document.querySelector('script[data-pass50-connector-sections]')){const script=document.createElement('script');script.src='./connector-sections-v1.js?v=1.1';script.dataset.pass50ConnectorSections='1.1';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-youtube-oauth-ui]')){const script=document.createElement('script');script.src='./youtube-oauth-ui-v1.js?v=1.0';script.dataset.pass50YoutubeOauthUi='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-youtube-click-hotfix-v3]')){const script=document.createElement('script');script.src='./youtube-oauth-click-hotfix-v2.js?v=3.0';script.dataset.pass50YoutubeClickHotfixV3='3.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-youtube-analytics-ui]')){const script=document.createElement('script');script.src='./youtube-analytics-ui-v1.js?v=1.0';script.dataset.pass50YoutubeAnalyticsUi='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-meta-oauth-ui]')){const script=document.createElement('script');script.src='./meta-oauth-ui-v1.js?v=1.5';script.dataset.pass50MetaOauthUi='1.5';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-tiktok-oauth-ui]')){const script=document.createElement('script');script.src='./tiktok-oauth-ui-v1.js?v=1.0';script.dataset.pass50TiktokOauthUi='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-live-trust-gate]')){const script=document.createElement('script');script.src='./live-trust-gate-v1.js?v=1.2';script.dataset.pass50LiveTrustGate='1.2';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-live-experience-v41]')){const script=document.createElement('script');script.src='./live-experience-v4-1.js?v=1.4';script.dataset.pass50LiveExperienceV41='1.4';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-live-dismiss-ui]')){const script=document.createElement('script');script.src='./live-dismiss-ui-v1.js?v=1.0';script.dataset.pass50LiveDismissUi='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-profile-lionel-pcs]')){const script=document.createElement('script');script.src='./profile-lionel-pcs.js?v=1.0';script.dataset.pass50ProfileLionelPcs='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-profile-yasmine-fofana]')){const script=document.createElement('script');script.src='./profile-yasmine-fofana.js?v=1.0';script.dataset.pass50ProfileYasmineFofana='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-fictive-ranking-admin]')){const script=document.createElement('script');script.src='./admin-fictive-ranking-v1.js?v=1.0';script.dataset.pass50FictiveRankingAdmin='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-classability-sync]')){const script=document.createElement('script');script.src='./classability-sync-v1.js?v=1.4';script.async=false;script.dataset.pass50ClassabilitySync='1.4';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-mobile-bottom-nav]')){const script=document.createElement('script');script.src='./mobile-bottom-nav-v1.js?v=1.2';script.async=false;script.dataset.pass50MobileBottomNav='1.2';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-duel-audio-feed]')){const script=document.createElement('script');script.src='./duel-audio-feed-v1.js?v=1.1';script.async=false;script.dataset.pass50DuelAudioFeed='1.1';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-mobile-modal-video-progress]')){const script=document.createElement('script');script.src='./mobile-modal-video-progress-v1.js?v=1.0';script.async=false;script.dataset.pass50MobileModalVideoProgress='1.0';document.head.appendChild(script);}
})();