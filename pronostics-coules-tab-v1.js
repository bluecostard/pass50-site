(function(){
'use strict';
if(window.__pass50PronosticsCoulesTabV1)return;
window.__pass50PronosticsCoulesTabV1=true;

const path=location.pathname||'';
const isProno=/(?:^|\/)pronostics\.html$/i.test(path);
const params=new URLSearchParams(location.search);
const isCoulesEmbed=!isProno&&params.get('embed')==='coules';

function installEmbedMode(){
  if(!isCoulesEmbed)return false;
  const coules=document.getElementById('coules');
  if(!coules)return false;
  document.documentElement.classList.add('p50-coules-embed');
  document.body.classList.add('p50-coules-embed');
  const parent=coules.parentElement;
  if(parent){
    Array.from(parent.children).forEach(el=>{if(el!==coules)el.style.setProperty('display','none','important');});
    parent.style.maxWidth='760px';
    parent.style.margin='0 auto';
    parent.style.padding='8px 10px 24px';
  }
  coules.style.margin='0';
  coules.style.display='block';
  const style=document.createElement('style');
  style.id='p50CoulesEmbedStyles';
  style.textContent=`
    html.p50-coules-embed,body.p50-coules-embed{background:#050705!important;overflow-x:hidden}
    body.p50-coules-embed .p50-bottom-nav{display:none!important}
    body.p50-coules-embed #coules{box-shadow:none!important;border-color:rgba(183,255,0,.18)!important}
    body.p50-coules-embed .coules-banner{margin-top:0!important}
    body.p50-coules-embed .footer{display:none!important}
    @media(max-width:680px){body.p50-coules-embed{padding-bottom:16px!important}}
  `;
  document.head.appendChild(style);
  window.scrollTo(0,0);
  const sendHeight=()=>{
    try{
      const h=Math.ceil(Math.max(coules.scrollHeight,coules.getBoundingClientRect().height)+24);
      window.parent?.postMessage?.({type:'pass50-coules-height',height:h},'*');
    }catch(_e){}
  };
  new MutationObserver(sendHeight).observe(coules,{childList:true,subtree:true,attributes:true});
  window.addEventListener('resize',sendHeight);
  setTimeout(sendHeight,100);
  setTimeout(sendHeight,700);
  return true;
}

function installPronoTabs(){
  if(!isProno)return false;
  const shell=document.querySelector('.shell');
  const header=shell?.querySelector('.page-header');
  if(!shell||!header)return false;
  if(document.getElementById('p50PronoModeTabs'))return true;

  const style=document.createElement('style');
  style.id='p50PronoCoulesTabStyles';
  style.textContent=`
    .p50-prono-mode-tabs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin:8px 0 16px;padding:5px;border:1px solid rgba(183,255,0,.18);border-radius:16px;background:#0b0f0b}
    .p50-prono-mode-tabs button{min-height:44px;border:0;border-radius:12px;background:transparent;color:#aab3a7;font-weight:900;font-size:13px;padding:9px 8px;position:relative}
    .p50-prono-mode-tabs button.active{background:linear-gradient(135deg,var(--lime,#b7ff00),#71ff00);color:#050705;box-shadow:0 8px 22px rgba(183,255,0,.16)}
    .p50-prono-mode-tabs button.is-live-on::after{content:"";position:absolute;top:8px;right:8px;width:7px;height:7px;border-radius:50%;background:#ff4d4d;box-shadow:0 0 0 4px rgba(255,77,77,.18)}
    .p50-prono-mode-tabs button.active.is-live-on::after{background:#050705;box-shadow:none}
    #p50CoulesPronoPanel,#p50LivePronoPanel{display:none;margin-top:6px}
    #p50CoulesPronoPanel .p50-coules-frame-wrap{overflow:hidden;border:1px solid rgba(183,255,0,.18);border-radius:20px;background:#080b08;min-height:560px}
    #p50CoulesPronoFrame{display:block;width:100%;height:720px;min-height:560px;border:0;background:#050705}
    body.p50-prono-coules-view #scoreRow,
    body.p50-prono-coules-view #statusSection,
    body.p50-prono-coules-view #pubsSection,
    body.p50-prono-coules-view #slipBar,
    body.p50-prono-live-view #scoreRow,
    body.p50-prono-live-view #statusSection,
    body.p50-prono-live-view #pubsSection,
    body.p50-prono-live-view #slipBar{display:none!important}
    body.p50-prono-coules-view #p50CoulesPronoPanel{display:block}
    body.p50-prono-live-view #p50LivePronoPanel{display:block}
    body.p50-prono-coules-view .page-header .countdown-chip,
    body.p50-prono-live-view .page-header .countdown-chip{display:none!important}
    @media(max-width:430px){.p50-prono-mode-tabs{margin-top:4px}.p50-prono-mode-tabs button{font-size:11px;padding:9px 4px}#p50CoulesPronoFrame{min-height:620px}}
  `;
  document.head.appendChild(style);

  const tabs=document.createElement('nav');
  tabs.id='p50PronoModeTabs';
  tabs.className='p50-prono-mode-tabs rise rise-1';
  tabs.setAttribute('aria-label','Modes Pronostics');
  tabs.innerHTML='<button type="button" data-prono-mode="pronos" class="active">Pronostics</button><button type="button" data-prono-mode="coules">Vote des Coulés</button><button type="button" data-prono-mode="live">Prono50 live</button>';
  header.insertAdjacentElement('afterend',tabs);

  const panel=document.createElement('section');
  panel.id='p50CoulesPronoPanel';
  panel.setAttribute('aria-label','Vote des Coulés');
  panel.innerHTML='<div class="p50-coules-frame-wrap"><iframe id="p50CoulesPronoFrame" title="Vote des Coulés PASS50" loading="eager" src="./?embed=coules#coules"></iframe></div>';
  const pubs=document.getElementById('pubsSection');
  if(pubs)pubs.insertAdjacentElement('afterend',panel);else shell.appendChild(panel);

  const livePanel=document.createElement('section');
  livePanel.id='p50LivePronoPanel';
  livePanel.setAttribute('aria-label','Prono50 live');
  livePanel.innerHTML='<div id="p50LivePronoRoot"></div>';
  panel.insertAdjacentElement('afterend',livePanel);

  const kicker=header.querySelector('.kicker');
  const title=header.querySelector('h1');
  const rules=header.querySelector('.rules-line');
  const original={kicker:kicker?.textContent||'',title:title?.textContent||'',rules:rules?.textContent||''};

  function setMode(mode,push=true){
    const coules=mode==='coules';
    const live=mode==='live';
    document.body.classList.toggle('p50-prono-coules-view',coules);
    document.body.classList.toggle('p50-prono-live-view',live);
    tabs.querySelectorAll('[data-prono-mode]').forEach(btn=>btn.classList.toggle('active',btn.dataset.pronoMode===mode));
    if(kicker)kicker.textContent=live?'PRONO50 LIVE':(coules?'LES COULÉS':'Pronostics 24H');
    if(title)title.textContent=live?'Prono50 live':(coules?'Vote des Coulés':original.title);
    if(rules)rules.textContent=live?'Gains doublés.':(coules?'Qui est le plus coulé ? Vote unique par compte, choix modifiable.':original.rules);
    if(push){
      const url=new URL(location.href);
      if(coules)url.searchParams.set('view','coules');
      else if(live)url.searchParams.set('view','live');
      else url.searchParams.delete('view');
      history.replaceState(null,'',url.pathname+(url.search?url.search:'')+url.hash);
    }
    if(live)window.PASS50_PRONO_LIVE?.refresh?.();
    else window.PASS50_PRONO_LIVE?.hide?.();
  }

  tabs.addEventListener('click',e=>{
    const btn=e.target.closest('[data-prono-mode]');
    if(btn)setMode(btn.dataset.pronoMode);
  });

  window.addEventListener('message',e=>{
    if(e.data?.type!=='pass50-coules-height')return;
    const h=Math.max(560,Math.min(1800,Number(e.data.height)||720));
    const frame=document.getElementById('p50CoulesPronoFrame');
    if(frame)frame.style.height=h+'px';
  });

  const view=params.get('view');
  const initial=view==='coules'?'coules':(view==='live'?'live':'pronos');
  setMode(initial,false);
  window.PASS50_PRONOSTICS_COULES={version:'1.1',setMode};
  loadLiveModule();
  return true;
}

function loadLiveModule(){
  if(window.__pass50PronoLiveTabV1||document.querySelector('script[data-pass50-prono-live-tab]'))return;
  const script=document.createElement('script');
  script.src='./pronostics-live-tab-v1.js?v=1.0';
  script.async=false;
  script.dataset.pass50PronoLiveTab='1.0';
  document.head.appendChild(script);
}

function boot(){
  if(isCoulesEmbed){if(!installEmbedMode())setTimeout(boot,120);return;}
  if(isProno){if(!installPronoTabs())setTimeout(boot,120);}
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
