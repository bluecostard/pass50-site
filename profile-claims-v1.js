(function(){
'use strict';
var pendingKey='pass50.profileClaim.pending.v1';
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function connected(){try{return typeof CLOUD!=='undefined'&&CLOUD&&CLOUD.token;}catch(_){return false;}}
async function call(path,options){if(typeof apiFetch==='function')return apiFetch(path,options||{});throw new Error('API PASS50 indisponible');}
function notice(message){if(typeof toast==='function')toast(message);else alert(message);}
function ensure(){
 if(document.getElementById('p50ClaimModal'))return;
 var style=document.createElement('style');style.textContent='.p50-claim-box{display:grid;gap:12px}.p50-claim-intro{padding:14px;border:1px solid #334033;border-radius:14px;background:#0d120d}.p50-claim-networks{display:flex;gap:8px;flex-wrap:wrap}.p50-claim-state{padding:10px 12px;border-radius:12px;background:#182018;color:#dce8d8}.p50-claim-state.ok{border:1px solid #b7ff00}.p50-claim-state.warn{border:1px solid #e7a91a}.p50-claim-admin-card{padding:14px;border:1px solid #303a30;border-radius:14px;margin-bottom:10px;background:#0b100b}.p50-claim-proof{font-size:12px;color:#aeb8aa;word-break:break-word}.p50-claimed-badge{display:inline-flex;align-items:center;gap:6px;color:#b7ff00;font-weight:900;font-size:12px;margin-top:10px}';
 document.head.appendChild(style);
 var modal=document.createElement('div');modal.className='modal';modal.id='p50ClaimModal';modal.innerHTML='<div class="modal-box" style="width:min(680px,96vw)"><div class="modal-head"><strong>REVENDIQUER CETTE FICHE</strong><button class="close" data-p50-claim-close>×</button></div><div class="modal-body" id="p50ClaimBody"></div></div>';document.body.appendChild(modal);
 modal.addEventListener('click',function(e){if(e.target.hasAttribute('data-p50-claim-close')||e.target===modal)modal.classList.remove('show');});
}
async function publicStatus(profileId,host){
 try{var data=await call('profile-claims.php?profileId='+encodeURIComponent(profileId));if(data.claimed){host.innerHTML='<span class="p50-claimed-badge">✓ Profil revendiqué · '+esc(data.platform)+'</span>';return true;}}catch(_){}
 return false;
}
function injectProfile(){
 ensure();var body=document.getElementById('profileBody');if(!body||!body.children.length||body.querySelector('[data-p50-claim-host]'))return;
 var marker=body.querySelector('.fav[data-id],.follow[data-id]');var profileId=marker&&marker.getAttribute('data-id');if(!profileId)return;
 var host=document.createElement('div');host.setAttribute('data-p50-claim-host','1');host.style.marginTop='14px';
 host.innerHTML='<button type="button" class="btn small" data-p50-claim="'+esc(profileId)+'">Revendiquer cette fiche</button><div class="muted" style="font-size:12px;margin-top:6px">Réservé au propriétaire de ses comptes officiels.</div>';
 var actions=body.querySelector('.card-actions')||body;actions.appendChild(host);publicStatus(profileId,host);
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
document.addEventListener('click',function(e){
 var claim=e.target.closest('[data-p50-claim]');if(claim){openClaim(claim.getAttribute('data-p50-claim'));return;}
 var platform=e.target.closest('[data-p50-claim-platform]');if(platform){var body=document.getElementById('p50ClaimBody');submit(body.dataset.profileId,platform.getAttribute('data-p50-claim-platform'),true);return;}
 var admin=e.target.closest('[data-p50-claims-admin]');if(admin){document.querySelectorAll('#adminModal .admin-menu .btn').forEach(function(x){x.classList.remove('primary');});admin.classList.add('primary');renderAdmin();return;}
 var review=e.target.closest('[data-p50-review]');if(review){var decision=review.getAttribute('data-p50-review');var note='';if(decision==='reject'||!review.closest('.p50-claim-admin-card').textContent.includes('CORRESPONDANCE EXACTE'))note=prompt('Motif de la décision :')||'';call('profile-claims.php',{method:'POST',body:{action:'review',claimId:review.getAttribute('data-id'),decision:decision,note:note}}).then(function(){notice('Décision enregistrée');renderAdmin();}).catch(function(err){notice(err.message||err);});}
});
new MutationObserver(function(){injectProfile();injectAdmin();}).observe(document.documentElement,{childList:true,subtree:true});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',function(){ensure();resume();});else{ensure();resume();}
})();