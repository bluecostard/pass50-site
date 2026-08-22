(function(){
'use strict';
if(window.PASS50_WEEKLY_DIGEST_SHARE)return;
window.PASS50_WEEKLY_DIGEST_SHARE={version:'1.0.0'};

const W=1080,H=1350;
const INK='#0b0f0b';
const PAPER='#eef1ec';
const MUTED='#5c665c';
const LIME='#b7ff00';
const ACCENT='#0e7c7c';
const CARD='#121812';

function esc(value){return String(value??'').replace(/[&<>"']/g,c=> ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function notify(msg){try{if(typeof toast==='function')toast(msg)}catch{}}

function formatViewers(n){
  const v=Math.max(0,Number(n)||0);
  return v.toLocaleString('fr-FR');
}

function demoStats(){
  return {
    weekKey:'2026-W34',
    window:{label:'15/08 → 22/08/2026'},
    topLive:{name:'Samuella Kouassi',platform:'TikTok',viewers:12840,profileId:'census-samuella-kouassi'},
    topRankOne:{name:'Roseline Layo',timesFirst:5,periodKey:'24H',profileId:'census-roseline-layo'},
    topProno:{name:'Jordan Evraa',voteCount:312,uniqueVoters:186,profileId:'census-jordan-evraa'}
  };
}

function normalizeStats(raw){
  if(!raw||typeof raw!=='object')return demoStats();
  return {
    weekKey:String(raw.weekKey||''),
    window:raw.window&&typeof raw.window==='object'?raw.window:{label:''},
    topLive:raw.topLive||null,
    topRankOne:raw.topRankOne||null,
    topProno:raw.topProno||null
  };
}

function shareBase(){
  const path=location.pathname.replace(/[^/]*$/,'');
  return `${location.origin}${path}`;
}

function publicCardUrl(weekKey){
  const url=new URL('bilan-semaine.php',shareBase());
  if(weekKey)url.searchParams.set('week',weekKey);
  return url.href;
}

function roundRect(ctx,x,y,w,h,r){
  const rad=Math.min(r,w/2,h/2);
  ctx.beginPath();
  ctx.moveTo(x+rad,y);
  ctx.arcTo(x+w,y,x+w,y+h,rad);
  ctx.arcTo(x+w,y+h,x,y+h,rad);
  ctx.arcTo(x,y+h,x,y,rad);
  ctx.arcTo(x,y,x+w,y,rad);
  ctx.closePath();
}

function wrapLines(ctx,text,maxWidth,maxLines){
  const words=String(text||'').split(/\s+/),lines=[];let line='';
  for(const word of words){
    const next=line?`${line} ${word}`:word;
    if(ctx.measureText(next).width<=maxWidth)line=next;
    else{if(line)lines.push(line);line=word;if(lines.length>=maxLines-1)break;}
  }
  if(line&&lines.length<maxLines)lines.push(line);
  return lines;
}

function drawStatBlock(ctx,x,y,w,icon,label,value,sub){
  roundRect(ctx,x,y,w,210,22);
  ctx.fillStyle='rgba(183,255,0,.08)';
  ctx.fill();
  ctx.strokeStyle='rgba(183,255,0,.35)';
  ctx.lineWidth=2;
  ctx.stroke();
  ctx.fillStyle=LIME;
  ctx.font='900 52px system-ui,sans-serif';
  ctx.fillText(icon,x+28,y+62);
  ctx.fillStyle=MUTED;
  ctx.font='800 24px system-ui,sans-serif';
  ctx.fillText(label.toUpperCase(),x+28,y+104);
  ctx.fillStyle=PAPER;
  ctx.font='1000 42px system-ui,sans-serif';
  wrapLines(ctx,value,w-56,2).forEach((line,i)=>ctx.fillText(line,x+28,y+156+i*46));
  if(sub){
    ctx.fillStyle='rgba(238,241,236,.72)';
    ctx.font='700 26px system-ui,sans-serif';
    ctx.fillText(sub,x+28,y+196);
  }
}

function drawWeeklyDigestCard(canvas,stats){
  const data=normalizeStats(stats);
  const ctx=canvas.getContext('2d');
  canvas.width=W;canvas.height=H;
  const bg=ctx.createLinearGradient(0,0,W,H);
  bg.addColorStop(0,'#172019');
  bg.addColorStop(.55,'#0a0f0b');
  bg.addColorStop(1,'#1a2410');
  ctx.fillStyle=bg;ctx.fillRect(0,0,W,H);
  roundRect(ctx,48,48,W-96,H-96,36);
  ctx.fillStyle=CARD;ctx.fill();
  ctx.strokeStyle='rgba(183,255,0,.42)';ctx.lineWidth=3;ctx.stroke();
  ctx.fillStyle=LIME;
  ctx.fillRect(48,48,W-96,10);
  ctx.fillStyle=PAPER;
  ctx.font='1000 58px system-ui,sans-serif';
  ctx.fillText('PASS50',88,138);
  ctx.fillStyle=ACCENT;
  ctx.font='900 28px system-ui,sans-serif';
  ctx.fillText('BILAN DU VENDREDI SOIR',88,186);
  ctx.fillStyle=MUTED;
  ctx.font='700 30px system-ui,sans-serif';
  const weekLabel=String(data.window?.label||'Cette semaine');
  ctx.fillText(`Semaine ${weekLabel}`,88,232);
  ctx.fillStyle='rgba(183,255,0,.18)';
  roundRect(ctx,88,262,W-176,4,2);ctx.fill();
  const live=data.topLive;
  const rank=data.topRankOne;
  const prono=data.topProno;
  drawStatBlock(ctx,88,292,W-176,'🔴','Live le plus suivi',
    live?.name||'—',
    live?`${formatViewers(live.viewers)} auditeurs${live.platform?` · ${live.platform}`:''}`:'Données insuffisantes');
  drawStatBlock(ctx,88,522,W-176,'👑','N°1 le plus souvent',
    rank?.name||'—',
    rank?`${rank.timesFirst} fois en tête (${rank.periodKey||'24H'})`:'Données insuffisantes');
  drawStatBlock(ctx,88,752,W-176,'🎯','Le plus pronostiqué',
    prono?.name||'—',
    prono?`${prono.voteCount} pronostic${prono.voteCount>1?'s':''} · ${prono.uniqueVoters||0} votant${(prono.uniqueVoters||0)>1?'s':''}`:'Données insuffisantes');
  ctx.fillStyle=MUTED;
  ctx.font='700 24px system-ui,sans-serif';
  ctx.fillText('pass50.store · Classement & pronostics influenceurs',88,H-118);
  ctx.fillStyle=LIME;
  ctx.font='900 30px system-ui,sans-serif';
  ctx.fillText('Téléchargeable · Partageable',88,H-78);
}

function canvasBlob(canvas,type='image/png'){
  return new Promise(resolve=>canvas.toBlob(resolve,type,0.95));
}

function downloadBlob(blob,filename){
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;a.download=filename;a.click();
  setTimeout(()=>URL.revokeObjectURL(url),4000);
}

async function downloadPng(stats){
  const canvas=document.createElement('canvas');
  drawWeeklyDigestCard(canvas,stats);
  const blob=await canvasBlob(canvas);
  if(!blob)return notify('Export impossible');
  const week=(stats?.weekKey||'semaine').replace(/[^\w-]/g,'');
  downloadBlob(blob,`pass50-bilan-${week}.png`);
  notify('Carte téléchargée');
}

async function downloadPdf(stats){
  const canvas=document.createElement('canvas');
  drawWeeklyDigestCard(canvas,stats);
  const dataUrl=canvas.toDataURL('image/png');
  const week=(stats?.weekKey||'semaine').replace(/[^\w-]/g,'');
  const html=`<!doctype html><html><head><meta charset="utf-8"><title>PASS50 Bilan ${esc(week)}</title><style>@page{size:A4 portrait;margin:12mm}body{margin:0;display:grid;place-items:center;background:#fff}img{width:100%;max-width:180mm;height:auto}</style></head><body><img src="${dataUrl}" alt="Bilan PASS50"></body></html>`;
  const blob=new Blob([html],{type:'text/html'});
  const url=URL.createObjectURL(blob);
  const w=window.open(url,'_blank');
  if(!w){notify('Autorise les pop-ups pour exporter le PDF');return;}
  w.addEventListener('load',()=>{try{w.focus();w.print();}catch{}});
  notify('Choisis « Enregistrer en PDF » dans l’impression');
}

async function shareNative(stats){
  const canvas=document.createElement('canvas');
  drawWeeklyDigestCard(canvas,stats);
  const blob=await canvasBlob(canvas);
  const weekLabel=stats?.window?.label||'cette semaine';
  const text=`Bilan PASS50 — semaine ${weekLabel}\nLive, classement et pronostics de la semaine.`;
  const url=publicCardUrl(stats?.weekKey);
  if(blob&&navigator.share){
    try{
      const file=new File([blob],`pass50-bilan.png`,{type:'image/png'});
      if(navigator.canShare?.({files:[file]})){
        await navigator.share({title:'Bilan PASS50',text,url,files:[file]});
        return;
      }
      await navigator.share({title:'Bilan PASS50',text:`${text}\n${url}`});
      return;
    }catch(err){if(err?.name==='AbortError')return;}
  }
  try{await navigator.clipboard.writeText(`${text}\n${url}`);notify('Lien copié');}catch{notify(url);}
}

function ensureModal(){
  if(document.getElementById('weeklyDigestModal'))return;
  const style=document.createElement('style');
  style.id='weeklyDigestShareStyle';
  style.textContent=`
  #weeklyDigestModal{position:fixed;inset:0;z-index:12500;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.86);backdrop-filter:blur(12px)}
  #weeklyDigestModal.show{display:flex}
  #weeklyDigestModal .wd-box{width:min(980px,100%);max-height:96vh;overflow:auto;border:1px solid rgba(183,255,0,.35);border-radius:22px;background:#0a0d0a;padding:14px}
  #weeklyDigestModal .wd-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
  #weeklyDigestModal .wd-shell{display:grid;grid-template-columns:minmax(260px,.95fr) minmax(280px,1.05fr);gap:16px}
  #weeklyDigestModal .wd-preview{border:1px solid rgba(183,255,0,.4);border-radius:18px;padding:8px;background:linear-gradient(145deg,#172019,#0a0f0b 58%,#19240f);display:grid;place-items:center}
  #weeklyDigestModal canvas{width:100%;max-height:72vh;object-fit:contain;border-radius:14px;cursor:pointer}
  #weeklyDigestModal .wd-panel{display:flex;flex-direction:column;gap:10px}
  #weeklyDigestModal .wd-facts{border:1px solid var(--line,#1f261f);border-radius:16px;padding:14px;background:#080b08}
  #weeklyDigestModal .wd-actions{display:flex;gap:8px;flex-wrap:wrap}
  #weeklyDigestModal .wd-notice-card{display:grid;grid-template-columns:72px 1fr;gap:12px;align-items:center;padding:12px;border:1px solid rgba(183,255,0,.28);border-radius:14px;background:#0c100c;cursor:pointer}
  #weeklyDigestModal .wd-notice-card img,#weeklyDigestModal .wd-notice-thumb{width:72px;height:72px;border-radius:10px;object-fit:cover;border:1px solid rgba(183,255,0,.3)}
  @media(max-width:760px){#weeklyDigestModal .wd-shell{grid-template-columns:1fr}}
  `;
  document.head.appendChild(style);
  const modal=document.createElement('div');
  modal.id='weeklyDigestModal';
  modal.innerHTML=`<div class="wd-box" role="dialog" aria-modal="true" aria-label="Bilan hebdomadaire PASS50">
    <div class="wd-head"><div><div class="eyebrow">BILAN DU VENDREDI</div><strong id="weeklyDigestTitle">Bilan de la semaine PASS50</strong></div><button class="close" type="button" data-close="weeklyDigestModal" aria-label="Fermer">×</button></div>
    <div class="wd-shell"><div class="wd-preview"><canvas id="weeklyDigestCanvas" width="1080" height="1350" aria-label="Carte bilan hebdomadaire"></canvas></div>
    <div class="wd-panel"><div class="wd-facts" id="weeklyDigestFacts"></div>
    <div class="wd-actions">
      <button class="btn primary" type="button" id="weeklyDigestShareNative">Partager</button>
      <button class="btn" type="button" id="weeklyDigestDownloadPng">Télécharger PNG</button>
      <button class="btn" type="button" id="weeklyDigestDownloadPdf">Télécharger PDF</button>
      <button class="btn" type="button" id="weeklyDigestCopyLink">Copier le lien</button>
    </div>
    <div class="share-note">Carte cliquable dans les notifications · lien public pour partage externe (WhatsApp, réseaux).</div>
    </div></div></div>`;
  document.body.appendChild(modal);
}

function renderFacts(stats){
  const el=document.getElementById('weeklyDigestFacts');
  if(!el)return;
  const data=normalizeStats(stats);
  const rows=[
    ['Live le plus suivi',data.topLive?`${data.topLive.name} · ${formatViewers(data.topLive.viewers)} auditeurs`:'—'],
    ['N°1 le plus souvent',data.topRankOne?`${data.topRankOne.name} · ${data.topRankOne.timesFirst} fois`:'—'],
    ['Le plus pronostiqué',data.topProno?`${data.topProno.name} · ${data.topProno.voteCount} pronos`:'—']
  ];
  el.innerHTML=`<div class="muted" style="margin-bottom:8px">Semaine ${esc(data.window?.label||'')}</div>${rows.map(([k,v])=>`<div style="margin:8px 0"><strong>${esc(k)}</strong><div>${esc(v)}</div></div>`).join('')}`;
}

let currentStats=null;

function openWeeklyDigestCard(stats){
  ensureModal();
  currentStats=normalizeStats(stats);
  const canvas=document.getElementById('weeklyDigestCanvas');
  if(canvas)drawWeeklyDigestCard(canvas,currentStats);
  renderFacts(currentStats);
  const modal=document.getElementById('weeklyDigestModal');
  modal?.classList.add('show');
}

function closeWeeklyDigestCard(){
  document.getElementById('weeklyDigestModal')?.classList.remove('show');
}

function noticePreviewHtml(stats){
  const data=normalizeStats(stats);
  return `<div class="wd-notice-card" data-weekly-digest-open="1" title="Ouvrir la carte bilan">
    <canvas class="wd-notice-thumb" width="144" height="180" aria-hidden="true"></canvas>
    <div><strong>${esc(data.window?.label||'Bilan semaine')}</strong><div class="muted">Carte bilan · toucher pour afficher</div><div style="margin-top:6px;font-size:12px;color:var(--lime)">PNG · PDF · Partage</div></div>
  </div>`;
}

function paintNoticeThumbs(root=document){
  root.querySelectorAll('.wd-notice-card canvas.wd-notice-thumb').forEach(canvas=>{
    const card=canvas.closest('[data-weekly-digest-stats]');
    let stats=demoStats();
    try{stats=JSON.parse(card?.getAttribute('data-weekly-digest-stats')||'')}catch{}
    drawWeeklyDigestCard(canvas,stats);
  });
}

async function fetchStats(weekKey){
  const q=weekKey?`?week=${encodeURIComponent(weekKey)}`:'';
  const r=await fetch(`./api/weekly-digest-card.php${q}`,{cache:'no-store'});
  if(!r.ok)throw new Error('Bilan indisponible');
  const data=await r.json();
  if(!data?.ok)throw new Error(data?.error||'Bilan indisponible');
  return data.stats;
}

async function openFromWeek(weekKey){
  try{
    const stats=await fetchStats(weekKey);
    openWeeklyDigestCard(stats);
  }catch{
    openWeeklyDigestCard(demoStats());
    notify('Aperçu prototype — données démo');
  }
}

function bind(){
  ensureModal();
  document.addEventListener('click',async e=>{
    if(e.target.matches('[data-close="weeklyDigestModal"]')||e.target.id==='weeklyDigestModal'&&e.target===e.currentTarget){closeWeeklyDigestCard();return;}
    if(e.target.closest('[data-weekly-digest-open]')){e.preventDefault();openWeeklyDigestCard(currentStats||demoStats());return;}
    if(e.target.id==='weeklyDigestShareNative'){await shareNative(currentStats||demoStats());return;}
    if(e.target.id==='weeklyDigestDownloadPng'){await downloadPng(currentStats||demoStats());return;}
    if(e.target.id==='weeklyDigestDownloadPdf'){await downloadPdf(currentStats||demoStats());return;}
    if(e.target.id==='weeklyDigestCopyLink'){
      const url=publicCardUrl(currentStats?.weekKey);
      try{await navigator.clipboard.writeText(url);notify('Lien public copié');}catch{notify(url);}
      return;
    }
    const canvas=e.target.closest('#weeklyDigestCanvas');
    if(canvas){canvas.requestFullscreen?.().catch(()=>{});}
  });
  const params=new URLSearchParams(location.search);
  if(params.get('digest')==='1'||params.has('week')&&/weekly-digest-card\.html$/i.test(location.pathname)){
    const week=params.get('week')||'';
    openFromWeek(week);
  }
}

async function openWeeklyDigestFromActionUrl(actionUrl){
  try{
    const target=new URL(String(actionUrl||''),location.origin);
    if(!/\/bilan-semaine\.php$/i.test(target.pathname)&&target.searchParams.get('digest')!=='1')return false;
    const week=target.searchParams.get('week')||'';
    await openFromWeek(week);
    return true;
  }catch{return false}
}
window.openWeeklyDigestFromActionUrl=openWeeklyDigestFromActionUrl;
window.closeWeeklyDigestCard=closeWeeklyDigestCard;
window.paintWeeklyDigestNoticeThumbs=paintNoticeThumbs;
window.weeklyDigestNoticePreviewHtml=noticePreviewHtml;
window.PASS50_WEEKLY_DIGEST_DEMO=demoStats;

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});
else bind();
})();
