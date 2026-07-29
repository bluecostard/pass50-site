'use strict';
(function(){
  const STORAGE_KEY='pass50_connector_sections_v1';
  const KNOWN_IDS={p50YoutubeOauthSection:'youtube',p50MetaOauthSection:'meta'};
  let scheduled=false;

  function readState(){
    try{const parsed=JSON.parse(localStorage.getItem(STORAGE_KEY)||'{}');return parsed&&typeof parsed==='object'?parsed:{};}catch{return {};}
  }

  function writeState(state){
    try{localStorage.setItem(STORAGE_KEY,JSON.stringify(state));}catch{}
  }

  function keyFor(section){
    return String(section?.dataset?.p50ConnectorKey||KNOWN_IDS[section?.id]||'').trim();
  }

  function defaultCollapsed(section){
    const explicit=section?.dataset?.p50ConnectorDefault;
    if(explicit==='open')return false;
    if(explicit==='collapsed')return true;
    return true;
  }

  function mustStayOpen(section){
    return Boolean(section.querySelector('[role="alert"],.p50-meta-message.error,[data-p50-connector-force-open],button[disabled][data-p50-meta-connect],button[disabled][data-p50-youtube-connect]'));
  }

  function storedCollapsed(section,key){
    const state=readState();
    return Object.prototype.hasOwnProperty.call(state,key)?Boolean(state[key]):defaultCollapsed(section);
  }

  function persist(key,collapsed){
    const state=readState();state[key]=Boolean(collapsed);writeState(state);
  }

  function apply(section,collapsed,{persistState=false}={}){
    const key=keyFor(section);if(!key)return;
    const panel=section.querySelector(':scope > .p50-connector-panel');
    const toggle=section.querySelector(':scope > .user-title [data-p50-connector-toggle]');
    if(!panel||!toggle)return;
    const forced=mustStayOpen(section);
    const effective=forced?false:Boolean(collapsed);
    panel.hidden=effective;
    section.classList.toggle('is-collapsed',effective);
    toggle.setAttribute('aria-expanded',String(!effective));
    toggle.setAttribute('aria-controls',panel.id);
    toggle.querySelector('[data-p50-connector-arrow]').textContent=effective?'▸':'▾';
    toggle.querySelector('[data-p50-connector-label]').textContent=effective?'Déplier':'Replier';
    if(persistState&&!forced)persist(key,effective);
  }

  function toggle(section){
    const key=keyFor(section);if(!key)return;
    const panel=section.querySelector(':scope > .p50-connector-panel');if(!panel)return;
    apply(section,!panel.hidden,{persistState:true});
  }

  function enhance(section){
    const key=keyFor(section);if(!key)return;
    section.dataset.p50ConnectorKey=key;
    section.classList.add('p50-connector-section');
    const title=section.querySelector(':scope > .user-title');if(!title)return;
    title.classList.add('p50-connector-header');

    let panel=section.querySelector(':scope > .p50-connector-panel');
    if(!panel){
      panel=document.createElement('div');
      panel.className='p50-connector-panel';
      panel.id=`p50ConnectorPanel_${key.replace(/[^a-z0-9_-]/gi,'_')}`;
      [...section.children].filter(node=>node!==title).forEach(node=>panel.appendChild(node));
      section.appendChild(panel);
    }

    let button=title.querySelector('[data-p50-connector-toggle]');
    if(!button){
      button=document.createElement('button');
      button.type='button';
      button.className='p50-connector-toggle';
      button.dataset.p50ConnectorToggle='1';
      button.innerHTML='<span data-p50-connector-arrow aria-hidden="true">▸</span><span data-p50-connector-label>Déplier</span>';
      button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();toggle(section);});
      title.appendChild(button);
    }

    if(!title.dataset.p50ConnectorClickInstalled){
      title.dataset.p50ConnectorClickInstalled='1';
      title.addEventListener('click',event=>{
        if(event.target.closest('a,button,input,select,textarea,label'))return;
        toggle(section);
      });
    }

    apply(section,storedCollapsed(section,key));
  }

  function scan(){
    scheduled=false;
    const selector='[data-p50-connector-key],#p50YoutubeOauthSection,#p50MetaOauthSection';
    document.querySelectorAll(selector).forEach(enhance);
  }

  function schedule(){
    if(scheduled)return;scheduled=true;queueMicrotask(scan);
  }

  function injectStyles(){
    if(document.getElementById('p50ConnectorSectionsStyles'))return;
    const style=document.createElement('style');style.id='p50ConnectorSectionsStyles';
    style.textContent=`
      .p50-connector-section>.p50-connector-header{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer}
      .p50-connector-toggle{display:inline-flex;align-items:center;gap:6px;border:1px solid #394339;border-radius:999px;background:#0c110c;color:#d7ded4;padding:5px 9px;font:inherit;font-size:10px;font-weight:950;cursor:pointer;white-space:nowrap}
      .p50-connector-toggle:hover,.p50-connector-toggle:focus-visible{border-color:#b7ff00;color:#b7ff00;outline:none}
      .p50-connector-panel[hidden]{display:none!important}
      .p50-connector-section.is-collapsed{padding-bottom:10px}
      .p50-connector-section.is-collapsed>.p50-connector-header{margin-bottom:0}
      @media(max-width:720px){.p50-connector-toggle [data-p50-connector-label]{display:none}.p50-connector-toggle{padding:5px 9px}}
    `;
    document.head.appendChild(style);
  }

  function setOpen(key,open){
    const escaped=CSS.escape(String(key));
    let section=document.querySelector(`[data-p50-connector-key="${escaped}"]`);
    if(!section){
      const entry=Object.entries(KNOWN_IDS).find(([,value])=>value===key);
      if(entry)section=document.getElementById(entry[0]);
    }
    if(!section)return;
    enhance(section);
    apply(section,!open,{persistState:true});
  }

  function install(){
    injectStyles();scan();
    new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
    window.PASS50_CONNECTOR_SECTIONS={
      register(section,key,defaultState='collapsed'){
        if(!section)return;
        section.dataset.p50ConnectorKey=String(key);
        section.dataset.p50ConnectorDefault=defaultState;
        enhance(section);
      },
      open:key=>setOpen(key,true),
      close:key=>setOpen(key,false),
      refresh:scan,
    };
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
}());
