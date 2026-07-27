(function(){
'use strict';

const API=(window.PASS50_API?.baseUrl||'./api')+'/fi-engagement.php';
let requestedProfileId='';
let scheduled=false;

const css=`.p50-fi-actions{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}.p50-fi-action{flex:1;min-width:120px;border:1px solid #293129;background:#0a0d0a;color:#f6f8f4;border-radius:999px;padding:9px 13px;font-weight:900}.p50-fi-action:hover,.p50-fi-action.on{border-color:#b7ff00;color:#b7ff00}.p50-verified{display:inline-flex;align-items:center;gap:5px;margin-left:7px;padding:3px 7px;border:1px solid rgba(183,255,0,.45);border-radius:999px;color:#b7ff00;background:rgba(183,255,0,.08);font-size:10px;font-weight:950;vertical-align:middle;cursor:help}.p50-home-like{flex:1!important;min-width:0!important}.p50-home-like.on{border-color:#b7ff00!important;color:#b7ff00!important}.p50-admin-metrics{margin:16px 0;padding:14px;border:1px solid #293129;border-radius:16px;background:#0b0e0b}.p50-admin-metrics-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap}.p50-admin-metrics-summary{margin-top:5px;color:#aeb8aa;font-size:12px}.p50-admin-metrics-search{width:min(290px,100%);padding:10px 12px;border:1px solid #293129;border-radius:12px;background:#0f130f;color:#fff}.p50-admin-metrics-table-wrap{max-height:430px;overflow:auto;margin-top:12px;border:1px solid #202820;border-radius:12px}.p50-admin-metrics table{width:100%;border-collapse:collapse}.p50-admin-metrics thead{position:sticky;top:0;z-index:1;background:#0b0e0b}.p50-admin-metrics th,.p50-admin-metrics td{text-align:left;padding:8px;border-bottom:1px solid #293129;font-size:12px}.p50-admin-metrics td:first-child strong{display:block}.p50-admin-metrics td:first-child small{display:block;margin-top:2px;color:#8f9a8c}.p50-admin-metrics tr.p50-metric-zero{color:#8f9a8c}.p50-admin-metrics-footer{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:9px;color:#8f9a8c;font-size:11px}`;
const style=document.createElement('style');
style.textContent=css;
document.head.appendChild(style);

function pById(id){try{return db.profiles.find(x=>x.id===id)}catch{return null}}
function liked(id){return localStorage.getItem('pass50.like.'+id)==='1'}
function isVerified(p){return p?.verifiedPass50!==false}
function verifiedBadge(){return '<span class="p50-verified" tabindex="0" title="Les liens et informations de cette fiche ont été vérifiés par PASS50." aria-label="Vérifié PASS50. Les liens et informations de cette fiche ont été vérifiés par PASS50.">✓ Vérifié PASS50</span>'}
function esc(value=''){return String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]))}
function engagementSearchKey(value=''){return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase()}

function getProfileId(){
  const body=document.getElementById('profileBody');
  if(!body)return '';
  const handle=(body.textContent.match(/@[A-Za-z0-9._-]+/)||[])[0];
  if(handle){
    const found=(()=>{try{return db.profiles.find(p=>p.handle===handle)}catch{return null}})();
    if(found)return found.id;
  }
  const navigationId=body.dataset.p50CurrentProfile;
  if(navigationId&&pById(navigationId))return navigationId;
  const actionId=body.querySelector('.p50-fi-actions[data-profile-id]')?.dataset.profileId;
  if(actionId&&pById(actionId))return actionId;
  return requestedProfileId&&pById(requestedProfileId)?requestedProfileId:'';
}

async function record(action,profileId,extra={}){
  try{
    const response=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,profileId,...extra})});
    if(!response.ok)throw new Error();
    return await response.json();
  }catch{return null}
}

