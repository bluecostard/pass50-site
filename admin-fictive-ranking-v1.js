(function(){
  'use strict';
  function install(){
    var body=document.getElementById('adminBody');
    if(!body||body.querySelector('[data-pass50-fictive-ranking]'))return;
    var card=document.createElement('section');card.dataset.pass50FictiveRanking='1.0';
    card.style.cssText='margin:0 0 14px;padding:14px;border:1px solid #ff9d1d;border-radius:16px;background:rgba(255,157,29,.08)';
    card.innerHTML='<strong style="color:#ffd08a">CLASSEMENT FICTIF MR‑V1.0</strong><p style="margin:7px 0 11px;color:#cbd3c8;font-size:13px">Consultez le candidat expérimental et la couverture X, TikTok, Instagram et Facebook. Le classement public reste inchangé.</p><a class="btn small" href="./classement-fictif.html" target="_blank" rel="noopener">Ouvrir le classement fictif</a>';
    body.prepend(card);
  }
  document.addEventListener('DOMContentLoaded',function(){var target=document.getElementById('adminBody');if(!target)return;new MutationObserver(install).observe(target,{childList:true,subtree:false});install()});
})();
