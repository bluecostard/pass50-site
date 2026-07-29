(function(){
  'use strict';
  const INTERNAL_TEXT='Lien original à valider dans Administration → Actualité';
  const PUBLIC_TEXT='Source en cours de validation';
  function replaceInternalCopy(root=document){const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);nodes.forEach(node=>{if(node.nodeValue&&node.nodeValue.includes(INTERNAL_TEXT))node.nodeValue=node.nodeValue.replace(INTERNAL_TEXT,PUBLIC_TEXT);});}
  const observer=new MutationObserver(()=>replaceInternalCopy(document));observer.observe(document.documentElement,{subtree:true,childList:true,characterData:true});document.addEventListener('DOMContentLoaded',()=>replaceInternalCopy(document));
  if(!document.querySelector('script[data-pass50-youtube-oauth-ui]')){const script=document.createElement('script');script.src='./youtube-oauth-ui-v1.js?v=1.0';script.dataset.pass50YoutubeOauthUi='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-youtube-click-hotfix-v3]')){const script=document.createElement('script');script.src='./youtube-oauth-click-hotfix-v2.js?v=3.0';script.dataset.pass50YoutubeClickHotfixV3='3.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-youtube-analytics-ui]')){const script=document.createElement('script');script.src='./youtube-analytics-ui-v1.js?v=1.0';script.dataset.pass50YoutubeAnalyticsUi='1.0';document.head.appendChild(script);}
  if(!document.querySelector('script[data-pass50-meta-oauth-ui]')){const script=document.createElement('script');script.src='./meta-oauth-ui-v1.js?v=1.0';script.dataset.pass50MetaOauthUi='1.0';document.head.appendChild(script);}
})();