async function share(url,title,text,action,profileId,extra={}){
  let done=false;
  try{
    if(navigator.share){await navigator.share({title,text,url});done=true;}
    else{await navigator.clipboard.writeText(url);toast?.('Lien copié');done=true;}
  }catch(error){
    if(error?.name!=='AbortError'){
      try{await navigator.clipboard.writeText(url);toast?.('Lien copié');done=true;}catch{}
    }
  }
  if(done)record(action,profileId,extra);
}

function enhanceProfile(forcedId=''){
  const body=document.getElementById('profileBody');
  if(!body||!body.querySelector('.profile-grid'))return;
  const id=forcedId||getProfileId();
  const profile=pById(id);
  if(!profile)return;
  requestedProfileId=id;

  const existingActions=body.querySelector('.p50-fi-actions');
  const existingBadge=body.querySelector('.p50-verified');
  const verified=isVerified(profile);
  const alreadyCorrect=body.dataset.p50EngagementProfile===id&&existingActions?.dataset.profileId===id&&Boolean(existingBadge)===verified;
  if(alreadyCorrect){
    const like=existingActions.querySelector('.p50-like');
    if(like){
      const active=liked(id);
      like.classList.toggle('on',active);
      like.setAttribute('aria-pressed',active?'true':'false');
    }
    return;
  }

  body.querySelectorAll('.p50-fi-actions,.p50-verified').forEach(element=>element.remove());
  const handle=body.querySelector('.handle');
  if(handle&&verified)handle.insertAdjacentHTML('beforeend',verifiedBadge());

  const left=body.querySelector('.profile-grid .left');
  if(!left)return;
  const live=(typeof activeLives==='function'?activeLives():[]).find(item=>item.profileId===id);
  const actions=document.createElement('div');
  actions.className='p50-fi-actions';
  actions.dataset.profileId=id;
  actions.innerHTML=`<button class="p50-fi-action p50-like ${liked(id)?'on':''}" data-id="${id}" aria-pressed="${liked(id)?'true':'false'}">♥ J’aime</button><button class="p50-fi-action p50-share-fi" data-id="${id}">↗ Partager</button>${live?`<button class="p50-fi-action p50-share-live" data-id="${id}" data-live="${encodeURIComponent(live.url)}">● Partager le live</button>`:''}`;
  const avatar=left.querySelector('.avatar');
  if(avatar)avatar.insertAdjacentElement('afterend',actions);else left.prepend(actions);
  body.dataset.p50EngagementProfile=id;
}

function enhanceHomeLikes(){
  document.querySelectorAll('.rank-card[data-profile]').forEach(card=>{
    const id=card.dataset.profile;
    if(!id)return;
    const actions=card.querySelector('.card-actions');
    if(!actions)return;
    let button=actions.querySelector('.p50-home-like');
    if(!button){
      button=document.createElement('button');
      button.type='button';
      button.className='btn p50-home-like p50-like';
      const follow=actions.querySelector('.follow');
      if(follow)actions.insertBefore(button,follow);else actions.appendChild(button);
    }
    const active=liked(id);
    button.dataset.id=id;
    button.classList.toggle('on',active);
    button.setAttribute('aria-label',`J’aime ${pById(id)?.name||'ce profil'}`);
    button.setAttribute('aria-pressed',active?'true':'false');
    if(button.textContent!=='♥ J’aime')button.textContent='♥ J’aime';
  });
}

function enhanceLiveModal(){
  document.querySelectorAll('#liveBody .live-card').forEach(card=>{
    if(card.querySelector('.p50-share-live'))return;
    const link=card.querySelector('.live-watch-link');
    if(!link)return;
    const profile=[...db.profiles].find(item=>card.textContent.includes(item.name));
    if(!profile)return;
    const button=document.createElement('button');
    button.className='btn p50-share-live';
    button.dataset.id=profile.id;
    button.dataset.live=encodeURIComponent(link.href);
    button.textContent='PARTAGER LE LIVE';
    link.after(button);
  });
}

