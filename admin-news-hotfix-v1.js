(function(){
  'use strict';

  const VERSION='PASS50-ADMIN-NEWS-HOTFIX-V1.2';

  async function runNewsSearch(){
    const select=document.getElementById('newsProfile');
    const daysSelect=document.getElementById('newsDays');
    const box=document.getElementById('newsResults');
    if(!select||!box)return;
    const id=select.value;
    const p=typeof profile==='function'?profile(id):null;
    if(!p){box.innerHTML='<div class="tool-empty">Fiche introuvable.</div>';return;}
    const days=Math.max(1,Number(daysSelect?.value||30));
    box.innerHTML='<div class="tool-loading">Recherche des comptes officiels, vidéos et médias en cours…</div>';
    try{
      const data=await apiFetch('news-discover-v2.php',{method:'POST',body:{profileId:p.id,name:p.name,handle:p.handle||'',days}});
      if(typeof PASS50_V9==='object'){
        PASS50_V9.newsProfileId=id;
        PASS50_V9.newsDays=days;
        PASS50_V9.news=data.results||data.articles||[];
      }
      const items=data.results||data.articles||[];
      const diagnostics=Array.isArray(data.diagnostics)?data.diagnostics:[];
      const diagnosticHtml=diagnostics.length?`<details class="media-hint"><summary><strong>Diagnostic des sources</strong></summary>${diagnostics.map(d=>`<div>${safeAttr(d.source||'Source')} · HTTP ${Number(d.status||0)} · ${Number(d.count||0)} résultat(s)${d.error?` · ${safeAttr(d.error)}`:''}</div>`).join('')}</details>`:'';
      const warning=data.warning?`<div class="media-hint"><strong>Attention :</strong> ${safeAttr(data.warning)}</div>`:'';
      const source=`<div class="muted" style="margin:8px 0">${safeAttr(data.message||'')} · Sources : ${safeAttr(data.source||'')}</div>`;
      box.innerHTML=warning+source+diagnosticHtml+(items.length?items.map((a,i)=>`<article class="news-card">${a.image?`<img src="${safeAttr(a.image)}" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:'<div class="trigger-thumb">${a.kind==='video'?'▶':'📰'}</div>'}<div><h4>${safeAttr(a.title||'Résultat')}</h4><div class="tool-meta">${safeAttr(a.platform||a.domain||'Web')} · ${safeAttr(a.date||'Date inconnue')} · confiance ${Number(a.confidence||0)} %</div><div class="tool-actions"><a class="btn small" href="${safeAttr(a.url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small primary use-news" data-index="${i}">Utiliser comme déclencheur</button></div></div></article>`).join(''):'<div class="tool-empty">Aucun résultat exploitable. Ouvre le diagnostic ci-dessus pour voir quelle source a répondu ou échoué.</div>');
    }catch(err){
      box.innerHTML=`<div class="tool-empty"><strong>Recherche interrompue.</strong><br>${safeAttr(err?.message||'Erreur serveur')}</div>`;
    }
  }

  function interceptNewsSearch(event){
    const button=event.target.closest('#searchNewsBtn');
    if(!button)return;
    event.preventDefault();
    event.stopImmediatePropagation();
    runNewsSearch();
  }

  document.addEventListener('click',interceptNewsSearch,true);
  window.PASS50_ADMIN_NEWS_HOTFIX={version:VERSION,run:runNewsSearch};
})();
