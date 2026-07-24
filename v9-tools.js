'use strict';

const PASS50_V9={photoCandidates:[],photoProfileId:null,preview:null,previewEventId:null,news:[],newsProfileId:null,newsDays:15,socialHydrated:new Set(),socialHydrating:new Set()};

function p50v9IsGenericLink(url=''){
  try{
    const u=new URL(url),path=u.pathname.replace(/^\/+|\/+$/g,'').toLowerCase();
    return !path||/(^|\/)(search|results|explore\/search)(\/|$)/i.test(path)||/^(accounts\/)?login(\/|$)/i.test(path)||/^(home|feed|watch)(\/|$)/i.test(path)||u.searchParams.has('search_query')||u.searchParams.has('q');
  }catch{return true}
}
function p50v9IsDirectPlatformLink(platform,url=''){
  if(!url||p50v9IsGenericLink(url))return false;
  try{
    const u=new URL(url),h=u.hostname.toLowerCase().replace(/^www\./,''),path=u.pathname.replace(/\/+$/,'')||'/',segments=path.split('/').filter(Boolean),first=(segments[0]||'').toLowerCase();
    const reservedInstagram=new Set(['accounts','about','developer','developers','direct','directory','explore','legal','privacy','reel','reels','stories','terms']);
    const reservedFacebook=new Set(['login','home','watch','groups','marketplace','gaming','events','reel','reels','share','sharer','photo','photos','videos','help','privacy','settings']);
    const reservedX=new Set(['home','explore','notifications','messages','i','search','settings','compose']);
    const rules={
      Instagram:()=>h.endsWith('instagram.com')&&segments.length===1&&!reservedInstagram.has(first)&&/^[A-Za-z0-9._-]+$/.test(segments[0]),
      TikTok:()=>h.endsWith('tiktok.com')&&segments.length===1&&/^@[A-Za-z0-9._-]+$/.test(segments[0]),
      YouTube:()=>h.endsWith('youtube.com')&&(/^\/@[A-Za-z0-9._-]+$/.test(path)||/^\/(?:channel|c|user)\/[A-Za-z0-9._-]+$/i.test(path)),
      Facebook:()=>h.endsWith('facebook.com')&&((segments.length===1&&!reservedFacebook.has(first)&&/^[A-Za-z0-9._-]+$/.test(segments[0]))||(first==='profile.php'&&/^\d+$/.test(u.searchParams.get('id')||''))||(segments.length===3&&first==='pages'&&/^\d+$/.test(segments[2]))),
      LinkedIn:()=>h.endsWith('linkedin.com')&&/^\/(?:in|company)\/[A-Za-z0-9._-]+$/i.test(path),
      Snapchat:()=>h.endsWith('snapchat.com')&&/^\/add\/[A-Za-z0-9._-]+$/i.test(path),
      X:()=> (h==='x.com'||h==='twitter.com')&&segments.length===1&&!reservedX.has(first)&&/^[A-Za-z0-9_]+$/.test(segments[0])
    };
    return rules[platform]?rules[platform]():false;
  }catch{return false}
}
function p50v9OfficialLinks(p){return Object.entries(p?.links||{}).filter(([platform,url])=>p50v9IsDirectPlatformLink(platform,url));}
function p50v9ExactContentLink(url=''){return /^https?:\/\//i.test(url)&&!p50v9IsGenericLink(url)}

/* PASS50 V20 — vignette automatique des contenus validés */
function p50v20DetectedCover(ev){
  if(!ev)return '';
  const url=String(ev.coverUrl||ev.coverCandidateUrl||'').trim();
  if(!url)return '';
  if(ev.coverStatus==='validated')return url;
  // Le propriétaire a confirmé le lien original : une vignette extraite de ce même lien
  // peut être affichée sans une seconde validation redondante.
  if(ev.originalLinkValidated&&ev.coverCandidateUrl)return url;
  return '';
}
function p50v20TrendCover(p,ev){return p50v20DetectedCover(ev)||(typeof publicPhoto==='function'?publicPhoto(p):'')||''}
function p50v20EventThumbHtml(p,ev){
  const detected=p50v20DetectedCover(ev),cover=p50v20TrendCover(p,ev),fallback=Boolean(cover&&!detected);
  return `<div class="trigger-thumb ${cover?'has-cover':''}"><span>${ev?.icon||'⚡'}</span>${cover?`<img src="${safeAttr(cover)}" alt="Visuel ${safeAttr(p?.name||'contenu')}" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:''}${fallback?'<small class="trigger-cover-fallback">VISUEL DU PROFIL</small>':''}</div>`;
}
function p50v20SyncTrendContent(profileId,ev,platform='Réseau social'){
  if(!profileId||!ev)return;
  let c=(db.content||[]).find(x=>x.profileId===profileId);
  if(!c){
    c={id:'trend_'+profileId+'_'+Date.now(),profileId,platform,badge:'HOT',views:'Contenu validé',comments:'',time:'Récent',url:ev.url||''};
    db.content.push(c);
  }
  c.url=ev.url||c.url||'';
  c.platform=platform||c.platform||'Réseau social';
  c.badge=c.badge||'HOT';
  c.time=c.time||'Récent';
}
function p50v9ApplyPatch(){
  db.profiles.forEach(p=>{
    p.linkChecks=p.linkChecks||{};p.links=p.links||{};
    Object.entries(p.links).forEach(([platform,url])=>{if(!p.linkChecks[platform])p.linkChecks[platform]={status:p50v9IsDirectPlatformLink(platform,url)?'pending':'search_not_official',checkedAt:null};});
    if(!p.photoPosition)p.photoPosition='50% 50%';
    p.photoManualLocked=Boolean(p.photoManualLocked);p.photoManualUpdatedAt=p.photoManualUpdatedAt||null;
  });
  db.events=(db.events||[]).map(e=>({...e,coverStatus:e.coverStatus||'missing',coverUrl:e.coverUrl||'',coverCandidateUrl:e.coverCandidateUrl||'',coverSource:e.coverSource||'',coverNote:e.coverNote||''}));
  db.content.forEach(c=>{const ev=primaryEvent(c.profileId);if(ev&&p50v9ExactContentLink(ev.url)&&!p50v9ExactContentLink(c.url))c.url=ev.url;});
  db.version=Math.max(Number(db.version||0),9);
}
p50v9ApplyPatch();save();render();
const p50v9CloudPatchTimer=setInterval(()=>{if(window.__pass50CloudReady){p50v9ApplyPatch();save();render();clearInterval(p50v9CloudPatchTimer)}},500);
setTimeout(()=>clearInterval(p50v9CloudPatchTimer),20000);

function p50v9OpenTool(title,html){$('#toolTitle').textContent=title;$('#toolBody').innerHTML=html;open('toolModal')}
function p50v9CloseTool(){close('toolModal')}
function p50v9StatusClass(status){return ['ok','owner_verified','manual_verified','blocked_but_exists'].includes(status)?'ok':status==='pending'?'pending':'bad'}
function p50v9StatusText(status){return ({ok:'OFFICIEL',owner_verified:'OFFICIEL',manual_verified:'OFFICIEL',blocked_but_exists:'À CONFIRMER',pending:'NON TESTÉ',search_not_official:'RECHERCHE',generic_or_content:'LIEN GÉNÉRIQUE',wrong_platform:'MAUVAIS RÉSEAU',broken:'CASSÉ',invalid:'INVALIDE'})[status]||String(status||'NON TESTÉ').toUpperCase()}

const p50v8RenderAdminPane=renderAdminPane;
renderAdmin=function(){const menu=`<div class="admin-menu"><button class="btn ${ui.adminTab==='signals'?'primary':''}" data-admin-tab="signals">Signaux</button><button class="btn ${ui.adminTab==='profiles'?'primary':''}" data-admin-tab="profiles">Influenceurs</button><button class="btn ${ui.adminTab==='media'?'primary':''}" data-admin-tab="media">Médias</button><button class="btn ${ui.adminTab==='links'?'primary':''}" data-admin-tab="links">Liens officiels</button><button class="btn ${ui.adminTab==='news'?'primary':''}" data-admin-tab="news">Actualité</button><button class="btn ${ui.adminTab==='ranking'?'primary':''}" data-admin-tab="ranking">Classement</button><button class="btn ${ui.adminTab==='data'?'primary':''}" data-admin-tab="data">Données</button></div>`;$('#adminBody').innerHTML=`<div class="admin-grid">${menu}<div class="admin-pane" id="adminPane"></div></div>`;renderAdminPane()}
renderAdminPane=function(){if(ui.adminTab==='links')return p50v9RenderLinks();if(ui.adminTab==='news')return p50v9RenderNews();return p50v8RenderAdminPane()}

mediaCard=function(kind,item){const isProfile=kind==='profile',name=isProfile?item.name:(profile(item.profileId)?.name+' · '+item.title),status=isProfile?item.photoStatus:item.coverStatus,url=isProfile?candidatePhoto(item):(item.coverUrl||item.coverCandidateUrl||''),source=isProfile?item.photoSource:item.coverSource,note=isProfile?item.photoNote:item.coverNote,fallback=isProfile?item.initials:(item.icon||'▶');return `<article class="media-card"><div class="media-preview ${isProfile?'':'cover'}"><span>${fallback}</span>${url?`<img src="${safeAttr(url)}" alt="${safeAttr(name)}" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:''}<span class="media-state ${status}">${mediaStatusText(status)}</span></div><div class="media-body"><h4>${name}</h4><div class="media-source">${source||'Aucune source enregistrée'}${note?`<br>${note}`:''}</div><div class="media-url-row"><input class="media-url-input" data-kind="${kind}" data-id="${item.id}" value="${safeAttr(url)}" placeholder="Coller une URL d’image"><button class="btn small media-save-url" data-kind="${kind}" data-id="${item.id}">Ajouter</button></div><div class="media-actions">${isProfile?`<button class="btn small media-discover" data-id="${item.id}">🔎 Rechercher gratuitement</button>`:`<button class="btn small event-preview" data-id="${item.id}">🎬 Analyser le lien</button>`}<button class="btn small primary media-validate" data-kind="${kind}" data-id="${item.id}">Valider</button><button class="btn small danger media-reject" data-kind="${kind}" data-id="${item.id}">Rejeter</button><label class="file-label">Importer${isProfile?' une photo':' une couverture'}<input type="file" accept="image/*" class="media-file" data-kind="${kind}" data-id="${item.id}"></label></div></div></article>`}
function p50MediaSearchKey(value=''){return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9@]+/g,' ').trim()}
function p50ApplyMediaSearch(){const input=document.getElementById('mediaProfileSearch');if(!input)return;const q=p50MediaSearchKey(input.value),items=[...document.querySelectorAll('[data-media-search]')];let visible=0;items.forEach(item=>{const match=!q||p50MediaSearchKey(item.dataset.mediaSearch||'').includes(q);item.style.display=match?'':'none';if(match)visible++;});const count=document.getElementById('mediaSearchCount');if(count)count.textContent=q?`${visible} résultat${visible>1?'s':''}`:`${items.length} éléments`;}
renderMediaPane=function(pane){const order={pending:0,missing:1,rejected:2,validated:3},profiles=[...db.profiles].sort((a,b)=>(order[a.photoStatus]??9)-(order[b.photoStatus]??9)||String(a.name||'').localeCompare(String(b.name||''),'fr')),events=[...db.events].sort((a,b)=>(order[a.coverStatus]??9)-(order[b.coverStatus]??9));const profileCards=profiles.map(p=>`<div data-media-search="${safeAttr([p.name,p.handle,p.id,p.category].filter(Boolean).join(' '))}">${mediaCard('profile',p)}</div>`).join(''),eventCards=events.map(e=>{const p=profile(e.profileId);return `<div data-media-search="${safeAttr([p?.name,p?.handle,e.title,e.type,e.id].filter(Boolean).join(' '))}">${mediaCard('event',e)}</div>`}).join('');pane.innerHTML=`<div class="media-hint"><strong>Règle stricte :</strong> aucune photo ou couverture n’est téléchargée sans confirmation humaine qu’elle représente le bon profil ou le bon contenu.</div><div class="media-search-box"><input id="mediaProfileSearch" type="search" autocomplete="off" placeholder="Rechercher une fiche par nom, pseudo ou identifiant…"><span id="mediaSearchCount">${profiles.length+events.length} éléments</span></div><div class="free-tools"><span class="free-pill">Openverse</span><span class="free-pill">Wikimedia Commons</span><span class="free-pill">Wikipédia</span><span class="free-pill">TikTok oEmbed</span><span class="free-pill">YouTube oEmbed</span></div><div class="admin-toolbar"><button class="btn primary" id="bulkPhotoSearch">Rechercher les photos du Top 10</button><button class="btn" id="bulkCoverSearch">Analyser les couvertures du Top 10</button></div><div class="section-head"><div class="section-title">Photos des influenceurs</div><span class="muted">${profiles.filter(x=>x.photoStatus==='pending').length} à valider</span></div><div class="media-grid">${profileCards}</div><div class="section-head" style="margin-top:22px"><div class="section-title">Couvertures des éléments déclencheurs</div><span class="muted">Vidéos, articles et événements</span></div><div class="media-grid">${eventCards}</div>`;p50ApplyMediaSearch()}

