(function(){
'use strict';
function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function inject(){
 var menu=document.querySelector('#adminModal .admin-menu');
 if(!menu||menu.querySelector('[data-p50-coules-votes]'))return;
 var button=document.createElement('button');button.className='btn';button.textContent='Votes Les Coulés';button.setAttribute('data-p50-coules-votes','1');menu.appendChild(button);
}
function candidateName(id){try{var p=typeof profile==='function'?profile(id):null;return p&&p.name?p.name:id;}catch(_){return id;}}
function fmt(date){if(!date)return '—';var parsed=new Date(String(date).replace(' ','T')+'Z');return Number.isNaN(parsed.getTime())?String(date):parsed.toLocaleString('fr-FR',{dateStyle:'short',timeStyle:'short'});}
async function render(){
 var pane=document.getElementById('adminPane');if(!pane)return;
 var poll=typeof coulesPollKey==='function'?coulesPollKey():'';
 pane.innerHTML='<div class="section-head"><div><div class="section-title">VOTES · LES COULÉS</div><div class="muted">Détail du duel actuellement affiché.</div></div><button class="btn" data-p50-coules-refresh>Actualiser</button></div><div id="p50CoulesVotesBody" class="muted">Chargement…</div>';
 var body=document.getElementById('p50CoulesVotesBody');
 if(!poll||poll==='aucun_duel'){body.textContent='Aucun duel actif.';return;}
 try{
  var data=await apiFetch('coules.php?admin=1&poll='+encodeURIComponent(poll),{auth:true});
  var totals=data.totals||{},items=Array.isArray(data.items)?data.items:[];
  var cards=Object.keys(totals).map(function(id){return '<div class="stat"><span class="muted">'+esc(candidateName(id))+'</span><b>'+Number(totals[id]||0)+'</b><span class="muted">vote'+(Number(totals[id]||0)>1?'s':'')+'</span></div>';}).join('');
  body.innerHTML='<div class="stats"><div class="stat"><span class="muted">Total</span><b>'+Number(data.total||0)+'</b><span class="muted">votant'+(Number(data.total||0)>1?'s':'')+'</span></div>'+cards+'</div>'+
   (items.length?'<div style="overflow:auto;margin-top:16px"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:9px">Votant</th><th style="text-align:left;padding:9px">Choix</th><th style="text-align:left;padding:9px">Date</th></tr></thead><tbody>'+items.map(function(item){return '<tr style="border-top:1px solid #293129"><td style="padding:9px"><strong>'+esc(item.displayName||'Sans pseudo')+'</strong><div class="muted">'+esc(item.email||item.userId)+'</div></td><td style="padding:9px">'+esc(candidateName(item.profileId))+'</td><td style="padding:9px">'+esc(fmt(item.votedAt))+'</td></tr>';}).join('')+'</tbody></table></div>':'<div class="note" style="margin-top:14px">Aucun vote enregistré pour ce duel.</div>');
 }catch(error){body.textContent=String(error.message||'Impossible de charger les votes.');}
}
document.addEventListener('click',function(event){
 var open=event.target.closest('[data-p50-coules-votes]');if(open){document.querySelectorAll('#adminModal .admin-menu .btn').forEach(function(x){x.classList.remove('primary');});open.classList.add('primary');render();return;}
 if(event.target.closest('[data-p50-coules-refresh]'))render();
});
new MutationObserver(inject).observe(document.documentElement,{childList:true,subtree:true});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',inject,{once:true});else inject();
})();
