'use strict';
(function(){
  const SECTION_ID='p50MetaOauthSection',TOKEN_KEY='pass50_api_token';
  const RESULT_KEY='pass50_meta_oauth_result_v2',LEGACY_ERROR_KEY='pass50_meta_oauth_error_code';
  const messages={
    public_profile_advanced_access:'Active l’accès avancé à public_profile dans Contrôle app → Autorisations et fonctionnalités, puis relance la connexion.',
    invalid_configuration:'Vérifie que configuration_id correspond bien à PASS50 Meta LIVE dans la nouvelle application Business.',
    redirect_uri_mismatch:'Vérifie que l’URI OAuth valide est exactement https://www.pass50.store/api/meta-oauth-callback.php.',
    invalid_client:'L’App ID ou l’App Secret de PASS50 Business ne correspond pas à la nouvelle application.',
    unsupported_permission:'La configuration Meta contient une permission non prise en charge par Facebook Login for Business.',
    app_not_available:'L’application Meta n’est pas disponible pour cette connexion. Vérifie son état, ton rôle dans l’application et public_profile.',
    permissions_missing:'Meta n’a pas accordé toutes les permissions attendues : pages_show_list, pages_read_engagement et instagram_basic.',
    pass50_session_expired:'Ta session PASS50 a expiré pendant la connexion. Reconnecte-toi à PASS50 puis relance Meta.',
    code_exchange_failed:'Meta a autorisé la connexion, mais l’échange du code a échoué. Vérifie l’App ID, l’App Secret et l’URI OAuth.',
    pages_access_failed:'La connexion Meta fonctionne, mais les Pages sélectionnées n’ont pas pu être lues.',
    invalid_state:'La vérification de sécurité OAuth a expiré. Recharge PASS50 puis recommence.',
    missing_code:'Meta n’a pas renvoyé de code d’autorisation.',
    connection_failed:'Meta a autorisé la connexion, mais PASS50 n’a pas pu terminer l’enregistrement côté serveur.'
  };
  let status=null,loading=false,connecting=false,refreshingAssets=false,autoMapping=false,lastError='';
  let mappingProfiles=null,mappingAsset=null,mappingSaving=false;
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const apiBase=()=>String(window.PASS50_API?.baseUrl||'./api').replace(/\/+$/,'')||'./api';
  const token=()=>String(localStorage.getItem(TOKEN_KEY)||'').trim();
  const diagnosticMessage=code=>messages[String(code||'').trim()]||'Meta a interrompu ou refusé la connexion.';

  async function api(path,options={}){
    const accessToken=token();if(!accessToken)throw new Error('Connecte-toi d’abord à PASS50.');
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),25000),headers=new Headers(options.headers||{});
    headers.set('Accept','application/json');headers.set('Authorization',`Bearer ${accessToken}`);
    let body=options.body;if(body&&typeof body!=='string'&&!(body instanceof FormData)){headers.set('Content-Type','application/json');body=JSON.stringify(body);}
    try{
      const response=await fetch(`${apiBase()}/${path}`,{method:options.method||'GET',headers,body,cache:'no-store',credentials:'same-origin',signal:controller.signal});
      const text=await response.text();let data={};try{data=text?JSON.parse(text):{};}catch{}
      if(!response.ok)throw new Error(data.detail||data.error||data.message||`Erreur serveur Meta (${response.status}).`);
      if(!data||typeof data!=='object')throw new Error('Réponse Meta invalide reçue du serveur.');return data;
    }catch(error){if(error?.name==='AbortError')throw new Error('Le serveur Meta ne répond pas. Réessaie dans quelques secondes.');throw error;}
    finally{clearTimeout(timeout);}
  }

  function notify(message){if(typeof window.toast==='function')window.toast(message);const node=document.getElementById('toast');if(node){node.textContent=message;node.classList.add('show');setTimeout(()=>node.classList.remove('show'),3500);}}
  function styles(){
    if(document.getElementById('p50MetaOauthStyles'))return;const style=document.createElement('style');style.id='p50MetaOauthStyles';
    style.textContent=`#${SECTION_ID} .p50-meta-card{padding:16px;border:1px solid #293129;border-radius:17px;background:linear-gradient(145deg,#171b17,#090c09)}#${SECTION_ID} .p50-meta-head{display:flex;align-items:center;justify-content:space-between;gap:14px}#${SECTION_ID} .p50-meta-main{display:flex;align-items:center;gap:12px;min-width:0}#${SECTION_ID} .p50-meta-logo{width:54px;height:54px;border-radius:15px;display:grid;place-items:center;background:linear-gradient(135deg,#1877f2,#d62976);font-weight:1000;font-size:21px}#${SECTION_ID} .p50-meta-title{font-weight:1000;font-size:16px}#${SECTION_ID} .p50-meta-copy{color:#9da79b;font-size:12px;line-height:1.5;margin-top:4px}#${SECTION_ID} .p50-meta-actions{display:flex;gap:8px;flex-wrap:wrap}#${SECTION_ID} .p50-meta-assets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:9px;margin-top:13px}#${SECTION_ID} .p50-meta-asset{padding:11px;border:1px solid #293129;border-radius:13px;background:#090c09}#${SECTION_ID} .p50-meta-platform{font-size:10px;font-weight:950;color:#b7ff00}#${SECTION_ID} .p50-meta-name{font-weight:950;margin-top:3px}#${SECTION_ID} .p50-meta-state{font-size:11px;color:#9da79b;margin-top:4px}#${SECTION_ID} .p50-meta-state.warn{color:#ffc065}#${SECTION_ID} .p50-meta-map{margin-top:9px;border:1px solid #4d5a4d;background:#121812;color:#dfffb0;border-radius:9px;padding:7px 9px;font-size:11px;font-weight:900;cursor:pointer}#${SECTION_ID} .p50-meta-message{margin-top:12px;padding:10px 12px;border-radius:12px;font-size:12px;line-height:1.45}#${SECTION_ID} .p50-meta-message.error{border:1px solid #8e3030;background:#210d0d;color:#ffb4b4}#${SECTION_ID} .p50-meta-message.warn{border:1px solid #7a5a1d;background:#1c1609;color:#ffd27c}#${SECTION_ID} button[disabled]{opacity:.65;cursor:wait}.p50-meta-map-overlay{position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,.72);display:grid;place-items:center;padding:18px}.p50-meta-map-dialog{width:min(620px,100%);max-height:86vh;overflow:auto;background:#0d110d;border:1px solid #3a473a;border-radius:18px;padding:20px;color:#fff;box-shadow:0 24px 80px rgba(0,0,0,.55)}.p50-meta-map-dialog h3{margin:0 0 7px;font-size:20px}.p50-meta-map-dialog p{margin:0 0 16px;color:#aab4a7;font-size:13px;line-height:1.5}.p50-meta-map-dialog input{width:100%;box-sizing:border-box;border:1px solid #4a584a;background:#070907;color:#fff;border-radius:11px;padding:12px;font-size:14px}.p50-meta-map-dialog .p50-meta-map-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.p50-meta-map-dialog .p50-meta-map-actions button{border-radius:10px;padding:10px 13px;font-weight:950;cursor:pointer}.p50-meta-map-save{border:0;background:#b7ff00;color:#050705}.p50-meta-map-cancel{border:1px solid #5b665b;background:transparent;color:#fff}.p50-meta-map-remove{border:1px solid #a33b3b;background:#260b0b;color:#ffb5b5;margin-left:auto}@media(max-width:720px){#${SECTION_ID} .p50-meta-head{align-items:stretch;flex-direction:column}#${SECTION_ID} .p50-meta-actions .btn{flex:1}.p50-meta-map-remove{margin-left:0}}`;
    document.head.appendChild(style);
  }
  function isStaff(){
    try{
      const user=typeof window.currentUser==='function'?window.currentUser():null;
      return !!(user&&['owner','admin'].includes(String(user.role||'')));
    }catch(_){return false;}
  }
  function ensure(){
    if(!isStaff()){document.getElementById(SECTION_ID)?.remove();return null;}
    const body=document.getElementById('userBody');if(!body||!body.innerHTML.trim())return null;const grid=body.querySelector('.user-grid');if(!grid)return null;
    let section=document.getElementById(SECTION_ID);if(!section){section=document.createElement('section');section.id=SECTION_ID;section.className='user-section full';if(typeof window.p50MesComptesMount==='function')window.p50MesComptesMount(section);else{const youtube=document.getElementById('p50YoutubeOauthSection');if(youtube?.parentNode===grid)youtube.insertAdjacentElement('afterend',section);else grid.appendChild(section);}}else if(typeof window.p50MesComptesMount==='function'&&section.parentElement?.id!=='p50MesComptesPanel')window.p50MesComptesMount(section);return section;
  }
  function configurationWarning(){const configuration=status?.configuration;if(!configuration||configuration.ready!==false)return '';const missing=Array.isArray(configuration.missing)?configuration.missing.join(', '):'réglages Meta';return `<div class="p50-meta-message warn" role="status">Configuration temporairement indisponible : ${esc(missing)}.</div>`;}
  function discoveryWarning(){return status?.discoveryWarning?`<div class="p50-meta-message warn" role="status"><strong>Pages Meta :</strong> ${esc(status.discoveryWarning)}</div>`:'';}
  function errorHtml(){return lastError?`<div class="p50-meta-message error" role="alert" aria-live="assertive"><strong>Connexion Meta impossible :</strong> ${esc(lastError)}</div>`:'';}
  function profileLabel(profile){const handle=String(profile?.handle||'').trim();return `${String(profile?.name||'Fiche PASS50')} — ${String(profile?.id||'')}${handle?` · ${handle}`:''}`;}
  function mappingModalHtml(){
    if(!mappingAsset)return '';
    const profiles=Array.isArray(mappingProfiles)?mappingProfiles:[];
    const current=profiles.find(profile=>profile.id===mappingAsset.profileId);
    const options=profiles.map(profile=>`<option value="${esc(profileLabel(profile))}"></option>`).join('');
    return `<div class="p50-meta-map-overlay" data-p50-meta-map-overlay><div class="p50-meta-map-dialog" role="dialog" aria-modal="true" aria-labelledby="p50MetaMapTitle"><h3 id="p50MetaMapTitle">Associer ${esc(mappingAsset.name||mappingAsset.id)}</h3><p>Choisis la véritable fiche PASS50 correspondant à ce compte. Cette association sert uniquement au radar LIVE et ne change ni le classement ni les scores.</p><input type="text" list="p50MetaProfileOptions" data-p50-meta-profile-input placeholder="Rechercher une personne ou saisir son identifiant" value="${esc(current?profileLabel(current):mappingAsset.profileId||'')}" autocomplete="off"><datalist id="p50MetaProfileOptions">${options}</datalist><div class="p50-meta-map-actions"><button type="button" class="p50-meta-map-save" data-p50-meta-map-save ${mappingSaving?'disabled':''}>${mappingSaving?'Enregistrement…':'Enregistrer'}</button><button type="button" class="p50-meta-map-cancel" data-p50-meta-map-close>Annuler</button>${mappingAsset.mapped?`<button type="button" class="p50-meta-map-remove" data-p50-meta-map-remove ${mappingSaving?'disabled':''}>Retirer l’association</button>`:''}</div></div></div>`;
  }

  function render(){
    const section=ensure();if(!section)return;if(!token()){section.remove();return;}
    if(loading){section.innerHTML='<div class="user-title"><span>Meta · Facebook & Instagram</span><span class="muted">Connexion officielle</span></div><div class="p50-meta-card">Vérification de la connexion Meta…</div>';return;}
    if(!status?.connected){
      section.innerHTML=`<div class="user-title"><span>Meta · Facebook & Instagram</span><span class="muted">Connexion officielle</span></div><div class="p50-meta-card"><div class="p50-meta-head"><div class="p50-meta-main"><div class="p50-meta-logo">META</div><div><div class="p50-meta-title">Connecter mes comptes professionnels</div><div class="p50-meta-copy">PASS50 lira uniquement les Pages Facebook gérées et les comptes Instagram Business ou Creator liés. Aucune publication automatique.</div></div></div><div class="p50-meta-actions"><button class="btn primary" type="button" data-p50-meta-connect ${connecting?'disabled':''}>${connecting?'Redirection vers Meta…':'Connecter Meta'}</button></div></div>${configurationWarning()}${errorHtml()}</div>`;return;
    }
    const assets=Array.isArray(status.assets)?status.assets:[],mapped=assets.filter(asset=>asset.mapped).length,unmapped=assets.length-mapped;
    const items=assets.map(asset=>{
      const mapButton=status.canManageMappings?`<button type="button" class="p50-meta-map" data-p50-meta-map data-platform="${esc(asset.platform)}" data-asset-id="${esc(asset.id)}">${asset.mapped?'Modifier l’association':'Associer à une fiche'}</button>`:'';
      return `<div class="p50-meta-asset"><div class="p50-meta-platform">${esc(asset.platform)}</div><div class="p50-meta-name">${esc(asset.name||asset.username||asset.id)}</div><div class="p50-meta-state ${asset.mapped?'':'warn'}">${asset.mapped?`Relié à la fiche PASS50 ${esc(asset.profileId)}`:'Compte connecté, fiche PASS50 non associée'}${asset.lastError?` · ${esc(asset.lastError)}`:''}</div>${mapButton}</div>`;
    }).join('');
    const autoMapButton=status.canManageMappings&&unmapped>0?`<button class="btn" type="button" data-p50-meta-auto-map ${autoMapping?'disabled':''}>${autoMapping?'Association…':'Associer automatiquement'}</button>`:'';
    const primary=status.requiresReauthorization?'<button class="btn primary" type="button" data-p50-meta-connect>Reconnecter</button>':assets.length?'<button class="btn primary" type="button" data-p50-meta-collect>Actualiser les LIVE</button>':`<button class="btn primary" type="button" data-p50-meta-refresh-assets ${refreshingAssets?'disabled':''}>${refreshingAssets?'Recherche en cours…':'Rechercher mes Pages'}</button>`;
    const growHint=assets.length<3?`<div class="p50-meta-message warn" role="status">Pour classer plus d’IG/FB : reconnecte Meta et sélectionne <strong>toutes</strong> les Pages FI dans l’autorisation Business, puis « Rechercher mes Pages ».</div>`:'';
    section.innerHTML=`<div class="user-title"><span>Meta · Facebook & Instagram</span><span class="muted">Lecture seule</span></div><div class="p50-meta-card"><div class="p50-meta-head"><div class="p50-meta-main"><div class="p50-meta-logo">META</div><div><div class="p50-meta-title">${esc(status.account?.name||'Compte Meta connecté')}</div><div class="p50-meta-copy">${assets.length} compte(s) professionnel(s) découvert(s) · ${mapped} associé(s)${unmapped?` · ${unmapped} à associer`:''}${status.requiresReauthorization?' · Reconnexion nécessaire':''}</div></div></div><div class="p50-meta-actions">${primary}${autoMapButton}${assets.length?`<button class="btn" type="button" data-p50-meta-refresh-assets ${refreshingAssets?'disabled':''}>${refreshingAssets?'Recherche…':'Rechercher mes Pages'}</button>`:''}<button class="btn danger" type="button" data-p50-meta-disconnect>Déconnecter</button></div></div>${items?`<div class="p50-meta-assets">${items}</div>`:'<div class="p50-meta-copy" style="margin-top:12px">Connexion enregistrée. PASS50 va relire les Pages sélectionnées dans l’autorisation Meta.</div>'}${growHint}${discoveryWarning()}${errorHtml()}</div>${mappingModalHtml()}`;
    if(mappingAsset)setTimeout(()=>section.querySelector('[data-p50-meta-profile-input]')?.focus(),0);
  }

  async function refresh(showError=false){if(!token())return null;loading=true;render();try{status=await api('meta-oauth-status.php');return status;}catch(error){status={connected:false};if(showError){lastError=error.message||'Statut Meta indisponible.';notify(lastError);}return null;}finally{loading=false;render();}}
  async function connect(){
    if(connecting)return;connecting=true;lastError='';render();
    try{sessionStorage.removeItem(RESULT_KEY);sessionStorage.removeItem(LEGACY_ERROR_KEY);const data=await api('meta-oauth-start.php',{method:'POST',body:{}});const authorizationUrl=String(data.authorizationUrl||'');if(!authorizationUrl)throw new Error('Le serveur n’a pas retourné l’adresse d’autorisation Meta.');let parsed;try{parsed=new URL(authorizationUrl);}catch{throw new Error('L’adresse d’autorisation Meta reçue est invalide.');}if(!/(^|\.)facebook\.com$/i.test(parsed.hostname))throw new Error('Le serveur a retourné une adresse d’autorisation inattendue.');sessionStorage.setItem('pass50_meta_oauth_return','1');window.location.assign(authorizationUrl);}catch(error){lastError=error?.message||'La connexion Meta n’a pas pu démarrer.';connecting=false;render();notify(lastError);}
  }
  async function disconnect(){if(!confirm('Déconnecter Facebook et Instagram de PASS50 ?'))return;try{const data=await api('meta-oauth-disconnect.php',{method:'POST',body:{}});status={connected:false,configuration:status?.configuration,canManageMappings:status?.canManageMappings};mappingAsset=null;lastError='';render();notify(data.warning||'Comptes Meta déconnectés.');}catch(error){lastError=error.message;render();notify(lastError);}}
  async function refreshAssets(thenCollect=false){
    if(refreshingAssets)return;refreshingAssets=true;lastError='';render();
    try{const data=await api('meta-oauth-refresh-assets.php',{method:'POST',body:{}});const auto=Number(data.autoMapped||0);notify(`${Number(data.assets||0)} compte(s) professionnel(s) retrouvé(s)${auto?` · ${auto} associé(s) auto`:''}.`);await refresh(false);if(thenCollect&&Number(data.assets||0)>0)await collect(true);}catch(error){lastError=error.message||'Impossible de relire les Pages Meta.';notify(lastError);}finally{refreshingAssets=false;render();}
  }
  async function autoMap(){
    if(autoMapping)return;autoMapping=true;lastError='';render();
    try{const data=await api('meta-oauth-auto-map.php',{method:'POST',body:{}});notify(`${Number(data.mapped||0)} association(s) automatique(s) · ${Number(data.checked||0)} compte(s) contrôlé(s).`);await refresh(false);if(Number(data.mapped||0)>0)await collect(true);}catch(error){lastError=error.message||'Association automatique impossible.';notify(lastError);}finally{autoMapping=false;render();}
  }
  async function collect(skipRefresh=false){
    if(!skipRefresh&&(!Array.isArray(status?.assets)||status.assets.length===0)){await refreshAssets(true);return;}
    try{const data=await api('meta-live-collect.php',{method:'POST',body:{}});notify(`${Number(data.activeLives||0)} LIVE Meta actif(s) détecté(s).`);await refresh(false);if(typeof window.PASS50_RUN_LIVE_RADAR==='function')setTimeout(()=>window.PASS50_RUN_LIVE_RADAR(false),250);}catch(error){lastError=error.message;render();notify(lastError);}
  }
  async function loadMappingProfiles(){
    if(Array.isArray(mappingProfiles))return mappingProfiles;
    const data=await api('meta-oauth-mapping-options.php');mappingProfiles=Array.isArray(data.profiles)?data.profiles:[];return mappingProfiles;
  }
  async function openMapping(platform,assetId){
    const asset=(status?.assets||[]).find(item=>item.platform===platform&&item.id===assetId);if(!asset)return;
    try{await loadMappingProfiles();mappingAsset={...asset};lastError='';render();}catch(error){lastError=error.message||'Liste des fiches PASS50 indisponible.';render();notify(lastError);}
  }
  function closeMapping(){if(mappingSaving)return;mappingAsset=null;render();}
  function resolveProfile(value){
    const text=String(value||'').trim();if(!text)return null;const lower=text.toLocaleLowerCase('fr');
    let profile=(mappingProfiles||[]).find(item=>item.id===text||profileLabel(item).toLocaleLowerCase('fr')===lower);
    if(profile)return profile;
    const matches=(mappingProfiles||[]).filter(item=>String(item.name||'').toLocaleLowerCase('fr')===lower||String(item.handle||'').toLocaleLowerCase('fr')===lower);
    return matches.length===1?matches[0]:null;
  }
  async function saveMapping(remove=false){
    if(!mappingAsset||mappingSaving)return;let profile=null;
    if(!remove){const input=document.querySelector('[data-p50-meta-profile-input]');profile=resolveProfile(input?.value);if(!profile){notify('Choisis une fiche PASS50 dans la liste ou saisis son identifiant exact.');input?.focus();return;}}
    mappingSaving=true;render();
    try{
      const data=await api('meta-oauth-map-asset.php',{method:'POST',body:{platform:mappingAsset.platform,assetId:mappingAsset.id,profileId:remove?'':profile.id}});
      notify(remove?'Association retirée.':`${mappingAsset.name||'Compte Meta'} relié à ${data.profileName||profile.name}.`);mappingAsset=null;await refresh(false);
    }catch(error){lastError=error.message||'Association Meta impossible.';notify(lastError);}finally{mappingSaving=false;render();}
  }
  async function result(value,code=''){
    connecting=false;sessionStorage.removeItem('pass50_meta_oauth_return');
    if(value==='connected'){lastError='';sessionStorage.removeItem(LEGACY_ERROR_KEY);notify('Autorisation Meta reçue. Vérification de l’enregistrement…');await refresh(true);if(status?.connected){if(!status.assets?.length)await refreshAssets(false);else notify('Comptes Meta connectés.');return;}if(!lastError)lastError='Meta a autorisé PASS50, mais la connexion n’a pas été enregistrée.';render();return;}
    if(value==='cancelled'){lastError='';notify('Connexion Meta annulée.');await refresh(false);return;}
    lastError=diagnosticMessage(code);sessionStorage.setItem(LEGACY_ERROR_KEY,String(code||'connection_failed'));notify('Connexion Meta non finalisée.');await refresh(false);render();
  }
  function readStoredResult(){let payload=null;try{const raw=sessionStorage.getItem(RESULT_KEY);if(raw)payload=JSON.parse(raw);}catch{}sessionStorage.removeItem(RESULT_KEY);if(payload&&typeof payload==='object'&&payload.source==='PASS50_META_OAUTH')return payload;return null;}
  function consume(){let payload=readStoredResult();const url=new URL(location.href),legacyStatus=url.searchParams.get('meta_oauth');if(!payload&&legacyStatus)payload={source:'PASS50_META_OAUTH',status:legacyStatus,code:url.searchParams.get('meta_oauth_code')||url.searchParams.get('code')||''};if(legacyStatus){url.searchParams.delete('meta_oauth');url.searchParams.delete('meta_oauth_code');url.searchParams.delete('code');history.replaceState(null,'',url.pathname+url.search+url.hash);}if(!payload)return;setTimeout(()=>{document.getElementById('accountBtn')?.click();result(String(payload.status||'error'),String(payload.code||''));},350);}
  function install(){
    styles();document.addEventListener('click',event=>{
      const connectButton=event.target.closest?.('[data-p50-meta-connect]');if(connectButton){event.preventDefault();event.stopPropagation();connect();return;}
      const refreshButton=event.target.closest?.('[data-p50-meta-refresh-assets]');if(refreshButton){event.preventDefault();event.stopPropagation();refreshAssets(false);return;}
      const autoMapButton=event.target.closest?.('[data-p50-meta-auto-map]');if(autoMapButton){event.preventDefault();event.stopPropagation();autoMap();return;}
      const disconnectButton=event.target.closest?.('[data-p50-meta-disconnect]');if(disconnectButton){event.preventDefault();event.stopPropagation();disconnect();return;}
      const collectButton=event.target.closest?.('[data-p50-meta-collect]');if(collectButton){event.preventDefault();event.stopPropagation();collect();return;}
      const mapButton=event.target.closest?.('[data-p50-meta-map]');if(mapButton){event.preventDefault();event.stopPropagation();openMapping(String(mapButton.dataset.platform||''),String(mapButton.dataset.assetId||''));return;}
      if(event.target.closest?.('[data-p50-meta-map-save]')){event.preventDefault();saveMapping(false);return;}
      if(event.target.closest?.('[data-p50-meta-map-remove]')){event.preventDefault();if(confirm('Retirer cette association PASS50 ?'))saveMapping(true);return;}
      if(event.target.closest?.('[data-p50-meta-map-close]')){event.preventDefault();closeMapping();return;}
      if(event.target.matches?.('[data-p50-meta-map-overlay]')){closeMapping();return;}
      if(event.target.closest?.('#accountBtn'))setTimeout(()=>refresh(false),100);
    },true);
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&mappingAsset)closeMapping();});
    window.addEventListener('message',event=>{if(event.origin===location.origin&&event.data?.source==='PASS50_META_OAUTH')result(String(event.data.status||'error'),String(event.data.code||''));});
    const body=document.getElementById('userBody');if(body)new MutationObserver(()=>{if(body.innerHTML.trim()&&token()&&isStaff()){ensure();if(!loading&&status===null)refresh(false);}else if(!isStaff())document.getElementById(SECTION_ID)?.remove();}).observe(body,{childList:true,subtree:false});consume();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
}());
