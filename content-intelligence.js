'use strict';

(() => {
  const P50CI={period:'24h',data:null,loading:false,news:new Map(),lastRefresh:0,profileHookInstalled:false};
  const PERIODS={"2h":"2 H","24h":"24 H","48h":"48 H","7d":"7 J","15d":"15 J"};
  const NEWS_TTL=60*1000;
  const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const attr=value=>esc(value).replace(/`/g,'&#96;');
  const num=value=>{
    const n=Number(value||0);if(!Number.isFinite(n))return '0';
    return n>=1e6?`${(n/1e6).toFixed(n>=1e7?0:1)} M`:n>=1e3?`${(n/1e3).toFixed(n>=1e4?0:1)} k`:Math.round(n).toLocaleString('fr-FR');
  };
  const relative=value=>{
    if(!value)return 'Date inconnue';const ts=Date.parse(value);if(!Number.isFinite(ts))return 'Date inconnue';
    const seconds=Math.max(0,(Date.now()-ts)/1000);
    if(seconds<3600)return `il y a ${Math.max(1,Math.round(seconds/60))} min`;
    if(seconds<86400)return `il y a ${Math.round(seconds/3600)} h`;
    if(seconds<7*86400)return `il y a ${Math.round(seconds/86400)} j`;
    return new Date(ts).toLocaleDateString('fr-FR',{day:'2-digit',month:'short'});
  };
  const profileFor=id=>typeof window.profile==='function'?window.profile(id):null;
  const fallbackCover=id=>{const p=profileFor(id);return p&&typeof window.publicPhoto==='function'?(window.publicPhoto(p)||''):''};
  const badgeClass=badge=>String(badge||'RISING').toLowerCase().replace(/[^a-z]/g,'');
  const movement=item=>item.rankDelta>0?`▲ ${item.rankDelta}`:item.rankDelta<0?`▼ ${Math.abs(item.rankDelta)}`:item.previousRank==null?'NOUVEAU':'—';
  const movementClass=item=>item.rankDelta>0?'up':item.rankDelta<0?'down':'flat';
  const isFacebook=item=>String(item?.platform||'').toLowerCase()==='facebook';

  function injectStyles(){
    if(document.getElementById('p50ContentIntelligenceStyles'))return;
    const style=document.createElement('style');style.id='p50ContentIntelligenceStyles';style.textContent=`
      .p50ci-periods{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}.p50ci-period{border:1px solid var(--line);background:#0a0d0a;color:#cfd7cc;border-radius:999px;padding:7px 10px;font-size:10px;font-weight:950}.p50ci-period.active{background:var(--lime);border-color:var(--lime);color:#050705}
      .p50ci-card{padding:0!important;min-height:250px!important;color:#fff}.p50ci-card-cover{height:142px;position:relative;overflow:hidden;background:radial-gradient(circle at 30% 20%,rgba(183,255,0,.2),transparent 50%),#111611}.p50ci-card-cover img{width:100%;height:100%;object-fit:cover;display:block}.p50ci-card-cover:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 42%,rgba(0,0,0,.86))}.p50ci-rank{position:absolute;left:10px;top:9px;z-index:2;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:#050705;border:2px solid var(--lime);color:var(--lime);font-weight:1000;font-size:17px}.p50ci-badge{position:absolute;right:9px;top:9px;z-index:2;border-radius:999px;padding:6px 9px;font-size:10px;font-weight:1000;border:1px solid var(--orange);background:rgba(7,8,7,.86);color:#ffc065}.p50ci-badge.viral{border-color:var(--purple);color:#ddcaff}.p50ci-badge.new{border-color:var(--cyan);color:#9ff5ff}.p50ci-card-body{padding:12px;display:grid;gap:8px;position:relative;z-index:2}.p50ci-name{font-size:12px;color:var(--lime);font-weight:950}.p50ci-title{font-size:14px;line-height:1.35;font-weight:950;min-height:35px}.p50ci-metrics{display:flex;justify-content:space-between;gap:8px;color:#c6cec3;font-size:10px;font-weight:850}.p50ci-empty{grid-column:1/-1;border:1px dashed var(--line);border-radius:16px;padding:28px;text-align:center;color:var(--muted)}.p50ci-card-action{display:flex;justify-content:flex-end}.p50ci-facebook-note{font-size:10px;line-height:1.35;color:#9ed7ff;border-left:2px solid #4ca7ff;padding-left:8px}
      .p50ci-news{margin-top:16px;padding:15px;border:1px solid var(--line);border-radius:18px;background:#0c100c}.p50ci-news-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.p50ci-news-title{font-size:18px;font-weight:1000}.p50ci-news-list{display:grid;gap:9px}.p50ci-news-card{display:grid;grid-template-columns:76px minmax(0,1fr) auto;gap:11px;align-items:center;padding:9px;border:1px solid var(--line);border-radius:14px;background:#101510}.p50ci-news-card.facebook{border-color:rgba(76,167,255,.35)}.p50ci-news-card.is-extra{display:none}.p50ci-news.expanded .p50ci-news-card.is-extra{display:grid}.p50ci-news-thumb{width:76px;height:62px;border-radius:11px;overflow:hidden;display:grid;place-items:center;background:#171d17;font-size:24px}.p50ci-news-thumb img{width:100%;height:100%;object-fit:cover}.p50ci-news-card h4{margin:0 0 4px;font-size:13px;line-height:1.35}.p50ci-news-meta{font-size:10px;color:var(--muted);font-weight:850}.p50ci-official{color:var(--lime)}
      @media(max-width:680px){.p50ci-periods{justify-content:flex-start}.p50ci-card-cover{height:150px}.p50ci-news-card{grid-template-columns:62px minmax(0,1fr)}.p50ci-news-thumb{width:62px;height:58px}.p50ci-news-card>a{grid-column:1/-1;width:100%;text-align:center}}
    `;document.head.appendChild(style);
  }

  async function fetchFeed(profileId=''){
    const query=new URLSearchParams({period:P50CI.period});if(profileId)query.set('profileId',profileId);
    query.set('_',String(Date.now()));
    const response=await fetch(`./api/content-feed.php?${query}`,{headers:{Accept:'application/json'},cache:'no-store'});
    const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Flux de contenus indisponible.');return data;
  }

  function ensurePeriodControls(){
    const section=document.getElementById('tendance'),head=section?.querySelector('.section-head');if(!head)return;
    let controls=document.getElementById('p50ciPeriods');
    if(!controls){controls=document.createElement('div');controls.id='p50ciPeriods';controls.className='p50ci-periods';head.querySelector('.muted')?.replaceWith(controls);}
    controls.innerHTML=Object.entries(PERIODS).map(([key,label])=>`<button class="p50ci-period ${P50CI.period===key?'active':''}" data-p50ci-period="${key}">${label}</button>`).join('');
  }

  function renderTrends(){
    ensurePeriodControls();const grid=document.getElementById('contentGrid');if(!grid)return;
    if(P50CI.loading&&!P50CI.data){grid.innerHTML='<div class="p50ci-empty">Calcul du Top 5 en cours…</div>';return;}
    const data=P50CI.data;if(!data?.ready){grid.innerHTML='<div class="p50ci-empty">Le premier calcul automatique est en préparation.</div>';return;}
    const items=data.trends||[];
    if(!items.length){grid.innerHTML='<div class="p50ci-empty">Aucun contenu suffisamment récent ne progresse sur cette période.</div>';return;}
    grid.innerHTML=items.map(item=>{
      const cover=item.thumbnailUrl||fallbackCover(item.profileId),title=item.title||`Contenu récent de ${item.name}`,facebook=isFacebook(item);
      return `<article class="content-card p50ci-card" data-content="${item.contentId}">
        <div class="p50ci-card-cover">${cover?`<img src="${attr(cover)}" alt="${attr(title)}" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">`:'<div class="play">▶</div>'}<span class="p50ci-rank">${item.rank}</span><span class="p50ci-badge ${badgeClass(item.badge)}">${esc(item.badge)}</span></div>
        <div class="p50ci-card-body"><div class="p50ci-name">${esc(item.name)} · ${esc(item.platform)}</div><div class="p50ci-title">${esc(title)}</div>
        ${facebook?'<div class="p50ci-facebook-note">Le contenu essentiel est affiché ici. Facebook peut demander une connexion pour voir la publication originale.</div>':''}
        <div class="p50ci-metrics"><span>+${num(item.viewDelta)} vues</span><span>+${num(item.interactionDelta)} interactions</span></div>
        <div class="p50ci-metrics"><span>Score ${Math.round(item.score)}/100</span><span class="${movementClass(item)}">${movement(item)}</span></div>
        <div class="p50ci-card-action"><a class="btn small" href="${attr(item.url)}" target="_blank" rel="noopener">${facebook?'Ouvrir Facebook':'Voir le contenu'} ↗</a></div></div></article>`;
    }).join('');
  }

  async function refreshTrends(force=false){
    if(P50CI.loading)return;if(!force&&Date.now()-P50CI.lastRefresh<30000)return;
    P50CI.loading=true;renderTrends();
    try{P50CI.data=await fetchFeed();P50CI.lastRefresh=Date.now();}
    catch(error){console.warn('PASS50 Content Intelligence',error);}
    finally{P50CI.loading=false;renderTrends();}
  }

  function newsCard(item,index){
    const cover=item.thumbnailUrl||fallbackCover(item.profileId),extra=index>=3,facebook=isFacebook(item);
    return `<article class="p50ci-news-card ${facebook?'facebook':''} ${extra?'is-extra':''}"><div class="p50ci-news-thumb">${cover?`<img src="${attr(cover)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">`:'📰'}</div><div><h4>${esc(item.title||'Actualité récente')}</h4><div class="p50ci-news-meta"><span class="${item.official?'p50ci-official':''}">${item.official?'SOURCE OFFICIELLE':esc(item.sourceType)}</span> · ${esc(item.platform)} · ${esc(relative(item.publishedAt))}${item.trendBadge?` · ${esc(item.trendBadge)}`:''}</div>${facebook?'<div class="p50ci-facebook-note" style="margin-top:6px">Aperçu lisible dans Pass50.</div>':''}</div><a class="btn small" href="${attr(item.url)}" target="_blank" rel="noopener">${facebook?'Ouvrir Facebook':'Voir'} ↗</a></article>`;
  }

  async function renderProfileNews(profileId){
    const body=document.getElementById('profileBody');if(!body)return;
    body.querySelector('#p50ciProfileNews')?.remove();
    const shell=document.createElement('section');shell.id='p50ciProfileNews';shell.className='p50ci-news';shell.innerHTML='<div class="p50ci-news-head"><div class="p50ci-news-title">📰 Actualité récente</div></div><div class="p50ci-empty">Chargement des publications officielles…</div>';body.appendChild(shell);
    try{
      const key=`${profileId}:${P50CI.period}`,cached=P50CI.news.get(key);let data;
      if(cached&&Date.now()-cached.fetchedAt<NEWS_TTL)data=cached.data;
      else{data=await fetchFeed(profileId);P50CI.news.set(key,{data,fetchedAt:Date.now()});}
      if(!document.body.contains(shell))return;const items=data.news||[];
      shell.innerHTML=`<div class="p50ci-news-head"><div><div class="p50ci-news-title">📰 Actualité récente</div><div class="muted" style="font-size:11px">Publications officielles des 72 dernières heures · informations externes validées</div></div>${items.length>3?'<button class="btn small" data-p50ci-expand>Voir toute l’actualité</button>':''}</div><div class="p50ci-news-list">${items.length?items.map(newsCard).join(''):'<div class="p50ci-empty">Aucune actualité récente de moins de 72 heures pour cette fiche.</div>'}</div>`;
    }catch(error){shell.innerHTML='<div class="p50ci-news-head"><div class="p50ci-news-title">📰 Actualité récente</div></div><div class="p50ci-empty">Actualité momentanément indisponible.</div>';}
  }

  function installProfileHook(){
    if(P50CI.profileHookInstalled)return;
    P50CI.profileHookInstalled=true;
    document.addEventListener('p50:profile-opened',event=>{
      const id=event?.detail?.profileId;if(id)setTimeout(()=>renderProfileNews(id),0);
    });
    if(typeof window.openProfile!=='function'||window.openProfile.__p50ci||window.openProfile.__p50NavigationV3)return;
    const original=window.openProfile;
    const wrapped=function(id){const result=original.apply(this,arguments);setTimeout(()=>renderProfileNews(id),0);return result;};
    wrapped.__p50ci=true;window.openProfile=wrapped;
  }

  function installRenderHook(){
    if(typeof window.renderContent!=='function'||window.renderContent.__p50ci)return;
    const original=window.renderContent;
    const wrapped=function(){if(P50CI.data){renderTrends();return;}const result=original.apply(this,arguments);ensurePeriodControls();refreshTrends();return result;};
    wrapped.__p50ci=true;window.renderContent=wrapped;
  }

  function installAdminHooks(){
    if(typeof window.p50v9SearchNews==='function')window.p50v9SearchNews=async function(){
      const id=document.querySelector('#newsProfile')?.value,p=profileFor(id),days=Number(document.querySelector('#newsDays')?.value||15),box=document.querySelector('#newsResults');
      if(!p||!box)return;P50_V9.newsProfileId=id;P50_V9.newsDays=days;box.innerHTML='<div class="tool-loading">Recherche sur les comptes officiels et les médias…</div>';
      try{
        const data=await apiFetch('news-discover.php',{method:'POST',body:{profileId:p.id,name:p.name,handle:p.handle,days}});P50_V9.news=data.results||data.articles||[];
        box.innerHTML=P50_V9.news.length?P50_V9.news.map((a,i)=>`<article class="news-card">${a.image?`<img src="${attr(a.image)}" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:'<div class="trigger-thumb">📰</div>'}<div><h4>${esc(a.title||'Contenu sans titre')}</h4><div class="tool-meta">${esc(a.platform||a.domain||'Web')} · ${esc(a.date||'')} · ${esc(a.source||'')}</div><div class="tool-actions"><a class="btn small" href="${attr(a.url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small primary use-news" data-index="${i}">Valider dans la fiche</button></div></div></article>`).join(''):'<div class="tool-empty">Aucun résultat récent suffisamment précis.</div>';
      }catch(error){box.innerHTML=`<div class="tool-empty">${esc(error.message||'Recherche indisponible')}</div>`;}
    };
    if(typeof window.p50v9UseNews==='function')window.p50v9UseNews=async function(index){
      const item=P50_V9.news[index],id=P50_V9.newsProfileId,p=profileFor(id);if(!item||!p)return;
      if(!confirm(`Confirmer que ce lien concerne bien ${p.name} et qu’il peut apparaître dans sa rubrique Actualité ?`))return;
      try{
        await apiFetch('news-validate.php',{method:'POST',body:{confirmed:true,profileId:id,title:item.title,url:item.url,image:item.image||'',publishedAt:item.date||'',source:item.source||item.domain||''}});
        const patch={type:item.kind==='video'?'Vidéo':'Article',title:item.title||`Actualité concernant ${p.name}`,platforms:[item.platform||'Web'],metric:'Contenu validé',publishedLabel:item.date||'Récent',reason:'Lien original confirmé dans Administration → Actualité.',url:item.url,icon:item.kind==='video'?'▶':'📰',confidence:'forte',originalLinkValidated:true,coverCandidateUrl:item.image||'',coverUrl:'',coverStatus:item.image?'pending':'missing',coverSource:item.source||item.domain||'',coverNote:'Source originale confirmée.'};
        let ev=typeof primaryEvent==='function'?primaryEvent(id):null;if(ev)Object.assign(ev,patch);else db.events.push({id:`news_${id}_${Date.now()}`,profileId:id,...patch});
        if(typeof p50v20SyncTrendContent==='function')p50v20SyncTrendContent(id,ev||patch,item.platform||'Web');save();render();P50CI.news.delete(`${id}:${P50CI.period}`);toast('Actualité validée et publiée dans la fiche');
      }catch(error){toast(error.message||'Validation impossible');}
    };
    if(typeof window.p50v9RenderNews==='function'&&!window.p50v9RenderNews.__p50ci){
      const original=window.p50v9RenderNews;const wrapped=function(){const result=original.apply(this,arguments);const hint=document.querySelector('#adminPane .media-hint');if(hint)hint.innerHTML='<strong>Actualité hybride :</strong> les publications des comptes officiels sont ajoutées automatiquement. Les articles et reprises externes exigent toujours votre validation.';return result;};wrapped.__p50ci=true;window.p50v9RenderNews=wrapped;
    }
  }

  function bind(){
    document.addEventListener('click',event=>{
      const period=event.target.closest('[data-p50ci-period]');if(period){P50CI.period=period.dataset.p50ciPeriod;P50CI.data=null;P50CI.news.clear();ensurePeriodControls();refreshTrends(true);return;}
      const expand=event.target.closest('[data-p50ci-expand]');if(expand){const shell=expand.closest('.p50ci-news');shell?.classList.toggle('expanded');expand.textContent=shell?.classList.contains('expanded')?'Réduire':'Voir toute l’actualité';}
    });
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'){P50CI.news.clear();refreshTrends(true);}});
  }

  function init(){injectStyles();installRenderHook();installProfileHook();installAdminHooks();bind();ensurePeriodControls();refreshTrends(true);setInterval(()=>refreshTrends(true),60*1000);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