function completeEngagementRows(serverProfiles=[]){
  const metricsById=new Map((serverProfiles||[]).map(item=>[String(item.profileId||''),item]));
  let localProfiles=[];
  let rankedProfiles=[];
  try{localProfiles=Array.isArray(db.profiles)?db.profiles:[]}catch{}
  try{rankedProfiles=typeof ranking==='function'?ranking():[]}catch{}
  const rankById=new Map(rankedProfiles.map((item,index)=>[String(item.id),index]));
  const seen=new Set();
  const rows=localProfiles.map(profileItem=>{
    const id=String(profileItem.id||'');
    const metrics=metricsById.get(id)||{};
    const likes=Number(metrics.likes||0),profileShares=Number(metrics.profileShares||0),liveShares=Number(metrics.liveShares||0);
    seen.add(id);
    return {
      profileId:id,
      name:String(profileItem.name||profileItem.handle||id||'Profil'),
      handle:String(profileItem.handle||''),
      likes,profileShares,liveShares,total:likes+profileShares+liveShares,
      order:rankById.has(id)?rankById.get(id):999999,
    };
  });
  (serverProfiles||[]).forEach(metrics=>{
    const id=String(metrics.profileId||'');
    if(!id||seen.has(id))return;
    const likes=Number(metrics.likes||0),profileShares=Number(metrics.profileShares||0),liveShares=Number(metrics.liveShares||0);
    rows.push({profileId:id,name:String(metrics.name||id),handle:'',likes,profileShares,liveShares,total:likes+profileShares+liveShares,order:999999});
  });
  return rows.sort((a,b)=>b.total-a.total||a.order-b.order||a.name.localeCompare(b.name,'fr'));
}

async function adminMetrics(){
  const pane=document.getElementById('adminPane');
  if(!pane||pane.querySelector('.p50-admin-metrics')||pane.dataset.p50MetricsLoading==='1')return;
  let user;
  try{user=currentUser()}catch{}
  if(!user||!['owner','admin'].includes(user.role))return;
  pane.dataset.p50MetricsLoading='1';
  try{
    const token=localStorage.getItem('pass50_api_token')||'';
    const response=await fetch(API,{headers:token?{Authorization:'Bearer '+token}:{}});
    if(!response.ok)return;
    const data=await response.json();
    const allRows=completeEngagementRows(data.profiles||[]);
    const activeCount=allRows.filter(item=>item.total>0).length;
    const totalLikes=allRows.reduce((sum,item)=>sum+item.likes,0);
    const totalProfileShares=allRows.reduce((sum,item)=>sum+item.profileShares,0);
    const totalLiveShares=allRows.reduce((sum,item)=>sum+item.liveShares,0);
    const rows=allRows.map(item=>`<tr class="${item.total===0?'p50-metric-zero':''}" data-engagement-row data-search="${esc(engagementSearchKey([item.name,item.handle,item.profileId].join(' ')))}"><td><strong>${esc(item.name)}</strong>${item.handle?`<small>${esc(item.handle)}</small>`:''}</td><td>${item.likes}</td><td>${item.profileShares}</td><td>${item.liveShares}</td></tr>`).join('');
    const box=document.createElement('section');
    box.className='p50-admin-metrics';
    box.innerHTML=`<div class="p50-admin-metrics-head"><div><strong>Engagement des fiches</strong><div class="p50-admin-metrics-summary"><strong>${activeCount}</strong> fiche${activeCount>1?'s':''} avec activité sur <strong>${allRows.length}</strong> recensée${allRows.length>1?'s':''} · les autres apparaissent avec zéro.</div></div><input id="p50EngagementSearch" class="p50-admin-metrics-search" type="search" autocomplete="off" placeholder="Rechercher une fiche…"></div><div class="p50-admin-metrics-table-wrap"><table><thead><tr><th>Influenceur</th><th>Likes</th><th>Partages FI</th><th>Partages live</th></tr></thead><tbody>${rows||'<tr><td colspan="4">Aucune fiche disponible</td></tr>'}</tbody></table></div><div class="p50-admin-metrics-footer"><span id="p50EngagementVisibleCount">${allRows.length} fiche${allRows.length>1?'s':''} affichée${allRows.length>1?'s':''}</span><span>${totalLikes} like${totalLikes>1?'s':''} · ${totalProfileShares} partage${totalProfileShares>1?'s':''} FI · ${totalLiveShares} partage${totalLiveShares>1?'s':''} live</span></div>`;
    pane.prepend(box);
    const search=box.querySelector('#p50EngagementSearch');
    search?.addEventListener('input',()=>{
      const query=engagementSearchKey(search.value.trim());
      let visible=0;
      box.querySelectorAll('[data-engagement-row]').forEach(row=>{
        const match=!query||String(row.dataset.search||'').includes(query);
        row.hidden=!match;
        if(match)visible++;
      });
      const count=box.querySelector('#p50EngagementVisibleCount');
      if(count)count.textContent=`${visible} fiche${visible>1?'s':''} affichée${visible>1?'s':''}`;
    });
  }catch{}finally{delete pane.dataset.p50MetricsLoading;}
}

