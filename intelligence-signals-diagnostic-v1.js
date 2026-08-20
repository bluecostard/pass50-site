(function(){
  'use strict';

  const RUNTIME='PASS50-INTELLIGENCE-SIGNALS-DIAGNOSTIC-V1.0';
  let running=false;
  let lastPane=null;

  function esc(value){
    return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  }

  function number(value){
    return Number(value||0).toLocaleString('fr-FR');
  }

  async function renderDiagnostic(){
    const content=document.getElementById('p50IntelligenceSignalsContent');
    const shell=content?.closest('.p50is-shell');
    if(!content||!shell||running||lastPane===shell)return;
    if(typeof apiFetch!=='function')return;
    running=true;
    try{
      const data=await apiFetch('intelligence.php');
      if(!document.documentElement.contains(shell))return;
      const refresh=data.liveRefresh||{};
      const errors=Array.isArray(refresh.errors)?refresh.errors:[];
      let diagnostic=shell.querySelector('#p50isRuntimeDiagnostic');
      if(!diagnostic){
        diagnostic=document.createElement('div');
        diagnostic.id='p50isRuntimeDiagnostic';
        diagnostic.className='p50is-note';
        shell.insertBefore(diagnostic,content);
      }
      diagnostic.style.borderColor=errors.length?'#805d26':'#355b35';
      diagnostic.innerHTML=`<strong>Synchronisation vérifiée :</strong> ${number(refresh.liveSignalsImported)} live(s) radar relié(s) · ${number(refresh.activitySignalsImported)} événement(s) récent(s) · ${number(refresh.manualSignalsImported)} ancien(s) signal(aux) repris · ${number(refresh.processed)} profil(s) recalculé(s) à l’ouverture · ${number(errors.length)} erreur(s).`;
      diagnostic.title=errors.length?errors.map(item=>String(item.error||item.profileId||'Erreur')).join('\n'):RUNTIME;
      lastPane=shell;
    }catch(error){
      if(!document.documentElement.contains(shell))return;
      let diagnostic=shell.querySelector('#p50isRuntimeDiagnostic');
      if(!diagnostic){
        diagnostic=document.createElement('div');
        diagnostic.id='p50isRuntimeDiagnostic';
        diagnostic.className='p50is-error';
        shell.insertBefore(diagnostic,content);
      }
      diagnostic.innerHTML=`<strong>Diagnostic de synchronisation indisponible :</strong> ${esc(error?.message||'erreur inconnue')}`;
      lastPane=shell;
    }finally{
      running=false;
    }
  }

  function schedule(){
    const shell=document.querySelector('.p50is-shell');
    if(shell!==lastPane)setTimeout(renderDiagnostic,500);
  }

  const observer=new MutationObserver(schedule);
  observer.observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('click',event=>{
    if(event.target.closest('#p50isReload,[data-admin-tab="intelligence"]')){
      lastPane=null;
      setTimeout(renderDiagnostic,900);
    }
  },true);
  schedule();
  window.PASS50_INTELLIGENCE_SIGNALS_DIAGNOSTIC=RUNTIME;
})();
