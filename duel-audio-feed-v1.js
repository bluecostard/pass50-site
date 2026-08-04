(function(){
'use strict';

if(window.PASS50_DUEL_AUDIO_FEED)return;

const CONTRACT='PASS50-DUEL-AUDIO-FEED-V1.1';
const API='./api/duel-audio.php';
const MAX_DUEL_AUDIOS=3;
const SHARE_EVENTS=new Set(['native_share_triggered','download','platform_selected']);
const state={pollKey:'',items:[],loading:false,lastFetch:0,consent:new Map(),uploading:new Map(),uploaded:new Set(),status:new Map()};

const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const attr=value=>esc(value).replace(/`/g,'&#96;');

function token(){return localStorage.getItem('pass50_api_token')||'';}
function currentPollKey(){try{return typeof coulesPollKey==='function'?String(coulesPollKey()||''):''}catch{return ''}}
function currentVoteShare(){try{return window.VOTE_SHARE||VOTE_SHARE||null}catch{return null}}
function notify(message){try{if(typeof toast==='function')toast(message)}catch{}}

function relativeDate(value){
  const timestamp=Date.parse(value||'');if(!Number.isFinite(timestamp))return 'Date inconnue';
  const seconds=Math.max(0,(Date.now()-timestamp)/1000);
  if(seconds<3600)return `il y a ${Math.max(1,Math.round(seconds/60))} min`;
  if(seconds<86400)return `il y a ${Math.round(seconds/3600)} h`;
  return `il y a ${Math.round(seconds/86400)} j`;
}

function durationLabel(durationMs){
  const seconds=Math.max(1,Math.round(Number(durationMs||0)/1000));
  return `00:${String(Math.min(59,seconds)).padStart(2,'0')}`;
}

function ensureStyles(){
  if(document.getElementById('p50DuelAudioStyles'))return;
  const style=document.createElement('style');style.id='p50DuelAudioStyles';style.textContent=`
    .p50-duel-audios{margin-top:14px;padding:14px;border:1px solid rgba(166,108,255,.38);border-radius:18px;background:linear-gradient(145deg,rgba(166,108,255,.1),rgba(9,7,13,.96))}.p50-duel-audios-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}.p50-duel-audios-title{font-size:18px;font-weight:1000}.p50-duel-audios-note{font-size:10px;color:var(--muted);line-height:1.4}.p50-duel-audio-list{display:grid;gap:9px}.p50-duel-audio-card{display:grid;grid-template-columns:minmax(0,1fr) 190px;gap:12px;align-items:center;padding:11px;border:1px solid rgba(166,108,255,.28);border-radius:14px;background:#0d0a12}.p50-duel-audio-kicker{color:#c8a8ff;font-size:9px;font-weight:1000;letter-spacing:.7px}.p50-duel-audio-card strong{display:block;margin:3px 0;font-size:13px}.p50-duel-audio-meta{font-size:10px;color:var(--muted)}.p50-duel-audio-card audio{width:100%;height:38px}.p50-duel-audio-empty{padding:18px;border:1px dashed rgba(166,108,255,.3);border-radius:14px;text-align:center;color:var(--muted);font-size:11px}
    .p50-duel-audio-consent{margin-top:10px;padding:10px;border:1px solid rgba(166,108,255,.42);border-radius:13px;background:rgba(166,108,255,.09);text-align:left}.p50-duel-audio-consent label{display:flex;gap:9px;align-items:flex-start;font-size:11px;line-height:1.4;color:#ddd2ef}.p50-duel-audio-consent input{margin-top:2px;accent-color:#a66cff}.p50-duel-audio-consent small{display:block;margin-top:7px;color:var(--muted);font-size:10px}.p50-duel-audio-consent .ok{color:var(--lime)}.p50-duel-audio-consent .bad{color:#ff9e9e}
    @media(max-width:680px){.p50-duel-audio-card{grid-template-columns:1fr}.p50-duel-audio-card audio{height:42px}.p50-duel-audios-head{display:grid}}
  `;document.head.appendChild(style);
}

function ensureDuelSection(){
  const grid=document.getElementById('sunkGrid');if(!grid)return null;
  let section=document.getElementById('p50DuelAudios');
  if(!section){
    section=document.createElement('section');section.id='p50DuelAudios';section.className='p50-duel-audios';
    grid.insertAdjacentElement('afterend',section);
  }
  return section;
}

function renderDuelAudios(){
  const section=ensureDuelSection();if(!section)return;
  const pollKey=currentPollKey();
  if(state.loading&&!state.items.length){section.innerHTML='<div class="p50-duel-audio-empty">Chargement des commentaires audio…</div>';return;}
  const items=state.pollKey===pollKey?state.items.slice(0,MAX_DUEL_AUDIOS):[];
  const list=items.length?items.map((item,index)=>{
    const a=item.candidateA||{},b=item.candidateB||{};
    const selected=String(item.selectedProfileId)===String(a.profileId)?a:b;
    const author=String(item.authorPseudo||'Membre PASS50').trim()||'Membre PASS50';
    return `<article class="p50-duel-audio-card"><div><div class="p50-duel-audio-kicker">🎙 AUDIO #${index+1} · ${esc(author)}</div><strong>${esc(a.name||'Influenceur')} VS ${esc(b.name||'Influenceur')}</strong><div class="p50-duel-audio-meta">${esc(author)} a commenté son vote pour ${esc(selected.name||'un influenceur')} · ${durationLabel(item.durationMs)} · ${esc(relativeDate(item.publishedAt))}</div></div><audio controls preload="metadata" src="${attr(item.audioUrl)}" aria-label="Commentaire audio de ${attr(author)}"></audio></article>`;
  }).join(''):'<div class="p50-duel-audio-empty">Aucun commentaire audio public pour ce duel pour le moment.</div>';
  section.innerHTML=`<div class="p50-duel-audios-head"><div><div class="p50-duel-audios-title">🎙 Les 3 derniers audios du duel</div><div class="p50-duel-audios-note">Chaque audio est attribué au pseudo public du compte PASS50 qui l’a publié.</div></div><div class="p50-duel-audios-note">Maximum ${MAX_DUEL_AUDIOS}</div></div><div class="p50-duel-audio-list">${list}</div>`;
}

async function fetchDuelAudios(force=false){
  const pollKey=currentPollKey();if(!pollKey)return;
  if(!force&&state.pollKey===pollKey&&Date.now()-state.lastFetch<30000)return;
  state.pollKey=pollKey;state.loading=true;renderDuelAudios();
  try{
    const response=await fetch(`${API}?pollKey=${encodeURIComponent(pollKey)}&_=${Date.now()}`,{headers:{Accept:'application/json'},cache:'no-store'});
    const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Audios indisponibles.');
    state.items=Array.isArray(data.items)?data.items.slice(0,MAX_DUEL_AUDIOS):[];state.lastFetch=Date.now();
  }catch(error){console.warn('PASS50 duel audio',error);state.items=[];}
  finally{state.loading=false;renderDuelAudios();}
}

function consentKey(){const share=currentVoteShare();return String(share?.shareId||'');}
function consentEnabled(){const key=consentKey();return key!==''&&state.consent.get(key)!==false;}

function injectConsent(){
  const share=currentVoteShare();const body=document.getElementById('voteShareBody');if(!body||!share?.shareId||!share?.audioBlob)return;
  const key=String(share.shareId);if(!state.consent.has(key))state.consent.set(key,true);
  let box=body.querySelector('[data-p50-duel-audio-consent]');
  if(!box){
    box=document.createElement('div');box.className='p50-duel-audio-consent';box.dataset.p50DuelAudioConsent='1';
    const audioPanel=body.querySelector('.vote-share-audio')||body.querySelector('.vote-share-panel')||body;
    audioPanel.appendChild(box);
  }
  const status=state.status.get(key)||'';
  box.innerHTML=`<label><input type="checkbox" data-p50-duel-audio-publish ${state.consent.get(key)!==false?'checked':''}><span><strong>Publier aussi cet audio dans PASS50</strong><br>Il sera visible sous ce duel et dans Mon fil des membres qui suivent l’un des deux influenceurs, pendant 30 jours.</span></label><small class="${status.startsWith('✓')?'ok':status.startsWith('Erreur')?'bad':''}">${esc(status||'Votre pseudo public PASS50 sera affiché avec cet audio.')}</small>`;
}

function audioDurationMs(blob){
  return new Promise(resolve=>{
    const url=URL.createObjectURL(blob),audio=document.createElement('audio');let settled=false;
    const finish=value=>{if(settled)return;settled=true;URL.revokeObjectURL(url);resolve(Math.max(250,Math.min(15000,Math.round(value||0))))};
    audio.preload='metadata';audio.onloadedmetadata=()=>finish(Number.isFinite(audio.duration)?audio.duration*1000:0);audio.onerror=()=>finish(0);audio.src=url;
    setTimeout(()=>finish(0),2500);
  });
}

function extensionFor(type){const mime=String(type||'').toLowerCase();if(mime.includes('ogg'))return 'ogg';if(mime.includes('mp4')||mime.includes('m4a'))return 'm4a';return 'webm';}

async function publishCurrentAudio(trigger='share'){
  const share=currentVoteShare();if(!share?.shareId||!share?.audioBlob||!consentEnabled())return null;
  const shareId=String(share.shareId),blob=share.audioBlob,key=`${shareId}:${blob.size}:${blob.type}`;
  if(state.uploaded.has(key))return true;if(state.uploading.has(key))return state.uploading.get(key);
  const task=(async()=>{
    try{
      state.status.set(shareId,'Publication de l’audio dans PASS50…');injectConsent();
      let durationMs=await audioDurationMs(blob);if(durationMs<=250)durationMs=Math.max(1000,Math.min(15000,Number(share.seconds||1)*1000));
      const form=new FormData();form.append('shareId',shareId);form.append('durationMs',String(durationMs));form.append('publishConsent','1');form.append('trigger',trigger);form.append('audio',blob,`pass50-duel-audio.${extensionFor(blob.type)}`);
      const headers={Accept:'application/json'};const auth=token();if(auth)headers.Authorization=`Bearer ${auth}`;
      const response=await fetch(API,{method:'POST',headers,body:form});const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Publication audio impossible.');
      state.uploaded.add(key);state.status.set(shareId,'✓ Audio publié avec votre pseudo dans le duel et Mon fil');injectConsent();notify('Audio publié avec votre pseudo');
      await fetchDuelAudios(true);return data;
    }catch(error){state.status.set(shareId,`Erreur : ${error.message||'publication impossible'}`);injectConsent();console.warn('Publication audio duel',error);return null;}
    finally{state.uploading.delete(key);}
  })();
  state.uploading.set(key,task);return task;
}

function installAnalyticsBridge(){
  const current=window.voteShareAnalytics;
  if(typeof current!=='function'||current.__p50DuelAudio)return;
  const wrapped=function(event,platform){if(SHARE_EVENTS.has(String(event)))publishCurrentAudio(String(event));return current.apply(this,arguments);};
  wrapped.__p50DuelAudio=true;window.voteShareAnalytics=wrapped;
}

function installEvents(){
  document.addEventListener('change',event=>{
    const input=event.target.closest?.('[data-p50-duel-audio-publish]');if(!input)return;
    const key=consentKey();if(key)state.consent.set(key,Boolean(input.checked));
  });
  document.addEventListener('click',event=>{
    const target=event.target.closest?.('#voteShareNative,#voteShareDownload,#voteShareWhatsapp');
    if(target)publishCurrentAudio(target.id);
  },true);
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')fetchDuelAudios(true);});
}

function init(){
  ensureStyles();ensureDuelSection();installEvents();installAnalyticsBridge();fetchDuelAudios(true);
  setInterval(()=>{ensureDuelSection();installAnalyticsBridge();injectConsent();fetchDuelAudios(false);},1000);
  window.PASS50_DUEL_AUDIO_FEED=Object.freeze({contract:CONTRACT,maxPerDuel:MAX_DUEL_AUDIOS,refresh:()=>fetchDuelAudios(true),publish:publishCurrentAudio});
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
