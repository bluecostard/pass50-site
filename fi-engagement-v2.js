(function(){
'use strict';

const API=(window.PASS50_API?.baseUrl||'./api')+'/fi-engagement.php';
let requestedProfileId='';

const css=`.p50-fi-actions{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}.p50-fi-action{flex:1;min-width:120px;border:1px solid #293129;background:#0a0d0a;color:#f6f8f4;border-radius:999px;padding:9px 13px;font-weight:900}.p50-fi-action:hover,.p50-fi-action.on{border-color:#b7ff00;color:#b7ff00}.p50-verified{display:inline-flex;align-items:center;gap:5px;margin-left:7px;padding:3px 7px;border:1px solid rgba(183,255,0,.45);border-radius:999px;color:#b7ff00;background:rgba(183,255,0,.08);font-size:10px;font-weight:950;vertical-align:middle;cursor:help}.p50-home-like{flex:1!important;min-width:0!important}.p50-home-like.on{border-color:#b7ff00!important;color:#b7ff00!important}.p50-admin-metrics{margin:16px 0;padding:14px;border:1px solid #293129;border-radius:16px;background:#0b0e0b}.p50-admin-metrics table{width:100%;border-collapse:collapse}.p50-admin-metrics th,.p50-admin-metrics td{text-align:left;padding:8px;border-bottom:1px solid #293129;font-size:12px}`;
const style=document.createElement('style');
style.textContent=css;
document.head.appendChild(style);

function pById(id){
  try{return db.profiles.find(x=>x.id===id)}catch{return null}
}

function getProfileId(){
  const body=document.getElementById('profileBody');
  if(!body)return '';

  const handle=(body.textContent.match(/@[A-Za-z0-9._-]+/)||[])[0];
  if(handle){
    try{
      const current=db.profiles.find(p=>p.handle===handle);
      if(current){requestedProfileId=current.id;return current.id;}
    }catch{}
  }

  const actionId=body.querySelector('.p50-fi-actions[data-profile-id]')?.dataset.profileId;
  if(actionId&&pById(actionId)){requestedProfileId=actionId;return actionId;}
  return requestedProfileId&&pById(requestedProfileId)?requestedProfileId:'';
}

function liked(id){return localStorage.getItem('pass50.like.'+id)==='1'}

async function record(action,profileId,extra={}){
  try{
    const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,profileId,...extra})});
    if(!r.ok)throw new Error();
    return await r.json();
  }catch{return null}
}

async function share(url,title,text,action,profileId,extra={}){
  let done=false;
  try{
    if(navigator.share){await navigator.share({title,text,url});done=true;}
    else{await navigator.clipboard.writeText(url);toast?.('Lien copié');done=true;}
  }catch(e){
    if(e?.name!=='AbortError'){
      try{await navigator.clipboard.writeText(url);toast?.('Lien copié');done=true;}catch{}
    }
  }
  if(done)record(action,profileId,extra);
}

function isVerified(p){return p?.verifiedPass50!==false}
function verifiedBadge(p){
  return isVerified(p)?'<span class="p50-verified" tabindex="0" title="Les liens et informations de cette fiche ont été vérifiés par PASS50." aria-label="Vérifié PASS50. Les liens et informations de cette fiche ont été vérifiés par PASS50.">✓ Vérifié PASS50</span>':'';
}

function enhanceProfile(forcedId=''){
  const body=document.getElementById('profileBody');
  if(!body)return;
  const id=forcedId||getProfileId();
  const p=pById(id);
  if(!p)return;
  requestedProfileId=id;

  body.querySelectorAll('.p50-fi-actions,.p50-verified').forEach(el=>el.remove());
  const handle=body.querySelector('.handle');
  if(handle)handle.insertAdjacentHTML('beforeend',verifiedBadge(p));

  const left=body.querySelector('.profile-grid .left');
  if(!left)return;
  const live=(typeof activeLives==='function'?activeLives():[]).find(x=>x.profileId===id);
  const actions=document.createElement('div');
  actions.className='p50-fi-actions';
  actions.dataset.profileId=id;
  actions.innerHTML=`<button class="p50-fi-action p50-like ${liked(id)?'on':''}" data-id="${id}" aria-pressed="${liked(id)}">♥ J’aime</button><button class="p50-fi-action p50-share-fi" data-id="${id}">↗ Partager</button>${live?`<button class="p50-fi-action p50-share-live" data-id="${id}" data-live="${encodeURIComponent(live.url)}">● Partager le live</button>`:''}`;
  const avatar=left.querySelector('.avatar');
  if(avatar)avatar.insertAdjacentElement('afterend',actions);else left.prepend(actions);
}

