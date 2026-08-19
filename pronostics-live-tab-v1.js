(function(){
'use strict';
if(window.__pass50PronoLiveTabV1)return;
window.__pass50PronoLiveTabV1=true;

const STAKE_DEFAULT=100;
const STAKE_MIN=100;
const STAKE_STEP=50;
const LIVE_MULT=2;
const state={
  active:false,
  items:[],
  session:null,
  slip:{},
  stake:STAKE_DEFAULT,
  points:1000,
  auth:false,
  loading:false,
};

function authHeaders(json){
  const token=localStorage.getItem('pass50_api_token')||'';
  const h={Accept:'application/json'};
  if(token)h.Authorization='Bearer '+token;
  if(json)h['Content-Type']='application/json';
  return h;
}
function esc(s){
  return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}
function fmtOdd(n){
  const v=Number(n);
  return Number.isFinite(v)?v.toFixed(2):'—';
}
function showToast(msg){
  const t=document.getElementById('toast');
  if(!t)return;
  t.textContent=msg;
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),2800);
}
function installStyles(){
  if(document.getElementById('p50LivePronoStyles'))return;
  const style=document.createElement('style');
  style.id='p50LivePronoStyles';
  style.textContent=`
    .p50-live-event{display:block;margin:0 0 12px;padding:14px 16px;border-radius:16px;border:1px solid rgba(183,255,0,.45);background:linear-gradient(135deg,rgba(183,255,0,.16),rgba(113,255,0,.06));color:#b7ff00;font-weight:900;text-align:center}
    .p50-live-gift{margin-bottom:14px}
    .p50-live-gift h3{color:#b7ff00;margin-top:8px}
    .p50-live-gift p{margin:8px 0 12px;font-size:14px;line-height:1.45;font-weight:650}
    .p50-live-gift .btn{display:inline-flex;width:auto;padding:10px 16px}
  `;
  document.head.appendChild(style);
}
function root(){return document.getElementById('p50LivePronoRoot');}
function isLiveView(){return document.body.classList.contains('p50-prono-live-view');}

function markTab(active){
  const btn=document.querySelector('#p50PronoModeTabs [data-prono-mode="live"]');
  if(btn)btn.classList.toggle('is-live-on',!!active);
}

function slipLegs(){
  return Object.entries(state.slip).map(([questionId,pick])=>({questionId,...pick}));
}
function combinedOdd(){
  const legs=slipLegs();
  if(!legs.length)return 0;
  return legs.reduce((acc,leg)=>acc*(Number(leg.odd)||1),1);
}
function currentStake(){
  const n=Number(state.stake);
  return Number.isFinite(n)?Math.max(STAKE_MIN,Math.round(n)):STAKE_DEFAULT;
}
function potentialPayout(){
  const odd=combinedOdd();
  if(odd<=0)return 0;
  return Math.max(1,Math.round(currentStake()*odd*LIVE_MULT));
}

function eventGiftHtml(session){
  const eventUrl=String(session?.eventUrl||'').trim();
  const photo=String(session?.giftPhoto||'').trim();
  const giftUrl=String(session?.giftUrl||'').trim();
  const text=String(session?.giftText||'').trim();
  const kind=session?.giftKind==='jour'?'Cadeau du jour':'Cadeau du soir';
  const event=eventUrl
    ?`<a class="p50-live-event" href="${esc(eventUrl)}" target="_blank" rel="noopener noreferrer">Événement</a>`
    :'';
  if(!photo&&!giftUrl&&!text){
    return event;
  }
  const img=photo?`<img class="cover" src="${esc(photo)}" alt="" loading="lazy">`:'';
  const copy=text?`<p>${esc(text)}</p>`:'';
  const link=giftUrl?`<a class="btn primary" href="${esc(giftUrl)}" target="_blank" rel="noopener noreferrer">Ouvrir</a>`:'';
  return `${event}<article class="pub-card p50-live-gift">${img}<h3>${kind}</h3>${copy}${link}</article>`;
}

