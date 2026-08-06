(function(){
  'use strict';

  const RUNTIME='PASS50-INTELLIGENCE-SIGNALS-UI-V1.0';
  let installed=false;
  let currentData=null;
  let loading=false;

  function esc(value){return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'}[char]));}
  function attr(value){return esc(value);}
  function number(value){return Number(value||0).toLocaleString('fr-FR');}
  function date(value){const d=new Date(value);return Number.isNaN(d.getTime())?'—':d.toLocaleString('fr-FR');}
  function confidence(level){const cls=level==='élevée'?'high':level==='moyenne'?'medium':'low';return `<span class="p50is-confidence ${cls}">${esc(level||'faible')}</span>`;}
  function classification(value){return ({confirmed_buzz:'BUZZ CONFIRMÉ',emerging:'SIGNAL ÉMERGENT',decline:'RECUL FIABLE',building:'À CONSTRUIRE'})[value]||'À CONSTRUIRE';}

  function ensureStyles(){
    if(document.getElementById('p50IntelligenceSignalsStyles'))return;
    const style=document.createElement('style');style.id='p50IntelligenceSignalsStyles';
    style.textContent=`
      .p50is-shell{display:grid;gap:18px}.p50is-toolbar{display:flex;gap:9px;flex-wrap:wrap;align-items:center}.p50is-summary{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px}.p50is-kpi{border:1px solid var(--line);background:#0b100b;border-radius:14px;padding:12px}.p50is-kpi strong{display:block;font-size:24px;color:var(--lime)}.p50is-kpi span{color:var(--muted);font-size:11px}.p50is-section{display:grid;gap:10px}.p50is-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:12px}.p50is-card{border:1px solid var(--line);background:rgba(13,17,13,.92);border-radius:17px;padding:14px;display:grid;gap:10px}.p50is-card.confirmed_buzz{border-color:var(--lime);box-shadow:0 0 20px rgba(183,255,0,.09)}.p50is-card.emerging{border-color:#735f28}.p50is-card.decline{border-color:#703030}.p50is-head{display:flex;gap:10px;align-items:center}.p50is-avatar{width:46px;height:46px;border-radius:50%;overflow:hidden;background:#202820;display:grid;place-items:center;font-weight:900}.p50is-avatar img{width:100%;height:100%;object-fit:cover}.p50is-title{min-width:0;flex:1}.p50is-title strong{display:block}.p50is-title small{color:var(--muted)}.p50is-confidence{display:inline-flex;padding:3px 7px;border-radius:999px;text-transform:uppercase;font-size:9px;font-weight:900}.p50is-confidence.high{background:#183d23;color:#78e795}.p50is-confidence.medium{background:#403719;color:#f4d36b}.p50is-confidence.low{background:#3c2525;color:#ef9292}.p50is-scores{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.p50is-score{background:#111711;border-radius:11px;padding:8px;text-align:center;color:var(--muted);font-size:9px;text-transform:uppercase}.p50is-score b{display:block;color:#fff;font-size:20px}.p50is-platforms{display:flex;gap:5px;flex-wrap:wrap}.p50is-platforms span{border:1px solid #344034;border-radius:999px;padding:3px 7px;font-size:9px;color:#cad4c8}.p50is-evidence{display:grid;gap:6px}.p50is-evidence a,.p50is-evidence div{font-size:11px;color:#ced7cb;line-height:1.35}.p50is-evidence a{color:var(--lime)}.p50is-pending{display:grid;gap:8px}.p50is-pending-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;border:1px solid var(--line);border-radius:14px;padding:11px;background:#0b100b}.p50is-pending-row small{color:var(--muted)}.p50is-actions{display:flex;gap:6px}.p50is-empty{border:1px dashed var(--line);border-radius:14px;padding:18px;color:var(--muted)}.p50is-form{display:grid;grid-template-columns:1.2fr 2fr 1.2fr 2fr auto;gap:8px}.p50is-form input,.p50is-form select{min-width:0;padding:10px;border-radius:11px;border:1px solid var(--line);background:#0c100c;color:#fff}.p50is-note{border:1px solid #3c4429;background:#11170d;border-radius:13px;padding:11px;color:#dbe8c9;font-size:12px}.p50is-error{border:1px solid #703030;background:#1a0c0c;color:#ffaaaa;padding:14px;border-radius:14px}
      @media(max-width:850px){.p50is-summary{grid-template-columns:repeat(2,1fr)}.p50is-form{grid-template-columns:1fr}.p50is-pending-row{grid-template-columns:1fr}.p50is-actions{justify-content:flex-start}}
    `;
    document.head.appendChild(style);
  }

  function scrubLegacySignals(){
    document.querySelectorAll('[data-admin-tab="signals"]').forEach(node=>node.remove());
    document.querySelectorAll('[data-admin-tab="intelligence"]').forEach(node=>{
      if(node.matches('button')&&node.classList.contains('de-admin-home-card')){
        const strong=node.querySelector('strong');if(strong)strong.textContent='Intelligence & Signaux';
        const span=node.querySelector('span');if(span)span.textContent='Détecter, qualifier et valider les signaux dans un moteur unique.';
      }else if(node.matches('button'))node.textContent='Intelligence & Signaux';
    });
  }

  function profileOptions(){
    const list=Array.isArray(window.db?.profiles)?window.db.profiles.filter(profile=>profile&&profile.id&&profile.name):[];
    return [...list].sort((a,b)=>String(a.name).localeCompare(String(b.name),'fr',{sensitivity:'base'})).map(profile=>`<option value="${attr(profile.id)}">${esc(profile.name)}</option>`).join('');
  }

  function evidenceHtml(item){
    const signals=Array.isArray(item.recentSignals)?item.recentSignals:[];
    if(!signals.length)return '<div class="muted">Aucun événement récent rattaché.</div>';
    return signals.slice(0,3).map(signal=>{
      const source=`${signal.platforms?.join(' · ')||'Source'} · score ${number(signal.signalScore)} · ${date(signal.occurredAt)}`;
      return signal.evidenceUrl?`<a href="${attr(signal.evidenceUrl)}" target="_blank" rel="noopener">${esc(signal.title)}<br><small>${esc(source)}</small></a>`:`<div>${esc(signal.title)}<br><small>${esc(source)}</small></div>`;
    }).join('');
  }

  function profileCard(item){
    const initials=String(item.name||'?').split(/\s+/).slice(0,2).map(part=>part[0]||'').join('').toUpperCase();
    const photo=item.photo?`<img src="${attr(item.photo)}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">`:`<span>${esc(initials)}</span>`;
    return `<article class="p50is-card ${esc(item.classification)}">
      <div class="p50is-head"><div class="p50is-avatar">${photo}</div><div class="p50is-title"><strong>${esc(item.name)}</strong><small>${esc(classification(item.classification))} · priorité ${number(item.priorityScore)}</small></div>${confidence(item.combinedConfidence)}</div>
      <div class="p50is-scores"><div class="p50is-score"><b>${number(item.combinedBuzzIndex)}</b>Buzz fusionné</div><div class="p50is-score"><b>${number(item.combinedGrowthIndex)}</b>Growth fusionné</div><div class="p50is-score"><b>${number(item.signalScore)}</b>Signaux</div></div>
      <div class="p50is-platforms">${(item.signalPlatforms||[]).map(platform=>`<span>${esc(platform)}</span>`).join('')||'<span>Aucune plateforme</span>'}</div>
      <div class="p50is-evidence">${evidenceHtml(item)}</div>
      <small class="muted">${number(item.signalCount)} signal(aux) · ${number(item.validatedSignalCount)} validé(s) · Intelligence : ${item.fresh?'fraîche':'à rafraîchir'}</small>
    </article>`;
  }

  function section(title,items,empty){
    return `<section class="p50is-section"><div class="section-head"><div class="section-title">${esc(title)}</div><span class="muted">${items.length}</span></div>${items.length?`<div class="p50is-grid">${items.map(profileCard).join('')}</div>`:`<div class="p50is-empty">${esc(empty)}</div>`}</section>`;
  }

  function pendingRows(items){
    if(!items.length)return '<div class="p50is-empty">Aucun signal en attente de validation.</div>';
    return `<div class="p50is-pending">${items.map(signal=>`<div class="p50is-pending-row"><div><strong>${esc(signal.name)}</strong><div>${esc(signal.title)}</div><small>${esc((signal.platforms||[]).join(' · ')||'Source inconnue')} · score ${number(signal.signalScore)} · ${date(signal.occurredAt)}${signal.evidenceUrl?` · <a href="${attr(signal.evidenceUrl)}" target="_blank" rel="noopener">preuve</a>`:''}</small></div><div class="p50is-actions"><button class="btn small primary p50is-review" data-action="validate" data-signal-id="${signal.id}">Valider</button><button class="btn small danger p50is-review" data-action="reject" data-signal-id="${signal.id}">Rejeter</button></div></div>`).join('')}</div>`;
  }

  function draw(data){
    currentData=data;
    const content=document.getElementById('p50IntelligenceSignalsContent');if(!content)return;
    const summary=data.summary||{};
    content.innerHTML=`
      <div class="p50is-note"><strong>Moteur fusionné :</strong> les événements collectés alimentent Signaux ; leur fraîcheur, leurs plateformes et leur validation renforcent ensuite les scores Intelligence. Aucun rang public n’est forcé directement.</div>
      <div class="p50is-summary">
        <div class="p50is-kpi"><strong>${number(summary.priorityAlerts)}</strong><span>Alertes prioritaires</span></div>
        <div class="p50is-kpi"><strong>${number(summary.confirmedBuzz)}</strong><span>Buzz confirmés</span></div>
        <div class="p50is-kpi"><strong>${number(summary.signalsPending)}</strong><span>Signaux à valider</span></div>
        <div class="p50is-kpi"><strong>${number(summary.profilesWithSignals)}</strong><span>Profils avec signaux</span></div>
        <div class="p50is-kpi"><strong>${number(summary.signalsTotal)}</strong><span>Événements sur 7 jours</span></div>
      </div>
      ${section('Alertes prioritaires',data.priorityAlerts||[],'Aucune alerte suffisamment étayée pour l’instant.')}
      <section class="p50is-section"><div class="section-head"><div class="section-title">Signaux à valider</div><span class="muted">${number((data.signalsPending||[]).length)}</span></div>${pendingRows(data.signalsPending||[])}</section>
      ${section('Buzz confirmés',data.buzzDetected||[],'Aucun buzz confirmé par les deux moteurs.')}
      ${section('Tendances fortes',data.strongTrends||[],'Aucune tendance forte suffisamment récente.')}
      ${section('Profils en recul',data.declines||[],'Aucun recul fiable détecté.')}
      ${section('Données à construire',data.buildingSignals||[],'Toutes les fiches disposent de données suffisantes.')}
    `;
  }

  async function load(){
    if(loading)return;loading=true;
    const content=document.getElementById('p50IntelligenceSignalsContent');if(content)content.innerHTML='<div class="de-loading">Fusion et analyse des événements en cours…</div>';
    try{draw(await apiFetch('intelligence.php'));}
    catch(error){if(content)content.innerHTML=`<div class="p50is-error">${esc(error.message||'Moteur Intelligence & Signaux indisponible')}</div>`;}
    finally{loading=false;}
  }

  function renderUnified(pane){
    ensureStyles();
    pane.innerHTML=`<div class="p50is-shell"><div class="section-head"><div><div class="section-title">PASS50 INTELLIGENCE & SIGNAUX</div><div class="muted">Un seul moteur pour détecter, qualifier, confirmer et prioriser le buzz.</div></div><div class="p50is-toolbar"><button class="btn" id="p50isReload">Actualiser</button></div></div><div class="p50is-form"><select id="p50isProfile"><option value="">Influenceur…</option>${profileOptions()}</select><input id="p50isTitle" placeholder="Titre du signal manuel"><input id="p50isPlatforms" placeholder="Plateformes, séparées par des virgules"><input id="p50isUrl" placeholder="Lien de preuve (facultatif)"><button class="btn" id="p50isCreate">Ajouter</button></div><div id="p50IntelligenceSignalsContent"><div class="de-loading">Chargement…</div></div></div>`;
    load();
  }

  async function review(button){
    const signalId=Number(button.dataset.signalId||0),action=button.dataset.action;
    if(!signalId||!['validate','reject'].includes(action))return;
    button.disabled=true;
    try{draw(await apiFetch('intelligence.php',{method:'POST',body:{action,signalId}}));if(typeof toast==='function')toast(action==='validate'?'Signal validé':'Signal rejeté');}
    catch(error){if(typeof toast==='function')toast(error.message||'Mise à jour impossible');}
    finally{button.disabled=false;}
  }

  async function createManual(){
    const profileId=document.getElementById('p50isProfile')?.value||'';
    const title=document.getElementById('p50isTitle')?.value.trim()||'';
    const platforms=(document.getElementById('p50isPlatforms')?.value||'').split(',').map(value=>value.trim()).filter(Boolean);
    const evidenceUrl=document.getElementById('p50isUrl')?.value.trim()||'';
    if(!profileId||!title){if(typeof toast==='function')toast('Choisis un influenceur et saisis un titre');return;}
    try{draw(await apiFetch('intelligence.php',{method:'POST',body:{action:'create',profileId,title,platforms,evidenceUrl}}));if(typeof toast==='function')toast('Signal ajouté à la file de validation');}
    catch(error){if(typeof toast==='function')toast(error.message||'Ajout impossible');}
  }

  function install(){
    if(installed)return;
    const currentRenderAdmin=window.renderAdmin,currentRenderPane=window.renderAdminPane;
    if(typeof currentRenderAdmin!=='function'||typeof currentRenderPane!=='function')return;
    if(!String(currentRenderAdmin).includes('ADMIN_ITEMS'))return;
    installed=true;
    window.renderAdminPane=function(){
      if(window.ui?.adminTab==='signals')window.ui.adminTab='intelligence';
      if(window.ui?.adminTab==='intelligence')return renderUnified(document.getElementById('adminPane'));
      return currentRenderPane();
    };
    window.renderAdmin=function(){
      if(window.ui?.adminTab==='signals')window.ui.adminTab='intelligence';
      currentRenderAdmin();
      requestAnimationFrame(scrubLegacySignals);
    };
    scrubLegacySignals();
    if(document.getElementById('adminModal')?.classList.contains('show'))window.renderAdmin();
    window.PASS50_INTELLIGENCE_SIGNALS_UI=RUNTIME;
  }

  document.addEventListener('click',event=>{
    const legacy=event.target.closest('[data-admin-tab="signals"]');
    if(legacy){event.preventDefault();event.stopImmediatePropagation();if(window.ui)window.ui.adminTab='intelligence';window.renderAdmin?.();return;}
    const reviewButton=event.target.closest('.p50is-review');if(reviewButton){event.preventDefault();review(reviewButton);return;}
    if(event.target.closest('#p50isReload')){event.preventDefault();load();return;}
    if(event.target.closest('#p50isCreate')){event.preventDefault();createManual();}
  },true);

  const observer=new MutationObserver(scrubLegacySignals);
  observer.observe(document.documentElement,{childList:true,subtree:true});
  const timer=setInterval(()=>{install();if(installed)clearInterval(timer);},100);
  setTimeout(()=>clearInterval(timer),15000);
})();
