(function(){
'use strict';
if(window.__pass50LiveDismissUiV1)return;
window.__pass50LiveDismissUiV1=true;

function normalizeUrl(value){try{const url=new URL(String(value||''),location.href);url.hash='';return url.href.replace(/\/+$/,'');}catch{return String(value||'').replace(/\/+$/,'')}}
function currentLives(){try{return Array.isArray(db?.liveStreams)?db.liveStreams:[]}catch{return []}}
function findLiveByProfile(profileId,platform,url){
  const wanted=normalizeUrl(url);
  return currentLives().find(item=>String(item?.profileId||'')===String(profileId)&&String(item?.platform||'')===String(platform)&&item?.status==='live')
    ||currentLives().find(item=>normalizeUrl(item?.url)===wanted&&item?.status==='live')
    ||null;
}
function notify(message){try{if(typeof toast==='function')toast(message)}catch{}}
function enhanceAdmin(){
  document.querySelectorAll('#adminPane .signal').forEach(card=>{
    if(card.querySelector('.p50-live-dismiss'))return;
    const open=card.querySelector('a[href][target="_blank"]');if(!open)return;
    const live=findLiveByProfile('','',open.href);
    if(!live||live.source==='manual')return;
    const actions=card.querySelector('.signal-actions')||open.parentElement;if(!actions)return;
    const button=document.createElement('button');button.type='button';button.className='btn small danger p50-live-dismiss';button.textContent='Supprimer';
    button.dataset.profile=String(live.profileId||'');button.dataset.platform=String(live.platform||'');button.dataset.url=String(live.url||'');actions.appendChild(button);
  });
  const profileBadge=document.querySelector('#profileBody .badge.live-badge');
  if(profileBadge&&!profileBadge.dataset.liveClickable){profileBadge.dataset.liveClickable='1';profileBadge.style.cursor='pointer';profileBadge.title='Regarder le direct';profileBadge.tabIndex=0;profileBadge.setAttribute('role','link');}
}
async function dismiss(button){
  const profileId=String(button.dataset.profile||''),platform=String(button.dataset.platform||''),url=String(button.dataset.url||'');
  if(!profileId||!platform||!url)return notify('Direct introuvable');
  if(!confirm('Supprimer ce faux direct du radar ?\n\nIl ne reviendra pas pendant 7 jours, même si TikTok renvoie un nouveau signal.'))return;
  button.disabled=true;button.textContent='Suppression…';
  try{
    if(typeof apiFetch!=='function')throw new Error('Connexion serveur indisponible');
    await apiFetch('live-dismiss.php',{method:'POST',body:{profileId,platform,url}});
    if(Array.isArray(db?.liveStreams))db.liveStreams=db.liveStreams.filter(item=>!(String(item.profileId)===profileId&&String(item.platform)===platform&&item?.status==='live'));
    try{localStorage.setItem(APP_KEY,JSON.stringify(db))}catch{}
    if(typeof normalizeLiveStreams==='function')normalizeLiveStreams();
    if(typeof render==='function')render();
    if(typeof renderAdminPane==='function')renderAdminPane();
    if(document.getElementById('liveModal')?.classList.contains('show')&&typeof openLives==='function')openLives();
    notify('Faux direct supprimé');
  }catch(error){button.disabled=false;button.textContent='Supprimer';notify(error?.message||'Suppression impossible');}
}
document.addEventListener('click',event=>{const button=event.target.closest?.('.p50-live-dismiss');if(button){event.preventDefault();event.stopImmediatePropagation();dismiss(button);}},true);
document.addEventListener('keydown',event=>{const badge=event.target.closest?.('#profileBody .badge.live-badge');if(badge&&(event.key==='Enter'||event.key===' ')){event.preventDefault();badge.click();}},true);
const observer=new MutationObserver(()=>requestAnimationFrame(enhanceAdmin));observer.observe(document.documentElement,{subtree:true,childList:true});
document.addEventListener('DOMContentLoaded',enhanceAdmin);setTimeout(enhanceAdmin,0);
})();
