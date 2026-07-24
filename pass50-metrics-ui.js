/* PASS50 Metrics UI V1 */
(function(){
  'use strict';
  async function p50MetricsStatus(){
    return apiFetch('metrics-status.php');
  }
  async function p50MetricsCollect(profileId='',platform=''){
    return apiFetch('metrics-collect.php',{method:'POST',body:{profileId,platform,limit:20}});
  }
  window.PASS50Metrics={status:p50MetricsStatus,collect:p50MetricsCollect};

  const previousRenderAdmin=typeof renderAdmin==='function'?renderAdmin:null;
  if(previousRenderAdmin){
    renderAdmin=function(){
      previousRenderAdmin();
      const menu=document.querySelector('.admin-menu');
      if(menu&&!menu.querySelector('[data-admin-tab="metrics"]')){
        const btn=document.createElement('button');
        btn.className='btn '+(ui.adminTab==='metrics'?'primary':'');
        btn.dataset.adminTab='metrics';btn.textContent='Métriques';
        menu.appendChild(btn);
      }
      if(ui.adminTab==='metrics')p50RenderMetrics();
    };
  }

  const previousPane=typeof renderAdminPane==='function'?renderAdminPane:null;
  if(previousPane){
    renderAdminPane=function(){
      if(ui.adminTab==='metrics')return p50RenderMetrics();
      return previousPane();
    };
  }

  async function p50RenderMetrics(){
    const pane=document.querySelector('#adminPane');if(!pane)return;
    pane.innerHTML='<div class="tool-loading">Chargement des métriques…</div>';
    try{
      const data=await p50MetricsStatus();
      const rows=(data.accounts||[]).map(a=>`<tr>
        <td><strong>${safeAttr(a.profile_id)}</strong></td>
        <td>${safeAttr(a.platform)}</td>
        <td>${safeAttr(a.status)}</td>
        <td>${a.last_collected_at||'Jamais'}</td>
        <td>${a.last_error?safeAttr(a.last_error):'—'}</td>
        <td><button class="btn small p50-metric-one" data-profile="${safeAttr(a.profile_id)}" data-platform="${safeAttr(a.platform)}">Collecter</button></td>
      </tr>`).join('');
      pane.innerHTML=`<div class="section-title">Collecte automatique des métriques</div>
        <div class="note" style="margin-bottom:12px">
          YouTube : ${data.youtubeConfigured?'ACTIF':'CLÉ API MANQUANTE'} ·
          X : ${data.xConfigured?'ACTIF':'JETON MANQUANT'}
        </div>
        <div class="admin-toolbar">
          <button class="btn primary" id="p50CollectAllMetrics">Collecter maintenant</button>
          <button class="btn" id="p50RefreshMetrics">Actualiser</button>
        </div>
        <div style="overflow:auto"><table class="admin-table"><thead><tr>
          <th>Profil</th><th>Réseau</th><th>État</th><th>Dernière collecte</th><th>Erreur</th><th></th>
        </tr></thead><tbody>${rows||'<tr><td colspan="6">Aucun lien compatible.</td></tr>'}</tbody></table></div>`;
    }catch(err){pane.innerHTML=`<div class="tool-empty">${safeAttr(err.message||'Métriques indisponibles')}</div>`;}
  }

  document.addEventListener('click',async e=>{
    if(e.target.id==='p50RefreshMetrics')p50RenderMetrics();
    if(e.target.id==='p50CollectAllMetrics'){
      e.target.disabled=true;e.target.textContent='COLLECTE…';
      try{const r=await p50MetricsCollect();toast(`${r.publishedProfiles||0} profil(s) recalculé(s)`);await loadCloudState();render();p50RenderMetrics();}
      catch(err){toast(err.message||'Collecte impossible');}
      finally{e.target.disabled=false;e.target.textContent='Collecter maintenant';}
    }
    if(e.target.matches('.p50-metric-one')){
      const b=e.target;b.disabled=true;
      try{await p50MetricsCollect(b.dataset.profile,b.dataset.platform);toast('Métriques mises à jour');await loadCloudState();render();p50RenderMetrics();}
      catch(err){toast(err.message||'Collecte impossible');}
      finally{b.disabled=false;}
    }
  });
})();
