(function(){
'use strict';
var pendingKey='pass50.profileClaim.pending.v1';
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function connected(){try{return typeof CLOUD!=='undefined'&&CLOUD&&CLOUD.token;}catch(_){return false;}}
async function call(path,options){if(typeof apiFetch==='function')return apiFetch(path,options||{});throw new Error('API PASS50 indisponible');}
function notice(message){if(typeof toast==='function')toast(message);else alert(message);}
function ensure(){
 if(document.getElementById('p50ClaimModal'))return;
 var style=document.createElement('style');style.textContent='.p50-claim-box{display:grid;gap:12px}.p50-claim-intro{padding:14px;border:1px solid #334033;border-radius:14px;background:#0d120d}.p50-claim-networks{display:flex;gap:8px;flex-wrap:wrap}.p50-claim-state{padding:10px 12px;border-radius:12px;background:#182018;color:#dce8d8}.p50-claim-state.ok{border:1px solid #b7ff00}.p50-claim-state.warn{border:1px solid #e7a91a}.p50-claim-admin-card{padding:14px;border:1px solid #303a30;border-radius:14px;margin-bottom:10px;background:#0b100b}.p50-claim-proof{font-size:12px;color:#aeb8aa;word-break:break-word}.p50-claim-account{display:grid;gap:10px;width:100%}.p50-claim-account select{width:100%;padding:11px 12px;border:1px solid #334033;border-radius:12px;background:#0b100b;color:#f6f8f4;font:inherit}.p50-claimed-badge{display:inline-flex;align-items:center;gap:6px;color:#b7ff00;font-weight:900;font-size:12px;margin-top:10px}';
 document.head.appendChild(style);
 var modal=document.createElement('div');modal.className='modal';modal.id='p50ClaimModal';modal.innerHTML='<div class="modal-box" style="width:min(680px,96vw)"><div class="modal-head"><strong>REVENDIQUER CETTE FICHE</strong><button class="close" data-p50-claim-close>×</button></div><div class="modal-body" id="p50ClaimBody"></div></div>';document.body.appendChild(modal);
 modal.addEventListener('click',function(e){if(e.target.hasAttribute('data-p50-claim-close')||e.target===modal)modal.classList.remove('show');});
}
function claimableProfiles(){
 var items=[];
 try{
  if(typeof completeRanking==='function')items=completeRanking();
  else if(typeof db!=='undefined'&&Array.isArray(db.profiles))items=db.profiles;
 }catch(_){}
 return items.filter(function(p){return p&&p.id&&p.alive!==false;}).slice().sort(function(a,b){return String(a.name||'').localeCompare(String(b.name||''),'fr');});
}
function injectAccount(){
 ensure();
 var panel=document.querySelector('#userBody [data-user-fold="account"] .user-panel');
 if(!panel||panel.querySelector('[data-p50-claim-account]'))return;
 var profiles=claimableProfiles();
 var host=document.createElement('div');host.className='pref';host.setAttribute('data-p50-claim-account','1');
 host.innerHTML='<div class="p50-claim-account"><div><strong>Revendiquer une fiche</strong><div class="muted">Sélectionnez votre nom pour vérifier un compte officiel.</div></div><select data-p50-claim-select aria-label="Nom de l’influenceur"><option value="">Choisir une fiche…</option>'+profiles.map(function(p){return '<option value="'+esc(p.id)+'">'+esc(p.name||p.handle||p.id)+'</option>';}).join('')+'</select><button type="button" class="btn primary" data-p50-claim-account-open disabled>Continuer</button></div>';
 var anchor=panel.querySelector('[data-p50-account-danger]');panel.insertBefore(host,anchor||null);
}
async function openClaim(profileId){
 if(!connected()){if(typeof requireAuth==='function')requireAuth();return notice('Connectez-vous d’abord à PASS50.');}
 ensure();var p=typeof profile==='function'?profile(profileId):null;if(!p)return notice('Fiche introuvable');
 var platforms=(Array.isArray(p.platforms)?p.platforms:[]).filter(function(x){return /^(TikTok|Facebook|Instagram|YouTube)$/i.test(x)&&p.links&&p.links[x];});
 var body=document.getElementById('p50ClaimBody');body.innerHTML='<div class="p50-claim-box"><div class="p50-claim-intro"><strong>'+esc(p.name)+'</strong><div class="muted">Choisissez un compte officiel. La plateforme authentifiera directement son propriétaire ; PASS50 ne reçoit jamais son mot de passe.</div></div><div class="p50-claim-networks">'+platforms.map(function(x){return '<button class="btn primary" data-p50-claim-platform="'+esc(x)+'">Vérifier avec '+esc(x)+'</button>';}).join('')+'</div><div id="p50ClaimMessage" class="p50-claim-state">Après la connexion, la demande sera comparée au lien officiel déjà protégé sur cette fiche.</div></div>';
 body.dataset.profileId=profileId;document.getElementById('p50ClaimModal').classList.add('show');
}
function oauthEndpoint(platform){platform=platform.toLowerCase();if(platform==='tiktok')return 'tiktok-oauth-start.php';if(platform==='youtube')return 'youtube-oauth-start.php';return 'meta-oauth-start.php';}
async function submit(profileId,platform,allowConnect){
 var message=document.getElementById('p50ClaimMessage');if(message)message.textContent='Vérification sécurisée en cours…';
 try{
  var result=await call('profile-claims.php',{method:'POST',body:{action:'submit',profileId:profileId,platform:platform}});
  localStorage.removeItem(pendingKey);if(message){message.className='p50-claim-state '+(result.matchStatus==='exact'?'ok':'warn');message.textContent=result.message;}notice(result.message);return;
 }catch(err){
  var text=String(err&&err.message||err);
  if(allowConnect&&(/Connectez d’abord|connection_required/i.test(text))){
   localStorage.setItem(pendingKey,JSON.stringify({profileId:profileId,platform:platform,createdAt:Date.now()}));
   var start=await call(oauthEndpoint(platform),{method:'POST',body:{}});if(!start.authorizationUrl)throw new Error('Lien de connexion indisponible');location.href=start.authorizationUrl;return;
  }
  if(message){message.className='p50-claim-state warn';message.textContent=text;}notice(text);
 }
}
async function resume(){
 var raw=localStorage.getItem(pendingKey);if(!raw)return;var item;try{item=JSON.parse(raw);}catch(_){localStorage.removeItem(pendingKey);return;}
 if(!item||Date.now()-Number(item.createdAt||0)>3600000){localStorage.removeItem(pendingKey);return;}
 setTimeout(function(){submit(item.profileId,item.platform,false);},1200);
}
async function renderAdmin(){
 var pane=document.getElementById('adminPane');if(!pane)return;pane.innerHTML='<div class="section-head"><div><div class="section-title">REVENDICATIONS DE FICHES</div><div class="muted">Preuves OAuth comparées aux comptes officiels protégés.</div></div></div><div id="p50ClaimAdminList">Chargement…</div>';
 try{
  var data=await call('profile-claims.php?admin=1');var list=document.getElementById('p50ClaimAdminList');var claims=data.claims||[];
  if(!claims.length){list.innerHTML='<div class="note">Aucune revendication reçue.</div>';return;}
  list.innerHTML=claims.map(function(c){var p=typeof profile==='function'?profile(c.profile_id):null;return '<article class="p50-claim-admin-card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><strong>'+esc(p?p.name:c.profile_id)+'</strong><div>'+esc(c.display_name||c.email)+' · '+esc(c.platform)+'</div></div><span class="status '+(c.match_status==='exact'?'validated':'pending')+'">'+esc(c.match_status==='exact'?'CORRESPONDANCE EXACTE':'À CONTRÔLER')+'</span></div><div class="p50-claim-proof">Compte connecté : '+esc(c.network_username||c.network_account_id)+'<br>Compte attendu : '+esc(c.expected_url)+'<br>'+esc(c.match_reason)+'<br>Demande : '+esc(c.submitted_at)+'</div>'+(c.status==='pending'?'<div style="display:flex;gap:8px;margin-top:10px"><button class="btn small primary" data-p50-review="approve" data-id="'+esc(c.id)+'">Valider</button><button class="btn small danger" data-p50-review="reject" data-id="'+esc(c.id)+'">Refuser</button></div>':'<div class="p50-claim-state '+(c.status==='approved'?'ok':'warn')+'" style="margin-top:10px">'+esc(c.status==='approved'?'Validée':'Refusée')+(c.review_note?' · '+esc(c.review_note):'')+'</div>')+'</article>';}).join('');
 }catch(err){document.getElementById('p50ClaimAdminList').textContent=String(err.message||err);}
}
function injectAdmin(){
 var menu=document.querySelector('#adminModal .admin-menu');if(!menu||menu.querySelector('[data-p50-claims-admin]'))return;
 var b=document.createElement('button');b.className='btn';b.textContent='Revendications';b.setAttribute('data-p50-claims-admin','1');menu.appendChild(b);
}
document.addEventListener('change',function(e){
 if(e.target.matches('[data-p50-claim-select]')){
  var button=document.querySelector('[data-p50-claim-account-open]');
  if(button)button.disabled=!e.target.value;
 }
});
document.addEventListener('click',function(e){
 var accountClaim=e.target.closest('[data-p50-claim-account-open]');if(accountClaim){var select=document.querySelector('[data-p50-claim-select]');if(select&&select.value)openClaim(select.value);return;}
 var platform=e.target.closest('[data-p50-claim-platform]');if(platform){var body=document.getElementById('p50ClaimBody');submit(body.dataset.profileId,platform.getAttribute('data-p50-claim-platform'),true);return;}
 var admin=e.target.closest('[data-p50-claims-admin]');if(admin){document.querySelectorAll('#adminModal .admin-menu .btn').forEach(function(x){x.classList.remove('primary');});admin.classList.add('primary');renderAdmin();return;}
 var review=e.target.closest('[data-p50-review]');if(review){var decision=review.getAttribute('data-p50-review');var note='';if(decision==='reject'||!review.closest('.p50-claim-admin-card').textContent.includes('CORRESPONDANCE EXACTE'))note=prompt('Motif de la décision :')||'';call('profile-claims.php',{method:'POST',body:{action:'review',claimId:review.getAttribute('data-id'),decision:decision,note:note}}).then(function(){notice('Décision enregistrée');renderAdmin();}).catch(function(err){notice(err.message||err);});}
});
new MutationObserver(function(){injectAccount();injectAdmin();}).observe(document.documentElement,{childList:true,subtree:true});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',function(){ensure();resume();});else{ensure();resume();}
})();