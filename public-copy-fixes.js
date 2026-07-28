(function(){
  'use strict';
  const INTERNAL_TEXT='Lien original à valider dans Administration → Actualité';
  const PUBLIC_TEXT='Source en cours de validation';

  function replaceInternalCopy(root=document){
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);
    const nodes=[];
    while(walker.nextNode())nodes.push(walker.currentNode);
    nodes.forEach(node=>{
      if(node.nodeValue&&node.nodeValue.includes(INTERNAL_TEXT)){
        node.nodeValue=node.nodeValue.replace(INTERNAL_TEXT,PUBLIC_TEXT);
      }
    });
  }

  const observer=new MutationObserver(()=>replaceInternalCopy(document));
  observer.observe(document.documentElement,{subtree:true,childList:true,characterData:true});
  document.addEventListener('DOMContentLoaded',()=>replaceInternalCopy(document));

  // Interface sécurisée de connexion YouTube dans « Mon espace ».
  if(!document.querySelector('script[data-pass50-youtube-oauth-ui]')){
    const script=document.createElement('script');
    script.src='./youtube-oauth-ui-v1.js?v=1.0';
    script.dataset.pass50YoutubeOauthUi='1.0';
    document.head.appendChild(script);
  }
})();