function p50v9RenderLinks(){const pane=$('#adminPane');pane.innerHTML=`<div class="media-hint"><strong>Objectif :</strong> seuls les profils officiels directs sont visibles au public. Les liens de recherche sont masqués.</div><div class="admin-toolbar"><button class="btn primary" id="checkTop10Links">Vérifier les liens du Top 10</button></div><div id="linksCards">${ranking().slice(0,30).map(p50v9LinkCard).join('')}</div>`}
function p50v9LinkCard(p){const plats=[...new Set([...(p.platforms||[]),...Object.keys(p.links||{})])];return `<article class="link-card" data-link-profile="${p.id}"><div class="link-card-head"><div><strong>${p.name}</strong><div class="muted">${p.handle}</div></div><div class="tool-actions"><button class="btn small save-links" data-id="${p.id}">Enregistrer</button><button class="btn small primary check-links" data-id="${p.id}">Vérifier</button></div></div><div class="link-grid">${plats.map(platform=>{const check=p.linkChecks?.[platform]||{status:'pending'};return `<strong>${platform}</strong><input data-link-platform="${platform}" value="${safeAttr(p.links?.[platform]||'')}" placeholder="URL officielle exacte"><span class="link-state ${p50v9StatusClass(check.status)}" title="${safeAttr(check.message||'')}">${p50v9StatusText(check.status)}</span>`}).join('')}</div></article>`}
function p50v9SaveLinks(id,card){const p=profile(id);if(!p)return;card.querySelectorAll('[data-link-platform]').forEach(input=>{const platform=input.dataset.linkPlatform,url=input.value.trim();if(url)p.links[platform]=url;else delete p.links[platform];p.linkChecks[platform]={status:'pending',checkedAt:null}});save();render();toast('Liens enregistrés')}
async function p50v9CheckLinks(id,card){const p=profile(id);if(!p)return;p50v9SaveLinks(id,card);const btn=card.querySelector('.check-links');btn.disabled=true;btn.textContent='Vérification…';try{const data=await apiFetch('link-check.php',{method:'POST',body:{links:p.links}});p.linkChecks={...p.linkChecks,...Object.fromEntries(Object.entries(data.results||{}).map(([k,v])=>[k,{...v,checkedAt:data.checkedAt}]))};save();p50v9RenderLinks();toast('Liens contrôlés')}catch(err){toast(err.message||'Contrôle impossible');btn.disabled=false;btn.textContent='Vérifier'}}

