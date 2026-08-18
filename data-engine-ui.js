(function(){
  window.majPass50Running=Boolean(window.majPass50Running);
  const fallbackRenderAdminPane=renderAdminPane;
  const DE={hub:null,intelligence:null,metricsDiagnostic:null,metricsDiagnosticLoading:false,rankingLab:null,rankingLabPeriod:'2H',rankingLabLoading:false,rankingLabView:'current',rankingCalibration:null,rankingCalibrationLoading:false,rankingCalibrationRuns:24,rankingHealth:null,rankingHealthLoading:false,members:null,membersLoading:false,membersQuery:'',membersTimer:null,loading:false,lastError:'',platforms:['Instagram','TikTok','Facebook','YouTube','Snapchat','X','Web'],socialProfileId:'',autoRunning:false,stopRequested:false,autoSeen:new Set(),autoTarget:0,autoMessage:'',majRunning:false,majStopRequested:false,majSeen:new Set(),majTarget:0,majStage:'',majMessage:'',majStartedAt:null,majLastResult:null};
  const ADMIN_ITEMS=[
    ['adminhome','Accueil'],['todo','A faire !'],['members','Membres'],['signals','Signaux'],['profiles','Influenceurs'],['media','Médias'],
    ['links','Liens officiels'],['news','Actualité'],['live','LIVE'],['pronostics','Pronostics'],['update','MAJ PASS50'],
    ['metricsdiag','Diagnostic métriques'],['intelligence','PASS50 Intelligence'],['hub','Data Hub'],
    ['quality','Contrôle qualité'],['rankinglab','Classement métrique'],['ranking','Classement'],['data','Maintenance']
  ];
  const ADMIN_DESCRIPTIONS={
    adminhome:'Vue d’ensemble et accès rapide à tous les outils administratifs.',
    todo:'Alertes cloche + push · clôtures, brouillons, médias, liens, LIVE…',
    members:'Voir les inscriptions et attribuer un accès administration (rôle admin).',
    signals:'Valider les signaux et événements détectés.',profiles:'Créer et modifier les fiches des influenceurs.',
    media:'Contrôler les photos et couvertures proposées.',links:'Vérifier les comptes officiels des plateformes.',
    news:'Rechercher et valider les contenus déclencheurs.',live:'Superviser les directs et leur disponibilité.',
    pronostics:'Créer les questions + cotes dans les 3 thèmes (influenceurs · artiste/sportif · actualité).',
    update:'Synchroniser, calculer et publier les données validées.',metricsdiag:'Inspecter le pipeline métrique en lecture seule.',
    intelligence:'Consulter les tendances et diagnostics éditoriaux.',hub:'Contrôler la complétude et les preuves des fiches.',
    quality:'Repérer les données manquantes ou incohérentes.',rankinglab:'Calculer MR‑V1.0 puis publier le classement public (avec backup).',ranking:'Prévisualiser et publier le classement.',
    data:'Sauvegarder, restaurer et diagnostiquer les données.'
  };

  renderAdmin=function(){
    const menu=`<div class="admin-menu">${ADMIN_ITEMS.map(([id,label])=>`<button class="btn ${ui.adminTab===id?'primary':''}" data-admin-tab="${id}"${id==='members'?' style="border-color:rgba(183,255,0,.55);color:var(--lime)"':''}>${label}</button>`).join('')}</div>`;
    $('#adminBody').innerHTML=`<div class="admin-grid">${menu}<div class="admin-pane" id="adminPane"></div></div>`;
    renderAdminPane();
  };

  renderAdminPane=function(){if(ui.adminTab==='adminhome')return deRenderAdminHome($('#adminPane'));if(ui.adminTab==='todo')return deRenderAdminTodo($('#adminPane'));if(ui.adminTab==='members')return deRenderMembersAdmin($('#adminPane'));if(ui.adminTab==='pronostics')return deRenderPronosticsAdmin($('#adminPane'));if(ui.adminTab==='update')return deRenderMajPass50($('#adminPane'));if(ui.adminTab==='metricsdiag')return deRenderMetricsDiagnostic($('#adminPane'));if(ui.adminTab==='rankinglab')return deRenderRankingLab($('#adminPane'));if(ui.adminTab==='intelligence')return deRenderIntelligence($('#adminPane'));if(ui.adminTab==='hub')return deRenderHub($('#adminPane'));if(ui.adminTab==='quality'&&typeof window.renderQualityPane==='function')return window.renderQualityPane();return fallbackRenderAdminPane();};

  function deEsc(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
  function deTodoFmtTime(v){
    if(!v) return '—';
    const d=new Date(v);
    return Number.isNaN(d.getTime())?'—':d.toLocaleString('fr-FR',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'});
  }
  window.p50CollectAdminTodoTasks=async function p50CollectAdminTodoTasks(){
    const allTasks=[];
    const now=Date.now();
    const withinMs=6*3600000;
    try{
      const items=(await apiFetch('prono-admin-list.php?limit=120')||{}).items||[];
      const locked=items.filter(i=>String(i.status||'')==='locked');
      locked.sort((a,b)=>new Date(a.closesAt||0)-new Date(b.closesAt||0));
      for(const item of locked.slice(0,20)){
        allTasks.push({id:`prono-${item.id}`,title:item.title||item.id||'Prono',meta:[item.voteCount!=null?`${Number(item.voteCount)} vote(s)`:'','clôturé · attribuer les points'].filter(Boolean).join(' · '),priority:'urgent',tags:['prono'],openTab:'pronostics',scroll:'resolveId'});
      }
      const closesSoon=items.filter(i=>{
        if(String(i.status||'')!=='open') return false;
        if(!i.closesAt) return false;
        const t=new Date(i.closesAt).getTime();
        return Number.isFinite(t)&&t>=now&&t-now<=withinMs;
      });
      closesSoon.sort((a,b)=>new Date(a.closesAt||0)-new Date(b.closesAt||0));
      for(const item of closesSoon.slice(0,20)){
        allTasks.push({id:`prono-${item.id}`,title:item.title||item.id||'Prono',meta:[item.voteCount!=null?`${Number(item.voteCount)} vote(s)`:'',`clôture bientôt · ${deTodoFmtTime(item.closesAt)}`].filter(Boolean).join(' · '),priority:'must',tags:['prono'],openTab:'pronostics',scroll:'qList'});
      }
      const drafts=items.filter(i=>String(i.status||'')==='draft');
      drafts.sort((a,b)=>new Date(b.createdAt||0)-new Date(a.createdAt||0));
      for(const item of drafts.slice(0,12)){
        allTasks.push({id:`prono-${item.id}`,title:item.title||item.id||'Brouillon prono',meta:'brouillon · à publier (open)',priority:'plan',tags:['prono'],openTab:'pronostics',scroll:'freeForm'});
      }
    }catch(_e){}
    try{
      const live=await apiFetch('live-status.php?mode=quick');
      const radar=live?.radar||{};
      const lastScanAt=radar.lastScanAt;
      const ageMs=lastScanAt?(now-new Date(lastScanAt).getTime()):Infinity;
      const coverage=Number(radar.coveragePercent);
      if(!lastScanAt||!Number.isFinite(ageMs)){
        allTasks.push({id:'live-radar',title:'LIVE · relancer le radar',meta:'scan non disponible',priority:'urgent',tags:['live'],openTab:'live',scroll:''});
      }else if(ageMs>12*3600000){
        allTasks.push({id:'live-radar',title:'LIVE · relancer le radar',meta:`dernier scan ${deTodoFmtTime(lastScanAt)}`,priority:'urgent',tags:['live'],openTab:'live',scroll:''});
      }else if(Number.isFinite(coverage)&&coverage<90){
        allTasks.push({id:'live-radar',title:'LIVE · couverture à améliorer',meta:`coverage ${coverage}%`,priority:'must',tags:['live'],openTab:'live',scroll:''});
      }
    }catch(_e){}
    try{
      const profiles=db?.profiles||[];
      const events=db?.events||[];
      const pendingPhotos=profiles.filter(p=>['pending','missing','rejected'].includes(String(p.photoStatus||''))).length;
      const pendingCovers=events.filter(e=>['pending','missing','rejected'].includes(String(e.coverStatus||''))).length;
      if(pendingPhotos>0){
        allTasks.push({id:'media-photos',title:`Médias · photos à valider (${pendingPhotos})`,meta:'Influenceurs (photoStatus)',priority:pendingPhotos>40?'urgent':'must',tags:['media'],openTab:'media',scroll:''});
      }
      if(pendingCovers>0){
        allTasks.push({id:'media-covers',title:`Médias · couvertures à valider (${pendingCovers})`,meta:'Éléments déclencheurs (coverStatus)',priority:pendingCovers>40?'urgent':'must',tags:['media'],openTab:'media',scroll:''});
      }
    }catch(_e){}
    try{
      const profiles=db?.profiles||[];
      const linkIssues=profiles.filter(p=>{
        const checks=p?.linkChecks||{};
        const vals=Object.values(checks||{});
        if(!vals.length) return false;
        return vals.some(v=>{
          const st=String(v?.status||'');
          if(!st) return true;
          return !['owner_verified','manual_verified','verified','ok','blocked_but_exists','manual_owner'].includes(st);
        });
      }).length;
      if(linkIssues>0){
        allTasks.push({id:'links-audit',title:`Liens · vérifications en attente (${linkIssues})`,meta:'Statut incomplet / rejeté',priority:linkIssues>30?'urgent':'must',tags:['links'],openTab:'links',scroll:''});
      }
    }catch(_e){}
    return allTasks;
  };
  function deRenderAdminHome(pane){
    pane.innerHTML=`<div class="de-admin-home">
      <div class="section-head">
        <div>
          <div class="section-title">ACCUEIL DE L’ADMINISTRATION</div>
          <div class="muted">Pilotez les données, les fiches, les métriques et la publication de PASS50.</div>
        </div>
      </div>
      <div class="de-home-members" id="deHomeMembersCard" style="border:1px solid rgba(183,255,0,.45);border-radius:16px;padding:16px;background:#0c100c">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <div class="section-title" style="margin:0">MEMBRES INSCRITS</div>
            <div class="muted" id="deHomeMembersWho">Les plus récents en haut. Passe un compte en Administrateur pour lui ouvrir l’admin.</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn primary" data-admin-tab="members">Liste complète</button>
            <a class="btn" href="./admin-membres.html">Page dédiée →</a>
          </div>
        </div>
        <div id="deHomeMembersBody" class="muted" style="margin-top:12px">Chargement des inscriptions…</div>
      </div>
      <div class="de-admin-home-grid">${ADMIN_ITEMS.filter(([id])=>id!=='adminhome'&&id!=='todo').map(([id,label])=>`<button class="de-admin-home-card" data-admin-tab="${id}"><strong>${deEsc(label)}</strong><span>${deEsc(ADMIN_DESCRIPTIONS[id])}</span><i aria-hidden="true">Ouvrir →</i></button>`).join('')}</div>
    </div>`;
    deLoadHomeMembers();
  }
  function deRoleLabel(role){
    return {owner:'Propriétaire',admin:'Administrateur',editor:'Éditeur',verifier:'Vérificateur',member:'Membre'}[role]||role||'Membre';
  }
  function deFmtMemberDate(iso){
    if(!iso) return '—';
    const d=new Date(iso);
    return Number.isNaN(d.getTime())?'—':d.toLocaleString('fr-FR',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  }
  function deMembersViewerLine(data){
    const me=typeof currentUser==='function'?currentUser():null;
    const viewer=data?.viewer||{};
    const name=viewer.displayName||me?.displayName||viewer.email||me?.email||'compte';
    const role=deRoleLabel(viewer.role||me?.role);
    const canAssign=Boolean(data?.canAssignRoles);
    return `Connecté : ${name} · ${role}${canAssign?' · tu peux attribuer Administrateur':' · lecture seule'}`;
  }
  function deMembersTableHtml(data, limit){
    const stats=data.stats||{};
    const all=Array.isArray(data.items)?data.items:[];
    const items=limit?all.slice(0,limit):all;
    const canAssign=Boolean(data.canAssignRoles);
    const me=typeof currentUser==='function'?currentUser():null;
    const myId=String(me?.id||'');
    const kpis=[['Inscrits',stats.total??all.length],['7 derniers jours',stats.last7d??0],['E-mail confirmé',stats.confirmed??0],['Administrateurs',stats.admins??0]].map(([label,value])=>`<div><strong>${deEsc(value)}</strong><span>${deEsc(label)}</span></div>`).join('');
    const rows=items.map(item=>{
      const role=String(item.role||'member');
      const isOwner=role==='owner';
      const isMe=String(item.id)===myId;
      const select=(!canAssign||isOwner||isMe)
        ? `<span class="de-members-badge ${isOwner?'ok':''}">${deEsc(deRoleLabel(role))}</span>`
        : `<select class="de-members-role" data-member-role="${deEsc(item.id)}" data-current="${deEsc(role)}">
            <option value="member" ${role==='member'?'selected':''}>Membre</option>
            <option value="admin" ${role==='admin'?'selected':''}>Administrateur</option>
          </select>`;
      return `<tr>
        <td><strong>${deEsc(item.displayName||'—')}</strong><div class="muted" style="font-size:11px">${deEsc(item.email||'')}</div></td>
        <td>${select}</td>
        <td><span class="de-members-badge ${item.emailConfirmed?'ok':'wait'}">${item.emailConfirmed?'Confirmé':'En attente'}</span></td>
        <td>${deEsc(deFmtMemberDate(item.createdAt))}</td>
      </tr>`;
    }).join('');
    return `<div class="de-members-kpis">${kpis}</div>
      ${canAssign?'':'<p class="muted" style="margin:0 0 12px">Lecture seule : demande au propriétaire d’attribuer les rôles.</p>'}
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Personne</th><th>Rôle</th><th>Compte</th><th>Inscription</th></tr></thead>
      <tbody>${rows||'<tr><td colspan="4">Aucun membre pour cette recherche.</td></tr>'}</tbody></table></div>
      ${limit&&all.length>items.length?`<p class="muted" style="margin:10px 0 0">+ ${all.length-items.length} autre(s) — ouvre la liste complète.</p>`:''}`;
  }
  function deEnsureMembersStyles(){
    if(document.getElementById('deMembersStyles'))return;
    const style=document.createElement('style');style.id='deMembersStyles';
    style.textContent=`.de-members-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.de-members-toolbar input{flex:1;min-width:180px;padding:10px 12px;border-radius:12px;border:1px solid #293129;background:#0a0d0a;color:#fff;font:inherit}.de-members-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin:0 0 14px}.de-members-kpis div{border:1px solid var(--line);border-radius:14px;padding:12px;background:#0c100c}.de-members-kpis strong{display:block;font-size:22px;color:var(--lime)}.de-members-kpis span{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.06em}.de-members-role{padding:8px 10px;border-radius:10px;border:1px solid #293129;background:#0a0d0a;color:#fff;font:inherit;min-width:150px}.de-members-role:disabled{opacity:.55}.de-members-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid #3a453a}.de-members-badge.ok{color:#b7ff00}.de-members-badge.wait{color:#ffc065}`;
    document.head.appendChild(style);
  }
  async function deLoadHomeMembers(){
    deEnsureMembersStyles();
    const box=document.getElementById('deHomeMembersBody');
    const who=document.getElementById('deHomeMembersWho');
    if(!box||ui.adminTab!=='adminhome')return;
    const me=typeof currentUser==='function'?currentUser():null;
    if(who) who.textContent=me?`Connecté : ${me.displayName||me.email||'compte'} · ${deRoleLabel(me.role)} · chargement…`:'Vérification de la connexion…';
    if(typeof CLOUD!=='undefined'&&CLOUD.enabled&&!CLOUD.token){
      if(who) who.textContent='Session API absente.';
      box.innerHTML=`<div class="de-error">Tu es peut-être connecté en local, mais pas à la base PASS50. Déconnecte-toi, reconnecte-toi avec le compte propriétaire, puis rouvre Administration.</div>`;
      return;
    }
    try{
      const data=await apiFetch('admin-users.php?limit=40');
      DE.members=data;
      if(who) who.textContent=deMembersViewerLine(data)+' · les plus récents en haut.';
      if(!(data.items||[]).length){
        box.innerHTML='<p class="muted" style="margin:0">Aucun compte trouvé en base pour le moment.</p>';
        return;
      }
      box.innerHTML=deMembersTableHtml(data,12);
    }catch(error){
      const text=String(error.message||'Impossible de charger les membres');
      if(who) who.textContent=text;
      box.innerHTML=`<div class="de-error">${deEsc(text)}<p style="margin:8px 0 0">Ouvre <a href="./admin-membres.html" style="color:var(--lime)">la page Membres</a> après t’être reconnecté sur pass50.store.</p></div>`;
    }
  }
  function deRenderMembersAdmin(pane){
    pane.innerHTML=`<div class="de-members">
      <div class="section-head"><div><button type="button" class="btn admin-view-home" data-admin-tab="adminhome">← Accueil administration</button>
        <div class="section-title" style="margin-top:10px">MEMBRES</div>
        <div class="muted">Inscriptions récentes en haut. Seul le <strong>propriétaire</strong> peut attribuer le rôle Administrateur (accès à l’admin).</div>
      </div></div>
      <div class="de-members-toolbar">
        <input id="deMembersSearch" type="search" placeholder="Rechercher un nom ou un e-mail…" value="${deEsc(DE.membersQuery||'')}">
        <button type="button" class="btn" id="deMembersRefresh">Actualiser</button>
      </div>
      <div id="deMembersBody"><div class="muted">Chargement des inscriptions…</div></div>
    </div>`;
    deEnsureMembersStyles();
    deLoadMembers();
  }
  function deDrawMembersBody(){
    const box=document.getElementById('deMembersBody');
    if(!box||ui.adminTab!=='members')return;
    if(DE.membersLoading&&!DE.members){box.innerHTML='<div class="muted">Chargement des inscriptions…</div>';return;}
    const data=DE.members||{};
    if(data.error){box.innerHTML=`<div class="de-error">${deEsc(data.error)}</div>`;return;}
    box.innerHTML=`<p class="muted" style="margin:0 0 12px">${deEsc(deMembersViewerLine(data))}</p>`+deMembersTableHtml(data);
  }
  async function deLoadMembers(force=false){
    if(DE.membersLoading&&!force)return;
    DE.membersLoading=true;
    deDrawMembersBody();
    try{
      const q=encodeURIComponent(DE.membersQuery||'');
      DE.members=await apiFetch(`admin-users.php?limit=120&q=${q}`);
    }catch(error){
      DE.members={error:error.message||'Impossible de charger les membres'};
    }finally{
      DE.membersLoading=false;
      deDrawMembersBody();
      if(ui.adminTab==='adminhome')deLoadHomeMembers();
    }
  }
  function deRenderAdminTodo(pane){
    const TODO_KEY='pass50_admin_todo_v1';
    pane.innerHTML=`<div class="de-admin-home">
      <div class="section-head">
        <div>
          <button type="button" class="btn admin-view-home" data-admin-tab="adminhome">← Accueil administration</button>
          <div class="section-title" style="margin-top:10px">A FAIRE !</div>
          <div class="muted">Alertes cloche + push · cochez pour marquer “terminé”.</div>
        </div>
      </div>

      <div class="de-admin-todo">
        <div class="de-admin-todo-head">
          <div class="de-admin-todo-filters" aria-label="Filtres tâches">
            <input id="deTodoSearch" type="search" placeholder="Rechercher (ex: prono, live, médias…)" />
            <select id="deTodoTag">
              <option value="all" selected>Toutes</option>
              <option value="prono">Prono</option>
              <option value="live">LIVE</option>
              <option value="media">Médias</option>
              <option value="links">Liens</option>
            </select>
          </div>
        </div>
        <div class="de-admin-todo-grid" role="region" aria-label="Tâches prioritaires">
          <section class="de-admin-todo-col" data-priority="urgent">
            <div class="de-admin-todo-col-title"><span class="de-admin-pill urgent">Urgent</span></div>
            <div class="de-admin-todo-list" id="deTodoUrgent"></div>
          </section>
          <section class="de-admin-todo-col" data-priority="must">
            <div class="de-admin-todo-col-title"><span class="de-admin-pill must">À faire absolument</span></div>
            <div class="de-admin-todo-list" id="deTodoMust"></div>
          </section>
          <section class="de-admin-todo-col" data-priority="plan">
            <div class="de-admin-todo-col-title"><span class="de-admin-pill plan">À prévoir</span></div>
            <div class="de-admin-todo-list" id="deTodoPlan"></div>
          </section>
        </div>
        <div class="muted" style="font-size:12px;margin-top:8px">Auto-refresh à l’ouverture · alertes cloche toutes les 5 min.</div>
      </div>
    </div>`;

    // CSS léger (injecté une fois).
    if(!document.getElementById('deAdminTodoStyles')){
      const style=document.createElement('style');
      style.id='deAdminTodoStyles';
      style.textContent=`
        .de-admin-todo{border:1px solid var(--line);border-radius:16px;padding:14px 14px 10px;margin:14px 0;background:rgba(11,15,11,.25)}
        .de-admin-todo-head{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
        .de-admin-todo-filters{display:grid;grid-template-columns:1fr 160px;gap:10px;margin-top:10px}
        .de-admin-todo-filters input,.de-admin-todo-filters select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #293129;background:#0a0d0a;color:#fff;font:inherit}
        .de-admin-todo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .de-admin-todo-col-title{margin-bottom:8px}
        .de-admin-pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:1000;border:1px solid rgba(255,255,255,.15)}
        .de-admin-pill.urgent{color:#ff6b6b;border-color:rgba(255,107,107,.45);background:rgba(255,107,107,.08)}
        .de-admin-pill.must{color:#ffb050;border-color:rgba(255,176,80,.45);background:rgba(255,176,80,.08)}
        .de-admin-pill.plan{color:#b7ff00;border-color:rgba(183,255,0,.45);background:rgba(183,255,0,.08)}
        .de-admin-todo-list{display:grid;gap:8px}
        .de-admin-todo-item{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:start;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(10,13,10,.45)}
        .de-admin-todo-item input{width:auto;margin:0;transform:translateY(2px)}
        .de-admin-todo-title{font-weight:1000;font-size:13px;line-height:1.25}
        .de-admin-todo-meta{font-size:11px;color:var(--muted);margin-top:3px;line-height:1.3}
        .de-admin-todo-open{width:auto;margin:0;padding:8px 12px;border-radius:12px;border:1px solid #ff6b00;background:#ff6b00;color:#050705;font-weight:1000;font-size:12px;white-space:nowrap;cursor:pointer}
        .de-admin-todo-open.must{border-color:#ffb050;background:#ffb050}
        .de-admin-todo-open.plan{border-color:#b7ff00;background:#b7ff00}
      `;
      document.head.appendChild(style);
    }

    if(pane&&!pane.__deAdminTodoBound){
      pane.__deAdminTodoBound=true;
      pane.addEventListener('change',async(e)=>{
        const t=e.target;
        if(!(t instanceof HTMLInputElement) || !t.dataset.adminTodoId) return;
        const id=String(t.dataset.adminTodoId||'');
        const storage=localStorage.getItem(TODO_KEY);
        const state=storage?JSON.parse(storage):{done:{}};
        state.done=state.done||{};
        if(t.checked) state.done[id]=new Date().toISOString();
        else delete state.done[id];
        localStorage.setItem(TODO_KEY,JSON.stringify(state));
      });
    }

    async function loadAdminTodo(){
      const urgentEl=document.getElementById('deTodoUrgent');
      const mustEl=document.getElementById('deTodoMust');
      const planEl=document.getElementById('deTodoPlan');
      if(!urgentEl||!mustEl||!planEl) return;
      const state=(()=>{try{return JSON.parse(localStorage.getItem(TODO_KEY)||'{}')||{}}catch{return {}}})();
      state.done=state.done||{};

      const setList=(el,tasks,emptyMsg)=>{
        if(!tasks.length){
          el.innerHTML=`<div class="muted" style="font-size:12px;padding:4px 2px">${deEsc(emptyMsg)}</div>`;
          return;
        }
        el.innerHTML=tasks.map(t=>{
          const done=Boolean(state.done?.[t.id]);
          const pri=String(t.priority||'plan');
          const openClass=pri==='must'?'must':(pri==='plan'?'plan':'');
          const tags=Array.isArray(t.tags)&&t.tags.length?t.tags.map(x=>String(x)).join(', '):'';
          const meta=String([t.meta||'',tags?`Tags: ${tags}`:''].filter(Boolean).join(' · '));
          return `<div class="de-admin-todo-item">
            <input type="checkbox" ${done?'checked':''} data-admin-todo-id="${deEsc(t.id)}" aria-label="Terminé : ${deEsc(t.title)}">
            <div>
              <div class="de-admin-todo-title">${deEsc(t.title)}</div>
              <div class="de-admin-todo-meta">${deEsc(meta)}</div>
            </div>
            <button type="button" class="de-admin-todo-open ${openClass}" data-admin-tab="${deEsc(t.openTab||'adminhome')}" data-scroll="${deEsc(t.scroll||'')}">Ouvrir →</button>
          </div>`;
        }).join('');
      };

      let allTasks=[];
      try{
        allTasks=await window.p50CollectAdminTodoTasks();
      }catch(_e){}

      const searchEl=document.getElementById('deTodoSearch');
      const tagEl=document.getElementById('deTodoTag');
      const render=()=>{
        const q=String(searchEl?.value||'').trim().toLowerCase();
        const tag=String(tagEl?.value||'all');
        const filteredTasks=allTasks.filter(t=>{
          const tagsText=(t.tags||[]).join(' ');
          const text=String([t.title,t.meta,tagsText].filter(Boolean).join(' · ')).toLowerCase();
          const okQ=!q||text.includes(q);
          const okTag=(tag==='all')||((t.tags||[]).includes(tag));
          return okQ&&okTag;
        });
        const urgent=filteredTasks.filter(t=>String(t.priority||'plan')==='urgent').slice(0,12);
        const must=filteredTasks.filter(t=>String(t.priority||'plan')==='must').slice(0,12);
        const plan=filteredTasks.filter(t=>String(t.priority||'plan')==='plan').slice(0,12);
        setList(urgentEl,urgent,'Rien en urgent');
        setList(mustEl,must,'Rien à faire absolument');
        setList(planEl,plan,'Rien à prévoir');
      };
      if(searchEl) searchEl.addEventListener('input',render);
      if(tagEl) tagEl.addEventListener('change',render);
      render();
      if(typeof window.p50AdminNotifyAfterTodoLoad==='function'){
        window.p50AdminNotifyAfterTodoLoad(allTasks,state.done||{});
      }
    }

    // Auto-refresh immédiat.
    loadAdminTodo();
  }
  function deRenderPronosticsAdmin(pane){
    pane.innerHTML=`<div class="section-head"><div><button type="button" class="btn admin-view-home" data-admin-tab="adminhome">← Accueil administration</button><div class="section-title" style="margin-top:10px">PRONOSTICS</div><div class="muted">3 thèmes · questions + cotes que tu écris toi-même.</div></div></div>
      <div class="pref" style="margin-top:16px;display:flex;flex-direction:column;gap:12px;align-items:flex-start">
        <p class="muted" style="margin:0;max-width:42rem;line-height:1.45">Dans l’atelier : crée les questions avec cotes pour <strong>People influenceurs</strong>, <strong>People Artiste/sportif</strong> et <strong>People Actualité</strong>, puis publie (open).</p>
        <a class="btn primary" href="./admin-pronostics.html">Ouvrir l’atelier Pronostics →</a>
        
        <a class="btn" href="./admin-membres.html">Page Membres dédiée →</a>
        <a class="btn" href="./pronostics.html?v=83">Voir la page joueurs</a>
      </div>`;
  }
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
  function deObsAge(value){
    if(!value)return 'Jamais';
    const minutes=Number(value.minutes||0);
    if(minutes<60)return `il y a ${minutes} min`;
    if(minutes<1440)return `il y a ${Number(value.hours||0).toLocaleString('fr-FR',{maximumFractionDigits:1})} h`;
    return `il y a ${Number(value.days||0).toLocaleString('fr-FR',{maximumFractionDigits:1})} j`;
  }
  function deObsNumber(value){return Number(value||0).toLocaleString('fr-FR');}
  function deObsStatus(status){
    const values={operational:['Opérationnel','verified'],incomplete:['Incomplet','candidate'],blocked:['Bloqué','conflict']};
    const item=values[status]||['Inconnu','empty'];
    return `<span class="de-status ${item[1]}">${item[0]}</span>`;
  }
  const DE_RANKING_REASON_LABELS={editorial_not_eligible:'Non éligible éditorialement',no_official_metric_account:'Aucun compte métrique officiel',no_recent_activity:'Aucune activité récente mesurée',no_measurable_content:'Aucun contenu mesurable',coverage_below_5:'Couverture inférieure à 5 %',coverage_below_30:'Couverture inférieure à 30 %',confidence_below_35:'Confiance inférieure à 35 %',confidence_below_40:'Confiance inférieure à 40 %',stale_captures:'Captures trop anciennes'};
  async function deLoadRankingLab(force=false){
    if(DE.rankingLabLoading||(!force&&DE.rankingLab?.selectedPeriod===DE.rankingLabPeriod))return;
    DE.rankingLabLoading=true;
    try{DE.rankingLab=await apiFetch(`metrics-ranking.php?period=${encodeURIComponent(DE.rankingLabPeriod)}&limit=100`);}
    catch(error){DE.rankingLab={error:error.message||'Classement expérimental indisponible'};}
    finally{DE.rankingLabLoading=false;if(ui.adminTab==='rankinglab')deDrawRankingLab($('#adminPane'));}
    deLoadRankingHealth(force);
  }
  async function deLoadRankingHealth(force=false){
    if(DE.rankingHealthLoading||(!force&&DE.rankingHealth))return;
    DE.rankingHealthLoading=true;
    try{
      const payload=await apiFetch('metrics-ranking-publication-apply.php?action=health');
      DE.rankingHealth=payload?.health||null;
    }catch(error){DE.rankingHealth={status:'error',label:error.message||'Santé publication indisponible',ok:false,publicationEnabled:null,automaticPublicationEnabled:null};}
    finally{DE.rankingHealthLoading=false;if(ui.adminTab==='rankinglab')deDrawRankingLab($('#adminPane'));}
  }
  function deRankingHealthHtml(){
    const h=DE.rankingHealth;
    if(DE.rankingHealthLoading&&!h)return `<div class="de-ranking-health loading">Vérification de la publication publique…</div>`;
    if(!h)return '';
    const last=h.lastApplied||{};
    const ageHours=Number(last.ageHours);
    const age=last.ageHours==null?'—'
      :(!Number.isFinite(ageHours)?'—'
        :(ageHours<1?`${Math.max(1,Math.round(ageHours*60))} min`
          :`${ageHours.toFixed(1).replace(/\.0$/,'')} h`));
    const flagsKnown=typeof h.publicationEnabled==='boolean'&&typeof h.automaticPublicationEnabled==='boolean';
    const flags=!flagsKnown?'flags ?':(h.publicationEnabled&&h.automaticPublicationEnabled?'auto ON':'auto OFF');
    const detail=h.status==='error'
      ? `${flags} · réessaie Actualiser`
      :(last.generatedAt
        ? `Dernière écriture il y a ${age} · rév. ${last.revision||'—'} · ${flags}`
        : flags);
    const tone=h.status==='fresh'?'ok':(h.status==='aging'?'warn':(h.status==='stale'||h.status==='flags_off'||h.status==='error'?'bad':'warn'));
    return `<div class="de-ranking-health ${tone}" role="status"><strong>${deEsc(h.label||'Publication')}</strong><span>${deEsc(detail)}</span></div>`;
  }
  async function deLoadRankingCalibration(force=false){
    if(DE.rankingCalibrationLoading||(!force&&DE.rankingCalibration?.selectedPeriod===DE.rankingLabPeriod&&Number(DE.rankingCalibration?.requestedRuns||DE.rankingCalibrationRuns)===DE.rankingCalibrationRuns))return;
    DE.rankingCalibrationLoading=true;
    try{
      const data=await apiFetch(`metrics-ranking-calibration.php?period=${encodeURIComponent(DE.rankingLabPeriod)}&runs=${DE.rankingCalibrationRuns}`);
      DE.rankingCalibration={...data,requestedRuns:DE.rankingCalibrationRuns};
    }catch(error){DE.rankingCalibration={error:error.message||'Historique expérimental indisponible',requestedRuns:DE.rankingCalibrationRuns};}
    finally{DE.rankingCalibrationLoading=false;if(ui.adminTab==='rankinglab')deDrawRankingLab($('#adminPane'));}
  }
  function deRenderRankingLab(pane){
    pane.innerHTML='<div class="de-ranking-lab"><div class="de-loading">Chargement du classement expérimental…</div></div>';
    deLoadRankingLab();if(DE.rankingLabView!=='current')deLoadRankingCalibration();
  }
  function dePublicRanking(period){
    try{
      const profiles=(db.profiles||[]).filter(profile=>profile.alive&&isClassableProfile(profile)&&regionEligible(profile)&&Number.isFinite(Number(profile.scores?.[period])));
      return profiles.sort((a,b)=>Number(b.scores[period])-Number(a.scores[period])||a.name.localeCompare(b.name));
    }catch{return [];}
  }
  function dePublicRank(profileId,period){
    const index=dePublicRanking(period).findIndex(profile=>profile.id===profileId);return index<0?null:index+1;
  }
  function dePercent(value,digits=1){return value===null||value===undefined?'—':`${Number(value).toLocaleString('fr-FR',{maximumFractionDigits:digits})} %`;}
  function deNumber(value,digits=1){return value===null||value===undefined?'—':Number(value).toLocaleString('fr-FR',{maximumFractionDigits:digits});}
  function deRankingCurrentHtml(){
    const data=DE.rankingLab||{},summary=data.summary||{},rows=Array.isArray(data.rows)?data.rows.slice(0,100):[],run=data.latestRun||{};
    if(data.error)return `<div class="de-error">${deEsc(data.error)}</div>`;
    const tableRows=rows.map(row=>{const publicRank=dePublicRank(row.profileId,DE.rankingLabPeriod),gap=row.rank&&publicRank?publicRank-row.rank:null,evolution=!row.classable?'Non classé':row.rankDelta===null?'Nouvelle entrée':row.rankDelta>0?`+${row.rankDelta}`:String(row.rankDelta),evolutionClass=!row.classable?'unranked':row.rankDelta>0?'positive':row.rankDelta<0?'negative':'new',status=row.classable?'Classable':(row.exclusionReasons||[]).map(reason=>DE_RANKING_REASON_LABELS[reason]||reason).join(' · ');
      return `<tr><td>${row.rank??'—'}</td><td>${publicRank??'—'}</td><td>${gap===null?'—':gap>0?'+'+gap:gap}</td><td><strong>${deEsc(row.name)}</strong><small>${deEsc(row.handle||row.profileId)}</small></td><td class="de-ranking-score">${row.score===null?'—':Number(row.score).toLocaleString('fr-FR',{maximumFractionDigits:2})}</td><td>${Number(row.confidence||0).toLocaleString('fr-FR',{maximumFractionDigits:1})} %</td><td>${Number(row.coverage||0).toLocaleString('fr-FR',{maximumFractionDigits:1})} %</td><td>${Number(row.platformCount||0)}</td><td class="${evolutionClass}">${deEsc(evolution)}</td><td><span class="de-ranking-status ${row.classable?'classable':'insufficient'}">${deEsc(status||'Insuffisant')}</span></td></tr>`;}).join('');
    const exclusions=Object.entries(data.exclusionSummary||{}).map(([reason,count])=>`<li><strong>${Number(count)}</strong> ${deEsc(DE_RANKING_REASON_LABELS[reason]||reason)}</li>`).join('')||'<li>Aucune exclusion enregistrée.</li>';
    return `<div class="de-ranking-meta">Dernier calcul : <strong>${deEsc(run.finished_at?deTime(run.finished_at):'Jamais')}</strong> · Algorithme : <strong>${deEsc(data.algorithmVersion||'MR-V1.0')}</strong></div><div class="de-ranking-kpis"><div><strong>${Number(summary.classable||0)}</strong><span>Profils classables</span></div><div><strong>${Number(summary.excluded||0)}</strong><span>Profils exclus</span></div><div><strong>${Number(summary.averageConfidence||0).toLocaleString('fr-FR',{maximumFractionDigits:1})} %</strong><span>Confiance moyenne</span></div><div><strong>${Number(summary.averageCoverage||0).toLocaleString('fr-FR',{maximumFractionDigits:1})} %</strong><span>Couverture moyenne</span></div></div><div class="admin-table-wrap de-ranking-table-wrap"><table class="admin-table de-ranking-table"><thead><tr><th>Rang expérimental</th><th>Rang public</th><th>Écart</th><th>Influenceur</th><th>Score</th><th>Confiance</th><th>Couverture</th><th>Plateformes</th><th>Évolution expérimentale</th><th>Statut</th></tr></thead><tbody>${tableRows||'<tr><td colspan="10">Aucun résultat expérimental pour cette période.</td></tr>'}</tbody></table></div><section class="de-ranking-exclusions"><div class="section-title">Résumé des exclusions</div><ul>${exclusions}</ul></section>`;
  }
  function deRankingHistoryHtml(){
    const data=DE.rankingCalibration||{},history=data.historyStatus||{},aggregate=data.aggregate||{},runs=Array.isArray(data.runs)?data.runs:[];
    if(data.error)return `<div class="de-error">${deEsc(data.error)}</div>`;
    const trigger={admin_manual:'Manuel',cron_2h:'Automatique'};
    const rows=runs.map(run=>`<tr><td>${deEsc(run.finishedAt?deTime(run.finishedAt):'—')}</td><td>${deEsc(trigger[run.triggerType]||run.triggerType||'—')}</td><td>${run.classableCountCapped?'≥ 100':deNumber(run.classableCount,0)}</td><td>${deNumber(run.excludedCount,0)}</td><td>${deNumber(run.averageScore,2)}</td><td>${deNumber(run.medianScore,2)}</td><td>${dePercent(run.averageConfidence)}</td><td>${dePercent(run.averageCoverage)}</td><td>${dePercent(run.top10Retention)}</td><td>${dePercent(run.top50Retention)}</td><td>${deNumber(run.medianAbsoluteRankMovement,1)}</td><td>${deNumber(run.top50Entries,0)}</td><td>${deNumber(run.top50Exits,0)}</td><td><span class="de-ranking-summary-badge ${run.summaryExact?'exact':'fallback'}">${run.summaryExact?'Exact':'Historique Top 100'}</span></td></tr>`).join('');
    return `<div class="de-ranking-history-controls"><label>Cycles affichés <select id="deRankingCalibrationRuns">${[12,24,48,100].map(value=>`<option value="${value}" ${value===DE.rankingCalibrationRuns?'selected':''}>${value} cycles</option>`).join('')}</select></label></div><div class="de-ranking-kpis"><div><strong>${Number(history.successfulCycles||0)}</strong><span>Cycles réussis</span></div><div><strong>${Number(history.exactCycles||0)}</strong><span>Cycles exacts</span></div><div><strong>${runs[0]?.classableCountCapped?'≥ 100':deNumber(runs[0]?.classableCount,0)}</strong><span>Classables dernier cycle</span></div><div><strong>${dePercent(aggregate.averageTop10Retention)}</strong><span>Conservation Top 10</span></div><div><strong>${dePercent(aggregate.averageTop50Retention)}</strong><span>Conservation Top 50</span></div><div><strong>${deNumber(aggregate.medianAbsoluteRankMovement,1)}</strong><span>Mouvement médian</span></div></div><div class="admin-table-wrap de-ranking-history-wrap"><table class="admin-table de-ranking-history-table"><thead><tr><th>Date</th><th>Déclenchement</th><th>Profils classables</th><th>Profils exclus</th><th>Score moyen</th><th>Score médian</th><th>Confiance</th><th>Couverture</th><th>Top 10 conservé</th><th>Top 50 conservé</th><th>Mouvement médian</th><th>Entrées Top 50</th><th>Sorties Top 50</th><th>Fiabilité du résumé</th></tr></thead><tbody>${rows||'<tr><td colspan="14">Aucun cycle disponible.</td></tr>'}</tbody></table></div>`;
  }
  function deSpearman(pairs){
    if(!Array.isArray(pairs)||pairs.length<3)return null;
    const x=pairs.map(pair=>Number(pair.experimentalRank)),y=pairs.map(pair=>Number(pair.publicRank)),meanX=x.reduce((a,b)=>a+b,0)/x.length,meanY=y.reduce((a,b)=>a+b,0)/y.length;
    let numerator=0,denX=0,denY=0;for(let i=0;i<x.length;i++){const dx=x[i]-meanX,dy=y[i]-meanY;numerator+=dx*dy;denX+=dx*dx;denY+=dy*dy;}
    const denominator=Math.sqrt(denX*denY);return denominator===0?null:Math.max(-1,Math.min(1,numerator/denominator));
  }
  function deRankingPublicComparison(){
    const experimental=(DE.rankingLab?.rows||[]).filter(row=>row.classable&&row.rank!==null),publicRows=dePublicRanking(DE.rankingLabPeriod),publicRanks=new Map(publicRows.map((profile,index)=>[profile.id,index+1]));
    const pairs=experimental.filter(row=>publicRanks.has(row.profileId)).map(row=>({experimentalRank:Number(row.rank),publicRank:publicRanks.get(row.profileId)}));
    const overlap=limit=>{const exp=new Set(experimental.filter(row=>row.rank<=limit).map(row=>row.profileId)),pub=new Set(publicRows.slice(0,limit).map(profile=>profile.id));return [...exp].filter(id=>pub.has(id)).length;};
    return {commonProfiles:pairs.length,top10Overlap:overlap(10),top50Overlap:overlap(50),averageAbsoluteRankGap:pairs.length?pairs.reduce((sum,pair)=>sum+Math.abs(pair.experimentalRank-pair.publicRank),0)/pairs.length:null,spearman:deSpearman(pairs),region:ui.region||'ALL'};
  }
  function deRankingCalibrationHtml(){
    const data=DE.rankingCalibration||{},history=data.historyStatus||{},aggregate=data.aggregate||{},simulation=data.thresholdSimulation||{},comparison=deRankingPublicComparison();
    if(data.error)return `<div class="de-error">${deEsc(data.error)}</div>`;
    const maturity={collecting:'Collecte initiale',observing:'Observation en cours',calibratable:'Calibration possible'}[history.state]||'Collecte initiale';
    const stability={insufficient_history:'Historique insuffisant',stable:'Stable',moderate:'Modérée',volatile:'Volatile'}[aggregate.stability]||'Historique insuffisant';
    const cells=new Map((simulation.cells||[]).map(cell=>[`${cell.coverageThreshold}|${cell.confidenceThreshold}`,cell]));
    const matrix=(simulation.coverageThresholds||[]).map(coverage=>`<tr><th>${coverage} %</th>${(simulation.confidenceThresholds||[]).map(confidence=>{const cell=cells.get(`${coverage}|${confidence}`)||{},difference=Number(cell.differenceFromBaseline||0);return `<td class="${coverage===45&&confidence===55?'baseline':''}"><strong>${Number(cell.simulatedClassableCount||0)}</strong><span class="${difference>0?'positive':difference<0?'negative':''}">${difference>0?'+':''}${difference}</span></td>`;}).join('')}</tr>`).join('');
    return `<div class="de-ranking-calibration-warning">La calibration est une simulation. Aucun seuil, score ou rang public n’est modifié.</div><div class="de-ranking-calibration-grid"><section><div class="section-title">Maturité</div><span class="de-ranking-maturity ${deEsc(history.state||'collecting')}">${deEsc(maturity)}</span><strong>${Number(history.exactCycles||0)} / ${Number(history.minimumExactCycles||24)} cycles exacts</strong><p>24 cycles représentent seulement un premier seuil d’analyse et non une validation automatique de l’algorithme.</p></section><section><div class="section-title">Stabilité expérimentale</div><span class="de-ranking-maturity">${deEsc(stability)}</span><dl><dt>Conservation Top 10</dt><dd>${dePercent(aggregate.averageTop10Retention)}</dd><dt>Conservation Top 50</dt><dd>${dePercent(aggregate.averageTop50Retention)}</dd><dt>Mouvement médian</dt><dd>${deNumber(aggregate.medianAbsoluteRankMovement,1)}</dd><dt>Variation médiane du score</dt><dd>${deNumber(aggregate.medianScoreChange,2)}</dd></dl></section><section><div class="section-title">Comparaison publique actuelle</div><small>Zone publique active : ${deEsc(comparison.region)}</small><dl><dt>Profils communs</dt><dd>${comparison.commonProfiles}</dd><dt>Chevauchement Top 10</dt><dd>${comparison.top10Overlap}</dd><dt>Chevauchement Top 50</dt><dd>${comparison.top50Overlap}</dd><dt>Écart absolu moyen</dt><dd>${deNumber(comparison.averageAbsoluteRankGap,2)}</dd><dt>Spearman</dt><dd>${deNumber(comparison.spearman,3)}</dd></dl></section></div><section class="de-ranking-matrix-section"><div class="section-title">Matrice de simulation</div><div class="muted">Cette matrice ne change que les seuils de classabilité. Les poids et les scores MR-V1.0 restent inchangés.</div><div class="de-ranking-matrix-wrap"><table class="de-ranking-matrix"><thead><tr><th>Couverture \\ Confiance</th>${(simulation.confidenceThresholds||[]).map(value=>`<th>${value} %</th>`).join('')}</tr></thead><tbody>${matrix}</tbody></table></div></section>`;
  }
  function deDrawRankingLab(pane){
    if(!pane)return;const periods=['2H','24H','48H','7J','15J'];
    if(DE.rankingLabLoading&&!DE.rankingLab){pane.innerHTML='<div class="de-ranking-lab"><div class="de-loading">Chargement du classement expérimental…</div></div>';return;}
    if(DE.rankingLabView!=='current'&&DE.rankingCalibrationLoading&&!DE.rankingCalibration){pane.innerHTML='<div class="de-ranking-lab"><div class="de-loading">Chargement de l’historique…</div></div>';return;}
    const content=DE.rankingLabView==='history'?deRankingHistoryHtml():DE.rankingLabView==='calibration'?deRankingCalibrationHtml():deRankingCurrentHtml();
    pane.innerHTML=`<div class="de-ranking-lab"><div class="section-head"><div><div class="section-title">CLASSEMENT MÉTRIQUE MR‑V1.0</div><div class="de-ranking-warning">Calcul expérimental → publication publique via le bouton ci‑dessous (backup + garde‑fous). Le sélecteur de période ne fait que changer la vue ; la publication couvre toutes les périodes éligibles (ex. 24H+).</div></div><div class="de-ranking-actions"><button class="btn primary de-ranking-calculate">Calculer les 5 périodes</button><button class="btn primary de-ranking-publish">Publier vers le classement public</button><button class="btn de-ranking-refresh">Actualiser</button><select id="deRankingLabPeriod">${periods.map(period=>`<option ${period===DE.rankingLabPeriod?'selected':''}>${period}</option>`).join('')}</select></div></div>${deRankingHealthHtml()}<nav class="de-ranking-subnav" aria-label="Vues du classement expérimental"><button class="btn ${DE.rankingLabView==='current'?'primary':''}" data-ranking-view="current">Classement actuel</button><button class="btn ${DE.rankingLabView==='history'?'primary':''}" data-ranking-view="history">Historique des cycles</button><button class="btn ${DE.rankingLabView==='calibration'?'primary':''}" data-ranking-view="calibration">Calibration</button></nav>${content}</div>`;
    if(!document.getElementById('deRankingHealthStyles')){
      const style=document.createElement('style');style.id='deRankingHealthStyles';
      style.textContent=`.de-ranking-health{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:baseline;margin:0 0 14px;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:#0c100c}.de-ranking-health strong{font-size:13px}.de-ranking-health span{font-size:12px;color:var(--muted)}.de-ranking-health.ok{border-color:rgba(183,255,0,.45);background:linear-gradient(180deg,rgba(183,255,0,.1),rgba(10,13,10,.95))}.de-ranking-health.ok strong{color:var(--lime)}.de-ranking-health.warn{border-color:rgba(255,176,80,.45)}.de-ranking-health.warn strong{color:#ffc065}.de-ranking-health.bad{border-color:rgba(255,110,90,.55);background:linear-gradient(180deg,rgba(255,80,60,.1),rgba(10,13,10,.95))}.de-ranking-health.bad strong{color:#ff9e9e}.de-ranking-health.loading{color:var(--muted)}`;
      document.head.appendChild(style);
    }
  }
  async function deCalculateRankingLab(button){await deAction(button,async()=>{await apiFetch('metrics-ranking.php',{method:'POST',body:{action:'calculate',periods:['2H','24H','48H','7J','15J']}});DE.rankingLab=null;DE.rankingCalibration=null;await deLoadRankingLab(true);if(DE.rankingLabView!=='current')await deLoadRankingCalibration(true);toast('Classements expérimentaux calculés');},'Calcul…');}
  const DE_PUBLISH_GATE_LABELS={
    run_freshness:'calcul expérimental trop ancien (> 6 h) — cliquez d’abord « Calculer les 5 périodes »',
    candidate_non_empty:'aucun profil classable sur cette période',
    successful_run:'aucun cycle MR‑V1.0 réussi pour la période',
    public_ranking_non_empty:'classement public vide pour la période',
    candidate_run_consistency:'lignes candidates incohérentes avec le dernier cycle',
    exit_ratio:'trop de sorties vs classement public',
    entry_ratio:'trop d’entrées vs classement public',
  };
  function dePublishReasons(preview){
    const reasons=preview?.summary?.reasons||[];
    if(reasons.length){
      return reasons.map(raw=>{
        const [period,gates]=String(raw).split(/:(.+)/);
        const labels=(gates||'').split(',').map(g=>DE_PUBLISH_GATE_LABELS[g.trim()]||g.trim()).filter(Boolean);
        return `${period}: ${labels.join(', ')}`;
      }).join(' · ');
    }
    if((preview?.summary?.blockedPeriods||[]).length)return 'bloqué: '+(preview.summary.blockedPeriods||[]).join(', ');
    if(!preview?.config?.publicationEnabled)return 'flags publication désactivés';
    return preview?.status||'bloqué';
  }
  function dePublishOnlyStale(preview){
    const reasons=preview?.summary?.reasons||[];
    if(!reasons.length)return false;
    return reasons.every(raw=>{
      const gates=String(raw).split(/:(.+)/)[1]||'';
      return gates.split(',').every(g=>{
        const key=g.trim();
        return !key||key==='run_freshness'||key==='candidate_non_empty'||key==='successful_run'||key==='skipped'||key.startsWith('skipped');
      });
    })&&reasons.some(raw=>String(raw).includes('run_freshness'));
  }
  async function dePublishRankingLab(button){await deAction(button,async()=>{
    let preview=await apiFetch('metrics-ranking-publication-apply.php');
    // Le sélecteur 2H/24H… ne filtre pas la publication : toutes les périodes éligibles partent ensemble.
    if(!preview.publicationEligible&&dePublishOnlyStale(preview)){
      toast('Calcul expérimental trop ancien — recalcul automatique…');
      await apiFetch('metrics-ranking.php',{method:'POST',body:{action:'calculate',periods:['2H','24H','48H','7J','15J']}});
      DE.rankingLab=null;DE.rankingCalibration=null;
      await deLoadRankingLab(true);
      preview=await apiFetch('metrics-ranking-publication-apply.php');
    }
    if(!preview.publicationEligible){
      throw new Error(`Publication non éligible — ${dePublishReasons(preview)}`);
    }
    const periods=(preview.summary?.publishablePeriods||preview.periods||[]).join(', ')||'périodes OK';
    const skipped=(preview.summary?.skippedPeriods||[]).length?` (ignoré: ${preview.summary.skippedPeriods.join(', ')})`:'';
    const msg=preview.bootstrap
      ?`BOOTSTRAP : publier ${periods}${skipped} — ${preview.summary?.entries||0} entrées / ${preview.summary?.exits||0} sorties / ${preview.summary?.mutations||0} scores. Continuer ?`
      :`Publier MR‑V1.0 (${periods}${skipped}, ${preview.summary?.mutations||0} scores) vers le classement public ? Un backup sera créé.`;
    if(!confirm(msg))return;
    const result=await apiFetch('metrics-ranking-publication-apply.php',{method:'POST',body:{action:'apply',confirm:true,dispatchId:'admin-'+Date.now()}});
    DE.rankingLab=null;DE.rankingHealth=null;await deLoadRankingLab(true);
    await loadCloudState?.();render?.();
    const skipNote=(result.skippedPeriods||[]).length?` · ignoré ${result.skippedPeriods.join(', ')}`:'';
    toast(`Classement public mis à jour · révision ${result.publicStateRevision} · ${result.scoresWritten||0} scores${skipNote}`);
  },'Publication…');}
  async function deLoadMetricsDiagnostic(force=false){
    if(DE.metricsDiagnosticLoading||(!force&&DE.metricsDiagnostic))return;
    DE.metricsDiagnosticLoading=true;
    try{DE.metricsDiagnostic=await apiFetch('metrics-diagnostic.php');}
    catch(error){DE.metricsDiagnostic={error:error.message||'Diagnostic indisponible'};}
    finally{DE.metricsDiagnosticLoading=false;if(ui.adminTab==='metricsdiag')deDrawMetricsDiagnostic($('#adminPane'));}
  }
  async function deInstallMetricsSchema(button){
    if(!confirm('Installer le schéma métrique canonique et importer les données existantes ?'))return;
    const initialLabel=button.textContent;
    button.disabled=true;button.textContent='Installation et import des données en cours…';
    try{
      const result=await apiFetch('metrics-migrate.php',{method:'POST',body:{action:'migrate',limit:1000}});
      DE.metricsDiagnostic=null;
      await deLoadMetricsDiagnostic(true);
      if(DE.metricsDiagnostic?.canonicalSchema?.migrationStatus!=='applied')throw new Error('Installation non confirmée. La migration peut être relancée.');
      const backfill=result.backfill||{};
      toast(`Schéma installé · ${Number(backfill.accountsCreated||0)} comptes · ${Number(backfill.contentsCreated||0)} contenus · ${Number(backfill.capturesRecorded||0)} captures · ${Number(backfill.duplicatesSkipped||0)} doublons · ${Number(backfill.quarantinedCount||0)} quarantaines · ${Number(backfill.errors||0)} erreurs`);
    }catch(error){
      console.error('Installation du schéma métrique interrompue.');
      toast(error?.message||'Migration métrique interrompue. Elle peut être relancée.');
    }finally{
      if(button.isConnected){button.disabled=false;button.textContent=initialLabel;}
    }
  }
  async function deMetricsOrchestratorAction(button){
    const action=button.dataset.action,cadence=button.dataset.cadence||'p0';
    await deAction(button,async()=>{
      const result=await apiFetch('metrics-orchestrator.php',{method:'POST',body:{action,cadence,dispatchId:`admin-${Date.now()}`}});
      DE.metricsDiagnostic=null;await deLoadMetricsDiagnostic(true);
      if(action==='preview')toast(`${cadence.toUpperCase()} · ${Number(result?.candidates?.length||0)} tâche(s) éligible(s)`);
      else if(action==='enqueue')toast(`${cadence.toUpperCase()} · ${Number(result?.enqueued||0)} tâche(s) planifiée(s)`);
      else if(action==='work_one')toast(result?.processed?'Une tâche a été traitée':'Aucune tâche due');
      else toast(`${Number(result?.recovery?.retried||0)} tâche(s) reprise(s), ${Number(result?.recovery?.failed||0)} échouée(s)`);
    },action==='preview'?'Prévisualisation…':action==='enqueue'?'Planification…':action==='work_one'?'Traitement…':'Récupération…');
  }
  function deRenderMetricsDiagnostic(pane){
    if(!pane)return;
    pane.innerHTML='<div class="de-loading">Lecture du pipeline de métriques…</div>';
    if(DE.metricsDiagnostic)deDrawMetricsDiagnostic(pane);else deLoadMetricsDiagnostic();
  }
  function deDrawMetricsDiagnostic(pane){
    if(!pane)return;
    const data=DE.metricsDiagnostic;
    if(!data){pane.innerHTML='<div class="de-loading">Lecture du pipeline de métriques…</div>';return;}
    if(data.error){pane.innerHTML=`<div class="de-error">${deEsc(data.error)}</div><button class="btn de-metrics-refresh">Réessayer</button>`;return;}
    const ranking=data.ranking||{},volumes=data.volumes||{},fresh=data.freshness||{},collections=data.collections||{},canonical=data.canonicalSchema||{},metricCollectors=data.collectors||{},metricAutomation=data.automation?.metricsOrchestrator||{},platforms=data.platforms||[],errors=collections.recentErrors||[],failedJobs=Array.isArray(metricAutomation.recentFailedJobs)?metricAutomation.recentFailedJobs:[],schemaApplied=canonical.migrationStatus==='applied';
    const controlCenter=data.controlCenter||{},controlPlatforms=Array.isArray(controlCenter.platforms)?controlCenter.platforms:[],youtubeOAuth=controlCenter.youtubeOAuth||{},youtubeConnections=Array.isArray(youtubeOAuth.connections)?youtubeOAuth.connections:[],metaOAuth=controlCenter.metaOAuth||{},metaAssets=Array.isArray(metaOAuth.assets)?metaOAuth.assets:[],controlSummary=controlCenter.summary||{};
    const metricProfileOptions=(db?.profiles||[]).filter(profile=>profile?.id&&profile?.alive!==false).sort((a,b)=>String(a.name||a.id).localeCompare(String(b.name||b.id),'fr'));
    const controlState={operational:['Opérationnel','verified'],incomplete:['Incomplet','candidate'],degraded:['Dégradé','conflict'],no_coverage:['À démarrer','candidate'],no_verified_links:['Sans source officielle','empty'],authorization_required:['Autorisation requise','candidate'],not_configured:['Non configuré','empty']};
    const controlRows=controlPlatforms.map(row=>{const state=controlState[row.state]||['Inconnu','empty'];return `<tr><td><strong>${deEsc(row.platform)}</strong><div class="muted">${deEsc(row.mode||'—')}</div></td><td>${deObsNumber(row.eligibleProfiles)}</td><td>${deObsNumber(row.coveredProfiles)} / ${deObsNumber(row.eligibleProfiles)}<div class="muted">${deObsNumber(row.coveragePercent)} %</div></td><td>${deObsNumber(row.freshProfiles24h)}<div class="muted">${deObsNumber(row.freshnessPercent)} % à moins de 24 h</div></td><td>${deObsNumber(row.captures24h)}</td><td>${deObsNumber(Number(row.queue?.pending||0)+Number(row.queue?.retry_wait||0))} / ${deObsNumber(row.queue?.failed)}</td><td>${row.latestCaptureAt?deTime(row.latestCaptureAt):'Jamais'}</td><td><span class="de-status ${state[1]}">${state[0]}</span><div class="muted">${deEsc(row.actionRequired||'')}</div></td></tr>`}).join('');
    const youtubeMappingRows=youtubeConnections.map(row=>{const options=['<option value="">Non associée</option>',...metricProfileOptions.map(profile=>`<option value="${deEsc(profile.id)}" ${String(row.profileId||'')===String(profile.id)?'selected':''}>${deEsc(profile.name||profile.id)}</option>`)].join('');return `<tr><td><strong>${deEsc(row.channelTitle||row.channelId)}</strong><div class="muted">${deEsc(row.channelId)}</div></td><td>${deEsc(row.status||'—')}</td><td><select class="de-youtube-metrics-profile">${options}</select></td><td>${row.lastAnalyticsAt?deTime(row.lastAnalyticsAt):'Jamais'}</td><td><button class="btn de-youtube-metrics-map" data-channel-id="${deEsc(row.channelId)}">Enregistrer</button></td></tr>`}).join('');
    const metaMappingRows=metaAssets.map(row=>`<tr><td><strong>${deEsc(row.platform)}</strong></td><td><strong>${deEsc(row.assetName||row.username||row.assetId)}</strong><div class="muted">${deEsc(row.username?('@'+row.username):row.assetId)}</div></td><td>${row.profileName?`<span class="de-status verified">${deEsc(row.profileName)}</span>`:'<span class="de-status empty">Non associé</span>'}</td><td>${row.insightsAuthorized?'<span class="de-status verified">Base + Insights</span>':'<span class="de-status candidate">Données de base</span>'}</td><td>${row.lastError?`<span class="muted">${deEsc(row.lastError)}</span>`:(row.lastCheckedAt?deTime(row.lastCheckedAt):'Jamais')}</td></tr>`).join('');
    const kpis=[
      ['Événements uniques',volumes.activity_events],
      ['Captures métriques',volumes.activity_metric_history],
      ['Métriques actives',ranking.activeMetrics],
      ['Profils mesurables',ranking.measurableProfiles],
      ['Profils classables',ranking.classableProfiles],
      ['Scores modifiés',ranking.scoresChanged],
      ['Rangs modifiés',ranking.ranksChanged],
    ];
    const reasonLabels={insufficientConfidence:'Confiance insuffisante',insufficientCoverage:'Couverture insuffisante',fewerThanMinCriteria:'Moins de 4 critères',noRecentMetrics:'Aucune métrique récente'};
    pane.innerHTML=`<div class="de-observability-shell">
      <div class="section-head"><div><div class="section-title">DIAGNOSTIC MÉTRIQUES</div><div class="muted">Lecture seule · aucune collecte, aucun recalcul et aucune publication.</div></div><div class="de-toolbar">${deObsStatus(data.status)}<button class="btn de-metrics-refresh">Actualiser</button></div></div>
      <div class="de-observability-dates"><div><span>Dernière collecte réussie</span><strong>${deEsc(deObsAge(fresh.collection_success))}</strong><small>${deEsc(fresh.collection_success?.at?deTime(fresh.collection_success.at):'Jamais')}</small></div><div><span>Dernière publication atomique</span><strong>${deEsc(deObsAge(ranking.lastAtomicPublicationAge))}</strong><small>${deEsc(ranking.lastAtomicPublicationAt?deTime(ranking.lastAtomicPublicationAt):'Jamais')}</small></div></div>
      <div class="de-kpis">${kpis.map(([label,value])=>`<div class="de-kpi"><strong>${deObsNumber(value)}</strong><span>${deEsc(label.toUpperCase())}</span></div>`).join('')}</div>
      <section class="de-observability-card"><div class="section-head"><div><div class="section-title">CENTRE DE CONTRÔLE DE LA COLLECTE</div><div class="muted">Couverture réelle, fraîcheur, file d’attente et prochaines actions par plateforme.</div></div><span class="de-status ${controlCenter.orchestrator?.automationObservedRecently?'verified':'candidate'}">${controlCenter.orchestrator?.automationObservedRecently?'Automatisation observée':'Automatisation à vérifier'}</span></div><div class="de-reason-grid">${[['Seuil effectif',`${deObsNumber(controlCenter.threshold||data.threshold)} %`],['Profils éligibles',controlSummary.eligibleProfiles],['Profils couverts',controlSummary.coveredProfiles],['Fraîcheur globale',`${deObsNumber(controlSummary.globalFreshnessPercent)} %`],['Captures 24 h',controlSummary.captures24h],['Tâches en attente',controlSummary.pendingJobs],['Tâches échouées',controlSummary.failedJobs]].map(([label,value])=>`<div><strong>${deEsc(value)}</strong><span>${deEsc(label)}</span></div>`).join('')}</div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Plateforme</th><th>Éligibles</th><th>Couverture</th><th>Fraîcheur 24 h</th><th>Captures 24 h</th><th>Attente / échec</th><th>Dernière capture</th><th>État</th></tr></thead><tbody>${controlRows||'<tr><td colspan="8">Aucune plateforme observée.</td></tr>'}</tbody></table></div><div class="section-head"><div><div class="section-title">CHAÎNES YOUTUBE OAUTH</div><div class="muted">Une association explicite est obligatoire avant d’attribuer les métriques privées à une fiche PASS50.</div></div><span class="muted">${deObsNumber(youtubeOAuth.summary?.mapped)} associée(s) · ${deObsNumber(youtubeOAuth.summary?.unmapped)} non associée(s)</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Chaîne</th><th>État OAuth</th><th>Fiche PASS50</th><th>Dernières Analytics</th><th>Action</th></tr></thead><tbody>${youtubeMappingRows||'<tr><td colspan="5">Aucune chaîne YouTube OAuth connectée.</td></tr>'}</tbody></table></div><div class="media-hint">L’association active la collecte canonique YouTube. Les statistiques par période restent identifiées comme métriques d’intervalle et ne sont pas mélangées aux compteurs cumulés.</div><div class="section-head"><div><div class="section-title">COMPTES META AUTORISÉS</div><div class="muted">Facebook et Instagram associés sont collectés automatiquement. Les métriques Insights ne sont appelées que lorsque la permission correspondante est réellement accordée.</div></div><span class="muted">${deObsNumber(metaOAuth.summary?.mapped)} associé(s) · ${deObsNumber(metaOAuth.summary?.unmapped)} non associé(s)</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Plateforme</th><th>Compte</th><th>Fiche PASS50</th><th>Capacité</th><th>Dernier contrôle</th></tr></thead><tbody>${metaMappingRows||'<tr><td colspan="5">Aucun compte Meta OAuth connecté.</td></tr>'}</tbody></table></div><div class="media-hint">« Données de base » couvre notamment le compte, les abonnés et les interactions publiques disponibles. « Base + Insights » ajoute les métriques avancées autorisées par Meta.</div></section>
      <section class="de-observability-card"><div class="section-head"><div><div class="section-title">Schéma canonique</div><span class="muted">Version ${deObsNumber(canonical.schemaVersion)} · ${deEsc(canonical.migrationStatus||'non installé')}</span></div>${schemaApplied?'<span class="de-status verified">Schéma installé</span>':'<button class="btn primary de-install-metrics-schema">INSTALLER LE SCHÉMA CANONIQUE</button>'}</div><div class="media-hint">${schemaApplied?'Le schéma métrique canonique est installé.':'Cette opération crée les tables métriques et importe les données existantes. Elle ne modifie ni les scores, ni les rangs, ni le classement public.'}</div><div class="de-reason-grid">${[['Comptes',canonical.accounts],['Contenus',canonical.contents],['Captures',canonical.captures],['Jobs',canonical.jobs],['Runs',canonical.runs],['Quarantaines',canonical.quarantinedCaptures]].map(([label,value])=>`<div><strong>${value===null||value===undefined?'—':deObsNumber(value)}</strong><span>${deEsc(label)}</span></div>`).join('')}</div><div class="muted">Dernier backfill : ${deEsc(canonical.lastBackfillAt?deTime(canonical.lastBackfillAt):'Jamais')}</div></section>
      <section class="de-observability-card"><div class="section-head"><div><div class="section-title">COLLECTE DES MÉTRIQUES SOCIALES</div><div class="muted">Collecte expérimentale : les données ne modifient pas encore le classement public.</div>${schemaApplied?'':'<div class="media-hint">Installe d’abord le schéma canonique.</div>'}</div></div><label>Profil ciblé <select id="deMetricProfile" ${schemaApplied?'':'disabled'}><option value="">Lot de profils vérifiés</option>${(db?.profiles||[]).filter(p=>p?.id).map(p=>`<option value="${deEsc(p.id)}">${deEsc(p.name||p.id)}</option>`).join('')}</select></label><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Plateforme</th><th>Accès</th><th>Dernier état</th><th>Comptes</th><th>Contenus</th><th>Captures</th><th>24 h</th><th>Indisponible / limite</th><th>Action</th></tr></thead><tbody>${[['YouTube','youtube'],['X','x'],['TikTok','tiktok'],['Instagram','instagram'],['Facebook','facebook'],['Snapchat','snapchat']].map(([name,key])=>{const row=metricCollectors[key]||{};return `<tr><td><strong>${name}</strong></td><td>${row.configured?(row.authorized===false?'<span class="de-status candidate">Autorisation requise</span>':'<span class="de-status verified">Configuré</span>'):'<span class="de-status empty">Non configuré</span>'}<div class="muted">${deEsc(row.mode||'—')}</div></td><td>${deEsc(row.lastStatus||'Jamais')}${row.lastRun?.finished_at?`<div class="muted">${deTime(row.lastRun.finished_at)}</div>`:''}</td><td>${deObsNumber(row.accounts)}</td><td>${deObsNumber(row.contents)}</td><td>${deObsNumber(row.captures)} <span class="muted">(${deObsNumber(row.usableCaptures)} utiles / ${deObsNumber(row.quarantinedCaptures)} quarantaines)</span></td><td>${deObsNumber(row.captures24h)}</td><td>${deObsNumber(row.unavailableProfiles)} / ${deObsNumber(row.rateLimitedCount)}${row.lastError?`<div class="muted">${deEsc(row.lastError)}</div>`:''}</td><td><button class="btn de-collect-metrics" data-platform="${name}" ${schemaApplied?'':'disabled'}>Collecter</button></td></tr>`}).join('')}</tbody></table></div></section>
      <section class="de-observability-card"><div class="section-head"><div><div class="section-title">AUTOMATISATION DES MÉTRIQUES</div><div class="muted">L’automatisation collecte les métriques mais ne modifie pas encore le classement public.</div></div><span class="de-status ${metricAutomation.enabled?'verified':'empty'}">${metricAutomation.enabled?'Activé':'Désactivé'}</span></div><div class="de-reason-grid">${[
        ['Secret cron',metricAutomation.cronSecretConfigured?'Configuré':'Non configuré'],
        ['Dernier P0',metricAutomation.lastDispatchP0?.finished_at?deTime(metricAutomation.lastDispatchP0.finished_at):'Jamais'],
        ['Dernier P1',metricAutomation.lastDispatchP1?.finished_at?deTime(metricAutomation.lastDispatchP1.finished_at):'Jamais'],
        ['Dernier P2',metricAutomation.lastDispatchP2?.finished_at?deTime(metricAutomation.lastDispatchP2.finished_at):'Jamais'],
        ['Pending',deObsNumber(metricAutomation.queue?.pending)],
        ['Running',deObsNumber(metricAutomation.queue?.running)],
        ['Retry',deObsNumber(metricAutomation.queue?.retry_wait)],
        ['Terminées 24 h',deObsNumber(metricAutomation.queue?.completed24h)],
        ['Échouées',deObsNumber(metricAutomation.queue?.failed)],
        ['Plus ancienne attente',metricAutomation.oldestPendingAt?deTime(metricAutomation.oldestPendingAt):'Aucune'],
        ['Source LIVE P0',metricAutomation.liveSourceStatus||'unavailable'],
        ['Plateformes configurées',(metricAutomation.configuredPlatforms||[]).join(', ')||'Aucune'],
        ['Exclus faute d’autorisation',deObsNumber(metricAutomation.excludedAuthorization)]
      ].map(([label,value])=>`<div><strong>${deEsc(value)}</strong><span>${deEsc(label)}</span></div>`).join('')}</div><div class="media-hint">Cadences demandées : P0 ${deEsc(metricAutomation.expectedCadences?.p0||'—')} (prochain passage théorique ${deEsc(metricAutomation.nextExpectedAt?.p0?deTime(metricAutomation.nextExpectedAt.p0):'—')}) · P1 ${deEsc(metricAutomation.expectedCadences?.p1||'—')} (${deEsc(metricAutomation.nextExpectedAt?.p1?deTime(metricAutomation.nextExpectedAt.p1):'—')}) · P2 ${deEsc(metricAutomation.expectedCadences?.p2||'—')} (${deEsc(metricAutomation.nextExpectedAt?.p2?deTime(metricAutomation.nextExpectedAt.p2):'—')}). Les passages GitHub Actions peuvent être décalés.</div><div class="section-head"><div><div class="section-title">DERNIÈRES TÂCHES MÉTRIQUES ÉCHOUÉES</div><div class="muted">Ces échecs proviennent de la file du nouvel orchestrateur. Ils sont distincts du journal historique affiché plus bas.</div></div><span class="muted">${deObsNumber(failedJobs.length)} récente(s)</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Plateforme</th><th>Profil</th><th>Cadence</th><th>Tentatives</th><th>Erreur sécurisée</th></tr></thead><tbody>${failedJobs.map(row=>{const attempts=deObsNumber(row.attempts),maxAttempts=deObsNumber(row.maxAttempts);return `<tr><td>${deEsc(row.updatedAt?deTime(row.updatedAt):'—')}</td><td>${deEsc(row.platform||'—')}</td><td>${deEsc(row.profileId||'Global')}</td><td>${deEsc(String(row.cadence||'').toUpperCase())}</td><td>${attempts} / ${maxAttempts}</td><td>${deEsc(row.message||'Échec sans détail')}</td></tr>`}).join('')||'<tr><td colspan="6">Aucune tâche métrique échouée.</td></tr>'}</tbody></table></div><div class="de-toolbar">${['p0','p1','p2'].map(c=>`<button class="btn de-orchestrator-action" data-action="preview" data-cadence="${c}">Prévisualiser ${c.toUpperCase()}</button>`).join('')}<button class="btn primary de-orchestrator-action" data-action="enqueue" data-cadence="p0" ${schemaApplied?'':'disabled'}>Planifier un cycle</button><button class="btn de-orchestrator-action" data-action="work_one" ${schemaApplied?'':'disabled'}>Traiter une tâche</button><button class="btn de-orchestrator-action" data-action="recover_stale" ${schemaApplied?'':'disabled'}>Récupérer les tâches bloquées</button></div></section>
      <section class="de-observability-card"><div class="section-title">Pourquoi le classement reste statique</div><ul>${(data.staticRankingReasons||[]).map(reason=>`<li>${deEsc(reason)}</li>`).join('')||'<li>Aucun blocage principal détecté.</li>'}</ul><div class="de-reason-grid">${Object.entries(ranking.nonClassableReasons||{}).map(([key,value])=>`<div><strong>${deObsNumber(value)}</strong><span>${deEsc(reasonLabels[key]||key)}</span></div>`).join('')}</div><div class="media-hint">${deEsc(data.automation?.summary||'État de l’automatisation inconnu.')}</div></section>
      <section class="de-observability-card"><div class="section-head"><div class="section-title">Couverture par plateforme</div><span class="muted">${deObsNumber(data.platformsWithoutData?.length)} sans donnée</span></div><div class="admin-table-wrap"><table class="admin-table de-observability-table"><thead><tr><th>Plateforme</th><th>Événements</th><th>Captures</th><th>Exploitables</th><th>Actives</th><th>Profils</th><th>Dernière donnée</th><th>État</th></tr></thead><tbody>${platforms.map(row=>`<tr><td><strong>${deEsc(row.platform)}</strong></td><td>${deObsNumber(row.uniqueEvents)}</td><td>${deObsNumber(row.metricCaptures)}</td><td>${deObsNumber(row.usableMetrics)}</td><td>${deObsNumber(row.activeMetrics)}</td><td>${deObsNumber(row.coveredProfiles)}</td><td>${deEsc(row.lastMetricCaptureAt?deTime(row.lastMetricCaptureAt):(row.lastCollectedAt?deTime(row.lastCollectedAt):'Jamais'))}</td><td>${row.noData?'<span class="de-status conflict">Sans donnée</span>':'<span class="de-status verified">Collectée</span>'}</td></tr>`).join('')||'<tr><td colspan="8">Aucune plateforme observée.</td></tr>'}</tbody></table></div></section>
      <section class="de-observability-card"><div class="section-head"><div class="section-title">Dernières erreurs</div><span class="muted">${deObsNumber(collections.summary?.errors)} erreur(s) · ${deObsNumber(Number(collections.summary?.interrupted||0)+Number(collections.summary?.stale_running||0))} interrompue(s) ou bloquée(s)</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Collecteur</th><th>Profil</th><th>Statut</th><th>Erreur sécurisée</th></tr></thead><tbody>${errors.map(row=>`<tr><td>${deEsc(row.startedAt?deTime(row.startedAt):'—')}</td><td>${deEsc(row.collector)}</td><td>${deEsc(row.profileId||'Global')}</td><td>${deEsc(row.status)}</td><td>${deEsc(row.message||'Erreur sans détail')}</td></tr>`).join('')||'<tr><td colspan="5">Aucune erreur récente.</td></tr>'}</tbody></table></div></section>
      <div class="muted de-observability-foot">Généré ${deTime(data.generatedAt)} · limites : ${deObsNumber(data.limits?.eventRows)} événements, ${deObsNumber(data.limits?.captureSeries)} séries, ${deObsNumber(data.limits?.recentErrors)} erreurs.</div>
    </div>`;
  }
  function deApplyVerifiedBirthsFromHub(){
    if(!DE.hub||!Array.isArray(DE.hub.profiles)||!Array.isArray(db?.profiles))return 0;
    const threshold=deThreshold();let changed=0;
    for(const item of DE.hub.profiles){
      const birth=item.birthBest||item.facts?.birth_date,date=String(item.birthDate||birth?.normalized_value||'').trim(),confidence=Number(birth?.confidence||item.quality?.birth||0),status=String(item.birthStatus||birth?.status||'');
      if(!date||status!=='verified'||confidence<threshold)continue;
      const p=db.profiles.find(x=>x.id===item.id);if(!p)continue;
      const frozen=Boolean(p.birthManualLocked||(typeof p50BirthShouldPreserve==='function'&&p50BirthShouldPreserve(p))||(p.birthDate&&(p.ageStatus==='confirmed'||Number(p?.quality?.birth||0)>=90)));
      if(frozen&&String(p.birthDate||'')!==date)continue;
      if(p.birthDate!==date||p.ageStatus!=='confirmed'||Number(p?.quality?.birth||0)!==confidence){
        if(frozen)continue;
        p.birthDate=date;p.birthYear=Number(date.slice(0,4))||p.birthYear||null;p.ageStatus='confirmed';p.agePublic=p.agePublic!==false;p.quality=(typeof p50EnsurePlainObject==='function'?p50EnsurePlainObject(p.quality):(p.quality&&typeof p.quality==='object'?p.quality:{}));p.quality.birth=confidence;if(typeof p50LockBirthDate==='function')p50LockBirthDate(p);else{p.birthManualLocked=true;p.birthManualUpdatedAt=p.birthManualUpdatedAt||new Date().toISOString();p.dataEngine=(p.dataEngine&&typeof p.dataEngine==='object'&&!Array.isArray(p.dataEngine))?p.dataEngine:{};const facts=Array.isArray(p.dataEngine.verifiedFacts)?p.dataEngine.verifiedFacts:[];p.dataEngine.verifiedFacts=[...new Set([...facts,'birth_date'])];}changed++;
      }
    }
    if(changed){localStorage.setItem(APP_KEY,JSON.stringify(db));if(!DE.majRunning&&window.__pass50CloudReady&&typeof scheduleCloudSync==='function')scheduleCloudSync();}
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
  function deYoutubeMajStatus(youtube,captures){
    if(!youtube.configured)return 'API YouTube non configurée';
    if(youtube.callsAttempted===0)return 'API configurée mais aucun appel tenté';
    if(youtube.callsSucceeded===0)return 'Appels tentés mais tous échoués';
    if(youtube.videosRetrieved===0)return 'Appels réussis mais aucune vidéo récente';
    if(captures===0)return 'Vidéos récupérées mais aucune capture enregistrée';
    return 'Captures enregistrées avec succès';
  }
  function deYoutubeMajSummary(youtube,captures){
    const configured=youtube.configured?'oui':'non';
    return `YouTube — Clé configurée : ${configured} · ${youtube.profilesWithLink} profil(s) avec lien · ${youtube.callsAttempted} appel(s) tenté(s) · ${youtube.callsSucceeded} réussi(s) · ${youtube.videosRetrieved} vidéo(s) récupérée(s) · ${youtube.errors403} erreur(s) 403 · ${youtube.errors429} erreur(s) 429 · ${youtube.budgetExceeded} budget(s) dépassé(s) · ${youtube.invalidUrls} URL(s) invalide(s) · ${youtube.noRecentProfiles} profil(s) sans vidéo récente · ${deYoutubeMajStatus(youtube,captures)}.`;
  }
  function deMajCanResume(saved){
    return Boolean(saved&&saved.status!=='success'&&Array.isArray(saved.processedIds)&&saved.processedIds.length>0);
  }
  function deMajButtonLabel(){
    if(DE.majRunning)return 'MAJ EN COURS…';
    return deMajCanResume(DE.majLastResult||deMajSavedStatus())?'REPRENDRE LA MAJ PASS50':'LANCER LA MAJ PASS50';
  }
  function deMajSleep(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
  function deMajCheckpoint(extra){
    deMajPersistStatus(Object.assign({status:'running',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,processedIds:[...DE.majSeen],target:DE.majTarget},extra||{}));
  }
  async function deMajCollectBatch(){
    const body={limit:1,deep:true,excludeIds:[...DE.majSeen],includeHub:false,syncRegistry:false};
    let lastError=null;
    for(let attempt=1;attempt<=3;attempt++){
      try{return await apiFetch('data-collect.php',{method:'POST',body});}
      catch(error){
        lastError=error;
        if(attempt>=3)break;
        DE.majMessage=`Réponse serveur interrompue (${error?.message||'Erreur serveur'}). Nouvelle tentative ${attempt}/3 dans 5 secondes…`;
        deDrawMajProgress();
        await deMajSleep(5000);
      }
    }
    throw lastError||new Error('Erreur serveur');
  }
  function deRenderMajPass50(pane){
    const saved=DE.majLastResult||deMajSavedStatus();
    const last=saved?.finishedAt?new Date(saved.finishedAt).toLocaleString('fr-FR'):'Jamais';
    const resume=deMajCanResume(saved);
    pane.innerHTML=`<div class="data-engine-shell">
      <div class="section-head"><div><div class="section-title">⚡ MAJ PASS50</div><div class="muted">Une seule action pour synchroniser les FI, collecter les données publiques disponibles, calculer les 15 critères, publier les scores et capturer le classement.</div></div></div>
      <div class="media-hint"><strong>Fonctionnement :</strong> le traitement parcourt les ${Number(DE.hub?.kpis?.profiles||db?.profiles?.length||134)} FI une par une, pour rester sous la limite HTTP de l’hébergeur. Tu peux quitter cet onglet, mais garde la page PASS50 ouverte jusqu’au message de fin.</div>
      <div class="de-toolbar" style="margin-top:14px">
        <button class="btn primary" id="deMajPass50">${deMajButtonLabel()}</button>
        ${DE.majRunning?'<button class="btn danger" id="deStopMajPass50">ARRÊTER APRÈS CETTE FI</button>':''}
      </div>
      <div id="deMajProgress" class="de-auto-box"></div>
      <div class="de-kpis" style="margin-top:12px">
        <div class="de-kpi"><strong>${Number(db?.profiles?.length||0)}</strong><span>FI ACTUELLES</span><small>Total chargé dans PASS50</small></div>
        <div class="de-kpi"><strong>15</strong><span>CRITÈRES</span><small>Moteur algorithmique PASS50</small></div>
        <div class="de-kpi"><strong>5</strong><span>PÉRIODES</span><small>2H · 24H · 48H · 7J · 15J</small></div>
        <div class="de-kpi"><strong>${deEsc(last)}</strong><span>DERNIÈRE MAJ</span><small>${saved?.status==='success'?'Terminée avec succès':saved?.status==='stopped'?'Arrêtée':resume?`Reprise possible · ${Number(saved.processedIds.length)} FI`:'Aucune exécution complète'}</small></div>
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
    const btn=$('#deMajPass50');if(btn){btn.disabled=DE.majRunning;btn.textContent=deMajButtonLabel();}
  }
  async function deRunMajPass50(){
    if(DE.majRunning)return;
    window.majPass50Running=true;
    if(typeof CLOUD==='object'&&CLOUD?.syncTimer){clearTimeout(CLOUD.syncTimer);CLOUD.syncTimer=null;}
    const saved=deMajSavedStatus();
    const resume=deMajCanResume(saved);
    DE.majRunning=true;DE.majStopRequested=false;DE.majSeen=new Set(resume?saved.processedIds.map(String):[]);DE.majStartedAt=(resume&&saved.startedAt)?saved.startedAt:new Date().toISOString();DE.majLastResult=null;
    let completedSuccessfully=false;
    DE.majStage='1/7 · Synchronisation des FI';DE.majMessage=resume?`Reprise à ${DE.majSeen.size} FI déjà parcourues. Envoi des fiches actuelles vers le registre serveur…`:'Envoi des fiches actuelles vers le registre serveur…';deRenderMajPass50($('#adminPane'));
    let totals={found:0,verified:0,historicalMetrics:0,fiTraversed:0,officialLinksAnalyzed:0,recentPublications:0,uniqueEvents:0,capturesRecorded:0,activeMetrics:0,unavailablePlatforms:0,measurableProfiles:0,published:0,recalculated:0,notRecalculated:0,scoresChanged:0,ranksChanged:0,captured:0,batches:0,skipped:0,intelligence:{profilesAnalyzed:0,profilesIgnored:0,strongTrends:0,buzzDetected:0,declinesDetected:0,errors:0},youtube:{configured:false,profilesWithLink:0,callsAttempted:0,callsSucceeded:0,videosRetrieved:0,errors403:0,errors429:0,budgetExceeded:0,invalidUrls:0,noRecentProfiles:0}};
    try{
      if(typeof window.PASS50_STEP12_STATUS==='object'&&Number(window.PASS50_STEP12_STATUS.present||0)<7&&typeof window.p50EnsureStep12Profiles==='function')window.p50EnsureStep12Profiles();
      const sync=await apiFetch('data-hub.php',{method:'POST',body:{action:'sync'}});
      DE.hub=sync.hub||DE.hub;DE.majTarget=Number(DE.hub?.kpis?.profiles||sync.syncedProfiles||db?.profiles?.length||0);
      DE.majStage='2/7 · Collecte et conservation';DE.majMessage='Le moteur parcourt les FI une par une…';deDrawMajProgress();

      let consecutiveSkips=0;
      while(!DE.majStopRequested&&DE.majSeen.size<DE.majTarget){
        let data;
        try{
          data=await deMajCollectBatch();
          consecutiveSkips=0;
        }catch(error){
          consecutiveSkips++;
          if(consecutiveSkips>=8)throw new Error(`Le serveur ne répond plus après plusieurs tentatives (${DE.majSeen.size}/${DE.majTarget}). Relance le bouton pour reprendre au même point.`);
          let skippedIds=[];
          try{
            const preview=await apiFetch('data-collect.php',{method:'POST',body:{preview:true,limit:1,excludeIds:[...DE.majSeen],includeHub:false,syncRegistry:false}});
            skippedIds=(preview.processedIds||[]).map(String);
          }catch(previewError){
            DE.majMessage=`Hébergeur saturé. Pause 20 s avant reprise (${DE.majSeen.size}/${DE.majTarget})…`;
            deDrawMajProgress();
            await deMajSleep(20000);
            continue;
          }
          if(!skippedIds.length)break;
          skippedIds.forEach(id=>DE.majSeen.add(id));
          totals.skipped+=skippedIds.length;
          DE.majMessage=`FI ignorée après erreur serveur, poursuite ${DE.majSeen.size}/${DE.majTarget}…`;
          deMajCheckpoint({totals});
          deDrawMajProgress();
          await deMajSleep(2000);
          continue;
        }
        const ids=(data.processedIds||[]).map(String);
        if(!ids.length)break;
        const before=DE.majSeen.size;ids.forEach(id=>DE.majSeen.add(id));
        const radar=data.radar||{};
        totals.batches++;totals.found+=Number(data.found||0);totals.verified+=Number(data.verified||0);totals.historicalMetrics+=Number(data.historicalMetrics||0);totals.fiTraversed+=Number(radar.fiTraversed||data.processed||0);totals.officialLinksAnalyzed+=Number(radar.officialLinksAnalyzed||0);totals.recentPublications+=Number(radar.recentPublications||0);totals.uniqueEvents+=Number(data.uniqueEvents||0);totals.capturesRecorded+=Number(radar.capturesRecorded||0);totals.activeMetrics+=Number(data.activeMetrics||0);totals.unavailablePlatforms+=Number(radar.unavailablePlatforms||0);totals.measurableProfiles+=Number(data.measurableProfiles||0);
        const youtube=radar.youtubeApi||{};
        const intelligence=data.intelligence||{};
        for(const counter of ['profilesAnalyzed','profilesIgnored','strongTrends','buzzDetected','declinesDetected','errors'])totals.intelligence[counter]+=Number(intelligence[counter]||0);
        totals.youtube.configured=totals.youtube.configured||Boolean(youtube.configured);
        for(const counter of ['profilesWithLink','callsAttempted','callsSucceeded','videosRetrieved','errors403','errors429','budgetExceeded','invalidUrls','noRecentProfiles'])totals.youtube[counter]+=Number(youtube[counter]||0);
        DE.hub=data.hub||DE.hub;
        DE.majStage='3/7 · Calcul des 15 critères';
        DE.majMessage=`FI ${DE.majSeen.size}/${DE.majTarget} : ${ids.length} fiche(s) · ${Number(data.found||0)} donnée(s) trouvée(s). Le calcul final sera vérifié à la publication.`;
        deMajCheckpoint({totals});
        deDrawMajProgress();
        if(DE.majSeen.size===before)break;
        await deMajSleep(1000);
      }

      if(DE.majStopRequested){
        const result={status:'stopped',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,processedIds:[...DE.majSeen],target:DE.majTarget,totals};
        DE.majLastResult=result;deMajPersistStatus(result);DE.majStage='MAJ arrêtée';DE.majMessage=`${DE.majSeen.size}/${DE.majTarget} FI traitées. Relance le bouton pour reprendre au même point.`;toast('MAJ PASS50 arrêtée après la FI en cours');return;
      }

      DE.majStage='4/7 · Publication des scores';DE.majMessage='Écriture des données vérifiées et des scores calculés dans l’état PASS50…';deDrawMajProgress();
      const published=await apiFetch('data-publish.php',{method:'POST',body:{period:ui.period}});totals.published=Number(published.publishedProfiles||0);totals.historicalMetrics=Number(published.historicalMetrics??totals.historicalMetrics);totals.uniqueEvents=Number(published.uniqueEvents??totals.uniqueEvents);totals.activeMetrics=Number(published.activeMetrics??totals.activeMetrics);totals.measurableProfiles=Number(published.measurableProfiles??totals.measurableProfiles);totals.recalculated=Number(published.recalculatedProfiles||0);totals.notRecalculated=Number(published.notRecalculatedProfiles||0);totals.scoresChanged=Number(published.scoresChanged||0);totals.ranksChanged=Number(published.ranksChanged||0);DE.hub=published.hub||DE.hub;

      DE.majStage='5/7 · Rechargement et reclassement';DE.majMessage='Récupération de l’état serveur puis reclassement automatique…';deDrawMajProgress();
      try{
        if(typeof loadCloudState==='function')await loadCloudState();
        if(typeof render==='function')render();
      }catch(refreshError){console.warn('Reclassement affichage non bloquant',refreshError);}

      DE.majStage='6/7 · État final publié';DE.majMessage='Les nouveaux scores et le classement trié ont été publiés en une seule écriture atomique.';deDrawMajProgress();

      DE.majStage='7/7 · Capture du classement';DE.majMessage='Enregistrement de la photographie du classement actuel…';deDrawMajProgress();
      try{const snap=await apiFetch('data-snapshot.php',{method:'POST',body:{period:ui.period}});totals.captured=Number(snap.captured||0);}catch(error){console.warn('Capture classement non bloquante',error);}

      await deLoadHub(true);
      const result={status:'success',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,processedIds:[...DE.majSeen],target:DE.majTarget,totals,totalProfiles:Number(db?.profiles?.length||0),period:ui.period};
      const rankingChanged=totals.scoresChanged>0||totals.ranksChanged>0;
      const counters=`${totals.fiTraversed} FI parcourue(s) · ${totals.officialLinksAnalyzed} lien(s) officiel(s) analysé(s) · ${totals.recentPublications} publication(s) récente(s) détectée(s) · ${totals.uniqueEvents} événement(s) unique(s) · ${totals.capturesRecorded} capture(s) métrique(s) enregistrée(s) · ${totals.activeMetrics} métrique(s) active(s) · Intelligence : ${totals.intelligence.profilesAnalyzed} analysé(s), ${totals.intelligence.profilesIgnored} ignoré(s), ${totals.intelligence.strongTrends} tendance(s), ${totals.intelligence.buzzDetected} buzz, ${totals.intelligence.declinesDetected} recul(s), ${totals.intelligence.errors} erreur(s) non bloquante(s) · ${totals.unavailablePlatforms} plateforme(s) indisponible(s) · ${totals.recalculated} profil(s) recalculé(s) · ${totals.scoresChanged} score(s) modifié(s) · ${totals.ranksChanged} rang(s) modifié(s) · ${totals.published} profil(s) publié(s). ${deYoutubeMajSummary(totals.youtube,totals.capturesRecorded)}`;
      DE.majLastResult=result;deMajPersistStatus(result);DE.majStage=rankingChanged?'MAJ PASS50 terminée · classement actualisé':'MAJ PASS50 terminée';DE.majMessage=totals.activeMetrics===0?`Collecte terminée, mais aucune métrique récente n'est disponible pour recalculer les scores. ${counters}`:rankingChanged?`${counters} Classement actualisé.`:`Collecte terminée. Les profils ont été recalculés, mais aucun score ni rang n'a changé. ${counters}`;
      window.PASS50_MAJ_STATUS=result;
      completedSuccessfully=true;
      toast(`MAJ PASS50 terminée · ${result.processed} FI traitées`);
    }catch(err){
      console.error('MAJ PASS50',err);
      const result={status:'error',startedAt:DE.majStartedAt,finishedAt:new Date().toISOString(),processed:DE.majSeen.size,processedIds:[...DE.majSeen],target:DE.majTarget,totals,error:String(err?.message||err)};
      DE.majLastResult=result;deMajPersistStatus(result);DE.majStage='Erreur pendant la MAJ';DE.majMessage=result.error;window.PASS50_MAJ_STATUS=result;toast(result.error||'MAJ PASS50 impossible');
    }finally{
      DE.majRunning=false;DE.majStopRequested=false;
      window.majPass50Running=false;
      if(completedSuccessfully&&typeof scheduleCloudSync==='function')scheduleCloudSync();
      if(ui.adminTab==='update')deRenderMajPass50($('#adminPane'));
    }
  }

  function deRenderIntelligence(pane){
    pane.innerHTML=`<div class="data-engine-shell"><div class="section-head"><div><div class="section-title">PASS50 Intelligence</div><div class="muted">Analyse déterministe des données Radar. Les signaux à confiance faible restent visibles. Aucun résultat ne modifie le score officiel ni le classement public.</div></div><button class="btn" id="deReloadIntelligence">Actualiser</button></div><div id="deIntelligenceContent" class="de-loading">Chargement des analyses…</div></div>`;
    deLoadIntelligence();
  }
  function deConfidence(level){return `<span class="p50i-confidence ${level==='élevée'?'high':level==='moyenne'?'medium':'low'}">${deEsc(level)}</span>`;}
  function deIntelligenceCard(item){
    const initials=String(item.name||'?').split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase();
    const photo=item.photo?`<img src="${deEsc(item.photo)}" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:`<span>${deEsc(initials)}</span>`;
    const start=new Date(item.periodStart),end=new Date(item.periodEnd),period=Number.isNaN(start.getTime())?'Dernières 24 heures':`${start.toLocaleString('fr-FR')} – ${end.toLocaleString('fr-FR')}`;
    const low=item.confidenceLevel==='faible';
    return `<article class="p50i-card ${low?'is-building':''}"><div class="p50i-head"><div class="p50i-avatar">${photo}</div><div><strong>${deEsc(item.name)}</strong><div>${deConfidence(item.confidenceLevel)}</div></div></div><div class="p50i-indexes"><span><b>${Number(item.growthIndex)}</b> Growth</span><span><b>${Number(item.buzzIndex)}</b> Buzz</span></div><div class="p50i-signal">${deEsc(item.mainVariation)}</div><p>${deEsc(item.explanation)}</p><small>${deEsc(period)}</small></article>`;
  }
  function deIntelligenceSection(title,items,empty,limit=10,extraClass=''){
    return `<section class="p50i-section ${extraClass}"><div class="section-head"><div class="section-title">${deEsc(title)}</div><span class="muted">${items.length}/${limit} profil(s)</span></div>${items.length?`<div class="p50i-grid">${items.map(deIntelligenceCard).join('')}</div>`:`<div class="p50i-empty">${deEsc(empty)}</div>`}</section>`;
  }
  async function deLoadIntelligence(){
    const el=$('#deIntelligenceContent');if(!el)return;
    try{
      DE.intelligence=await apiFetch('intelligence.php');
      const summary=DE.intelligence.summary||{};
      const analyzed=Number(summary.profilesAnalyzed||0);
      const low=Number(summary.profilesLowConfidence||0);
      const trusted=Number(summary.profilesTrusted||0);
      const summaryLine=analyzed
        ? `${analyzed} profil(s) analysé(s) · ${trusted} confiance moyenne/élevée · ${low} en construction`
        : 'Aucune analyse récente : lance une MAJ PASS50 ou Enrichir pour alimenter cet onglet.';
      el.innerHTML=`<div class="media-hint"><strong>Période :</strong> ${deEsc(DE.intelligence.periodLabel||'Dernières 24 heures comparées à la période précédente')} · ${deEsc(summaryLine)}</div>${deIntelligenceSection('Tendances fortes',DE.intelligence.strongTrends||[],'Aucune tendance détectée pour l’instant.')}${deIntelligenceSection('Buzz détectés',DE.intelligence.buzzDetected||[],'Aucun buzz détecté pour l’instant.')}${deIntelligenceSection('Profils en recul',DE.intelligence.declines||[],'Aucun recul détecté pour l’instant.')}${deIntelligenceSection('Signaux en construction',DE.intelligence.buildingSignals||[],'Aucune analyse en attente d’historique. Relance la MAJ pour capturer plus de points Radar.',20,'is-building')}`;
    }catch(err){el.innerHTML=`<div class="de-error">${deEsc(err.message||'PASS50 Intelligence indisponible')}</div>`;}
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
    await deAction(btn,async()=>{const data=await apiFetch('data-collect.php',{method:'POST',body:{profileId,limit:profileId?1:5,deep:true}});DE.hub=data.hub;deApplyVerifiedBirthsFromHub();deDrawHub();await loadCloudState();deApplyVerifiedBirthsFromHub();render();toast(`${data.processed} profil(s) enrichi(s) · ${data.found} donnée(s) trouvée(s) · ${data.verified} vérifiée(s)`);},'Enrichissement…');
  }
  async function deAutoEnrich(btn){
    if(DE.autoRunning)return;DE.autoRunning=true;DE.stopRequested=false;DE.autoSeen=new Set();DE.autoTarget=Number(DE.hub?.kpis?.profiles||0);DE.autoMessage='Démarrage du moteur…';deSetAutoUi();deAutoProgress();
    try{
      let consecutiveFailures=0;
      while(!DE.stopRequested&&DE.autoSeen.size<DE.autoTarget){
        let data;
        try{
          // Une collecte profonde ouvre plusieurs sources externes. Un profil par
          // requête évite qu'un lot complet dépasse la limite HTTP de l'hébergeur.
          data=await apiFetch('data-collect.php',{method:'POST',body:{limit:1,deep:true,excludeIds:[...DE.autoSeen]}});
          consecutiveFailures=0;
        }catch(error){
          consecutiveFailures++;
          if(consecutiveFailures>=3)throw new Error(`Le serveur ne répond plus après 3 tentatives (${DE.autoSeen.size}/${DE.autoTarget}). Réessaie dans quelques minutes.`);
          DE.autoMessage=`Réponse serveur interrompue. Nouvelle tentative automatique ${consecutiveFailures}/3 dans 5 secondes…`;
          deAutoProgress();
          await new Promise(resolve=>setTimeout(resolve,5000));
          continue;
        }
        const ids=(data.processedIds||[]).map(String);if(!ids.length){
          DE.autoMessage=DE.autoSeen.size>=DE.autoTarget?'Tous les profils ont été parcourus.':'Aucun autre profil disponible dans le registre actif.';
          break;
        }
        const before=DE.autoSeen.size;ids.forEach(id=>DE.autoSeen.add(id));
        DE.hub=data.hub;DE.autoMessage=`Dernier lot : ${data.processed} profil(s), ${data.found} donnée(s) trouvée(s), ${data.verified} vérifiée(s).`;deDrawHub();
        // L'état public n'est qu'un rafraîchissement d'affichage. Une panne de cet
        // appel secondaire ne doit jamais arrêter le parcours d'enrichissement.
        if(DE.autoSeen.size%10===0||DE.autoSeen.size>=DE.autoTarget){
          try{await loadCloudState();render();}
          catch(refreshError){console.warn('Rafraîchissement public différé',refreshError);}
        }
        if(DE.autoSeen.size===before)break;
        // Laisse l'hébergement relâcher ses connexions avant le profil suivant.
        await new Promise(resolve=>setTimeout(resolve,1000));
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
      pane.innerHTML=`<div class="de-profile-head"><div><button class="btn admin-view-home" data-admin-tab="adminhome">← Accueil administration</button><div class="section-title" style="margin-top:10px">Date de naissance · ${deEsc(data.profile.public_name)}</div><div class="muted">Le moteur cherche cette date automatiquement. Utilise ce formulaire seulement lorsqu’une source publique n’a pas été détectée ou pour résoudre un conflit.</div></div></div><div class="de-link-card"><form id="deBirthForm" data-profile="${deEsc(profileId)}" class="form"><div class="two"><div class="field"><label>Date de naissance</label><input type="text" inputmode="numeric" autocomplete="off" name="value" placeholder="Ex. 12/08/1991" required><small>Formats : 12/08/1991, 12-08-1991 ou 1991-08-12.</small></div><div class="field"><label>Nom de la source</label><input name="sourceName" placeholder="Ex. site officiel, média" required></div></div><div class="field"><label>URL exacte de la source</label><input type="url" name="sourceUrl" placeholder="https://…" required></div><label class="de-confirm"><input type="checkbox" name="confirmedSource" required> Je confirme que cette source mentionne bien cette date.</label><button class="btn primary" type="submit">AJOUTER CETTE SOURCE</button></form></div><div class="section-head" style="margin-top:18px"><div class="section-title">Dates détectées</div><span class="muted">Seuil ${data.threshold||90} %</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Confiance</th><th>Sources</th><th>Statut</th></tr></thead><tbody>${birthFacts.length?birthFacts.map(f=>`<tr><td><strong>${deEsc(f.normalized_value)}</strong></td><td>${deStatus(f.status,f.confidence)}</td><td>${Number(f.evidence_count||0)}</td><td>${deEsc(f.status)}</td></tr>`).join(''):'<tr><td colspan="4" class="muted">Aucune date détectée.</td></tr>'}</tbody></table></div><div class="section-head" style="margin-top:18px"><div class="section-title">Sources enregistrées</div></div>${evidence.length?evidence.map(x=>`<div class="signal"><div><strong>${deEsc(x.source_name)}</strong><div>${deEsc(x.normalized_value)}</div><div class="muted">${deEsc(x.source_url||'')} · poids ${Number(x.source_weight||0)} %</div></div></div>`).join(''):'<div class="muted">Aucune source.</div>'}`;
    }catch(err){pane.innerHTML=`<div class="de-error">${deEsc(err.message)}</div><button class="btn" data-admin-tab="adminhome">← Accueil administration</button>`;}
  }

  async function deOpenSocial(profileId){
    DE.socialProfileId=profileId;const pane=$('#adminPane');pane.innerHTML='<div class="de-loading">Chargement des réseaux…</div>';
    try{
      const data=await apiFetch('social-links.php?profileId='+encodeURIComponent(profileId)),current=Object.fromEntries((data.links||[]).map(x=>[x.platform,x])),all=(DE.hub?.profiles||[]).slice().sort((a,b)=>a.name.localeCompare(b.name,'fr'));
      const options=all.map(p=>{const rank=typeof ranking==='function'?ranking().findIndex(x=>x.id===p.id):-1,label=(rank>=0&&rank<50?'TOP 50 · ':'')+(rank>=0?'#'+(rank+1)+' · ':'')+p.name;return `<option value="${deEsc(p.id)}" ${p.id===profileId?'selected':''}>${deEsc(label)}</option>`}).join(''),rank=typeof ranking==='function'?ranking().findIndex(x=>x.id===profileId):-1;
      pane.innerHTML=`<div class="de-social-shell"><div class="de-profile-head"><div><button class="btn admin-view-home" data-admin-tab="adminhome">← Accueil administration</button><div class="section-title" style="margin-top:10px">Réseaux officiels · ${deEsc(data.profile.public_name)}${rank>=0&&rank<50?'<span class="de-top50-pill">TOP 50</span>':''}</div><div class="muted">Le moteur tente aussi de récupérer les liens depuis Wikidata, le site officiel et les données structurées. Une validation manuelle reste possible.</div></div></div><div class="de-social-switcher"><label>Changer rapidement de FI<select id="deSocialProfileSelect">${options}</select></label><button class="btn" data-admin-tab="hub">Voir la liste complète</button></div><div class="de-links">${DE.platforms.map(platform=>deLinkCard(profileId,platform,current[platform])).join('')}</div></div>`;
    }catch(err){pane.innerHTML=`<div class="de-error">${deEsc(err.message)}</div><button class="btn" data-admin-tab="adminhome">← Accueil administration</button>`;}
  }

  function deLinkCard(profileId,platform,link){
    const status=link?.status||'empty',confidence=Number(link?.confidence||0),url=link?.url||'',icons={Instagram:'◎',TikTok:'♪',Facebook:'f',YouTube:'▶',Snapchat:'◉',X:'𝕏',Web:'↗'};
    return `<article class="de-link-card"><div class="de-link-head"><div class="de-platform-name"><span class="de-platform-icon">${icons[platform]||'•'}</span><strong>${deEsc(platform)}</strong></div>${deStatus(status,confidence)}</div><form class="de-link-form" data-profile="${deEsc(profileId)}" data-platform="${deEsc(platform)}"><input type="url" name="url" value="${deEsc(url)}" placeholder="Colle l’URL exacte du compte officiel" required><label class="de-confirm"><input type="checkbox" name="confirmedOfficial" required> Je confirme qu’il s’agit du compte officiel de cette FI.</label><button class="btn primary" type="submit">VALIDER LE LIEN</button></form>${url?`<div class="de-link-meta">${deEsc(url)}${link?.checked_at?` · contrôlé ${deTime(link.checked_at)}`:''}</div><div class="de-link-actions"><a class="btn small" href="${deEsc(url)}" target="_blank" rel="noopener">Ouvrir ↗</a><button class="btn small danger de-reject-link" data-profile="${deEsc(profileId)}" data-platform="${deEsc(platform)}">Rejeter</button></div>`:''}</article>`;
  }

  document.addEventListener('click',async e=>{
    try{
      if(e.target.id==='deMajPass50')await deRunMajPass50();
      if(e.target.id==='deReloadIntelligence')await deLoadIntelligence();
      if(e.target.id==='deMembersRefresh'){DE.members=null;await deLoadMembers(true);}
      if(e.target.matches('.de-metrics-refresh')){DE.metricsDiagnostic=null;await deLoadMetricsDiagnostic(true);}
      if(e.target.matches('.de-ranking-refresh')){DE.rankingLab=null;DE.rankingCalibration=null;DE.rankingHealth=null;await Promise.all([deLoadRankingLab(true),deLoadRankingCalibration(true)]);}
      if(e.target.matches('.de-ranking-calculate'))await deCalculateRankingLab(e.target);
      if(e.target.matches('.de-ranking-publish'))await dePublishRankingLab(e.target);
      if(e.target.matches('[data-ranking-view]')){DE.rankingLabView=e.target.dataset.rankingView;if(DE.rankingLabView!=='current'&&!DE.rankingCalibration)await deLoadRankingCalibration();else deDrawRankingLab($('#adminPane'));}
      if(e.target.matches('.de-install-metrics-schema'))await deInstallMetricsSchema(e.target);
      if(e.target.matches('.de-collect-metrics')){const platform=e.target.dataset.platform,profileId=document.getElementById('deMetricProfile')?.value||'';await deAction(e.target,async()=>{await apiFetch('metrics-canonical-collect.php',{method:'POST',body:profileId?{action:'collect_profile',platform,profileId,contentLimit:10}:{action:'collect_batch',platform,profileLimit:10,contentLimit:5}});DE.metricsDiagnostic=null;await deLoadMetricsDiagnostic(true);toast(`Collecte ${platform} terminée`);},'Collecte…');}
      if(e.target.matches('.de-youtube-metrics-map')){const channelId=String(e.target.dataset.channelId||''),profileId=e.target.closest('tr')?.querySelector('.de-youtube-metrics-profile')?.value||'';await deAction(e.target,async()=>{await apiFetch('youtube-metrics-map.php',{method:'POST',body:{channelId,profileId}});DE.metricsDiagnostic=null;await deLoadMetricsDiagnostic(true);toast(profileId?'Chaîne YouTube associée à la fiche':'Association YouTube retirée');},'Enregistrement…');}
      if(e.target.matches('.de-orchestrator-action'))await deMetricsOrchestratorAction(e.target);
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
  document.addEventListener('input',e=>{
    if(e.target.id!=='deMembersSearch')return;
    DE.membersQuery=e.target.value.trim();
    clearTimeout(DE.membersTimer);
    DE.membersTimer=setTimeout(()=>deLoadMembers(true),280);
  });
  document.addEventListener('change',async e=>{if(e.target.id==='deSocialProfileSelect')await deOpenSocial(e.target.value);if(e.target.id==='deRankingLabPeriod'){DE.rankingLabPeriod=e.target.value;DE.rankingLab=null;DE.rankingCalibration=null;await deLoadRankingLab(true);if(DE.rankingLabView!=='current')await deLoadRankingCalibration(true);}if(e.target.id==='deRankingCalibrationRuns'){DE.rankingCalibrationRuns=Number(e.target.value)||24;DE.rankingCalibration=null;await deLoadRankingCalibration(true);}
    const roleSel=e.target.closest('[data-member-role]');
    if(roleSel){
      const userId=roleSel.getAttribute('data-member-role');
      const previous=roleSel.getAttribute('data-current')||'member';
      const role=roleSel.value;
      if(role===previous)return;
      const label=deRoleLabel(role);
      if(!confirm(`Attribuer le rôle « ${label} » à ce compte ?`)){roleSel.value=previous;return;}
      try{
        await apiFetch('admin-users.php',{method:'POST',body:{userId,role}});
        toast(role==='admin'?'Accès administration attribué':'Rôle membre restauré');
        await deLoadMembers(true);
        if(ui.adminTab==='adminhome')await deLoadHomeMembers();
      }catch(err){
        roleSel.value=previous;
        toast(err.message||'Rôle non modifié');
      }
    }
  });
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
