(function(){
  const fallbackRenderAdminPane=renderAdminPane;
  const DE={hub:null,loading:false,offset:0,lastError:'',platforms:['Instagram','TikTok','Facebook','YouTube','X','Snapchat','Web']};

  renderAdmin=function(){
    const items=[['signals','Signaux'],['profiles','Influenceurs'],['media','Médias'],['links','Réseaux sociaux'],['news','Actualité'],['live','LIVE'],['hub','Data Hub'],['ranking','Classement'],['data','Données']];
    const menu=`<div class="admin-menu">${items.map(([id,label])=>`<button class="btn ${ui.adminTab===id?'primary':''}" data-admin-tab="${id}">${label}</button>`).join('')}</div>`;
    $('#adminBody').innerHTML=`<div class="admin-grid">${menu}<div class="admin-pane" id="adminPane"></div></div>`;
    renderAdminPane();
  };

  renderAdminPane=function(){
    if(ui.adminTab==='hub')return deRenderHub($('#adminPane'));
    return fallbackRenderAdminPane();
  };

  function deEsc(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
  function deStatus(status,confidence){
    const c=Number(confidence||0),s=status||'empty';
    if(s==='verified'&&c>=90)return `<span class="de-status verified">✓ ${c} %</span>`;
    if(s==='conflict')return `<span class="de-status conflict">Conflit</span>`;
    if(s==='rejected')return `<span class="de-status rejected">Rejeté</span>`;
    if(c>0)return `<span class="de-status candidate">${c} %</span>`;
    return '<span class="de-status empty">Absent</span>';
  }
  function deTime(value){if(!value)return 'Jamais';const d=new Date(String(value).replace(' ','T')+'Z');return Number.isNaN(d.getTime())?deEsc(value):d.toLocaleString('fr-FR');}

  function deNormalizeBirthDate(value){
    const raw=String(value||'').trim().toLowerCase();
    if(!raw)return '';
    const cleaned=raw.replace(/\s+/g,' ').replace(/[.-]/g,'/');
    let y,m,d,match;
    if((match=cleaned.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/))){y=Number(match[1]);m=Number(match[2]);d=Number(match[3]);}
    else if((match=cleaned.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/))){d=Number(match[1]);m=Number(match[2]);y=Number(match[3]);}
    else return '';
    const dt=new Date(Date.UTC(y,m-1,d));
    if(dt.getUTCFullYear()!==y||dt.getUTCMonth()!==m-1||dt.getUTCDate()!==d)return '';
    return `${String(y).padStart(4,'0')}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
  }

  function deRenderHub(pane){
    pane.innerHTML=`<div class="data-engine-shell"><div class="media-hint"><strong>Règle active :</strong> seules les données avec une confiance de <strong>90 % minimum</strong> sont publiées. Les photos restent soumises à validation humaine.</div><div class="de-toolbar"><button class="btn primary" id="deSync">Synchroniser les profils</button><button class="btn" id="deCollectBatch">Collecter 5 profils</button><button class="btn" id="dePublish">Publier les données ≥ 90 %</button><button class="btn" id="deSnapshot">Capturer le classement</button></div><div id="deHubContent" class="de-loading">Chargement du moteur de données…</div></div>`;
    deLoadHub();
  }

  async function deLoadHub(force=false){
    if(DE.loading&&!force)return;
    DE.loading=true;
    try{DE.hub=await apiFetch('data-hub.php');DE.lastError='';deDrawHub();}
    catch(err){DE.lastError=err.message||'Moteur indisponible';const el=$('#deHubContent');if(el)el.innerHTML=`<div class="de-error">${deEsc(DE.lastError)}<br><small>Vérifie que les nouveaux fichiers API sont déployés et que tu es connecté comme propriétaire.</small></div>`;}
    finally{DE.loading=false;}
  }

  function deDrawHub(){
    const el=$('#deHubContent');if(!el||!DE.hub)return;
    const k=DE.hub.kpis||{},profiles=DE.hub.profiles||[];
    el.innerHTML=`<div class="de-kpis"><div class="de-kpi"><strong>${k.profiles||0}</strong><span>PROFILS SYNCHRONISÉS</span></div><div class="de-kpi"><strong>${k.birthVerified||0}</strong><span>NAISSANCES ≥ 90 %</span></div><div class="de-kpi"><strong>${k.socialVerified||0}</strong><span>RÉSEAUX ≥ 90 %</span></div><div class="de-kpi"><strong>${k.fullyReliable||0}</strong><span>FICHES FIABLES</span></div></div><div class="admin-table-wrap"><table class="admin-table" style="min-width:980px"><thead><tr><th>Influenceur</th><th>Complétude</th><th>Naissance</th><th>Réseaux</th><th>Dernière collecte</th><th></th></tr></thead><tbody>${profiles.map(deProfileRow).join('')}</tbody></table></div><div class="muted" style="font-size:10px">Moteur actualisé : ${deTime(DE.hub.generatedAt)} · seuil ${DE.hub.threshold||90} %</div>`;
  }

  function deProfileRow(p){
    const birth=p.facts?.birth_date;
    const social=(p.socialLinks||[]).filter(x=>x.status==='verified'&&Number(x.confidence)>=90);
    const run=p.lastRun;
    const inTop50=ranking().slice(0,50).some(x=>x.id===p.id);return `<tr class="${inTop50?'is-top50':''}"><td><strong>${deEsc(p.name)}</strong>${inTop50?'<span class="top50-marker">TOP 50</span>':''}<div class="hub-detail">${deEsc(p.handle||'')}</div></td><td><div class="de-progress"><i style="width:${Number(p.completeness||0)}%"></i></div><div class="hub-detail">${Number(p.completeness||0)} %</div></td><td>${birth?deStatus('verified',birth.confidence):deStatus('empty',0)}</td><td>${social.length?`<span class="de-score ok">${social.length}</span> vérifié${social.length>1?'s':''}`:deStatus('empty',0)}</td><td><div class="de-run">${deTime(p.lastCollectedAt)}${run?.status==='error'?'<br><span style="color:#ff8080">Erreur de collecte</span>':''}</div></td><td><div class="de-row-actions"><button class="btn small de-collect-one" data-id="${deEsc(p.id)}">Collecter</button><button class="btn small de-social" data-id="${deEsc(p.id)}">Réseaux</button><button class="btn small de-birth" data-id="${deEsc(p.id)}">Naissance</button></div></td></tr>`;
  }

  async function deAction(button,work,label){
    const old=button?.textContent;if(button){button.disabled=true;button.textContent=label||'Traitement…';}
    try{return await work();}finally{if(button){button.disabled=false;button.textContent=old;}}
  }

  async function deSync(btn){
    await deAction(btn,async()=>{const data=await apiFetch('data-hub.php',{method:'POST',body:{action:'sync'}});DE.hub=data.hub;deDrawHub();toast(`${data.syncedProfiles} profils synchronisés`);},'Synchronisation…');
  }
  async function deCollect(btn,profileId=''){
    await deAction(btn,async()=>{const data=await apiFetch('data-collect.php',{method:'POST',body:{profileId,limit:profileId?1:5,offset:profileId?0:DE.offset,publishVerified:true}});DE.hub=data.hub;if(!profileId)DE.offset=Number(data.nextOffset||0);deDrawHub();await loadCloudState();render();toast(`${data.processed} profil(s) collecté(s) · ${data.verified} donnée(s) vérifiée(s)`);},'Collecte…');
  }
  async function dePublish(btn){
    await deAction(btn,async()=>{const data=await apiFetch('data-publish.php',{method:'POST',body:{}});DE.hub=data.hub;deDrawHub();await loadCloudState();render();toast(`${data.publishedProfiles} profils publiés`);},'Publication…');
  }
  async function deSnapshot(btn){
    await deAction(btn,async()=>{const data=await apiFetch('data-snapshot.php',{method:'POST',body:{period:ui.period}});toast(`${data.captured} positions enregistrées`);},'Capture…');
  }


  async function deOpenBirth(profileId){
    const pane=$('#adminPane');pane.innerHTML='<div class="de-loading">Chargement des sources de naissance…</div>';
    try{
      const data=await apiFetch('facts.php?profileId='+encodeURIComponent(profileId));
      const birthFacts=(data.facts||[]).filter(x=>x.fact_key==='birth_date');
      const evidence=(data.evidence||[]).filter(x=>x.fact_key==='birth_date');
      pane.innerHTML=`<div class="de-profile-head"><div><div class="section-title">Date de naissance · ${deEsc(data.profile.public_name)}</div><div class="muted">Ajoute deux sources distinctes indiquant la même date. Formats acceptés : JJ/MM/AAAA ou AAAA-MM-JJ.</div></div><button class="btn" data-admin-tab="hub">Retour au Data Hub</button></div><div class="de-link-card"><form id="deBirthForm" data-profile="${deEsc(profileId)}" class="form"><div class="two"><div class="field"><label>Date de naissance</label><input type="text" inputmode="numeric" autocomplete="off" name="value" placeholder="Ex. 12/08/1991" required><small>Formats acceptés : 12/08/1991, 12-08-1991 ou 1991-08-12.</small></div><div class="field"><label>Nom de la source</label><input name="sourceName" placeholder="Ex. site officiel, média" required></div></div><div class="field"><label>URL exacte de la source</label><input type="url" name="sourceUrl" placeholder="https://…" required></div><label class="de-confirm"><input type="checkbox" name="confirmedSource" required> Je confirme avoir vérifié que cette source mentionne bien cette date.</label><button class="btn primary" type="submit">AJOUTER CETTE SOURCE</button></form></div><div class="section-head" style="margin-top:18px"><div class="section-title">Dates détectées</div><span class="muted">Seuil ${data.threshold||90} %</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Confiance</th><th>Sources</th><th>Statut</th></tr></thead><tbody>${birthFacts.length?birthFacts.map(f=>`<tr><td><strong>${deEsc(f.normalized_value)}</strong></td><td>${deStatus(f.status,f.confidence)}</td><td>${Number(f.evidence_count||0)}</td><td>${deEsc(f.status)}</td></tr>`).join(''):'<tr><td colspan="4" class="muted">Aucune date vérifiée.</td></tr>'}</tbody></table></div><div class="section-head" style="margin-top:18px"><div class="section-title">Sources enregistrées</div></div>${evidence.length?evidence.map(x=>`<div class="signal"><div><strong>${deEsc(x.source_name)}</strong><div>${deEsc(x.normalized_value)}</div><div class="muted">${deEsc(x.source_url||'')} · poids ${Number(x.source_weight||0)} %</div></div></div>`).join(''):'<div class="muted">Aucune source.</div>'}`;
    }catch(err){pane.innerHTML=`<div class="de-error">${deEsc(err.message)}</div><button class="btn" data-admin-tab="hub">Retour</button>`;}
  }

  async function deOpenSocial(profileId){
    const pane=$('#adminPane');pane.innerHTML='<div class="de-loading">Chargement des réseaux…</div>';
    try{
      const data=await apiFetch('social-links.php?profileId='+encodeURIComponent(profileId));
      const current=Object.fromEntries((data.links||[]).map(x=>[x.platform,x]));
      pane.innerHTML=`<div class="de-profile-head"><div><div class="section-title">Réseaux officiels · ${deEsc(data.profile.public_name)}</div><div class="muted">Ajoute ou corrige manuellement Facebook, YouTube, Instagram, TikTok, Snapchat et les autres plateformes. Seuls les liens confirmés à 90 % ou plus apparaissent dans la FI.</div></div><button class="btn" data-admin-tab="hub">Retour au Data Hub</button></div><div class="de-social-summary"><strong>${(data.links||[]).filter(x=>x.status==='verified'&&Number(x.confidence)>=90).length}</strong><span>liens validés sur ${DE.platforms.length} plateformes</span></div><div class="de-links">${DE.platforms.map(platform=>deLinkCard(profileId,platform,current[platform])).join('')}</div>`;
    }catch(err){pane.innerHTML=`<div class="de-error">${deEsc(err.message)}</div><button class="btn" data-admin-tab="hub">Retour</button>`;}
  }

  function deLinkCard(profileId,platform,link){
    const status=link?.status||'empty',confidence=Number(link?.confidence||0),url=link?.url||'',icon=({Instagram:'◎',TikTok:'♪',Facebook:'f',YouTube:'▶',Snapchat:'◉',X:'𝕏',Web:'⌂'})[platform]||'•';
    return `<article class="de-link-card"><div class="de-link-head"><div class="de-platform-title"><span>${icon}</span><div><strong>${deEsc(platform)}</strong><small>${url?'URL enregistrée':'À renseigner'}</small></div></div>${deStatus(status,confidence)}</div><form class="de-link-form" data-profile="${deEsc(profileId)}" data-platform="${deEsc(platform)}"><input type="url" name="url" value="${deEsc(url)}" placeholder="Colle l’URL officielle exacte" required><button class="btn primary" type="submit">Valider</button><label class="de-confirm"><input type="checkbox" name="confirmedOfficial" required> Je confirme qu’il s’agit du compte officiel de cet influenceur.</label></form>${url?`<div class="de-link-meta">${deEsc(url)}${link?.checked_at?` · contrôlé ${deTime(link.checked_at)}`:''}</div><div class="de-link-actions"><a class="btn small" href="${deEsc(url)}" target="_blank" rel="noopener">Ouvrir le compte ↗</a><button class="btn small danger de-reject-link" data-profile="${deEsc(profileId)}" data-platform="${deEsc(platform)}">Rejeter</button></div>`:''}</article>`;
  }

  document.addEventListener('click',async e=>{
    try{
      if(e.target.id==='deSync')await deSync(e.target);
      if(e.target.id==='deCollectBatch')await deCollect(e.target);
      if(e.target.id==='dePublish')await dePublish(e.target);
      if(e.target.id==='deSnapshot')await deSnapshot(e.target);
      if(e.target.matches('.de-collect-one'))await deCollect(e.target,e.target.dataset.id);
      if(e.target.matches('.de-social'))await deOpenSocial(e.target.dataset.id);
      if(e.target.matches('.de-birth'))await deOpenBirth(e.target.dataset.id);
      if(e.target.matches('.de-reject-link')){
        if(!confirm('Rejeter ce lien officiel ?'))return;
        await apiFetch('social-links.php',{method:'POST',body:{action:'reject',profileId:e.target.dataset.profile,platform:e.target.dataset.platform}});
        await deOpenSocial(e.target.dataset.profile);toast('Lien rejeté');
      }
    }catch(err){console.error(err);toast(err.message||'Action impossible');}
  });

  document.addEventListener('submit',async e=>{
    if(e.target.id==='deBirthForm'){
      e.preventDefault();const form=e.target,fd=new FormData(form),button=form.querySelector('button[type=submit]');
      try{await deAction(button,async()=>{const normalized=deNormalizeBirthDate(fd.get('value'));if(!normalized)throw new Error('Date invalide. Utilise JJ/MM/AAAA ou AAAA-MM-JJ.');if(fd.get('confirmedSource')!=='on')throw new Error('Confirme que la source mentionne bien cette date.');await apiFetch('facts.php',{method:'POST',body:{profileId:form.dataset.profile,factKey:'birth_date',value:normalized,sourceName:String(fd.get('sourceName')||''),sourceUrl:String(fd.get('sourceUrl')||''),confirmedSource:true}});await loadCloudState();render();await deOpenBirth(form.dataset.profile);toast('Source de naissance ajoutée');},'Enregistrement…');}catch(err){console.error(err);toast(err.message||'Source refusée');}
      return;
    }
    if(!e.target.matches('.de-link-form'))return;
    e.preventDefault();
    const form=e.target,fd=new FormData(form),button=form.querySelector('button[type=submit]');
    try{
      await deAction(button,async()=>{
        const data=await apiFetch('social-links.php',{method:'POST',body:{action:'save',profileId:form.dataset.profile,platform:form.dataset.platform,url:String(fd.get('url')||''),confirmedOfficial:fd.get('confirmedOfficial')==='on'}});
        if(!data.confirmed)throw new Error('La confirmation du compte officiel est obligatoire.');
        await loadCloudState();render();await deOpenSocial(form.dataset.profile);toast('Lien officiel validé');
      },'Vérification…');
    }catch(err){console.error(err);toast(err.message||'Lien non validé');}
  });
})();
