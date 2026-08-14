(function(){
  'use strict';

  const ADMIN_SCOPE='#adminModal,#adminPane,[data-admin],[class*="admin-"],[id*="Admin"]';
  const EXACT_HIDDEN=[
    /^\s*\d+\s*\/\s*\d+\s+profils?\s+(?:chargés?|traités?|lus?)\s*\.?\s*$/i,
    /^\s*\d+\s+profils?\s+(?:chargés?|traités?|lus?)\s*\.?\s*$/i,
    /^\s*(?:révision publique|public state revision)\s*[:#]?\s*\d+\s*\.?\s*$/i
  ];
  const FRIENDLY_REASONS=[
    [/\bcoverage_below_\d+\b/gi,'Données récentes insuffisantes'],
    [/\bconfidence_below_\d+\b/gi,'Données récentes insuffisantes'],
    [/\bno_recent_activity\b/gi,'Aucune activité récente mesurée'],
    [/\bno_measurable_content\b/gi,'Aucun contenu récent mesurable']
  ];

  function isAdmin(node){
    const element=node.nodeType===Node.ELEMENT_NODE?node:node.parentElement;
    return Boolean(element&&element.closest(ADMIN_SCOPE));
  }

  function cleanTextNode(node){
    if(!node.nodeValue||isAdmin(node))return;
    const original=node.nodeValue;
    if(EXACT_HIDDEN.some(rule=>rule.test(original))){
      node.nodeValue='';
      return;
    }
    let value=original;
    FRIENDLY_REASONS.forEach(([rule,replacement])=>{value=value.replace(rule,replacement);});
    value=value
      .replace(/(?:\s*[·|]\s*)?(?:app_state|MR-V\d+(?:\.\d+)?|collector\s*P1|collecteur\s*P1)\b/gi,'')
      .replace(/\s*·\s*·\s*/g,' · ')
      .replace(/\s{2,}/g,' ');
    if(value!==original)node.nodeValue=value;
  }

  function sanitize(root){
    if(!root||isAdmin(root))return;
    if(root.nodeType===Node.TEXT_NODE){
      cleanTextNode(root);
      return;
    }
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);
    while(walker.nextNode())cleanTextNode(walker.currentNode);
  }

  let queued=false;
  const observer=new MutationObserver(records=>{
    if(queued)return;
    queued=true;
    requestAnimationFrame(()=>{
      queued=false;
      records.forEach(record=>record.addedNodes.forEach(sanitize));
    });
  });

  function boot(){
    sanitize(document.body);
    observer.observe(document.body,{childList:true,subtree:true});
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();