function render(){
  const el=root();
  if(!el)return;
  if(!state.active){
    el.innerHTML='';
    hideSlip();
    return;
  }
  const items=state.items||[];
  const cards=items.length?items.map(item=>{
    const picked=state.slip[item.id];
    const opts=(item.options||[]).map(opt=>{
      const on=picked&&picked.optionKey===opt.key;
      return `<button type="button" class="pub-opt${on?' selected':''}" data-live-qid="${esc(item.id)}" data-live-opt="${esc(opt.key)}" data-live-odd="${esc(opt.odd)}" data-live-label="${esc(opt.label)}">
        <span>${esc(opt.label)}</span>
        <span class="odd">${fmtOdd(opt.odd)}×2</span>
      </button>`;
    }).join('');
    const cover=item.coverPhoto
      ?`<img class="cover" src="${esc(item.coverPhoto)}" alt="" loading="lazy">`
      :'';
    const plays=Number(item.myPlayCount||0);
    return `<article class="pub-card">
      ${cover}
      <h3>${esc(item.title||'')}</h3>
      ${item.context?`<div class="pub-context"><p>${esc(item.context)}</p></div>`:''}
      ${plays>0?`<p class="pub-hint">${plays} participation${plays>1?'s':''}</p>`:''}
      <div class="pub-opts">${opts}</div>
    </article>`;
  }).join(''):'';
  el.innerHTML=eventGiftHtml(state.session)+cards;
  syncSlip();
}

function slipBar(){
  let bar=document.getElementById('p50LiveSlipBar');
  if(bar)return bar;
  bar=document.createElement('div');
  bar.id='p50LiveSlipBar';
  bar.className='slip-bar';
  bar.innerHTML=`<div class="slip-head">
      <div class="slip-top">
        <div>
          <div style="font-weight:800">Prono50 live</div>
          <div class="slip-meta" id="p50LiveSlipMeta">0 sélection</div>
        </div>
        <div style="text-align:right">
          <div class="slip-odd" id="p50LiveSlipOdd">—</div>
          <div class="slip-meta" id="p50LiveSlipPayout">gains ×2</div>
        </div>
      </div>
    </div>
    <div class="slip-details">
      <div class="slip-legs" id="p50LiveSlipLegs" hidden></div>
      <div class="slip-stake">
        <div class="slip-stake-row">
          <div class="slip-stake-label">Mise (pts)</div>
          <div class="slip-stake-ctrl">
            <button type="button" id="p50LiveStakeMinus">−</button>
            <input id="p50LiveStakeInput" type="number" inputmode="numeric" min="100" step="50" value="100">
            <button type="button" id="p50LiveStakePlus">+</button>
          </div>
        </div>
      </div>
      <div class="slip-actions">
        <button type="button" class="btn" id="p50LiveSlipClear">Vider</button>
        <button type="button" class="btn primary" id="p50LiveSlipValidate">Valider</button>
      </div>
    </div>`;
  document.body.appendChild(bar);
  bar.addEventListener('click',onSlipClick);
  document.getElementById('p50LiveStakeInput')?.addEventListener('change',e=>setStake(e.target.value));
  document.getElementById('p50LiveStakeInput')?.addEventListener('input',e=>{
    const raw=Number(e.target.value);
    if(!Number.isFinite(raw))return;
    state.stake=raw;
    syncSlip();
  });
  return bar;
}

function hideSlip(){
  const bar=document.getElementById('p50LiveSlipBar');
  if(bar)bar.classList.remove('show');
}

function setStake(value){
  const n=Number(value);
  state.stake=Number.isFinite(n)?Math.max(STAKE_MIN,Math.round(n/STAKE_STEP)*STAKE_STEP):STAKE_DEFAULT;
  const input=document.getElementById('p50LiveStakeInput');
  if(input)input.value=String(state.stake);
  syncSlip();
}