function p50v9RenderNews(){const pane=$('#adminPane'),profiles=ranking().slice(0,50);pane.innerHTML=`<div class="media-hint"><strong>Veille gratuite :</strong> GDELT recherche les articles récents. Aucun article n’entre dans le classement sans validation.</div><div class="admin-toolbar"><select id="newsProfile" style="padding:10px;border-radius:12px;border:1px solid var(--line);background:#0f130f;color:#fff">${profiles.map(p=>`<option value="${p.id}">${p.name}</option>`).join('')}</select><select id="newsDays" style="padding:10px;border-radius:12px;border:1px solid var(--line);background:#0f130f;color:#fff"><option value="7">7 jours</option><option value="15" selected>15 jours</option></select><button class="btn primary" id="searchNewsBtn">Rechercher l’actualité</button></div><div id="newsResults"><div class="tool-empty">Choisissez un profil puis lancez la recherche.</div></div>`}
async function p50v9SearchNews(){const id=$('#newsProfile').value,p=profile(id),days=Number($('#newsDays').value||15),box=$('#newsResults');PASS50_V9.newsProfileId=id;PASS50_V9.newsDays=days;box.innerHTML='<div class="tool-loading">Recherche GDELT en cours…</div>';try{const data=await apiFetch('news-discover.php',{method:'POST',body:{name:p.name,days}});PASS50_V9.news=data.articles||[];box.innerHTML=PASS50_V9.news.length?PASS50_V9.news.map((a,i)=>`<article class="news-card">${a.image?`<img src="${safeAttr(a.image)}" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:'<div class="trigger-thumb">📰</div>'}<div><h4>${a.title||'Article sans titre'}</h4><div class="tool-meta">${a.domain||''} · ${a.date||''} · ${a.language||''}</div><div class="tool-actions"><a class="btn small" href="${safeAttr(a.url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small primary use-news" data-index="${i}">Utiliser comme déclencheur</button></div></div></article>`).join(''):'<div class="tool-empty">Aucun article récent trouvé.</div>'}catch(err){box.innerHTML=`<div class="tool-empty">${err.message||'Recherche indisponible'}</div>`}}
function p50v9UseNews(index){const a=PASS50_V9.news[index],id=PASS50_V9.newsProfileId,p=profile(id);if(!a||!p)return;let ev=primaryEvent(id);const patch={type:'Article',title:a.title||`Actualité concernant ${p.name}`,platforms:['Web'],metric:'Article détecté',publishedLabel:a.date||`Sur ${PASS50_V9.newsDays} jours`,reason:'Article récent détecté via GDELT. À valider avant publication.',url:a.url,icon:'📰',confidence:'moyenne',coverCandidateUrl:a.image||'',coverUrl:'',coverStatus:a.image?'pending':'missing',coverSource:a.domain||'GDELT',coverNote:'Couverture détectée depuis l’article, validation requise.'};if(ev)Object.assign(ev,patch);else db.events.push({id:'news_'+id+'_'+Date.now(),profileId:id,...patch});save();render();toast('Élément déclencheur ajouté à valider')}

async function p50v9DiscoverPhotos(id,openResults=true){const p=profile(id);if(!p)return null;const officialUrls=p50v9OfficialLinks(p).map(([,url])=>url);const data=await apiFetch('media-discover.php',{method:'POST',body:{profileId:p.id,name:p.name,handle:p.handle,officialUrls}});if(openResults)p50v9ShowPhotoCandidates(p,data);return data}
function p50v9ShowPhotoCandidates(p,data){PASS50_V9.photoCandidates=data.candidates||[];PASS50_V9.photoProfileId=p.id;const cards=PASS50_V9.photoCandidates.map((c,i)=>`<article class="tool-card"><img src="${safeAttr(c.previewUrl||c.url)}" alt="Proposition pour ${safeAttr(p.name)}" referrerpolicy="no-referrer" onerror="this.style.display='none'"><h4>${c.sourceName||'Source'}</h4><div class="tool-meta">Confiance ${c.confidence||'moyenne'} · ${c.reason||''}</div><div class="tool-license">${c.attribution?`Attribution : ${c.attribution}<br>`:''}${c.license?`Licence : ${c.license}`:''}</div><label class="tool-check"><input type="checkbox" class="confirm-photo" data-index="${i}"> Je confirme que cette photo représente clairement ${p.name}.</label><div class="tool-actions"><a class="btn small" href="${safeAttr(c.sourcePage||c.url)}" target="_blank" rel="noopener">Voir la source ↗</a><button class="btn small" data-propose-photo="${i}">Proposer sans télécharger</button><button class="btn small primary" data-download-photo="${i}">Valider et télécharger</button></div></article>`).join('');p50v9OpenTool(`Photos proposées · ${p.name}`,`<div class="media-hint">${data.rule||''}</div><div class="free-tools">${(data.freeSources||[]).map(x=>`<span class="free-pill">${x}</span>`).join('')}</div>${cards?`<div class="tool-grid">${cards}</div>`:`<div class="tool-empty">Aucune photo suffisamment fiable. Le script n’a rien téléchargé.<br><br><a class="btn small" href="${safeAttr(data.googleImagesUrl||'#')}" target="_blank" rel="noopener">Recherche manuelle Google Images ↗</a></div>`}`)}
function p50v9ProposePhoto(index){const p=profile(PASS50_V9.photoProfileId),c=PASS50_V9.photoCandidates[index];if(!p||!c)return;if(p.photoManualLocked)return toast('La photo manuelle est protégée. Retire-la volontairement avant une nouvelle proposition.');p.photoCandidateUrl=c.previewUrl||c.url;p.photoUrl='';p.photoStatus='pending';p.photoSource=c.sourceName||'Source gratuite';p.photoNote='Proposition non téléchargée · validation requise';save();render();renderAdminPane();p50v9CloseTool();toast('Photo proposée, non publiée')}
async function p50v9DownloadPhoto(index){const p=profile(PASS50_V9.photoProfileId),c=PASS50_V9.photoCandidates[index],check=$(`.confirm-photo[data-index="${index}"]`);if(!p||!c)return;if(!check?.checked)return toast('Confirme d’abord que la photo représente bien la personne');try{const data=await apiFetch('media-download.php',{method:'POST',body:{kind:'profile',profileId:p.id,profileName:p.name,url:c.url,sourcePage:c.sourcePage,confirmedRepresentation:true}});p.photoUrl=data.url;p.photoCandidateUrl=data.url;p.photoStatus='validated';p.photoSource=[c.sourceName,c.attribution,c.license].filter(Boolean).join(' · ');p.photoNote='Validé manuellement puis copié sur IONOS';p.photoManualLocked=true;p.photoManualUpdatedAt=new Date().toISOString();save();render();renderAdminPane();p50v9CloseTool();toast('Photo validée et enregistrée sur IONOS')}catch(err){toast(err.message||'Téléchargement impossible')}}

async function p50v9PreviewEvent(id,openResults=true){const ev=db.events.find(e=>e.id===id);if(!ev||!p50v9ExactContentLink(ev.url)){if(openResults)toast('Ajoute d’abord le lien exact de la vidéo ou de l’article');return null}const data=await apiFetch('content-preview.php',{method:'POST',body:{url:ev.url}});if(openResults)p50v9ShowEventPreview(ev,data);return data}
function p50v9ShowEventPreview(ev,data){PASS50_V9.preview=data;PASS50_V9.previewEventId=ev.id;const image=data.thumbnail?`<img src="${safeAttr(data.thumbnail)}" alt="Couverture" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:'<div class="trigger-thumb">🎬</div>';p50v9OpenTool(`Couverture · ${profile(ev.profileId)?.name||''}`,`<div class="tool-grid"><article class="tool-card">${image}<h4>${data.title||ev.title}</h4><div class="tool-meta">${data.platform} · ${data.author||''} · ${data.source||''}</div>${data.thumbnail?`<label class="tool-check"><input type="checkbox" id="confirmCover"> Je confirme que cette couverture correspond au contenu original.</label>`:''}<div class="tool-actions"><a class="btn small" href="${safeAttr(data.canonicalUrl||data.url)}" target="_blank" rel="noopener">Ouvrir l’original ↗</a>${data.thumbnail?'<button class="btn small" id="proposeCover">Proposer</button><button class="btn small primary" id="downloadCover">Valider et télécharger</button>':''}</div></article></div>`)}
function p50v9ProposeCover(){const ev=db.events.find(e=>e.id===PASS50_V9.previewEventId),d=PASS50_V9.preview;if(!ev||!d?.thumbnail)return;ev.coverCandidateUrl=d.thumbnail;ev.coverUrl='';ev.coverStatus='pending';ev.coverSource=d.source||d.platform;ev.coverNote='Couverture proposée automatiquement, validation requise.';ev.resolvedUrl=d.canonicalUrl||d.url||ev.resolvedUrl||'';save();render();renderAdminPane();p50v9CloseTool();toast('Couverture proposée sans modifier les données manuelles')}
async function p50v9DownloadCover(){const ev=db.events.find(e=>e.id===PASS50_V9.previewEventId),d=PASS50_V9.preview;if(!ev||!d?.thumbnail)return;if(!$('#confirmCover')?.checked)return toast('Confirme d’abord la couverture');const p=profile(ev.profileId);try{const data=await apiFetch('media-download.php',{method:'POST',body:{kind:'event',itemId:ev.id,itemName:ev.title,profileId:p?.id||ev.id,profileName:p?.name||ev.title,url:d.thumbnail,sourcePage:d.canonicalUrl||d.url,confirmedRepresentation:true}});ev.coverUrl=data.url;ev.coverCandidateUrl=data.url;ev.coverStatus='validated';ev.coverSource=d.source||d.platform;ev.coverNote='Validé manuellement puis copié sur IONOS';ev.resolvedUrl=d.canonicalUrl||d.url||ev.resolvedUrl||'';save();render();renderAdminPane();p50v9CloseTool();toast('Couverture validée sans modifier les données manuelles')}catch(err){toast(err.message||'Téléchargement impossible')}}

async function p50v9BulkPhotos(){const list=ranking().slice(0,10).filter(p=>p.photoStatus!=='validated'&&!p.photoManualLocked);let found=0;for(const p of list){try{const data=await p50v9DiscoverPhotos(p.id,false),c=data?.candidates?.[0];if(c){p.photoCandidateUrl=c.previewUrl||c.url;p.photoUrl='';p.photoStatus='pending';p.photoSource=c.sourceName||'Source gratuite';p.photoNote='Première proposition automatique. Aucun téléchargement avant confirmation.';found++}}catch(e){console.warn(e)}}save();render();renderAdminPane();toast(`${found} proposition${found>1?'s':''} à valider · aucun téléchargement automatique`)}
async function p50v9BulkCovers(){const ids=ranking().slice(0,10).map(p=>primaryEvent(p.id)).filter(Boolean);let found=0;for(const ev of ids){if(!p50v9ExactContentLink(ev.url))continue;try{const d=await p50v9PreviewEvent(ev.id,false);if(d?.thumbnail){ev.coverCandidateUrl=d.thumbnail;ev.coverUrl='';ev.coverStatus='pending';ev.coverSource=d.source||d.platform;ev.coverNote='Proposition automatique, non téléchargée.';found++}}catch(e){console.warn(e)}}save();render();renderAdminPane();toast(`${found} couverture${found>1?'s':''} à valider`)}

const p50v8OpenProfile=openProfile;
openProfile=function(id){close('top50Modal');const p=profile(id),u=userPrefs(),bars=[31,38,42,36,51,59,63,70,66,79,85,score(p)],links=p50v9OfficialLinks(p);$('#profileBody').innerHTML=`<div class="profile-grid"><div class="left">${avatarHtml(p)}<div class="card-actions"><button class="btn fav ${u?.favorites.includes(id)?'on':''}" data-id="${id}">${u?.favorites.includes(id)?'★ Favori':'☆ Favori'}</button><button class="btn follow ${u?.following.includes(id)?'on':''}" data-id="${id}">${u?.following.includes(id)?'Ne plus suivre':'＋ Suivre'}</button></div></div><div><div class="eyebrow">#${completeRanking().findIndex(x=>x.id===id)+1} · ${p.category}</div><h2 style="font-size:39px;margin:7px 0 2px">${p.name}</h2><div class="handle">${p.handle}</div><div style="margin-top:11px">${p.badges.map(badgeHtml).join(' ')||'<span class="muted">Aucun badge actif</span>'}</div><div class="stats"><div class="stat"><span class="muted">Trend Score</span><b>${score(p)}/100</b></div><div class="stat"><span class="muted">Évolution</span><b>${arrow(p)}</b></div><div class="stat"><span class="muted">Âge</span><b style="font-size:18px">${ageText(p)}</b></div><div class="stat"><span class="muted">Réseaux officiels</span><b>${links.length}</b></div></div>${eventHtml(p)}<div class="chart">${bars.map(h=>`<div class="bar" style="height:${Math.max(8,h)}%"></div>`).join('')}</div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">${links.map(([x,url])=>`<a class="btn small" href="${safeAttr(url)}" target="_blank" rel="noopener">${x} ↗</a>`).join('')}</div>${links.length===0?'<div class="platform-hidden-note">Aucun lien officiel direct n’est encore validé. Les liens de recherche ne sont pas affichés au public.</div>':''}</div></div>`;open('profileModal')}

eventHtml=function(p){const e=primaryEvent(p.id);if(!e)return `<div class="trigger-empty"><strong>Élément déclencheur non encore validé</strong><div style="margin-top:5px">Le profil est classé sur la base de plusieurs signaux, mais aucun contenu principal n’a encore été sélectionné.</div></div>`;const link=p50v9ExactContentLink(e.url)?`<a class="btn small primary" href="${safeAttr(e.url)}" target="_blank" rel="noopener">Voir l’élément original ↗</a>`:'<span class="muted">Lien original à valider</span>';return `<section class="trigger-card"><div class="trigger-head"><div class="trigger-kicker">⚡ POURQUOI DANS LE TOP 10 ?</div><span class="trigger-type">${e.type}</span></div><div class="trigger-main">${p50v20EventThumbHtml(p,e)}<div><div class="trigger-title">${e.title}</div><div class="trigger-meta">${e.platforms.join(' · ')} · ${e.publishedLabel} · Confiance ${e.confidence}</div><div class="trigger-reason">${e.reason}</div></div></div><div class="trigger-actions"><span class="badge hot">${e.metric}</span>${link}</div></section>`}
renderContent=function(){const content=[...db.content].sort((a,b)=>{const pa=profile(a.profileId),pb=profile(b.profileId);return score(pb)-score(pa)}).slice(0,5);$('#contentGrid').innerHTML=content.map((c,i)=>{const p=profile(c.profileId),ev=primaryEvent(c.profileId),detected=p50v20DetectedCover(ev),cover=p50v20TrendCover(p,ev),fallback=Boolean(cover&&!detected),url=p50v9ExactContentLink(ev?.url)?ev.url:(p50v9ExactContentLink(c.url)?c.url:'');const body=`${cover?`<img class="cover-bg" src="${safeAttr(cover)}" alt="Visuel ${safeAttr(p.name)}" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:''}${fallback?'<span class="content-cover-fallback">VISUEL DU PROFIL</span>':''}<div><strong>#${i+1} · ${p.name}</strong><div style="margin-top:8px">${badgeHtml(c.badge)}</div></div><div class="play">▶</div><div class="content-meta"><span>${c.platform}</span><span>${c.views} · ${c.time}</span></div>`;return url?`<a class="content-card ${cover?'has-cover':''}" href="${safeAttr(url)}" target="_blank" rel="noopener" data-content="${c.id}">${body}</a>`:`<article class="content-card ${cover?'has-cover':''}" data-content="${c.id}">${body}<div class="platform-hidden-note">Lien original à valider</div></article>`}).join('')}

document.addEventListener('input',e=>{if(e.target.id==='mediaProfileSearch')p50ApplyMediaSearch();});

document.addEventListener('click',async e=>{
  if(e.target.matches('.media-discover')){try{e.target.disabled=true;e.target.textContent='Recherche…';await p50v9DiscoverPhotos(e.target.dataset.id)}catch(err){toast(err.message||'Recherche impossible')}finally{e.target.disabled=false;e.target.textContent='🔎 Rechercher gratuitement'}}
  if(e.target.matches('[data-propose-photo]'))p50v9ProposePhoto(Number(e.target.dataset.proposePhoto));
  if(e.target.matches('[data-download-photo]'))await p50v9DownloadPhoto(Number(e.target.dataset.downloadPhoto));
  if(e.target.matches('.event-preview')){try{await p50v9PreviewEvent(e.target.dataset.id)}catch(err){toast(err.message||'Prévisualisation impossible')}}
  if(e.target.id==='proposeCover')p50v9ProposeCover();if(e.target.id==='downloadCover')await p50v9DownloadCover();
  if(e.target.id==='bulkPhotoSearch')await p50v9BulkPhotos();if(e.target.id==='bulkCoverSearch')await p50v9BulkCovers();
  const saveBtn=e.target.closest('.save-links');if(saveBtn){const card=saveBtn.closest('[data-link-profile]');p50v9SaveLinks(saveBtn.dataset.id,card)}
  const checkBtn=e.target.closest('.check-links');if(checkBtn){const card=checkBtn.closest('[data-link-profile]');await p50v9CheckLinks(checkBtn.dataset.id,card)}
  if(e.target.id==='checkTop10Links'){for(const p of ranking().slice(0,10)){const card=document.querySelector(`[data-link-profile="${p.id}"]`);if(card)await p50v9CheckLinks(p.id,card)}toast('Top 10 contrôlé')}
  if(e.target.id==='searchNewsBtn')await p50v9SearchNews();if(e.target.matches('.use-news'))p50v9UseNews(Number(e.target.dataset.index));
});

render();

// PASS50 Data Engine UI loader — preserves the current homepage layout.
(function(){
  if(!document.querySelector('link[data-pass50-data-engine]')){
    const css=document.createElement('link');
    css.rel='stylesheet';
    css.href='./data-engine-ui.css?v=24.0';
    css.dataset.pass50DataEngine='1';
    document.head.appendChild(css);
  }
  if(!document.querySelector('script[data-pass50-data-engine]')){
    const js=document.createElement('script');
    js.src='./data-engine-ui.js?v=24.0';
    js.dataset.pass50DataEngine='1';
    document.body.appendChild(js);
  }
})();


/* PASS50 — correctifs administration / Actualité / FI */
(function(){
  PASS50_V9.linksProfileId=PASS50_V9.linksProfileId||'';
  PASS50_V9.triggerPreview=null;
  PASS50_V9.triggerProfileId='';
  PASS50_V9.linkHistory=PASS50_V9.linkHistory||[];

  const requestedProfiles=[
    ['coachhamond','Coach Hamond Chic','@coachhamondchic','HC','DIASPORA','Coaching / Lifestyle',['Instagram','TikTok','Facebook','YouTube','Snapchat'],34,0,0],
    ['dbz','DBZ','@dbz','DB','CI','Divertissement',['Instagram','TikTok','Facebook','YouTube','Snapchat'],18,0,0],
    ['gorsky','Gorsky','@gorsky','GO','CI','Football / Divertissement',['Instagram','TikTok','Facebook','YouTube'],22,0,0],
    ['dolpho','Dolpho','@dolpho','DO','CI','Humour',['Instagram','TikTok','Facebook','YouTube','Snapchat'],28,0,0]
  ];
  function p50AdminPatchProfiles(){
    const confirmedSocials={
      lopere:{Facebook:'https://www.facebook.com/Daloa001'},
      emma:{Facebook:'https://www.facebook.com/EmmaLohouesOfficiel'},
      'census-no-limit':{
        Facebook:'https://www.facebook.com/NolimitVousda.Officiel/',
        Instagram:'https://www.instagram.com/nolimit_vousda/',
        TikTok:'https://www.tiktok.com/@nolimit_vousdv',
        YouTube:'https://www.youtube.com/@nolimitvousdv'
      }
    };
    requestedProfiles.forEach(row=>{if(!db.profiles.some(p=>p.id===row[0]||p.name.toLowerCase()===row[1].toLowerCase()))db.profiles.push(buildProfile(row,db.profiles.length));});
    db.profiles.forEach(p=>{
      p.platforms=[...new Set([...(p.platforms||[]),'Facebook','YouTube'])];
      p.links=p.links||{};p.linkChecks=p.linkChecks||{};
      const byId=confirmedSocials[p.id]||null;
      const normalizedOwnerName=String(p.name||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,' ').trim();
      const byName=normalizedOwnerName==='no limit'?confirmedSocials['census-no-limit']:null;
      const ownerLinks=byId||byName||{};
      Object.entries(ownerLinks).forEach(([platform,url])=>{
        if(!p50v9IsDirectPlatformLink(platform,p.links[platform]||''))p.links[platform]=url;
        p.platforms=[...new Set([...(p.platforms||[]),platform])];
        p.linkChecks[platform]={status:'owner_verified',checkedAt:new Date().toISOString(),message:'Compte officiel confirmé par le propriétaire PASS50'};
      });
      ['Facebook','YouTube','Snapchat'].forEach(platform=>{if(!p.linkChecks[platform])p.linkChecks[platform]={status:p.links[platform]&&p50v9IsDirectPlatformLink(platform,p.links[platform])?'pending':'search_not_official',checkedAt:null};});
    });
    db.events=(db.events||[]).map(e=>({...e,originalLinkValidated:Boolean(e.originalLinkValidated)}));
    save();
  }
  p50AdminPatchProfiles();
  async function p50SeedNoLimitOfficialLinks(){
    const key='pass50_v226_nolimit_links_seeded';
    if(localStorage.getItem(key)==='1')return;
    const p=db.profiles.find(x=>x.id==='census-no-limit'||String(x.name||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim()==='no limit');
    if(!p)return;
    const links={
      Facebook:'https://www.facebook.com/NolimitVousda.Officiel/',
      Instagram:'https://www.instagram.com/nolimit_vousda/',
      TikTok:'https://www.tiktok.com/@nolimit_vousdv',
      YouTube:'https://www.youtube.com/@nolimitvousdv'
    };
    const results=await Promise.allSettled(Object.entries(links).map(([platform,url])=>apiFetch('social-links.php',{method:'POST',body:{action:'save',profileId:p.id,platform,url,confirmedOfficial:true,replaceExisting:true}})));
    if(results.every(x=>x.status==='fulfilled'))localStorage.setItem(key,'1');
  }
  const adminPatchTimer=setInterval(()=>{if(window.__pass50CloudReady){p50AdminPatchProfiles();p50SeedNoLimitOfficialLinks().catch(()=>null);p50BackupConfirmedLinksFromBrowser().catch(()=>null);render();clearInterval(adminPatchTimer)}},500);
  setTimeout(()=>clearInterval(adminPatchTimer),20000);

  const oldDirect=p50v9IsDirectPlatformLink;
  p50v9IsDirectPlatformLink=function(platform,url=''){
    if(platform==='Snapchat'){
      try{const u=new URL(url);return u.hostname.toLowerCase().endsWith('snapchat.com')&&/^\/add\/[^/]+\/?$/i.test(u.pathname)}catch{return false}
    }
    return oldDirect(platform,url);
  };

  function p50RankMeta(p){const i=ranking().findIndex(x=>x.id===p.id);return {rank:i>=0?i+1:null,top50:i>=0&&i<50};}
  function p50AllProfiles(){return [...db.profiles].sort((a,b)=>{const ra=p50RankMeta(a).rank??99999,rb=p50RankMeta(b).rank??99999;return ra-rb||a.name.localeCompare(b.name,'fr')});}
  function p50ProfileOption(p){const m=p50RankMeta(p);return `${m.top50?'TOP 50 · ':''}${m.rank?'#'+m.rank+' · ':''}${p.name}`;}
  async function p50HydrateOfficialLinks(profileId){
    const p=profile(profileId);if(!p||PASS50_V9.socialHydrated.has(profileId)||PASS50_V9.socialHydrating.has(profileId))return;
    PASS50_V9.socialHydrating.add(profileId);
    try{
      const data=await apiFetch('social-links.php?profileId='+encodeURIComponent(profileId));
      const threshold=Number(data.threshold||90),verified=(data.links||[]).filter(x=>x.status==='verified'&&Number(x.confidence||0)>=threshold&&p50v9IsDirectPlatformLink(String(x.platform||''),String(x.url||'')));
      verified.forEach(x=>{const platform=String(x.platform),url=String(x.url);p.links[platform]=url;p.platforms=[...new Set([...(p.platforms||[]),platform])];p.linkChecks[platform]={status:'ok',checkedAt:x.checked_at||new Date().toISOString(),message:'Lien officiel publié par le Data Engine'};});
      PASS50_V9.socialHydrated.add(profileId);save();render();
      if(ui.adminTab==='links'&&PASS50_V9.linksProfileId===profileId)p50v9RenderLinks();
    }catch(err){console.warn('Réseaux officiels non hydratés',err)}
    finally{PASS50_V9.socialHydrating.delete(profileId);}
  }

  function p50LocalLinkAudit(profileId,platform,previousUrl,newUrl,confirmed=false){
    const key='pass50_link_audit_v1',items=JSON.parse(localStorage.getItem(key)||'[]');
    items.unshift({profileId,platform,previousUrl:previousUrl||'',newUrl:newUrl||'',confirmed:Boolean(confirmed),createdAt:new Date().toISOString()});
    localStorage.setItem(key,JSON.stringify(items.slice(0,3000)));
  }
  async function p50LoadLinkHistory(profileId){
    try{
      const data=await apiFetch('social-links.php?profileId='+encodeURIComponent(profileId)+'&history=1&limit=80');
      PASS50_V9.linkHistory=data.history||[];
    }catch{PASS50_V9.linkHistory=[];}
    p50RenderLinkHistory(profileId);
  }
  function p50RenderLinkHistory(profileId){
    const box=document.getElementById('linkHistoryBox');if(!box||profileId!==PASS50_V9.linksProfileId)return;
    const items=PASS50_V9.linkHistory||[];
    box.innerHTML=items.length?`<div class="section-title" style="margin-bottom:8px">Historique enregistré</div>${items.slice(0,30).map(x=>`<div class="link-history-row"><strong>${safeAttr(x.platform)}</strong><span>${safeAttr(x.action_type)}</span><code>${safeAttr(x.new_url||x.previous_url||'')}</code><small>${safeAttr(x.created_at||'')}</small></div>`).join('')}`:'<div class="tool-empty">Aucun historique serveur antérieur. Les nouvelles actions seront désormais conservées.</div>';
  }
  async function p50RecoverOfficialLinks(scope='profile'){
    const profileId=scope==='profile'?PASS50_V9.linksProfileId:'';
    const button=document.querySelector(scope==='profile'?'#recoverProfileLinks':'#recoverAllLinks');if(button){button.disabled=true;button.textContent='Récupération…';}
    try{
      const data=await apiFetch('social-links-recovery.php',{method:'POST',body:{scope,profileId}});
      await loadCloudState();p50AdminPatchProfiles();render();p50v9RenderLinks();
      toast(`${data.restoredCount||0} lien(s) restauré(s)`);
    }catch(err){toast(err.message||'Récupération impossible');}
    finally{if(button){button.disabled=false;button.textContent=scope==='profile'?'Récupérer cette FI':'Récupérer toutes mes saisies';}}
  }
  async function p50BackupConfirmedLinksFromBrowser(){
    const key='pass50_v227_confirmed_links_backup';if(localStorage.getItem(key)==='1'||!window.__pass50CloudReady)return;
    const jobs=[];
    (db.profiles||[]).forEach(p=>Object.entries(p.links||{}).forEach(([platform,url])=>{
      const status=p.linkChecks?.[platform]?.status||'';
      if(p50v9IsDirectPlatformLink(platform,url)&&['owner_verified','manual_verified','ok'].includes(status))jobs.push(apiFetch('social-links.php',{method:'POST',body:{action:'save',profileId:p.id,platform,url,confirmedOfficial:true,replaceExisting:true}}).catch(()=>null));
    }));
    if(jobs.length)await Promise.allSettled(jobs);
    localStorage.setItem(key,'1');
  }
  p50v9RenderLinks=function(){
    const pane=$('#adminPane'),profiles=p50AllProfiles();
    if(!PASS50_V9.linksProfileId||!profile(PASS50_V9.linksProfileId))PASS50_V9.linksProfileId=profiles[0]?.id||'';
    const p=profile(PASS50_V9.linksProfileId),m=p?p50RankMeta(p):{};
    pane.innerHTML=`<div class="links-v2"><div class="media-hint"><strong>Sauvegarde renforcée :</strong> chaque lien direct est maintenant enregistré côté serveur dès que tu cliques sur Enregistrer. La confirmation le rend officiel et prioritaire.</div><div class="links-recovery-actions"><button class="btn" id="recoverProfileLinks">Récupérer cette FI</button><button class="btn primary" id="recoverAllLinks">Récupérer toutes mes saisies</button></div><div class="links-v2-toolbar"><label>FI à modifier<select id="linksProfileSelect">${profiles.map(x=>`<option value="${x.id}" ${x.id===PASS50_V9.linksProfileId?'selected':''}>${safeAttr(p50ProfileOption(x))}</option>`).join('')}</select></label></div>${p?`<article class="link-card focused" data-link-profile="${p.id}"><div class="link-card-head"><div><strong>${p.name}</strong>${m.top50?'<span class="top50-marker">TOP 50</span>':''}<div class="muted">${m.rank?'#'+m.rank+' · ':''}${p.handle}</div></div><div class="tool-actions"><button class="btn save-links" data-id="${p.id}">Enregistrer</button><button class="btn primary check-links" data-id="${p.id}">Vérifier</button></div></div>${p50v9LinkGrid(p)}<label class="tool-check"><input type="checkbox" class="confirm-all-links"> Je confirme que les liens renseignés correspondent aux comptes officiels de cette FI.</label></article><div id="linkHistoryBox" class="link-history-box"><div class="tool-loading">Chargement de l’historique…</div></div>`:'<div class="tool-empty">Aucune FI.</div>'}</div>`;
    if(p){p50HydrateOfficialLinks(p.id);p50LoadLinkHistory(p.id);}
  };
  function p50v9LinkGrid(p){
    const fixed=['Instagram','TikTok','Facebook','YouTube','Snapchat','X'];
    const plats=[...new Set([...fixed,...(p.platforms||[]),...Object.keys(p.links||{})])];
    return `<div class="link-grid">${plats.map(platform=>{const check=p.linkChecks?.[platform]||{status:'pending'};return `<strong>${platform}</strong><input data-link-platform="${platform}" value="${safeAttr(p.links?.[platform]||'')}" placeholder="URL officielle exacte"><span class="link-state ${p50v9StatusClass(check.status)}" title="${safeAttr(check.message||'')}">${p50v9StatusText(check.status)}</span>`}).join('')}</div>`;
  }

  p50v9RenderNews=function(){
    const pane=$('#adminPane'),profiles=p50AllProfiles();
    const selected=PASS50_V9.newsProfileId&&profile(PASS50_V9.newsProfileId)?PASS50_V9.newsProfileId:(profiles[0]?.id||'');
    PASS50_V9.newsProfileId=selected;
    const p=profile(selected),ev=p?primaryEvent(p.id):null,m=p?p50RankMeta(p):{};
    pane.innerHTML=`<div class="news-v2"><div class="media-hint"><strong>Actualité vidéo d’abord :</strong> le moteur visite les réseaux officiels accessibles, cherche en priorité les vidéos, Reels, Shorts et TikTok, puis complète avec quelques articles. La cible PASS50 doit pouvoir comprendre l’essentiel sans longue lecture.</div><section class="news-search-box"><div class="section-title" style="margin-bottom:10px">Rechercher l’actualité</div><div class="news-controls"><label>FI<select id="newsProfile">${profiles.map(x=>`<option value="${x.id}" ${x.id===selected?'selected':''}>${safeAttr(p50ProfileOption(x))}</option>`).join('')}</select></label><label>Période<select id="newsDays"><option value="2">2 jours</option><option value="7">7 jours</option><option value="15" ${PASS50_V9.newsDays===15?'selected':''}>15 jours</option><option value="30">30 jours</option><option value="60">60 jours</option></select></label><button class="btn primary" id="searchNewsBtn">Rechercher l’actualité</button></div><div id="newsResults"><div class="tool-empty">Sélectionne une FI puis lance la recherche.</div></div></section><section class="trigger-validation-box"><div class="section-title">Lien original du buzz · ${p?.name||''}${m.top50?'<span class="top50-marker">TOP 50</span>':''}</div><div class="muted" style="margin:5px 0 12px">Colle ici le lien exact de la vidéo, du post ou de l’article. Après validation, il apparaît dans la FI.</div><form id="newsTriggerForm" data-profile="${p?.id||''}"><div class="trigger-form-grid"><label>Type<select name="type"><option ${!ev||ev.type==='Vidéo'?'selected':''}>Vidéo</option><option ${ev?.type==='Publication'?'selected':''}>Publication</option><option ${ev?.type==='Article'?'selected':''}>Article</option><option ${ev?.type==='Déclaration'?'selected':''}>Déclaration</option><option ${ev?.type==='Événement'?'selected':''}>Événement</option></select></label><label>Titre<input name="title" value="${safeAttr(ev?.title||'')}" placeholder="Titre court du buzz" required></label><label class="full">URL originale exacte<input type="url" name="url" value="${safeAttr(ev?.url||'')}" placeholder="https://…" required></label><label class="full">Pourquoi cela provoque le buzz<input name="reason" value="${safeAttr(ev?.reason||'')}" placeholder="Explication courte"></label></div><label class="tool-check"><input type="checkbox" name="confirmedOriginal" required> J’ai ouvert le lien et je confirme qu’il s’agit bien du contenu original lié à cette FI.</label><div class="tool-actions"><button class="btn primary" type="submit">ANALYSER ET VALIDER DANS LA FI</button>${ev?`<a class="btn" href="${safeAttr(ev.url||'#')}" target="_blank" rel="noopener">Ouvrir le lien actuel ↗</a><button class="btn danger reject-trigger" type="button" data-profile="${p.id}">Rejeter le déclencheur</button>`:''}</div></form>${ev?`<div class="trigger-current"><div><strong>Déclencheur actuel</strong><div>${ev.title}</div><div class="muted">${ev.originalLinkValidated?'Lien original validé':'Lien original non validé'}</div></div><span class="link-state ${ev.originalLinkValidated?'ok':'pending'}">${ev.originalLinkValidated?'VALIDÉ':'À VALIDER'}</span></div>`:''}</section></div>`;
  };

  p50v9SearchNews=async function(){
    const id=$('#newsProfile')?.value,p=profile(id),days=Number($('#newsDays')?.value||15),box=$('#newsResults');
    if(!p||!box)return;
    PASS50_V9.newsProfileId=id;PASS50_V9.newsDays=days;box.innerHTML='<div class="tool-loading">Visite des réseaux officiels et recherche de vidéos…</div>';
    try{
      const data=await apiFetch('news-discover.php',{method:'POST',body:{profileId:id,name:p.name,handle:p.handle,days,socialLinks:p.links||{}}});
      PASS50_V9.news=data.articles||data.results||[];
      const warning=data.warning?`<div class="news-warning">${data.warning}</div>`:'';
      const summary=`<div class="news-summary"><strong>${Number(data.videoCount||0)} vidéo${Number(data.videoCount||0)>1?'s':''}</strong> · ${Number(data.articleCount||0)} article${Number(data.articleCount||0)>1?'s':''} · vidéos affichées en premier</div>`;
      box.innerHTML=warning+summary+(PASS50_V9.news.length?PASS50_V9.news.map((a,i)=>{const video=a.kind==='video'||a.type==='Vidéo'||['YouTube','TikTok','Instagram','Facebook','Snapchat'].includes(a.platform);const icon=video?'▶':'📰';const label=video?'VIDÉO':'ARTICLE';return `<article class="news-card ${video?'video-first':''}">${a.image?`<img src="${safeAttr(a.image)}" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:`<div class="trigger-thumb">${icon}</div>`}<div><div class="news-kind ${video?'video':''}">${label}</div><h4>${a.title||(video?'Vidéo récente':'Article récent')}</h4><div class="tool-meta"><span class="news-source-pill">${a.source||data.source||'Web'}</span> ${a.platform||a.domain||''} · ${a.date||''}</div><div class="tool-actions"><a class="btn small" href="${safeAttr(a.url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small primary use-news" data-index="${i}">Valider dans la FI</button></div></div></article>`}).join(''):`<div class="tool-empty">${data.message||'Aucune vidéo ni actualité récente trouvée.'}</div>`);
    }catch(err){console.error(err);box.innerHTML=`<div class="tool-empty"><strong>Recherche momentanément indisponible.</strong><br>${err.message||'Le serveur n’a pas pu joindre les sources d’actualité.'}<br><br>Tu peux toujours valider manuellement le lien original dans le formulaire ci-dessous.</div>`;}
  };

  p50v9UseNews=async function(index){
    const a=PASS50_V9.news[index],id=PASS50_V9.newsProfileId,p=profile(id);if(!a||!p)return;
    try{
      const preview=await apiFetch('content-preview.php',{method:'POST',body:{url:a.url}});
      const isVideo=a.kind==='video'||a.type==='Vidéo'||['YouTube','TikTok','Instagram','Facebook','Snapchat'].includes(a.platform);let ev=primaryEvent(id);const platform=a.platform||preview.platform||(isVideo?'Réseau social':'Web');const patch={type:isVideo?'Vidéo':'Article',title:a.title||`${isVideo?'Vidéo':'Actualité'} concernant ${p.name}`,platforms:[platform],metric:isVideo?'Vidéo détectée':'Article détecté',publishedLabel:a.date||`Sur ${PASS50_V9.newsDays} jours`,reason:isVideo?'Vidéo récente détectée sur un réseau officiel ou dans la recherche sociale, puis validée par le propriétaire.':'Article récent sélectionné et lien original validé par le propriétaire.',url:preview.canonicalUrl||a.url,icon:isVideo?'▶':'📰',confidence:'élevée',originalLinkValidated:true,originalLinkValidatedAt:new Date().toISOString(),coverCandidateUrl:a.image||preview.thumbnail||'',coverUrl:'',coverStatus:(a.image||preview.thumbnail)?'validated':'missing',coverSource:a.source||a.domain||preview.source||'Actualité',coverNote:(a.image||preview.thumbnail)?'Vignette extraite automatiquement du contenu original confirmé.':'Aucune vignette détectée : la photo validée du profil sera utilisée comme visuel de secours.'};if(ev)Object.assign(ev,patch);else{ev={id:'news_'+id+'_'+Date.now(),profileId:id,...patch};db.events.push(ev);}p50v20SyncTrendContent(id,ev,platform);save();render();p50v9RenderNews();toast(`${isVideo?'Vidéo':'Article'} validé avec vignette dans la FI et le Top 5`);
    }catch(err){toast(err.message||'Impossible de valider ce lien');}
  };

  async function p50ValidateTriggerForm(form){
    const fd=new FormData(form),id=form.dataset.profile,p=profile(id);if(!p)return;
    if(fd.get('confirmedOriginal')!=='on')return toast('Confirme d’abord le lien original.');
    const url=String(fd.get('url')||'').trim();
    const title=String(fd.get('title')||'').trim()||`Buzz de ${p.name}`;
    const reason=String(fd.get('reason')||'').trim()||'Contenu original validé par le propriétaire PASS50.';
    const btn=form.querySelector('button[type=submit]'),old=btn?.textContent||'';
    if(btn){btn.disabled=true;btn.textContent='ANALYSE…';}
    try{
      const preview=await apiFetch('content-preview.php',{method:'POST',body:{url}});
      let ev=primaryEvent(id),previousUrl=String(ev?.url||''),urlChanged=previousUrl!==url;
      const patch={type:String(fd.get('type')||'Vidéo'),title,platforms:[preview.platform||'Web'],metric:'Lien original validé',publishedLabel:'Validation manuelle',reason,url,submittedUrl:url,resolvedUrl:preview.canonicalUrl||url,icon:['YouTube','TikTok','Instagram','Facebook'].includes(preview.platform)?'▶':'📰',confidence:'élevée',originalLinkValidated:true,originalLinkValidatedAt:new Date().toISOString(),manualDataValidated:true,coverCandidateUrl:preview.thumbnail||(!urlChanged?(ev?.coverCandidateUrl||''):''),coverUrl:!urlChanged?(ev?.coverUrl||''):'',coverStatus:preview.thumbnail?'validated':(!urlChanged?(ev?.coverStatus||'missing'):'missing'),coverSource:preview.source||(!urlChanged?(ev?.coverSource||''):''),coverNote:preview.thumbnail?'Vignette extraite automatiquement du lien original confirmé.':(!urlChanged?(ev?.coverNote||''):'Aucune vignette détectée : la photo validée du profil sera utilisée dans le Top 5.')};
      if(ev){Object.assign(ev,patch);db.events=db.events.filter(x=>x.profileId!==id||x.id===ev.id);}else{ev={id:'trigger_'+id+'_'+Date.now(),profileId:id,...patch};db.events.push(ev);}
      db.content.forEach(c=>{if(c.profileId===id)c.url=url;});
      p50v20SyncTrendContent(id,ev,preview.platform||'Réseau social');
      save();render();p50v9RenderNews();toast(preview.thumbnail?'Lien, vignette et données enregistrés dans la FI et le Top 5':'Lien enregistré ; visuel du profil utilisé dans le Top 5');
    }catch(err){console.error(err);toast(err.message||'Lien original non validé');}
    finally{if(btn){btn.disabled=false;btn.textContent=old;}}
  }

  function p50RejectTrigger(profileId){const ev=primaryEvent(profileId);if(!ev)return;if(!confirm('Retirer ce déclencheur de la FI ?'))return;db.events=db.events.filter(x=>x.id!==ev.id);save();render();p50v9RenderNews();toast('Déclencheur retiré');}

  eventHtml=function(p){const e=primaryEvent(p.id);if(!e)return `<div class="trigger-empty"><strong>Élément déclencheur non encore validé</strong><div style="margin-top:5px">Aucun lien original n’a encore été sélectionné dans Administration → Actualité.</div></div>`;const valid=e.originalLinkValidated&&p50v9ExactContentLink(e.url);const link=valid?`<a class="btn small primary" href="${safeAttr(e.url)}" target="_blank" rel="noopener">Voir l’élément original ↗</a>`:'<span class="muted">Lien original à valider dans Administration → Actualité</span>';return `<section class="trigger-card"><div class="trigger-head"><div class="trigger-kicker">⚡ POURQUOI DANS LE TOP 10 ?</div><span class="trigger-type">${e.type}</span></div><div class="trigger-main">${p50v20EventThumbHtml(p,e)}<div><div class="trigger-title">${e.title}</div><div class="trigger-meta">${(e.platforms||[]).join(' · ')} · ${e.publishedLabel||''} · Confiance ${e.confidence||'à vérifier'}</div><div class="trigger-reason">${e.reason||''}</div></div></div><div class="trigger-actions"><span class="badge hot">${e.metric||'Signal détecté'}</span>${link}</div></section>`;};

  openProfile=function(id){
    const top50=$('#top50Modal'),profileWasOpen=$('#profileModal').classList.contains('show');
    if(top50?.classList.contains('show')){profileReturnContext={modalId:'top50Modal',scrollTop:top50.querySelector('.modal-box')?.scrollTop||0,profileId:id};close('top50Modal')}else if(!profileWasOpen){profileReturnContext=null}
    const p=profile(id),u=userPrefs(),bars=[31,38,42,36,51,59,63,70,66,79,85,score(p)],links=p50v9OfficialLinks(p);$('#profileBody').innerHTML=`<div class="profile-grid"><div class="left">${avatarHtml(p)}<div class="card-actions"><button class="btn fav ${u?.favorites.includes(id)?'on':''}" data-id="${id}">${u?.favorites.includes(id)?'★ Favori':'☆ Favori'}</button><button class="btn follow ${u?.following.includes(id)?'on':''}" data-id="${id}">${u?.following.includes(id)?'Ne plus suivre':'＋ Suivre'}</button></div></div><div><div class="eyebrow">#${completeRanking().findIndex(x=>x.id===id)+1} · ${p.category}</div><h2 style="font-size:39px;margin:7px 0 2px">${p.name}</h2><div class="handle">${p.handle}</div><div style="margin-top:11px">${p.badges.map(badgeHtml).join(' ')||'<span class="muted">Aucun badge actif</span>'}</div><div class="stats"><div class="stat"><span class="muted">Trend Score</span><b>${score(p)}/100</b></div><div class="stat"><span class="muted">Évolution</span><b>${arrow(p)}</b></div><div class="stat"><span class="muted">Âge</span><b style="font-size:18px">${ageText(p)}</b></div><div class="stat"><span class="muted">Réseaux officiels</span><b>${links.length}</b></div></div>${eventHtml(p)}<div class="chart">${bars.map(h=>`<div class="bar" style="height:${Math.max(8,h)}%"></div>`).join('')}</div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">${links.map(([x,url])=>`<a class="btn small" href="${safeAttr(url)}" target="_blank" rel="noopener">${x} ↗</a>`).join('')}</div>${links.length===0?'<div class="platform-hidden-note">Aucun lien officiel direct n’est encore validé.</div>':''}</div></div>`;open('profileModal');
  };

  const oldAdminRows=adminProfileRows;
  adminProfileRows=function(list){return list.map(p=>{const m=p50RankMeta(p);return `<tr data-admin-profile-name="${safeAttr(p.name.toLowerCase())}"><td><div class="admin-profile-name"><strong>${p.name}</strong>${m.top50?'<span class="top50-marker">TOP 50</span>':''}</div><div class="muted">${m.rank?'#'+m.rank+' · ':''}${p.handle}</div></td><td>${p.region}</td><td>${score(p)}</td><td>${p.eligible&&p.alive?'Oui':'Non'}</td><td><button class="btn small edit-profile" data-id="${p.id}">Modifier</button></td></tr>`}).join('')};

  document.addEventListener('change',e=>{
    if(e.target.id==='linksProfileSelect'){PASS50_V9.linksProfileId=e.target.value;p50v9RenderLinks();}
    if(e.target.id==='newsProfile'){PASS50_V9.newsProfileId=e.target.value;p50v9RenderNews();}
  });
  document.addEventListener('submit',e=>{if(e.target.id==='newsTriggerForm'){e.preventDefault();p50ValidateTriggerForm(e.target)}});
  document.addEventListener('click',e=>{if(e.target.matches('.reject-trigger'))p50RejectTrigger(e.target.dataset.profile);if(e.target.id==='recoverProfileLinks')p50RecoverOfficialLinks('profile');if(e.target.id==='recoverAllLinks')p50RecoverOfficialLinks('all');});
  document.addEventListener('input',e=>{if(e.target.id==='profileSearch'){const q=e.target.value.trim().toLowerCase();document.querySelectorAll('#profileAdminRows tr').forEach(r=>r.style.display=(r.dataset.adminProfileName||'').includes(q)?'':'none')}});

  p50v9SaveLinks=async function(id,card){
    const p=profile(id);if(!p||!card)return;
    const confirmed=Boolean(card.querySelector('.confirm-all-links')?.checked),previousLinks={...(p.links||{})};
    const tasks=[];
    card.querySelectorAll('[data-link-platform]').forEach(input=>{
      const platform=input.dataset.linkPlatform,url=input.value.trim();
      if(url){
        p50LocalLinkAudit(id,platform,previousLinks[platform]||'',url,confirmed);
        p.links[platform]=url;p.platforms=[...new Set([...(p.platforms||[]),platform])];
        const direct=p50v9IsDirectPlatformLink(platform,url);
        p.linkChecks[platform]={
          status:direct?(confirmed?'owner_verified':'pending'):'generic_or_content',
          checkedAt:confirmed&&direct?new Date().toISOString():null,
          message:direct?(confirmed?'Compte officiel confirmé par le propriétaire PASS50':'Lien direct prêt à contrôler'):'Le lien doit ouvrir directement le profil, pas la page d’accueil ou de connexion.'
        };
        if(direct){
          tasks.push(apiFetch('social-links.php',{method:'POST',body:{action:'save',profileId:id,platform,url,confirmedOfficial:confirmed,replaceExisting:confirmed}})
            .then(data=>{
              p.links[platform]=data?.validation?.normalizedUrl||url;
              p.linkChecks[platform]=confirmed
                ?{status:'owner_verified',checkedAt:new Date().toISOString(),message:'Compte officiel confirmé et publié par le propriétaire'}
                :{status:'pending',checkedAt:new Date().toISOString(),message:'Lien sauvegardé côté serveur, en attente de confirmation'};
            })
            .catch(err=>{
              p.linkChecks[platform]=confirmed
                ?{status:'owner_verified',checkedAt:new Date().toISOString(),message:'Compte officiel confirmé localement. Synchronisation serveur à relancer si nécessaire.'}
                :{status:'pending',checkedAt:null,message:'Lien conservé dans le navigateur. Synchronisation serveur à relancer.'};
              console.warn('Sauvegarde serveur différée pour '+platform,err);
            }));
        }
      }else{
        if(previousLinks[platform])p50LocalLinkAudit(id,platform,previousLinks[platform],'',confirmed);
        delete p.links[platform];p.linkChecks[platform]={status:'search_not_official',checkedAt:null};
        if(confirmed&&previousLinks[platform])tasks.push(apiFetch('social-links.php',{method:'POST',body:{action:'delete',profileId:id,platform}}).catch(()=>null));
      }
    });
    save();if(tasks.length)await Promise.allSettled(tasks);save();render();
    toast(confirmed?'Liens officiels confirmés et publiés':'Liens enregistrés localement · coche la confirmation pour les publier comme officiels');
  };
  p50v9CheckLinks=async function(id,card){
    const p=profile(id);if(!p||!card)return;
    const confirmed=Boolean(card.querySelector('.confirm-all-links')?.checked);
    await p50v9SaveLinks(id,card);
    const btn=card.querySelector('.check-links');if(btn){btn.disabled=true;btn.textContent='Vérification…'}
    if(confirmed){
      Object.entries(p.links||{}).forEach(([platform,url])=>{
        if(p50v9IsDirectPlatformLink(platform,url))p.linkChecks[platform]={status:'owner_verified',checkedAt:new Date().toISOString(),message:'Compte officiel confirmé par le propriétaire PASS50'};
      });
      save();p50v9RenderLinks();toast('Tous les liens directs ont été validés comme officiels');
      return;
    }
    try{
      const data=await apiFetch('link-check.php',{method:'POST',body:{links:p.links}});
      const checked=Object.fromEntries(Object.entries(data.results||{}).map(([k,v])=>{
        const url=p.links?.[k]||v.url||'';
        if(!p50v9IsDirectPlatformLink(k,url))return [k,{...v,status:'generic_or_content',message:'Le lien doit ouvrir directement le profil officiel.',checkedAt:data.checkedAt}];
        // Les réseaux bloquent fréquemment les robots. Une absence de réponse ne signifie pas que l’URL est fausse.
        const status=['ok','blocked_but_exists'].includes(v.status)?v.status:(v.status==='broken'?'blocked_but_exists':v.status);
        return [k,{...v,status,message:status==='blocked_but_exists'?'Profil direct reconnu ; la plateforme empêche le contrôle automatique. Coche la confirmation pour le publier.':v.message,checkedAt:data.checkedAt}];
      }));
      p.linkChecks={...p.linkChecks,...checked};save();p50v9RenderLinks();toast('Contrôle terminé');
    }catch(err){
      Object.entries(p.links||{}).forEach(([platform,url])=>{if(p50v9IsDirectPlatformLink(platform,url))p.linkChecks[platform]={status:'blocked_but_exists',checkedAt:new Date().toISOString(),message:'Profil direct reconnu ; contrôle distant indisponible.'};});
      save();p50v9RenderLinks();toast('Les profils directs sont reconnus. Confirme-les pour les publier.');
    }
  };



/* PASS50 V19 — duel éditorial demandé par le propriétaire.
   Il ne se substitue pas aux métriques du moteur : il permet d'afficher le duel
   pendant que l'historique automatisé continue de se constituer. */
(function(){
  const p50V19MeasuredCoules=typeof coulesCandidates==='function'?coulesCandidates:null;
  coulesCandidates=function(){
    const ids=['census-dougoutigui-lobeh','census-eudoxie-yao'];
    const selected=ids.map(id=>profile(id)).filter(Boolean).map(p=>({...p,coulesEditorial:true,decline:0}));
    if(selected.length===2)return selected;
    return p50V19MeasuredCoules?p50V19MeasuredCoules():[];
  };
})();
  render();
})();


/* PASS50 V19 — import réel du recensement élargi (90 candidats)
   Le fichier JSON placé dans le dépôt n'est pas importé par le navigateur
   sans ce mécanisme. Les nouveaux profils restent non éligibles tant que
   leurs comptes et leurs métriques n'ont pas été vérifiés. */
(function(){
  'use strict';
  const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.6';
  const CENSUS_VERSION='90-v22';
  let importing=false;

  function p50CensusNormalize(value=''){
    return String(value)
      .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
      .toLowerCase()
      .replace(/[’'`´]/g,'')
      .replace(/[^a-z0-9]+/g,'')
      .trim();
  }

  function p50CensusHandle(candidate){
    const alias=String(candidate?.known_alias||'');
    const found=alias.match(/@[A-Za-z0-9._-]+/);
    if(found)return found[0];
    const slug=String(candidate?.name||'profil')
      .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
      .toLowerCase().replace(/[^a-z0-9]+/g,'').slice(0,28);
    return '@'+(slug||'profil');
  }

  function p50CensusInitials(name=''){
    const words=String(name).replace(/[’']/g,' ').split(/\s+/).filter(Boolean);
    return (words.slice(0,2).map(w=>w[0]).join('')||'P').toUpperCase();
  }

  function p50CensusProfile(candidate,index){
    const official=(candidate&&typeof candidate.official_socials==='object'&&candidate.official_socials)||{};
    const platforms=Object.keys(official).filter(k=>official[k]);
    const scores=Object.fromEntries(periods.map(period=>[period,0]));
    return {
      id:String(candidate.id||('census-'+Date.now()+'-'+index)),
      name:String(candidate.name||'Profil à vérifier'),
      handle:p50CensusHandle(candidate),
      initials:p50CensusInitials(candidate.name),
      region:['CI','DIASPORA','BOTH'].includes(candidate.zone)?candidate.zone:'BOTH',
      category:String(candidate.category||'À catégoriser'),
      platforms,
      scores,
      delta:0,
      decline:0,
      alive:true,
      eligible:false,
      classable:false,
      censusStatus:String(candidate.census_status||'À vérifier – comptes officiels'),
      verificationPriority:String(candidate.verification_priority||'P2'),
      entityType:String(candidate.entity_type||'Personne'),
      knownAlias:String(candidate.known_alias||''),
      censusSource:candidate.source||{},
      censusNotes:String(candidate.notes||''),
      priorityWave:String(candidate.priority_wave||''),
      researchQueries:Array.isArray(candidate.research_queries)?candidate.research_queries:[],
      curatedSocialSources:(candidate&&typeof candidate.curated_social_sources==='object'&&candidate.curated_social_sources)||{},
      curatedFacts:(candidate&&typeof candidate.curated_facts==='object'&&candidate.curated_facts)||{},
      censusImportedAt:new Date().toISOString(),
      ageStatus:'unconfirmed',
      birthDate:null,
      birthYear:null,
      agePublic:true,
      birthManualLocked:false,
      birthManualUpdatedAt:null,
      photoUrl:'',
      photoCandidateUrl:'',
      photoStatus:'missing',
      photoSource:'',
      photoNote:'Profil recensé : identité visuelle à vérifier.',
      photoPosition:'50% 50%',
      badges:[],
      links:{...official},
      linkChecks:Object.fromEntries(platforms.map(platform=>[platform,{status:'pending',checkedAt:null}]))
    };
  }

  function p50CensusMerge(candidates){
    if(!Array.isArray(candidates))throw new Error('Format du recensement invalide');
    db.profiles=Array.isArray(db.profiles)?db.profiles:[];

    // Correction définitive de l'identité Cadic dans les anciennes bases locales/cloud.
    db.profiles.forEach(profileItem=>{
      if(profileItem.id==='louissette'||['louisettecadic','louissettecadic'].includes(p50CensusNormalize(profileItem.name))){
        profileItem.name='Cadic N’Guessan';
        profileItem.handle=profileItem.handle||'@misscadic';
        profileItem.initials='CN';
        profileItem.category='Beauté / Lifestyle / Mode';
        profileItem.knownAlias='Louisette Cadic';
      }
    });

    const existingIds=new Set(db.profiles.map(p=>String(p.id||'').toLowerCase()));
    const existingNames=new Set(db.profiles.map(p=>p50CensusNormalize(p.name)));
    const existingHandles=new Set(db.profiles.map(p=>p50CensusNormalize(p.handle)).filter(Boolean));
    let added=0,skipped=0;

    candidates.forEach((candidate,index)=>{
      const id=String(candidate?.id||'').toLowerCase();
      const name=p50CensusNormalize(candidate?.name);
      const handle=p50CensusNormalize(p50CensusHandle(candidate));
      const aliases=String(candidate?.known_alias||'').split(/[\/·,;|]/).map(p50CensusNormalize).filter(Boolean);
      const aliasConflict=aliases.some(alias=>existingNames.has(alias)||existingHandles.has(alias));
      if((id&&existingIds.has(id))||(name&&existingNames.has(name))||(handle&&existingHandles.has(handle))||aliasConflict){
        const current=db.profiles.find(p=>
          (id&&String(p.id||'').toLowerCase()===id)||
          (name&&p50CensusNormalize(p.name)===name)||
          (handle&&p50CensusNormalize(p.handle)===handle)||
          aliases.includes(p50CensusNormalize(p.name))||aliases.includes(p50CensusNormalize(p.handle))
        );
        if(current){
          const official=(candidate&&typeof candidate.official_socials==='object'&&candidate.official_socials)||{};
          current.links=current.links||{};current.linkChecks=current.linkChecks||{};
          Object.entries(official).forEach(([platform,url])=>{
            if(!url)return;
            const previous=String(current.links[platform]||'');
            if(!previous||!p50v9IsDirectPlatformLink(platform,previous)){
              current.links[platform]=String(url);
              current.linkChecks[platform]={status:'pending',checkedAt:null,source:'PASS50 V22'};
            }
          });
          current.platforms=[...new Set([...(current.platforms||[]),...Object.keys(official).filter(k=>official[k])])];
          current.priorityWave=String(candidate.priority_wave||current.priorityWave||'');
          current.verificationPriority=String(candidate.verification_priority||current.verificationPriority||'P2');
          current.researchQueries=Array.isArray(candidate.research_queries)?candidate.research_queries:(current.researchQueries||[]);
          current.curatedSocialSources={...(current.curatedSocialSources||{}),...((candidate&&typeof candidate.curated_social_sources==='object'&&candidate.curated_social_sources)||{})};
          current.curatedFacts={...(current.curatedFacts||{}),...((candidate&&typeof candidate.curated_facts==='object'&&candidate.curated_facts)||{})};
          current.censusSource=candidate.source||current.censusSource||{};
          current.censusNotes=String(candidate.notes||current.censusNotes||'');
          if((!current.category||['À catégoriser','Autre','Lifestyle'].includes(current.category))&&candidate.category)current.category=String(candidate.category);
        }
        skipped++;
        return;
      }
      const profileItem=p50CensusProfile(candidate,index);
      db.profiles.push(profileItem);
      existingIds.add(profileItem.id.toLowerCase());
      existingNames.add(p50CensusNormalize(profileItem.name));
      existingHandles.add(p50CensusNormalize(profileItem.handle));
      added++;
    });

    db.censusVersion=CENSUS_VERSION;
    db.censusImportedAt=new Date().toISOString();
    db.version=Math.max(Number(db.version||0),12);
    return {added,skipped,total:db.profiles.length,eligible:db.profiles.filter(p=>p.alive&&p.eligible).length};
  }

  async function p50ImportCensus(showMessage=false){
    if(importing)return null;
    importing=true;
    try{
      const response=await fetch(CENSUS_URL,{cache:'no-store'});
      if(!response.ok)throw new Error('Fichier de recensement introuvable ('+response.status+')');
      const candidates=await response.json();
      const result=p50CensusMerge(candidates);
      save();
      render();
      if(document.querySelector('#adminModal.show'))renderAdminPane();
      if(showMessage&&typeof toast==='function')toast(`${result.total} profils recensés · ${result.eligible} éligibles`);
      console.info('[PASS50 V12] Recensement importé',result);
      return result;
    }catch(error){
      console.error('[PASS50 V12] Import du recensement impossible',error);
      if(showMessage&&typeof toast==='function')toast('Import du recensement impossible');
      return null;
    }finally{
      importing=false;
    }
  }

  // Premier import local, puis nouvel import après le chargement de la base cloud,
  // car l'état MySQL peut remplacer l'état local pendant l'initialisation.
  p50ImportCensus(false);
  const cloudTimer=setInterval(()=>{
    if(window.__pass50CloudReady){
      clearInterval(cloudTimer);
      p50ImportCensus(true);
    }
  },400);
  setTimeout(()=>clearInterval(cloudTimer),25000);

  // Le compteur de l'administration distingue recensement et classement.
  const previousRenderAdminPane=renderAdminPane;
  renderAdminPane=function(){
    previousRenderAdminPane();
    if(ui.adminTab!=='profiles')return;
    const pane=document.querySelector('#adminPane');
    const toolbar=pane?.querySelector('.admin-toolbar');
    if(!pane||!toolbar||pane.querySelector('.census-count-note'))return;
    const total=db.profiles.length;
    const eligible=db.profiles.filter(p=>p.alive&&p.eligible).length;
    const pending=total-eligible;
    if(!toolbar.querySelector('#importCensusBtn')){
      const importButton=document.createElement('button');
      importButton.className='btn small';
      importButton.id='importCensusBtn';
      importButton.textContent='Importer le recensement';
      toolbar.appendChild(importButton);
    }
    const note=document.createElement('div');
    note.className='note census-count-note';
    note.style.marginBottom='12px';
    note.innerHTML=`<strong>${total} profils recensés</strong> · ${eligible} éligibles au classement · ${pending} en vérification`;
    toolbar.insertAdjacentElement('afterend',note);
  };

  document.addEventListener('click',event=>{
    if(event.target?.id==='importCensusBtn')p50ImportCensus(true);
  });

  window.p50ImportCensus=p50ImportCensus;
})();

/* PASS50 V22.9 — Contrôle qualité des fiches */
(function(){
  function qaEsc(v=''){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  function qaDirectLinks(p){
    try{return typeof p50v9OfficialLinks==='function'?p50v9OfficialLinks(p):Object.entries(p?.links||{}).filter(([,u])=>/^https?:\/\//i.test(u||''));}
    catch{return [];}
  }
  function qaEvent(p){try{return typeof primaryEvent==='function'?primaryEvent(p.id):null}catch{return null}}
  function qaLive(p){return (db.liveStreams||[]).find(l=>l.profileId===p.id&&l.status==='live'&&/^https?:\/\//i.test(l.url||''));}
  function qaProfile(p){
    const links=qaDirectLinks(p),ev=qaEvent(p),content=(db.content||[]).find(c=>c.profileId===p.id);
    const photo=Boolean((p.photoStatus==='validated'||p.photoManualLocked)&&((p.photoUrl||p.photoCandidateUrl)));
    const birth=Boolean((p.birthDate||p.birthYear)&&(p.ageStatus==='confirmed'||p.birthManualLocked));
    const socials=links.length>=2;
    const event=Boolean(ev&&/^https?:\/\//i.test(ev.url||'')&&(ev.originalLinkValidated!==false));
    let visual=false;
    try{visual=Boolean((ev&&typeof publicCover==='function'&&publicCover(ev))||(content&&content.thumbnail)||(photo));}catch{visual=photo;}
    const live=Boolean(qaLive(p));
    const checks=[
      {key:'socials',label:'Réseaux officiels',ok:socials,detail:links.length+' lien'+(links.length>1?'s':'')+' direct'+(links.length>1?'s':'')},
      {key:'photo',label:'Photo officielle',ok:photo,detail:photo?'Validée et protégée':'Photo absente ou non validée'},
      {key:'birth',label:'Âge / naissance',ok:birth,detail:birth?(p.birthDate||p.birthYear):'Date non publiée'},
      {key:'event',label:'Actualité déclencheuse',ok:event,detail:event?'Lien original validé':'Aucun lien buzz validé'},
      {key:'visual',label:'Vignette / visuel',ok:visual,detail:visual?'Visuel disponible':'Aucun visuel exploitable'},
      {key:'ranking',label:'Classement',ok:Boolean(p.eligible&&Number(score(p))>0),detail:p.eligible?'Score '+Number(score(p)||0):'Non classable'}
    ];
    const passed=checks.filter(x=>x.ok).length;
    return {profile:p,checks,passed,total:checks.length,percent:Math.round(passed/checks.length*100),live};
  }
  window.pass50QualityReport=function(){return (db.profiles||[]).map(qaProfile).sort((a,b)=>a.percent-b.percent||a.profile.name.localeCompare(b.profile.name,'fr'));};
  function qaAction(key,id){
    if(key==='socials'){window.PASS50_V9&&(PASS50_V9.linksProfileId=id);ui.adminTab='links';}
    else if(key==='photo'||key==='visual'){ui.adminTab='media';setTimeout(()=>{const s=document.querySelector('#mediaSearch');if(s){s.value=profile(id)?.name||'';s.dispatchEvent(new Event('input',{bubbles:true}));}},80);}
    else if(key==='birth'){ui.adminTab='hub';}
    else if(key==='event'){window.PASS50_V9&&(PASS50_V9.newsProfileId=id);ui.adminTab='news';}
    else if(key==='ranking'){ui.adminTab='profiles';}
    renderAdmin();
  }
  window.renderQualityPane=function(){
    const pane=document.querySelector('#adminPane');if(!pane)return;
    const q=(window.__qaSearch||'').trim().toLowerCase();
    const all=pass50QualityReport(),list=q?all.filter(r=>[r.profile.name,r.profile.handle,r.profile.category].join(' ').toLowerCase().includes(q)):all;
    const complete=all.filter(r=>r.percent===100).length,critical=all.filter(r=>r.percent<50).length,avg=all.length?Math.round(all.reduce((s,r)=>s+r.percent,0)/all.length):0;
    pane.innerHTML=`<div class="section-head"><div><div class="section-title">✅ CONTRÔLE QUALITÉ DES FI</div><div class="muted">Vérification automatique de la publication réelle des données.</div></div><button class="btn primary" id="qaRefresh">Relancer le contrôle</button></div>
      <div class="hub-kpis"><div class="stat"><span class="muted">Complétude moyenne</span><b>${avg}%</b></div><div class="stat"><span class="muted">Fiches complètes</span><b>${complete}/${all.length}</b></div><div class="stat"><span class="muted">Fiches critiques</span><b>${critical}</b></div><div class="stat"><span class="muted">Contrôle</span><b>Temps réel</b></div></div>
      <div class="admin-toolbar"><input id="qaSearch" value="${qaEsc(window.__qaSearch||'')}" placeholder="Rechercher une FI…" style="padding:11px;border-radius:12px;border:1px solid var(--line);background:#0f130f;color:#fff;width:100%"></div>
      <div class="media-hint"><strong>Lecture :</strong> une fiche à 100 % possède au moins deux réseaux directs, une photo protégée, une naissance publiée, une actualité originale, un visuel et un score classable. Le LIVE est contrôlé séparément car il est temporaire.</div>
      <div class="live-list">${list.map(r=>`<article class="link-card"><div class="link-card-head"><div><strong>${qaEsc(r.profile.name)}</strong><div class="muted">${qaEsc(r.profile.handle||'')} · ${qaEsc(r.profile.category||'')}</div></div><div><strong style="font-size:22px;color:${r.percent===100?'var(--lime)':r.percent<50?'var(--red)':'var(--orange)'}">${r.percent}%</strong><div class="muted">${r.passed}/${r.total} validés</div></div></div><div class="link-grid">${r.checks.map(c=>`<strong>${c.ok?'✅':'⚠️'} ${qaEsc(c.label)}</strong><span class="muted">${qaEsc(c.detail)}</span>${c.ok?'<span class="link-state ok">OK</span>':`<button class="btn small qa-fix" data-key="${c.key}" data-id="${qaEsc(r.profile.id)}">Corriger</button>`}`).join('')}</div>${r.live?'<div class="media-hint" style="margin-top:10px;color:var(--lime)">● LIVE confirmé et ouvrable</div>':''}</article>`).join('')||'<div class="tool-empty">Aucune fiche trouvée.</div>'}</div>`;
  };
  const prevPane=window.renderAdminPane;
  window.renderAdminPane=function(){if(ui.adminTab==='quality')return renderQualityPane();return prevPane();};
  const prevAdmin=window.renderAdmin;
  window.renderAdmin=function(){prevAdmin();if(ui.adminTab==='quality')renderQualityPane();};
  document.addEventListener('input',e=>{if(e.target.id==='qaSearch'){window.__qaSearch=e.target.value;renderQualityPane();}});
  document.addEventListener('click',e=>{const fix=e.target.closest('.qa-fix');if(fix)return qaAction(fix.dataset.key,fix.dataset.id);if(e.target.id==='qaRefresh'){renderQualityPane();toast('Contrôle qualité actualisé');}});
})();
/* BEGIN PASS50 REINSTALL STEPS 1+2 V23 */
(function(){'use strict';const ADD=[{"id":"census-african-ryou","name":"African Ryou","handle":"@african_ryou","region":"CI","category":"Humour / Culture ivoirienne / Lifestyle / Fitness","platforms":[],"censusStatus":"Recensé confirmé — intégration prioritaire","knownAlias":"@african_ryou"},{"id":"census-samuella-kouassi","name":"Samuella Kouassi","handle":"@samuellakouassiofficiel","region":"CI","category":"Lifestyle / Mode / Divertissement","platforms":[],"censusStatus":"Recensé confirmé — intégration prioritaire","knownAlias":"@samuellakouassiofficiel"},{"id":"census-nadiani","name":"Nadiani","handle":"@officialnad_","region":"BOTH","category":"Mode / Beauté / Lifestyle / Entrepreneuriat","platforms":[],"censusStatus":"Recensé confirmé — intégration prioritaire","knownAlias":"Imane Nadiani Touré / @officialnad_"},{"id":"census-investisseur-africain","name":"L’Investisseur Africain","handle":"@sbragbo","region":"BOTH","category":"Business / Investissement / Entrepreneuriat / Diaspora","platforms":[],"censusStatus":"Recensé confirmé — intégration prioritaire","knownAlias":"Jean-Yves Bragbo / @sbragbo"},{"id":"census-laura-ziehi","name":"Laura Ziehi","handle":"@laura.ziehi","region":"BOTH","category":"Lifestyle / Mode / Divertissement","platforms":[],"censusStatus":"Recensé confirmé — réseaux à compléter","knownAlias":"@laura.ziehi"},{"id":"census-aya-robert","name":"Aya Robert","handle":"@ayarobert","region":"CI","category":"TikTok / Lives / Débats / Divertissement","platforms":[],"censusStatus":"À vérifier — compte officiel actuel","knownAlias":"Aya Robert"},{"id":"census-smookii-gamer","name":"SmOokii Gamer","handle":"@smookii_gamer","region":"CI","category":"Gaming / Humour / Divertissement","platforms":[],"censusStatus":"Recensé confirmé — réseaux à compléter","knownAlias":"Smooki Gamer / @smookii_gamer"}];const n=v=>String(v||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'');function ensure(){if(typeof db==='undefined'||!db)return;db.profiles=db.profiles||[];for(const c of ADD){if(db.profiles.some(p=>p.id===c.id||n(p.name)===n(c.name)))continue;db.profiles.push({id:c.id,name:c.name,handle:c.handle,initials:c.name.split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase(),region:c.region,category:c.category,platforms:c.platforms,scores:{'2H':0,'24H':0,'48H':0,'7J':0,'15J':0},delta:0,decline:0,alive:true,eligible:false,classable:false,censusStatus:c.censusStatus,knownAlias:c.knownAlias,links:{},linkChecks:{},badges:[],photoUrl:'',algorithmVersion:'15C-v1',dataConfidence:0,measuredCoverage:0});}db.step12={version:'23-step12.1',present:ADD.filter(c=>db.profiles.some(p=>p.id===c.id)).length,total:db.profiles.length,updatedAt:new Date().toISOString()};try{localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}}function freshLive(x){if(!x||x.status!=='live')return false;const now=Date.now(),end=x.endsAt?new Date(x.endsAt).getTime():0,start=new Date(x.startedAt||x.detectedAt||x.createdAt||0).getTime();if(end&&end<=now)return false;if(start&&now-start>8*3600000)return false;return true}normalizeLiveStreams=function(){db.liveStreams=(db.liveStreams||[]).filter(freshLive);(db.profiles||[]).forEach(p=>{p.badges=(p.badges||[]).filter(b=>b!=='LIVE');if(db.liveStreams.some(x=>x.profileId===p.id&&freshLive(x)))p.badges.unshift('LIVE')})};function exact(v=''){try{const u=new URL(v,location.href),h=u.hostname.replace(/^www\./,'').toLowerCase(),p=u.pathname;if(h.includes('youtube.com'))return (p==='/watch'&&u.searchParams.get('v'))||/^\/(shorts|live|embed)\//.test(p);if(h==='youtu.be')return p.length>1;if(h.includes('facebook.com'))return u.searchParams.get('v')||/^\/(reel|share|watch|.*\/videos)\//.test(p);if(h.includes('instagram.com'))return /^\/(p|reel|reels|tv)\//.test(p);if(h.includes('tiktok.com'))return /\/video\//.test(p);if(h==='x.com'||h.includes('twitter.com'))return /\/status\//.test(p);return p.length>1}catch{return false}}const oldEvent=typeof eventHtml==='function'?eventHtml:null;if(oldEvent)eventHtml=function(p){const e=primaryEvent(p.id);if(e&&e.originalLinkValidated===true){const u=[e.resolvedUrl,e.canonicalUrl,e.submittedUrl,e.url].find(exact);if(u)e.url=u}return oldEvent(p)};const oldSave=typeof save==='function'?save:null;let q=Promise.resolve();if(oldSave)save=function(){const r=oldSave();if(typeof scheduleCloudSync==='function')scheduleCloudSync();return r};const oldSync=typeof syncCloudState==='function'?syncCloudState:null;if(oldSync)syncCloudState=function(){q=q.then(()=>oldSync()).catch(e=>console.warn('PASS50 sync',e));return q};window.p50EnsureStep12Profiles=ensure;ensure();normalizeLiveStreams();if(typeof render==='function')render();setTimeout(ensure,1500);setTimeout(()=>{ensure();normalizeLiveStreams();if(typeof scheduleCloudSync==='function')scheduleCloudSync();if(typeof render==='function')render();window.PASS50_STEP12_STATUS=db.step12},5000);setInterval(()=>{normalizeLiveStreams();if(typeof renderLiveHeader==='function')renderLiveHeader()},5000);window.PASS50_STEP12_STATUS=db.step12;})();
/* END PASS50 REINSTALL STEPS 1+2 V23 */