function enhanceHomeLikes(){
  document.querySelectorAll('.rank-card[data-profile]').forEach(card=>{
    const id=card.dataset.profile;
    if(!id)return;
    card.querySelectorAll('.avatar .p50-home-like').forEach(el=>el.remove());
    const actions=card.querySelector('.card-actions');
    if(!actions||actions.querySelector('.p50-home-like'))return;
    const follow=actions.querySelector('.follow');
    const button=document.createElement('button');
    button.type='button';
    button.className=`btn p50-home-like p50-like ${liked(id)?'on':''}`;
    button.dataset.id=id;
    button.setAttribute('aria-label',`J’aime ${pById(id)?.name||'ce profil'}`);
    button.setAttribute('aria-pressed',liked(id)?'true':'false');
    button.textContent='♥ J’aime';
    if(follow)actions.insertBefore(button,follow);else actions.appendChild(button);
  });
}

function enhanceLiveModal(){
  document.querySelectorAll('#liveBody .live-card').forEach(card=>{
    if(card.querySelector('.p50-share-live'))return;
    const link=card.querySelector('.live-watch-link');
    if(!link)return;
    const p=[...db.profiles].find(x=>card.textContent.includes(x.name));
    if(!p)return;
    const b=document.createElement('button');
    b.className='btn p50-share-live';
    b.dataset.id=p.id;
    b.dataset.live=encodeURIComponent(link.href);
    b.textContent='PARTAGER LE LIVE';
    link.after(b);
  });
}

async function adminMetrics(){
  const pane=document.getElementById('adminPane');
  if(!pane||pane.querySelector('.p50-admin-metrics'))return;
  let u;
  try{u=currentUser()}catch{}
  if(!u||!['owner','admin'].includes(u.role))return;
  try{
    const token=localStorage.getItem('pass50_api_token')||'';
    const r=await fetch(API,{headers:token?{Authorization:'Bearer '+token}:{}});
    if(!r.ok)return;
    const data=await r.json();
    const rows=(data.profiles||[]).slice(0,50).map(x=>`<tr><td>${x.name||x.profileId}</td><td>${x.likes}</td><td>${x.profileShares}</td><td>${x.liveShares}</td></tr>`).join('');
    const box=document.createElement('section');
    box.className='p50-admin-metrics';
    box.innerHTML=`<strong>Engagement des fiches</strong><div class="muted" style="margin:5px 0 10px">Compteurs internes, invisibles au public.</div><table><thead><tr><th>Influenceur</th><th>Likes</th><th>Partages FI</th><th>Partages live</th></tr></thead><tbody>${rows||'<tr><td colspan="4">Aucune interaction</td></tr>'}</tbody></table>`;
    pane.prepend(box);
  }catch{}
}

function adminVerifiedToggle(){
  const form=document.getElementById('profileForm');
  if(!form||form.querySelector('[name="verifiedPass50"]'))return;
  const id=form.dataset.id,p=pById(id);
  const label=document.createElement('label');
  label.innerHTML=`<input type="checkbox" name="verifiedPass50" ${isVerified(p)?'checked':''}> Badge Vérifié PASS50`;
  form.querySelector('button[type="submit"]')?.parentElement?.before(label);
  form.addEventListener('submit',()=>{if(p){p.verifiedPass50=form.elements.verifiedPass50.checked;save()}},{capture:true});
}

document.addEventListener('p50:profile-change',e=>{
  const id=String(e.detail?.profileId||'');
  if(id&&pById(id))requestedProfileId=id;
});

document.addEventListener('click',async e=>{
  const trigger=e.target.closest('[data-profile]');
  if(trigger?.dataset.profile)requestedProfileId=trigger.dataset.profile;

  const like=e.target.closest('.p50-like');
  if(like){
    e.preventDefault();e.stopPropagation();
    const id=like.dataset.id;
    if(liked(id)){toast?.('Vous avez déjà aimé cette fiche');return;}
    const res=await record('like',id);
    if(res?.ok){
      localStorage.setItem('pass50.like.'+id,'1');
      document.querySelectorAll(`.p50-like[data-id="${CSS.escape(id)}"]`).forEach(btn=>{btn.classList.add('on');btn.setAttribute('aria-pressed','true')});
      toast?.('Merci pour votre soutien');
    }
  }

  const sf=e.target.closest('.p50-share-fi');
  if(sf){
    const p=pById(sf.dataset.id),url=location.origin+location.pathname+'?profile='+encodeURIComponent(p.id);
    share(url,`Fiche PASS50 de ${p.name}`,`Découvre la fiche PASS50 de ${p.name}`,'profile_share',p.id);
  }

  const sl=e.target.closest('.p50-share-live');
  if(sl){
    const p=pById(sl.dataset.id),url=decodeURIComponent(sl.dataset.live||'');
    share(url,`${p?.name||'Influenceur'} est en direct`,`Regarde ce live partagé depuis PASS50`,'live_share',sl.dataset.id,{liveUrl:url});
  }
},true);

const obs=new MutationObserver(()=>{
  requestAnimationFrame(()=>{enhanceProfile();enhanceHomeLikes();enhanceLiveModal();adminVerifiedToggle();adminMetrics()});
});
obs.observe(document.body,{subtree:true,childList:true});

document.addEventListener('DOMContentLoaded',()=>{
  try{db.profiles.forEach(p=>{if(typeof p.verifiedPass50!=='boolean')p.verifiedPass50=true});save()}catch{}
  enhanceProfile();
  enhanceHomeLikes();
});
})();
