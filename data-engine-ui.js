(function(){
  const fallbackRenderAdminPane=renderAdminPane;
  const DE={hub:null,loading:false,lastError:'',platforms:['Instagram','TikTok','Facebook','YouTube','Snapchat','X','Web'],socialProfileId:'',autoRunning:false,stopRequested:false,autoSeen:new Set(),autoTarget:0,autoMessage:'',majRunning:false,majStopRequested:false,majSeen:new Set(),majTarget:0,majStage:'',majMessage:'',majStartedAt:null,majLastResult:null};

  renderAdmin=function(){
    const items=[['signals','Signaux'],['profiles','Influenceurs'],['media','Médias'],['links','Liens officiels'],['news','Actualité'],['live','LIVE'],['update','MAJ PASS50'],['hub','Data Hub'],['quality','Contrôle qualité'],['ranking','Classement'],['data','Maintenance']];
    const menu=`<div class="admin-menu">${items.map(([id,label])=>`<button class="btn ${ui.adminTab===id?'primary':''}" data-admin-tab="${id}">${label}</button>`).join('')}</div>`;
    $('#adminBody').innerHTML=`<div class="admin-grid">${menu}<div class="admin-pane" id="adminPane"></div></div>`;
    renderAdminPane();
  };

  renderAdminPane=function(){if(ui.adminTab==='update')return deRenderMajPass50($('#adminPane'));if(ui.adminTab==='hub')return deRenderHub($('#adminPane'));if(ui.adminTab==='quality'&&typeof window.renderQualityPane==='function')return window.renderQualityPane();return fallbackRenderAdminPane();};

  function deEsc(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
  function deThreshold(){return Number(DE.hub?.threshold||90);}
  function deStatus(status,confidence){
    const c=Number(confidence||0),s=status||'empty',threshold=deThreshold();
    if(s==='verified'&&c>=threshold)return `<span class="de-status verified">✓ ${c} %</span>`;
    if(s==='conflict')return `<span class="de-status conflict">Conflit</span>`;
    if(s==='rejected')return `<span class="de-status rejected">Rejeté</span>`;
    if(c>0)return `<span class="de-status candidate">${c} %</span>`;
    return '<span class="de-status empty">Absent</span>';
  }
  function deTime(value){if(!value)return 'Jamais';const d=new Date(String(value).replace(' ','T')+'Z');return Number.isNaN(d.getTime())?deEsc(value):d.toLocaleString('fr-FR');}
  function deApplyVerifiedBirthsFromHub(){
    if(!DE.hub||!Array.isArray(DE.hub.profiles)||!Array.isArray(db?.profiles))return 0;
    const threshold=deThreshold();let changed=0;
    for(const item of DE.hub.profiles){
      const birth=item.birthBest||item.facts?.birth_date,date=String(item.birthDate||birth?.normalized_value||'').trim(),confidence=Number(birth?.confidence||item.quality?.birth||0),status=String(item.birthStatus||birth?.status||'');
      if(!date||status!=='verified'||confidence<threshold)continue;
      const p=db.profiles.find(x=>x.id===item.id);if(!p)continue;
      if(p.birthDate!==date||p.ageStatus!=='confirmed'||Number(p?.quality?.birth||0)!==confidence){
        p.birthDate=date;p.birthYear=Number(date.slice(0,4))||p.birthYear||null;p.ageStatus='confirmed';p.agePublic=p.agePublic!==false;p.quality=p.quality||{};p.quality.birth=confidence;p.dataEngine=p.dataEngine||{};p.dataEngine.verifiedFacts=[...new Set([...(p.dataEngine.verifiedFacts||[]),'birth_date'])];changed++;
      }
    }
    if(changed){localStorage.setItem(APP_KEY,JSON.stringify(db));if(window.__pass50CloudReady&&typeof scheduleCloudSync==='function')scheduleCloudSync();}
    return changed;
  }

  function deNormalizeBirthDate(value){
    const raw=String(value||'').trim().toLowerCase();if(!raw)return '';
    const cleaned=raw.replace(/\s+/g,' ').replace(/[.-]/g,'/');let y,m,d,match;
    if((match=cleaned.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/))){y=Number(match[1]);m=Number(match[2]);d=Number(match[3]);}
    else if((match=cleaned.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/))){d=Number(match[1]);m=Number(match[2]);y=Number(match[3]);}
    else return '';
    const dt=new Date(Date.UTC(y,m-1,d));if(dt.getUTCFullYear()!==y||dt.getUTCMonth()!==m-1||dt.getUTCDate()!==d)return '';
    return `${String(y).padStart(4,'0')}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
  }


  function deMajSavedStatus(){
    try{return JSON.parse(localStorage.getItem('pass50.maj.status.v1')||'null');}catch{return null;}
  }
  function deMajPersistStatus(status){
    try{localStorage.setItem('pass50.maj.status.v1',JSON.stringify(status));}catch{}
  }
  function deMajPercent(){
    if(!DE.majTarget)return DE.majRunning?2:0;
    return Math.max(0,Math.min(100,Math.round(DE.majSeen.size/DE.majTarget*100)));
  }
  function deRenderMajPass50(pane){
    const saved=DE.majLastResult||deMajSavedStatus();
    const last=saved?.finishedAt?new Date(saved.finishedAt).toLocaleString('fr-FR'):'Jamais';
    pane.innerHTML=`<div class="data-engine-shell">
      <div class="section-head"><div><div class="section-title">⚡ MAJ PASS50</div><div class="muted">Une seule action pour synchroniser les FI, collecter les données publiques disponibles, calculer les 15 critères, publier les scores et capturer le classement.</div></div></div>
      <div class="media-hint"><strong>Fonctionnement :</strong> le traitement parcourt les ${Number(DE.hub?.kpis?.profiles||db?.profiles?.length||134)} FI par lots de 5. Tu peux quitter cet onglet, mais garde la page PASS50 ouverte jusqu’au message de fin.</div>
      <div class="de-toolbar" style="margin-top:14px">
        <button class="btn primary" id="deMajPass50">${DE.majRunning?'MAJ EN COURS…':'LANCER LA MAJ PASS50'}</button>
        ${DE.majRunning?'<button class="btn danger" id="deStopMajPass50">ARRÊTER APRÈS CE LOT</button>':''}
      </div>
      <div id="deMajProgress" class="de-auto-box"></div>
      <div class="de-kpis" style="margin-top:12px">
        <div class="de-kpi"><strong>${Number(db?.profiles?.length||0)}</strong><span>FI ACTUELLES</span><small>Total chargé dans PASS50</small></div>
        <div class="de-kpi"><strong>15</strong><span>CRITÈRES</span><small>Moteur algorithmique PASS50</small></div>
        <div class="de-kpi"><strong>5</strong><span>PÉRIODES</span><small>2H · 24H · 48H · 7J · 15J</small></div>
        <div class="de-kpi"><strong>${deEsc(last)}</strong><span>DERNIÈRE MAJ</span><small>${saved?.status==='success'?'Terminée avec succès':saved?.status==='stopped'?'Arrêtée':'Aucune exécution complète'}</small></div>
      </div>
      <div class="media-hint" style="margin-top:14px"><strong>Étapes exécutées automatiquement :</strong><br>1. Synchronisation des 134 FI · 2. Collecte et conservation des preuves · 3. Calcul des 15 critères · 4. Écriture dans les scores · 5. Reclassement · 6. Publication · 7. Capture du classement.</div>
    </div>`;
    deDrawMajProgress();
  }
  function deDrawMajProgress(){
    const el=$('#deMajProgress');if(!el)return;
    const pct=deMajPercent(),done=DE.majSeen.size,target=DE.majTarget||Number(DE.hub?.kpis?.profiles||db?.profiles?.length||0);
    const stage=DE.majStage||'Prêt';
    const message=DE.majMessage||'Aucune mise à jour en cours.';
    el.innerHTML=`<div class="de-auto-line"><strong>${deEsc(stage)}</strong><span>${done}/${target||0} FI · ${pct} %</span></div><div class="de-progress de-progress-large"><i style="width:${pct}%"></i></div><div class="muted">${deEsc(message)}</div>`;
    const btn=$('#deMajPass50');if(btn){btn.disabled=DE.majRunning;btn.textContent=DE.majRunning?'MAJ EN COURS…':'LANCER LA MAJ PASS50';}
  }
  async function deRunMajPass50(){
    if(DE.majRunning)return;
    DE.majRunning=true;DE.majStopRequested=false;DE.majSeen=new Set();DE.majStartedAt=new Date().toISOString();DE.majLastResult=null;
    DE.majStage='1/7 · Synchronisation des FI';DE.majMessage='Envoi des fiches actuelles vers le registre serveur…';deRenderMajPass50($('#adminPane'));
    let totals={found:0,verified:0,published:0,captured:0,batches:0};
    try{
      if(typeof window.PASS50_STEP12_STATUS==='object'&&Number(window.PASS50_STEP12_STATUS.present||0)<7&&typeof window.p50EnsureStep12Profiles==='function')window.p50EnsureStep12Profiles();
      if(typeof syncCloudState==='function')await syncCloudState();
      const sync=await apiFetch('data-hub.php',{method:'POST',body:{action:'sync'}});
      DE.hub=sync.hub||DE.hub;DE.majTarget=Number(DE.hub?.kpis?.profiles||sync.syncedProfiles||db?.profiles?.length||0);
      DE.majStage='2/7 · Collecte et conservation';DE.majMessage='Le moteur parcourt les FI par lots de 5…';deDrawMajProgress();

      while(!DE.majStopRequested&&DE.majSeen.size<DE.majTarget){
        const data=await apiFetch('data-collect.php',{method:'POST',body:{limit:5,deep:true,publishVerified:true,excludeIds:[...DE.majSeen]}});
        const ids=(data.processedIds||[]).map(String);
        if(!ids.length)break;
        const before=DE.majSeen.size;ids.forEach(id=>DE.majSeen.add(id));
        totals.batches++;totals.found+=Number(data.found||0);totals.verified+=Number(data.verified||0);
        DE.hub=data.hub||DE.hub;
        DE.majStage='3/7 · Calcul des 15 critères';
        DE.majMessage=`Lot ${totals.batches} : ${ids.length} FI · ${Number(data.found||0)} donnée(s) trouvée(s) · scores recalculés et enregistrés.`;
        deDrawMajProgress();
        if(DE.majSeen.size===before)break;
        await new Promise(resolve=>setTimeout(resolve,180));
      }

      if(DE.majStopRequested){
        const result={status:'stopped',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,target:DE.majTarget,totals};
        DE.majLastResult=result;deMajPersistStatus(result);DE.majStage='MAJ arrêtée';DE.majMessage=`${DE.majSeen.size}/${DE.majTarget} FI traitées. Relance le bouton pour effectuer un nouveau tour complet.`;toast('MAJ PASS50 arrêtée après le lot en cours');return;
      }

      DE.majStage='4/7 · Publication des scores';DE.majMessage='Écriture des données vérifiées et des scores calculés dans l’état PASS50…';deDrawMajProgress();
      const published=await apiFetch('data-publish.php',{method:'POST',body:{}});totals.published=Number(published.publishedProfiles||0);DE.hub=published.hub||DE.hub;

      DE.majStage='5/7 · Rechargement et reclassement';DE.majMessage='Récupération de l’état serveur puis reclassement automatique…';deDrawMajProgress();
      if(typeof loadCloudState==='function')await loadCloudState();
      if(typeof render==='function')render();

      DE.majStage='6/7 · Synchronisation finale';DE.majMessage='Sauvegarde de l’état final et des nouvelles positions…';deDrawMajProgress();
      if(typeof syncCloudState==='function')await syncCloudState();

      DE.majStage='7/7 · Capture du classement';DE.majMessage='Enregistrement de la photographie du classement actuel…';deDrawMajProgress();
      try{const snap=await apiFetch('data-snapshot.php',{method:'POST',body:{period:ui.period}});totals.captured=Number(snap.captured||0);}catch(error){console.warn('Capture classement non bloquante',error);}

      await deLoadHub(true);
      const result={status:'success',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,target:DE.majTarget,totals,totalProfiles:Number(db?.profiles?.length||0),period:ui.period};
      DE.majLastResult=result;deMajPersistStatus(result);DE.majStage='MAJ PASS50 terminée';DE.majMessage=`${result.processed}/${result.target} FI parcourues · ${totals.found} donnée(s) trouvée(s) · ${totals.published} profil(s) publié(s) · classement actualisé.`;
      window.PASS50_MAJ_STATUS=result;
      toast(`MAJ PASS50 terminée · ${result.processed} FI traitées`);
    }catch(err){
      console.error('MAJ PASS50',err);
      const result={status:'error',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,target:DE.majTarget,totals,error:String(err?.message||err)};
      DE.majLastResult=result;deMajPersistStatus(result);DE.majStage='Erreur pendant la MAJ';DE.majMessage=result.error;window.PASS50_MAJ_STATUS=result;toast(result.error||'MAJ PASS50 impossible');
    }finally{
      DE.majRunning=false;DE.majStopRequested=false;
      if(ui.adminTab==='update')deRenderMajPass50($('#adminPane'));
    }
  }

  function deRenderHub(pane){
    pane.innerHTML=`<div class="data-engine-shell"><div class="media-hint"><strong>Moteur V22 :</strong> il travaille sur <strong>tous les profils recensés</strong>, même non classables. Il explore aussi les archives publiques d’écoles, universités, diplômes, listes d’anciens élèves et institutions, sans déduire une naissance lorsqu’elle n’est pas explicitement publiée. Il visite les comptes validés et calcule un score uniquement à partir de preuves récentes. Seules les données ≥ <strong>90 %</strong> sont publiées ; les photos restent à valider.</div><div class="de-toolbar"><button class="btn" id="deSync">Synchroniser les profils</button><button class="btn" id="deCollectBatch">Enrichir 5 profils</button><button class="btn primary" id="dePriority16">Actualiser les 16 prioritaires</button><button class="btn primary" id="deAutoAll">Enrichir toute la base</button><button class="btn danger" id="deStopAuto" style="display:none">Arrêter</button><button class="btn" id="dePublish">Publier les données ≥ 90 %</button><button class="btn" id="deSnapshot">Capturer le classement</button></div><div id="deHubContent" class="de-loading">Chargement du moteur de données…</div></div>`;
    deLoadHub();deSetAutoUi();
  }

  async function deLoadHub(force=false){
    if(DE.loading&&!force)return;DE.loading=true;
    try{DE.hub=await apiFetch('data-hub.php');DE.lastError='';const ages=deApplyVerifiedBirthsFromHub();deDrawHub();if(ages)render();}
    catch(err){DE.lastError=err.message||'Moteur indisponible';const el=$('#deHubContent');if(el)el.innerHTML=`<div class="de-error">${deEsc(DE.lastError)}<br><small>Vérifie que les fichiers API V19 sont déployés et que tu es connecté comme propriétaire.</small></div>`;}
    finally{DE.loading=false;}
  }

  function deSetAutoUi(){
    const start=$('#deAutoAll'),stop=$('#deStopAuto'),batch=$('#deCollectBatch');
    if(start){start.disabled=DE.autoRunning;start.textContent=DE.autoRunning?'Enrichissement en cours…':'Enrichir toute la base';}
    if(batch)batch.disabled=DE.autoRunning;
    if(stop)stop.style.display=DE.autoRunning?'inline-flex':'none';
  }
  function deAutoProgress(){
    const el=$('#deAutoProgress');if(!el)return;
    const done=DE.autoSeen.size,target=Math.max(DE.autoTarget,1),pct=Math.min(100,Math.round(done/target*100));
    el.innerHTML=`<div class="de-auto-line"><strong>${DE.autoRunning?'Enrichissement automatique en cours':'Enrichissement automatique'}</strong><span>${done}/${DE.autoTarget||0} profils · ${pct} %</span></div><div class="de-progress de-progress-large"><i style="width:${pct}%"></i></div><div class="muted">${deEsc(DE.autoMessage||'Le moteur traite en priorité les fiches jamais collectées, puis les plus anciennes.')}</div>`;
  }

  function deDrawHub(){
    const el=$('#deHubContent');if(!el||!DE.hub)return;
    const k=DE.hub.kpis||{},profiles=(DE.hub.profiles||[]).slice().sort((a,b)=>{if(!a.lastRun&&b.lastRun)return -1;if(a.lastRun&&!b.lastRun)return 1;return Number(a.completeness||0)-Number(b.completeness||0)||String(a.name||'').localeCompare(String(b.name||''),'fr');});
    el.innerHTML=`<div id="deAutoProgress" class="de-auto-box"></div><div class="de-kpis"><div class="de-kpi"><strong>${k.profiles||0}</strong><span>PROFILS RECENSÉS</span><small>${k.eligible||0} classables · ${k.pending||0} en vérification</small></div><div class="de-kpi"><strong>${k.neverCollected||0}</strong><span>JAMAIS COLLECTÉS</span><small>À traiter automatiquement</small></div><div class="de-kpi"><strong>${k.birthVerified||0}</strong><span>NAISSANCES VÉRIFIÉES</span><small>${k.birthCandidates||0} candidate(s)</small></div><div class="de-kpi"><strong>${k.socialVerified||0}</strong><span>FICHES AVEC RÉSEAUX</span><small>Au moins un lien ≥ ${DE.hub.threshold||90} %</small></div><div class="de-kpi"><strong>${k.photoCandidates||0}</strong><span>PHOTOS PROPOSÉES</span><small>Validation humaine requise</small></div><div class="de-kpi"><strong>${k.autoEnriched||0}</strong><span>FICHES ENRICHIES</span><small>Au moins une donnée trouvée</small></div></div><div class="admin-table-wrap"><table class="admin-table" style="min-width:1160px"><thead><tr><th>Influenceur</th><th>Statut</th><th>Complétude</th><th>Naissance</th><th>Réseaux</th><th>Infos automatiques</th><th>Dernière collecte</th><th></th></tr></thead><tbody>${profiles.map(deProfileRow).join('')}</tbody></table></div><div class="muted" style="font-size:10px">PASS50 Data Engine V${DE.hub.engineVersion||19} · actualisé ${deTime(DE.hub.generatedAt)} · seuil ${DE.hub.threshold||90} %</div>`;
    deAutoProgress();deSetAutoUi();
  }

  function deProfileRow(p){
    const birth=p.birthBest||p.facts?.birth_date,social=(p.socialLinks||[]).filter(x=>x.status==='verified'&&Number(x.confidence)>=deThreshold()),run=p.lastRun;
    const rankIndex=typeof ranking==='function'?ranking().findIndex(x=>x.id===p.id):-1,top50=rankIndex>=0&&rankIndex<50;
    const info=[];if(p.categoryBest)info.push('Catégorie');if(p.bioBest)info.push('Bio');if(p.educationBest)info.push('Parcours scolaire');if(p.nationalityBest)info.push('Nationalité');if(p.photoBest)info.push('Photo');
    return `<tr><td><strong>${deEsc(p.name)}</strong>${top50?'<span class="de-top50-pill">TOP 50</span>':''}<div class="hub-detail">${rankIndex>=0?'#'+(rankIndex+1)+' · ':''}${deEsc(p.handle||'')}</div></td><td>${p.eligible?'<span class="de-status verified">Classable</span>':'<span class="de-status candidate">Recensé</span>'}</td><td><div class="de-progress"><i style="width:${Number(p.completeness||0)}%"></i></div><div class="hub-detail">${Number(p.completeness||0)} %</div></td><td>${birth?deStatus(birth.status,birth.confidence):deStatus('empty',0)}</td><td>${social.length?`<span class="de-score ok">${social.length}</span> vérifié${social.length>1?'s':''}`:deStatus('empty',0)}</td><td>${info.length?`<div class="de-info-chips">${info.map(x=>`<span>${x}</span>`).join('')}</div>`:'<span class="muted">Aucune</span>'}</td><td><div class="de-run">${deTime(p.lastCollectedAt)}${run?.items_found?`<br>${Number(run.items_found)} donnée(s) trouvée(s)`:''}${run?.status==='error'?'<br><span style="color:#ff8080">Erreur de collecte</span>':''}</div></td><td><div class="de-row-actions"><button class="btn small de-collect-one" data-id="${deEsc(p.id)}">Enrichir</button><button class="btn small de-social" data-id="${deEsc(p.id)}">Réseaux</button><button class="btn small de-birth" data-id="${deEsc(p.id)}">Naissance</button></div></td></tr>`;
  }

  async function deAction(button,work,label){const old=button?.textContent;if(button){button.disabled=true;button.textContent=label||'Traitement…';}try{return await work();}finally{if(button){button.disabled=false;button.textContent=old;}}}
  async function deSync(btn){await deAction(btn,async()=>{const data=await apiFetch('data-hub.php',{method:'POST',body:{action:'sync'}});DE.hub=data.hub;deApplyVerifiedBirthsFromHub();deDrawHub();render();toast(`${data.syncedProfiles} profils synchronisés`);},'Synchronisation…');}
  async function deCollect(btn,profileId=''){
    await deAction(btn,async()=>{const data=await apiFetch('data-collect.php',{method:'POST',body:{profileId,limit:profileId?1:5,deep:true,publishVerified:true}});DE.hub=data.hub;deApplyVerifiedBirthsFromHub();deDrawHub();await loadCloudState();deApplyVerifiedBirthsFromHub();render();toast(`${data.processed} profil(s) enrichi(s) · ${data.found} donnée(s) trouvée(s) · ${data.verified} vérifiée(s)`);},'Enrichissement…');
  }
  async function deAutoEnrich(btn){
    if(DE.autoRunning)return;DE.autoRunning=true;DE.stopRequested=false;DE.autoSeen=new Set();DE.autoTarget=Number(DE.hub?.kpis?.profiles||0);DE.autoMessage='Démarrage du moteur…';deSetAutoUi();deAutoProgress();
    try{
      while(!DE.stopRequested&&DE.autoSeen.size<DE.autoTarget){
        const data=await apiFetch('data-collect.php',{method:'POST',body:{limit:5,deep:true,publishVerified:true,excludeIds:[...DE.autoSeen]}});
        const ids=(data.processedIds||[]).map(String);if(!ids.length){
          DE.autoMessage=DE.autoSeen.size>=DE.autoTarget?'Tous les profils ont été parcourus.':'Aucun autre profil disponible dans le registre actif.';
          break;
        }
        const before=DE.autoSeen.size;ids.forEach(id=>DE.autoSeen.add(id));
        DE.hub=data.hub;DE.autoMessage=`Dernier lot : ${data.processed} profil(s), ${data.found} donnée(s) trouvée(s), ${data.verified} vérifiée(s).`;deDrawHub();
        await loadCloudState();render();
        if(DE.autoSeen.size===before)break;
        await new Promise(resolve=>setTimeout(resolve,250));
      }
      const complete=DE.autoSeen.size>=DE.autoTarget;
      DE.autoMessage=DE.stopRequested?'Enrichissement arrêté après le lot en cours.':complete?'Tour complet terminé. Les données fiables ont été publiées.':`Parcours interrompu à ${DE.autoSeen.size}/${DE.autoTarget}. Relance le moteur pour reprendre.`;
      toast(DE.stopRequested?'Enrichissement arrêté':complete?`${DE.autoSeen.size} profils parcourus par le moteur`:`${DE.autoSeen.size}/${DE.autoTarget} profils parcourus`);
    }catch(err){console.error(err);DE.autoMessage=err.message||'Le moteur a rencontré une erreur.';toast(DE.autoMessage);}
    finally{DE.autoRunning=false;DE.stopRequested=false;deSetAutoUi();deAutoProgress();await deLoadHub(true);}
  }
  async function dePriority16(btn){await deAction(btn,async()=>{const data=await apiFetch('priority-refresh.php',{method:'POST',body:{}});DE.hub=data.hub;deApplyVerifiedBirthsFromHub();deDrawHub();await loadCloudState();deApplyVerifiedBirthsFromHub();render();toast(`${data.processed} profils prioritaires parcourus · ${data.classable} classables sur preuves récentes`);},'Actualisation des 16…');}
  async function dePublish(btn){await deAction(btn,async()=>{const data=await apiFetch('data-publish.php',{method:'POST',body:{}});DE.hub=data.hub;deApplyVerifiedBirthsFromHub();deDrawHub();await loadCloudState();deApplyVerifiedBirthsFromHub();render();toast(`${data.publishedProfiles} profils publiés`);},'Publication…');}
  async function deSnapshot(btn){await deAction(btn,async()=>{const data=await apiFetch('data-snapshot.php',{method:'POST',body:{period:ui.period}});toast(`${data.captured} positions enregistrées`);},'Capture…');}

  async function deOpenBirth(profileId){
    const pane=$('#adminPane');pane.innerHTML='<div class="de-loading">Chargement des sources de naissance…</div>';
    try{
      const data=await apiFetch('facts.php?profileId='+encodeURIComponent(profileId)),birthFacts=(data.facts||[]).filter(x=>x.fact_key==='birth_date'),evidence=(data.evidence||[]).filter(x=>x.fact_key==='birth_date');
      pane.innerHTML=`<div class="de-profile-head"><div><div class="section-title">Date de naissance · ${deEsc(data.profile.public_name)}</div><div class="muted">Le moteur cherche cette date automatiquement. Utilise ce formulaire seulement lorsqu’une source publique n’a pas été détectée ou pour résoudre un conflit.</div></div><button class="btn" data-admin-tab="hub">Retour au Data Hub</button></div><div class="de-link-card"><form id="deBirthForm" data-profile="${deEsc(profileId)}" class="form"><div class="two"><div class="field"><label>Date de naissance</label><input type="text" inputmode="numeric" autocomplete="off" name="value" placeholder="Ex. 12/08/1991" required><small>Formats : 12/08/1991, 12-08-1991 ou 1991-08-12.</small></div><div class="field"><label>Nom de la source</label><input name="sourceName" placeholder="Ex. site officiel, média" required></div></div><div class="field"><label>URL exacte de la source</label><input type="url" name="sourceUrl" placeholder="https://…" required></div><label class="de-confirm"><input type="checkbox" name="confirmedSource" required> Je confirme que cette source mentionne bien cette date.</label><button class="btn primary" type="submit">AJOUTER CETTE SOURCE</button></form></div><div class="section-head" style="margin-top:18px"><div class="section-title">Dates détectées</div><span class="muted">Seuil ${data.threshold||90} %</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Confiance</th><th>Sources</th><th>Statut</th></tr></thead><tbody>${birthFacts.length?birthFacts.map(f=>`<tr><td><strong>${deEsc(f.normalized_value)}</strong></td><td>${deStatus(f.status,f.confidence)}</td><td>${Number(f.evidence_count||0)}</td><td>${deEsc(f.status)}</td></tr>`).join(''):'<tr><td colspan="4" class="muted">Aucune date détectée.</td></tr>'}</tbody></table></div><div class="section-head" style="margin-top:18px"><div class="section-title">Sources enregistrées</div></div>${evidence.length?evidence.map(x=>`<div class="signal"><div><strong>${deEsc(x.source_name)}</strong><div>${deEsc(x.normalized_value)}</div><div class="muted">${deEsc(x.source_url||'')} · poids ${Number(x.source_weight||0)} %</div></div></div>`).join(''):'<div class="muted">Aucune source.</div>'}`;
    }catch(err){pane.innerHTML=`<div class="de-error">${deEsc(err.message)}</div><button class="btn" data-admin-tab="hub">Retour</button>`;}
  }

  async function deOpenSocial(profileId){
    DE.socialProfileId=profileId;const pane=$('#adminPane');pane.innerHTML='<div class="de-loading">Chargement des réseaux…</div>';
    try{
      const data=await apiFetch('social-links.php?profileId='+encodeURIComponent(profileId)),current=Object.fromEntries((data.links||[]).map(x=>[x.platform,x])),all=(DE.hub?.profiles||[]).slice().sort((a,b)=>a.name.localeCompare(b.name,'fr'));
      const options=all.map(p=>{const rank=typeof ranking==='function'?ranking().findIndex(x=>x.id===p.id):-1,label=(rank>=0&&rank<50?'TOP 50 · ':'')+(rank>=0?'#'+(rank+1)+' · ':'')+p.name;return `<option value="${deEsc(p.id)}" ${p.id===profileId?'selected':''}>${deEsc(label)}</option>`}).join(''),rank=typeof ranking==='function'?ranking().findIndex(x=>x.id===profileId):-1;
      pane.innerHTML=`<div class="de-social-shell"><div class="de-profile-head"><div><div class="section-title">Réseaux officiels · ${deEsc(data.profile.public_name)}${rank>=0&&rank<50?'<span class="de-top50-pill">TOP 50</span>':''}</div><div class="muted">Le moteur tente aussi de récupérer les liens depuis Wikidata, le site officiel et les données structurées. Une validation manuelle reste possible.</div></div><button class="btn" data-admin-tab="hub">Retour au Data Hub</button></div><div class="de-social-switcher"><label>Changer rapidement de FI<select id="deSocialProfileSelect">${options}</select></label><button class="btn" data-admin-tab="hub">Voir la liste complète</button></div><div class="de-links">${DE.platforms.map(platform=>deLinkCard(profileId,platform,current[platform])).join('')}</div></div>`;
    }catch(err){pane.innerHTML=`<div class="de-error">${deEsc(err.message)}</div><button class="btn" data-admin-tab="hub">Retour</button>`;}
  }

  function deLinkCard(profileId,platform,link){
    const status=link?.status||'empty',confidence=Number(link?.confidence||0),url=link?.url||'',icons={Instagram:'◎',TikTok:'♪',Facebook:'f',YouTube:'▶',Snapchat:'◉',X:'𝕏',Web:'↗'};
    return `<article class="de-link-card"><div class="de-link-head"><div class="de-platform-name"><span class="de-platform-icon">${icons[platform]||'•'}</span><strong>${deEsc(platform)}</strong></div>${deStatus(status,confidence)}</div><form class="de-link-form" data-profile="${deEsc(profileId)}" data-platform="${deEsc(platform)}"><input type="url" name="url" value="${deEsc(url)}" placeholder="Colle l’URL exacte du compte officiel" required><label class="de-confirm"><input type="checkbox" name="confirmedOfficial" required> Je confirme qu’il s’agit du compte officiel de cette FI.</label><button class="btn primary" type="submit">VALIDER LE LIEN</button></form>${url?`<div class="de-link-meta">${deEsc(url)}${link?.checked_at?` · contrôlé ${deTime(link.checked_at)}`:''}</div><div class="de-link-actions"><a class="btn small" href="${deEsc(url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small danger de-reject-link" data-profile="${deEsc(profileId)}" data-platform="${deEsc(platform)}">Rejeter</button></div>`:''}</article>`;
  }

  document.addEventListener('click',async e=>{
    try{
      if(e.target.id==='deMajPass50')await deRunMajPass50();
      if(e.target.id==='deStopMajPass50'){DE.majStopRequested=true;DE.majMessage='Arrêt demandé : le lot en cours se termine…';deDrawMajProgress();}
      if(e.target.id==='deSync')await deSync(e.target);
      if(e.target.id==='deCollectBatch')await deCollect(e.target);
      if(e.target.id==='dePriority16')await dePriority16(e.target);
      if(e.target.id==='deAutoAll')await deAutoEnrich(e.target);
      if(e.target.id==='deStopAuto'){DE.stopRequested=true;DE.autoMessage='Arrêt demandé : le lot en cours se termine…';deAutoProgress();}
      if(e.target.id==='dePublish')await dePublish(e.target);
      if(e.target.id==='deSnapshot')await deSnapshot(e.target);
      if(e.target.matches('.de-collect-one'))await deCollect(e.target,e.target.dataset.id);
      if(e.target.matches('.de-social'))await deOpenSocial(e.target.dataset.id);
      if(e.target.matches('.de-birth'))await deOpenBirth(e.target.dataset.id);
      if(e.target.matches('.de-reject-link')){if(!confirm('Rejeter ce lien officiel ?'))return;await apiFetch('social-links.php',{method:'POST',body:{action:'reject',profileId:e.target.dataset.profile,platform:e.target.dataset.platform}});await deOpenSocial(e.target.dataset.profile);toast('Lien rejeté');}
    }catch(err){console.error(err);toast(err.message||'Action impossible');}
  });
  document.addEventListener('change',async e=>{if(e.target.id==='deSocialProfileSelect')await deOpenSocial(e.target.value);});
  document.addEventListener('submit',async e=>{
    if(e.target.id==='deBirthForm'){
      e.preventDefault();const form=e.target,fd=new FormData(form),button=form.querySelector('button[type=submit]');
      try{await deAction(button,async()=>{const normalized=deNormalizeBirthDate(fd.get('value'));if(!normalized)throw new Error('Date invalide. Utilise JJ/MM/AAAA ou AAAA-MM-JJ.');if(fd.get('confirmedSource')!=='on')throw new Error('Confirme que la source mentionne bien cette date.');await apiFetch('facts.php',{method:'POST',body:{profileId:form.dataset.profile,factKey:'birth_date',value:normalized,sourceName:String(fd.get('sourceName')||''),sourceUrl:String(fd.get('sourceUrl')||''),confirmedSource:true}});await loadCloudState();render();await deOpenBirth(form.dataset.profile);toast('Source de naissance ajoutée');},'Enregistrement…');}catch(err){console.error(err);toast(err.message||'Source refusée');}return;
    }
    if(!e.target.matches('.de-link-form'))return;e.preventDefault();const form=e.target,fd=new FormData(form),button=form.querySelector('button[type=submit]');
    try{await deAction(button,async()=>{const data=await apiFetch('social-links.php',{method:'POST',body:{action:'save',profileId:form.dataset.profile,platform:form.dataset.platform,url:String(fd.get('url')||''),confirmedOfficial:fd.get('confirmedOfficial')==='on'}});if(!data.confirmed)throw new Error('La confirmation du compte officiel est obligatoire.');await loadCloudState();render();await deOpenSocial(form.dataset.profile);toast('Lien officiel validé');},'Vérification…');}catch(err){console.error(err);toast(err.message||'Lien non validé');}
  });
  window.PASS50Maj={run:deRunMajPass50,status:()=>DE.majLastResult||deMajSavedStatus(),stop:()=>{DE.majStopRequested=true;}};
})();
