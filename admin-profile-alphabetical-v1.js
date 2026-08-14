// Déclenchement du déploiement ciblé de l’onglet unique — 2026-08-06T18:51+02:00
(function(){
  'use strict';

  const VERSION='PASS50-ADMIN-PROFILE-ALPHABETICAL-V1.5';
  const collator=new Intl.Collator('fr',{sensitivity:'base',ignorePunctuation:true,numeric:true});
  let scheduled=false;
  let linksRendererInstalled=false;
  let redirectingSignalsTab=false;
  let linksRenderToken=0;

  function label(value){
    return String(value||'').replace(/^[#\s\d.–—-]+/,'').trim();
  }

  function compare(a,b){
    return collator.compare(label(a),label(b));
  }

  function alphabeticalProfiles(){
    try{return [...(db.profiles||[])].sort((a,b)=>compare(a?.name,b?.name));}
    catch{return [];}
  }

  function activeAdminTab(){
    return document.querySelector('#adminModal .admin-menu [data-admin-tab].primary')?.dataset.adminTab||'';
  }

  function currentAdminTab(){
    try{return String(ui?.adminTab||'');}
    catch{return '';}
  }

  function openUnifiedIntelligence(){
    if(redirectingSignalsTab)return;
    redirectingSignalsTab=true;
    try{
      if(typeof ui==='object'&&ui)ui.adminTab='intelligence';
      if(typeof window.renderAdmin==='function')window.renderAdmin();
      else if(typeof renderAdmin==='function')renderAdmin();
    }catch(error){
      console.warn('PASS50 redirection Intelligence & Signaux',error);
    }finally{
      setTimeout(()=>{redirectingSignalsTab=false;unifyIntelligenceSignalsTabs();},0);
    }
  }

  function unifyIntelligenceSignalsTabs(){
    const signalTabs=[...document.querySelectorAll('[data-admin-tab="signals"]')];
    signalTabs.forEach(node=>node.remove());

    document.querySelectorAll('[data-admin-tab="intelligence"]').forEach(node=>{
      if(node.classList.contains('de-admin-home-card')){
        const title=node.querySelector('strong');
        const description=node.querySelector('span');
        if(title)title.textContent='Intelligence & Signaux';
        if(description)description.textContent='Détecter, qualifier et valider les signaux dans un moteur unique.';
      }else{
        node.textContent='Intelligence & Signaux';
        node.setAttribute('aria-label','Intelligence & Signaux');
      }
    });

    if(currentAdminTab()==='signals')openUnifiedIntelligence();
  }

  function reorder(parent,nodes,getLabel){
    if(!parent||nodes.length<2)return;
    const current=[...nodes];
    const sorted=[...current].sort((a,b)=>compare(getLabel(a),getLabel(b)));
    if(sorted.every((node,index)=>node===current[index]))return;
    const fragment=document.createDocumentFragment();
    sorted.forEach(node=>fragment.appendChild(node));
    parent.appendChild(fragment);
  }

  function sortSelect(select){
    if(!select||select.options.length<2)return;
    const selected=select.value;
    const options=[...select.options];
    const placeholders=options.filter(option=>option.disabled||option.value==='');
    const choices=options.filter(option=>!placeholders.includes(option)).sort((a,b)=>compare(a.textContent,b.textContent));
    const sorted=[...placeholders,...choices];
    if(sorted.every((option,index)=>option===options[index]))return;
    const fragment=document.createDocumentFragment();
    sorted.forEach(option=>fragment.appendChild(option));
    select.appendChild(fragment);
    select.value=selected;
  }

  function sortNamedTable(table){
    const headers=[...table.querySelectorAll('thead th')].map(th=>label(th.textContent).toLocaleLowerCase('fr'));
    const column=headers.findIndex(text=>text==='nom'||text.includes('influenceur')||text.includes('profil'));
    if(column<0)return;
    const body=table.tBodies[0];
    if(!body)return;
    reorder(body,[...body.rows],row=>row.cells[column]?.textContent||'');
  }

  function sortSignals(pane){
    const signals=[...pane.querySelectorAll(':scope > .signal')];
    reorder(pane,signals,node=>node.querySelector('strong')?.textContent||'');
  }

  function installOfficialLinksRenderer(){
    if(linksRendererInstalled||typeof p50v9RenderLinks!=='function'||typeof p50v9LinkCard!=='function')return;
    p50v9RenderLinks=function(){
      const pane=document.querySelector('#adminPane');
      if(!pane)return;
      const profiles=alphabeticalProfiles();
      const token=++linksRenderToken;
      pane.innerHTML=`<div class="media-hint"><strong>Objectif :</strong> seuls les profils officiels directs sont visibles au public. Les liens de recherche sont masqués.</div><div class="admin-toolbar"><button class="btn primary" id="checkTop10Links">Vérifier les liens du Top 10</button></div><div id="linksCards"></div>`;
      const cards=document.getElementById('linksCards');
      if(!cards)return;
      cards.dataset.searchExpanded='1';
      let index=0;
      const appendChunk=()=>{
        if(token!==linksRenderToken||!cards.isConnected||currentAdminTab()!=='links')return;
        const next=profiles.slice(index,index+12);
        if(next.length)cards.insertAdjacentHTML('beforeend',next.map(p50v9LinkCard).join(''));
        index+=next.length;
        if(index<profiles.length)requestAnimationFrame(appendChunk);
      };
      appendChunk();
    };
    linksRendererInstalled=true;
  }

  function applyAlphabeticalOrder(){
    unifyIntelligenceSignalsTabs();
    installOfficialLinksRenderer();
    const pane=document.querySelector('#adminPane');
    if(!pane)return;
    const tab=activeAdminTab();

    // Le classement public et son aperçu administratif restent ordonnés par score.
    if(tab==='ranking')return;

    pane.querySelectorAll('select[name="profileId"],#newsProfile,select[data-profile-select]').forEach(sortSelect);
    pane.querySelectorAll('table.admin-table').forEach(sortNamedTable);

    const links=pane.querySelector('#linksCards');
    if(links)reorder(links,[...links.querySelectorAll(':scope > .link-card')],node=>node.querySelector('.link-card-head strong')?.textContent||'');

    pane.querySelectorAll('.media-grid').forEach(grid=>{
      reorder(grid,[...grid.children],node=>node.querySelector('h4')?.textContent||node.textContent||'');
    });

    if(tab==='live')sortSignals(pane);
  }

  function schedule(){
    if(scheduled)return;
    scheduled=true;
    requestAnimationFrame(()=>{
      scheduled=false;
      applyAlphabeticalOrder();
    });
  }

  function boot(){
    installOfficialLinksRenderer();
    const root=document.querySelector('#adminModal')||document.body;
    new MutationObserver(schedule).observe(root,{childList:true,subtree:true});
    document.addEventListener('click',event=>{
      const obsoleteSignalTab=event.target.closest('[data-admin-tab="signals"]');
      if(obsoleteSignalTab){
        event.preventDefault();
        event.stopImmediatePropagation();
        openUnifiedIntelligence();
        return;
      }
      if(event.target.closest('[data-admin-tab]'))setTimeout(schedule,0);
    },true);
    schedule();
    setTimeout(schedule,250);
    setTimeout(schedule,1200);
  }

  window.PASS50_ADMIN_PROFILE_ALPHABETICAL={version:VERSION,apply:applyAlphabeticalOrder,compare,unifyIntelligenceSignalsTabs};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();