function adminVerifiedToggle(){
  const form=document.getElementById('profileForm');
  if(!form||form.querySelector('[name="verifiedPass50"]'))return;
  const id=form.dataset.id,profile=pById(id);
  const label=document.createElement('label');
  label.innerHTML=`<input type="checkbox" name="verifiedPass50" ${isVerified(profile)?'checked':''}> Badge Vérifié PASS50`;
  form.querySelector('button[type="submit"]')?.parentElement?.before(label);
  form.addEventListener('submit',()=>{if(profile){profile.verifiedPass50=form.elements.verifiedPass50.checked;save()}},{capture:true});
}

function runEnhancements(){
  scheduled=false;
  enhanceProfile();
  enhanceHomeLikes();
  enhanceLiveModal();
  adminVerifiedToggle();
  adminMetrics();
}

function scheduleEnhancements(){
  if(scheduled)return;
  scheduled=true;
  requestAnimationFrame(runEnhancements);
}

document.addEventListener('p50:profile-opened',event=>{
  const id=String(event.detail?.profileId||'');
  if(id&&pById(id)){
    requestedProfileId=id;
    requestAnimationFrame(()=>enhanceProfile(id));
  }
});

document.addEventListener('click',async event=>{
  const trigger=event.target.closest('[data-profile]');
  if(trigger?.dataset.profile)requestedProfileId=trigger.dataset.profile;

  const like=event.target.closest('.p50-like');
  if(like){
    event.preventDefault();event.stopPropagation();
    const id=like.dataset.id;
    if(liked(id)){toast?.('Vous avez déjà aimé cette fiche');return;}
    const result=await record('like',id);
    if(result?.ok){
      localStorage.setItem('pass50.like.'+id,'1');
      document.querySelectorAll(`.p50-like[data-id="${CSS.escape(id)}"]`).forEach(button=>{button.classList.add('on');button.setAttribute('aria-pressed','true')});
      toast?.('Merci pour votre soutien');
    }
  }

  const profileShare=event.target.closest('.p50-share-fi');
  if(profileShare){
    const profile=pById(profileShare.dataset.id);
    if(profile){
      const url=location.origin+location.pathname+'?profile='+encodeURIComponent(profile.id);
      share(url,`Fiche PASS50 de ${profile.name}`,`Découvre la fiche PASS50 de ${profile.name}`,'profile_share',profile.id);
    }
  }

  const liveShare=event.target.closest('.p50-share-live');
  if(liveShare){
    const profile=pById(liveShare.dataset.id),url=decodeURIComponent(liveShare.dataset.live||'');
    share(url,`${profile?.name||'Influenceur'} est en direct`,`Regarde ce live partagé depuis PASS50`,'live_share',liveShare.dataset.id,{liveUrl:url});
  }
},true);

const observer=new MutationObserver(scheduleEnhancements);
observer.observe(document.body,{subtree:true,childList:true});
document.addEventListener('DOMContentLoaded',()=>{
  try{db.profiles.forEach(profile=>{if(typeof profile.verifiedPass50!=='boolean')profile.verifiedPass50=true});save()}catch{}
  scheduleEnhancements();
});
})();
