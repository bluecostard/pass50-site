(function(){
  'use strict';

  const VERSION='PASS50-ADMIN-NEWS-HOTFIX-V1.0';

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
      const data=await apiFetch('news-discover.php',{method:'POST',body:{profileId:p.id,name:p.name,handle:p.handle||'',days}});
      if(typeof PASS50_V9==='object'){
        PASS50_V9.newsProfileId=id;
        PASS50_V9.newsDays=days;
        PASS50_V9.news=data.results||data.articles||[];
      }
      const items=data.results||data.articles||[];
      const warning=data.warning?`<div class="media-hint"><strong>Diagnostic :</strong> ${safeAttr(data.warning)}</div>`:'';
      const source=`<div class="muted" style="margin:8px 0">${safeAttr(data.message||'')} · Sources : ${safeAttr(data.source||'')}</div>`;
      box.innerHTML=warning+source+(items.length?items.map((a,i)=>`<article class="news-card">${a.image?`<img src="${safeAttr(a.image)}" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:'<div class="trigger-thumb">${a.kind==='video'?'▶':'📰'}</div>'}<div><h4>${safeAttr(a.title||'Résultat')}</h4><div class="tool-meta">${safeAttr(a.platform||a.domain||'Web')} · ${safeAttr(a.date||'Date inconnue')} · confiance ${Number(a.confidence||0)} %</div><div class="tool-actions"><a class="btn small" href="${safeAttr(a.url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small primary use-news" data-index="${i}">Utiliser comme déclencheur</button></div></div></article>`).join(''):'<div class="tool-empty">Aucun résultat exploitable. Le diagnostic ci-dessus indique les sources indisponibles.</div>');
    }catch(err){
      box.innerHTML=`<div class="tool-empty"><strong>Recherche interrompue.</strong><br>${safeAttr(err?.message||'Erreur serveur')}</div>`;
    }
  }

  window.p50v9SearchNews=runNewsSearch;
  window.PASS50_ADMIN_NEWS_HOTFIX={version:VERSION,run:runNewsSearch};
})();
