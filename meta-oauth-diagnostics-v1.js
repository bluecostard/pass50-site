'use strict';
(function(){
  const KEY='pass50_meta_oauth_error_code';
  const messages={
    public_profile_advanced_access:'Active l’accès avancé à public_profile dans Contrôle app → Autorisations et fonctionnalités, puis relance la connexion.',
    invalid_configuration:'Vérifie que configuration_id correspond bien à PASS50 Meta LIVE dans la nouvelle application Business.',
    redirect_uri_mismatch:'Vérifie que l’URI OAuth valide est exactement https://www.pass50.store/api/meta-oauth-callback.php.',
    invalid_client:'L’App ID ou l’App Secret de PASS50 Business ne correspond pas à la nouvelle application.',
    unsupported_permission:'La configuration Meta contient une permission non prise en charge par Facebook Login for Business.',
    app_not_available:'L’application Meta n’est pas disponible pour cette connexion. Vérifie son état, ton rôle dans l’application et l’accès avancé à public_profile.',
    permissions_missing:'La configuration Business n’a pas accordé toutes les permissions attendues : pages_show_list, pages_read_engagement et instagram_basic.',
    pass50_session_expired:'Ta session PASS50 a expiré pendant la connexion. Reconnecte-toi à PASS50 puis relance Meta.',
    code_exchange_failed:'Meta a autorisé la connexion, mais l’échange du code a échoué. Vérifie l’App ID, l’App Secret et l’URI OAuth.',
    pages_access_failed:'La connexion Meta fonctionne, mais aucune Page gérée n’a pu être lue avec les autorisations accordées.',
    invalid_state:'La vérification de sécurité OAuth a expiré. Recharge PASS50 puis recommence.',
    missing_code:'Meta n’a pas renvoyé de code d’autorisation.',
    connection_failed:'La connexion Meta a échoué après l’autorisation. Relance le test ; PASS50 journalise maintenant la cause côté serveur.'
  };
  const url=new URL(location.href),status=url.searchParams.get('meta_oauth'),code=url.searchParams.get('code');
  if(status==='connected')sessionStorage.removeItem(KEY);else if(code)sessionStorage.setItem(KEY,code);
  function apply(){
    const saved=sessionStorage.getItem(KEY);if(!saved)return;
    const node=document.querySelector('#p50MetaOauthSection .p50-meta-message.error');if(!node)return;
    const message=messages[saved]||`Erreur Meta : ${saved}`;
    node.innerHTML=`<strong>Connexion Meta impossible :</strong> ${message}`;
    node.dataset.p50MetaDiagnostic=saved;
  }
  const observer=new MutationObserver(apply);
  if(document.body)observer.observe(document.body,{childList:true,subtree:true});
  else document.addEventListener('DOMContentLoaded',()=>observer.observe(document.body,{childList:true,subtree:true}),{once:true});
  document.addEventListener('DOMContentLoaded',apply);
  setTimeout(apply,700);
}());