function syncSlip(){
  const legs=slipLegs();
  const bar=slipBar();
  if(!isLiveView()||!state.active||!legs.length){
    bar.classList.remove('show');
    return;
  }
  bar.classList.add('show');
  const meta=document.getElementById('p50LiveSlipMeta');
  const oddEl=document.getElementById('p50LiveSlipOdd');
  const payEl=document.getElementById('p50LiveSlipPayout');
  const list=document.getElementById('p50LiveSlipLegs');
  if(meta)meta.textContent=legs.length+' sélection'+(legs.length>1?'s':'');
  if(oddEl)oddEl.textContent=fmtOdd(combinedOdd()*LIVE_MULT);
  if(payEl)payEl.textContent='+'+potentialPayout()+' pts';
  if(list){
    list.hidden=legs.length<2;
    list.innerHTML=legs.map(leg=>`<div class="slip-leg">
      <div class="slip-leg-main"><span>${esc(leg.title||'')}</span><span class="slip-leg-ctx">${esc(leg.label||'')}</span></div>
      <span class="odd">${fmtOdd(leg.odd)}×2</span>
      <button type="button" class="slip-leg-remove" data-live-remove="${esc(leg.questionId)}">×</button>
    </div>`).join('');
  }
  const input=document.getElementById('p50LiveStakeInput');
  if(input&&document.activeElement!==input)input.value=String(currentStake());
}

function onSlipClick(e){
  if(e.target.closest('#p50LiveStakeMinus')){setStake(currentStake()-STAKE_STEP);return;}
  if(e.target.closest('#p50LiveStakePlus')){setStake(currentStake()+STAKE_STEP);return;}
  const remove=e.target.closest('[data-live-remove]');
  if(remove){
    delete state.slip[remove.getAttribute('data-live-remove')];
    render();
    return;
  }
  if(e.target.closest('#p50LiveSlipClear')){
    state.slip={};
    render();
    return;
  }
  if(e.target.closest('#p50LiveSlipValidate'))validate();
}

async function validate(){
  const legs=slipLegs().map(l=>({questionId:l.questionId,optionKey:l.optionKey}));
  if(!legs.length)return;
  const token=localStorage.getItem('pass50_api_token')||'';
  if(!token){showToast('Connecte-toi pour valider');return;}
  const btn=document.getElementById('p50LiveSlipValidate');
  if(btn)btn.disabled=true;
  try{
    const r=await fetch('/api/prono-slip.php',{
      method:'POST',
      headers:authHeaders(true),
      body:JSON.stringify({stake:currentStake(),legs,live:true}),
    });
    const d=await r.json().catch(()=>({}));
    if(!r.ok)throw new Error(d.error||d.message||('HTTP '+r.status));
    state.slip={};
    if(d.balance)state.points=Number(d.balance.balance||state.points);
    showToast(d.message||'Validé');
    await refresh();
  }catch(err){
    showToast(err.message||'Validation impossible');
  }finally{
    if(btn)btn.disabled=false;
  }
}

function onRootClick(e){
  const btn=e.target.closest('[data-live-qid][data-live-opt]');
  if(!btn)return;
  const qid=btn.getAttribute('data-live-qid');
  const opt=btn.getAttribute('data-live-opt');
  const odd=Number(btn.getAttribute('data-live-odd')||1);
  const label=btn.getAttribute('data-live-label')||opt;
  const item=state.items.find(i=>i.id===qid);
  if(!item)return;
  if(state.slip[qid]?.optionKey===opt)delete state.slip[qid];
  else state.slip[qid]={optionKey:opt,odd,label,title:item.title||''};
  render();
}

async function refresh(){
  if(state.loading)return;
  state.loading=true;
  try{
    const r=await fetch('/api/prono-live.php?_='+Date.now(),{headers:authHeaders()});
    const data=await r.json().catch(()=>({}));
    state.active=Boolean(data.active);
    state.items=Array.isArray(data.items)?data.items:[];
    state.session=data.session||null;
    state.auth=Boolean(data.auth);
    if(data.balance)state.points=Number(data.balance.balance||state.points);
    markTab(state.active);
    if(!state.active)state.slip={};
    if(isLiveView())render();
    else if(!state.active)hideSlip();
  }catch(_e){
    state.active=false;
    markTab(false);
    if(isLiveView()&&root())root().innerHTML='';
    hideSlip();
  }finally{
    state.loading=false;
  }
}

function boot(){
  const el=root();
  if(!el){setTimeout(boot,120);return;}
  installStyles();
  el.addEventListener('click',onRootClick);
  refresh();
  setInterval(()=>{
    if(isLiveView()||document.querySelector('#p50PronoModeTabs [data-prono-mode="live"]'))refresh();
  },12000);
}

window.PASS50_PRONO_LIVE={version:'1.0',refresh,hide:hideSlip};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
else boot();
})();
