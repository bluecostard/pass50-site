(function(){
  'use strict';

  const VERSION='PASS50-FACEBOOK-VIDEO-PLAYER-V1.1';
  const state={profileId:'',videos:new Map(),requestId:0,previousFocus:null,previousOverflow:'',source:'',title:'',embedType:'post'};

  function facebookSourceUrl(value){
    try{
      const url=new URL(String(value||''),window.location.href);
      const host=url.hostname.toLowerCase();
      if(url.protocol!=='https:')return '';
      if(!(host==='facebook.com'||host.endsWith('.facebook.com')||host==='fb.watch'))return '';
      return url.href;
    }catch(_){return '';}
  }

  function facebookKey(value){
    const source=facebookSourceUrl(value);if(!source)return '';
    const url=new URL(source);
    const host=url.hostname.toLowerCase().replace(/^(www|m|web)\./,'');
    const path=url.pathname.replace(/\/+$/,'')||'/';
    return `${host}${path}`.toLowerCase();
  }

  function normalizeEmbedType(value){
    return String(value||'').toLowerCase()==='video'?'video':'post';
  }

  function facebookEmbedTypeFromUrl(value){
    const source=facebookSourceUrl(value);if(!source)return 'post';
    const url=new URL(source),host=url.hostname.toLowerCase(),path=url.pathname.toLowerCase();
    if(host==='fb.watch'||/(?:^|\/)(?:watch\/?|reel\/|share\/(?:v|r)\/|[^/]+\/videos\/)/i.test(path))return 'video';
    return 'post';
  }

  function facebookEmbedUrl(value,embedType){
    const source=facebookSourceUrl(value);if(!source)return '';
    const type=normalizeEmbedType(embedType),plugin=type==='video'?'video':'post';
    const query=new URLSearchParams({href:source,width:'820',show_text:type==='post'?'true':'false'});
    return `https://www.facebook.com/plugins/${plugin}.php?${query.toString()}`;
  }

  function injectStyles(){
    if(document.getElementById('p50FacebookVideoPlayerStyles'))return;
    const style=document.createElement('style');
    style.id='p50FacebookVideoPlayerStyles';
    style.textContent=`
      .p50fb-actions{display:grid;gap:6px;min-width:132px}.p50fb-actions .btn{text-align:center}
      .p50fb-player[hidden]{display:none!important}.p50fb-player{position:fixed;inset:0;z-index:120000;display:grid;place-items:center;padding:18px;background:rgba(0,0,0,.84);backdrop-filter:blur(8px)}
      .p50fb-dialog{width:min(860px,100%);max-height:calc(100vh - 36px);overflow:auto;border:1px solid rgba(76,167,255,.55);border-radius:20px;background:#090c09;box-shadow:0 24px 90px rgba(0,0,0,.65)}
      .p50fb-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:14px 16px;border-bottom:1px solid var(--line)}
      .p50fb-title{font-size:15px;line-height:1.35;font-weight:1000}.p50fb-close{width:38px;height:38px;flex:0 0 auto;border:1px solid var(--line);border-radius:50%;background:#131813;color:#fff;font-size:24px;line-height:1;cursor:pointer}
      .p50fb-frame{position:relative;background:#000}.p50fb-frame iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
      .p50fb-player[data-embed-type="video"] .p50fb-frame{aspect-ratio:16/9;min-height:260px}.p50fb-player[data-embed-type="post"] .p50fb-frame{height:min(680px,70vh);min-height:520px;background:#18191a}
      .p50fb-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px}.p50fb-note{font-size:11px;line-height:1.45;color:var(--muted);flex:1}.p50fb-foot-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
      @media(max-width:680px){.p50fb-actions{grid-column:1/-1;grid-template-columns:1fr 1fr;width:100%}.p50fb-player{padding:8px}.p50fb-dialog{max-height:calc(100vh - 16px);border-radius:15px}.p50fb-player[data-embed-type="video"] .p50fb-frame{min-height:210px}.p50fb-player[data-embed-type="post"] .p50fb-frame{height:64vh;min-height:480px}.p50fb-foot{align-items:stretch;flex-direction:column}.p50fb-foot-actions{display:grid;grid-template-columns:1fr 1fr}.p50fb-foot .btn{text-align:center;width:100%}}
    `;
    document.head.appendChild(style);
  }

  function ensurePlayer(){
    let player=document.getElementById('p50FacebookVideoPlayer');if(player)return player;
    player=document.createElement('div');
    player.id='p50FacebookVideoPlayer';
    player.className='p50fb-player';
    player.hidden=true;
    player.dataset.version=VERSION;
    player.dataset.embedType='post';
    player.setAttribute('role','dialog');
    player.setAttribute('aria-modal','true');
    player.setAttribute('aria-labelledby','p50FacebookVideoTitle');
    player.innerHTML=`<div class="p50fb-dialog">
      <div class="p50fb-head"><div id="p50FacebookVideoTitle" class="p50fb-title">Vidéo Facebook</div><button type="button" class="p50fb-close" data-p50fb-close aria-label="Fermer le lecteur">×</button></div>
      <div class="p50fb-frame"><iframe title="Lecteur Facebook" src="about:blank" loading="eager" scrolling="yes" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe></div>
      <div class="p50fb-foot"><div class="p50fb-note" data-p50fb-note>Chargement de la publication Facebook…</div><div class="p50fb-foot-actions"><button type="button" class="btn small" data-p50fb-alternate>Essayer le lecteur vidéo</button><a class="btn small" data-p50fb-external href="#" target="_blank" rel="noopener">Ouvrir Facebook ↗</a></div></div>
    </div>`;
    document.body.appendChild(player);
    return player;
  }

  function renderPlayer(){
    const player=ensurePlayer(),type=normalizeEmbedType(state.embedType),embed=facebookEmbedUrl(state.source,type);
    const iframe=player.querySelector('iframe'),alternate=player.querySelector('[data-p50fb-alternate]'),note=player.querySelector('[data-p50fb-note]');
    player.dataset.embedType=type;
    if(iframe){
      iframe.title=`${type==='post'?'Publication':'Vidéo'} Facebook — ${String(state.title||'publication officielle').trim()}`;
      iframe.setAttribute('scrolling',type==='post'?'yes':'no');
      iframe.src='about:blank';
      requestAnimationFrame(()=>{iframe.src=embed;});
    }
    if(alternate)alternate.textContent=type==='post'?'Essayer le lecteur vidéo':'Essayer comme publication';
    if(note)note.textContent=type==='post'
      ?'Cette vidéo est ouverte comme une publication Facebook, car son lien officiel est un lien de post.'
      :'Cette adresse est ouverte avec le lecteur vidéo Facebook.';
  }

  function closePlayer(){
    const player=document.getElementById('p50FacebookVideoPlayer');if(!player||player.hidden)return;
    const iframe=player.querySelector('iframe');if(iframe)iframe.src='about:blank';
    player.hidden=true;
    document.body.style.overflow=state.previousOverflow;
    if(state.previousFocus&&typeof state.previousFocus.focus==='function')state.previousFocus.focus();
    state.previousFocus=null;state.source='';state.title='';state.embedType='post';
  }

  function openPlayer(source,title,embedType){
    const url=facebookSourceUrl(source);if(!url)return;
    const player=ensurePlayer();
    const external=player.querySelector('[data-p50fb-external]'),heading=player.querySelector('#p50FacebookVideoTitle');
    state.previousFocus=document.activeElement;
    state.previousOverflow=document.body.style.overflow;
    state.source=url;
    state.title=String(title||'Vidéo Facebook').trim()||'Vidéo Facebook';
    state.embedType=normalizeEmbedType(embedType||facebookEmbedTypeFromUrl(url));
    document.body.style.overflow='hidden';
    if(heading)heading.textContent=state.title;
    if(external)external.href=url;
    renderPlayer();
    player.hidden=false;
    player.querySelector('[data-p50fb-close]')?.focus();
  }

  function activePeriod(){
    return document.querySelector('[data-p50ci-period].active')?.dataset?.p50ciPeriod||'24h';
  }

  async function fetchProfileVideos(profileId){
    const requestId=++state.requestId;
    const query=new URLSearchParams({profileId,period:activePeriod(),newsLimit:'30',_:String(Date.now())});
    try{
      const response=await fetch(`./api/content-feed.php?${query}`,{headers:{Accept:'application/json'},cache:'no-store'});
      const data=await response.json().catch(()=>({}));
      if(requestId!==state.requestId||profileId!==state.profileId)return;
      state.videos.clear();
      if(response.ok){
        for(const item of data.news||[]){
          if(String(item.platform||'').toLowerCase()!=='facebook'||item.playableInPass50!==true)continue;
          const key=facebookKey(item.url);if(key)state.videos.set(key,item);
        }
      }
      decorateCards();
    }catch(error){console.warn('PASS50 Facebook video player',error);}
  }

  function decorateCards(){
    if(!state.profileId||state.videos.size===0)return;
    document.querySelectorAll('#p50ciProfileNews .p50ci-news-card.facebook').forEach(card=>{
      if(card.querySelector('[data-p50fb-play]'))return;
      const external=card.querySelector('a[href]');if(!external)return;
      const item=state.videos.get(facebookKey(external.href));if(!item)return;
      const title=String(item.title||card.querySelector('h4')?.textContent||'Vidéo Facebook').trim();
      const embedType=normalizeEmbedType(item.facebookEmbedType||facebookEmbedTypeFromUrl(item.url));
      const button=document.createElement('button');
      button.type='button';
      button.className='btn small primary';
      button.dataset.p50fbPlay='1';
      button.dataset.url=facebookSourceUrl(item.url);
      button.dataset.title=title;
      button.dataset.embedType=embedType;
      button.textContent='▶ Lire la vidéo';
      const actions=document.createElement('div');
      actions.className='p50fb-actions';
      external.replaceWith(actions);
      actions.append(button,external);
      const note=card.querySelector('.p50ci-facebook-note');
      if(note)note.textContent=embedType==='post'?'Vidéo lisible via la publication Facebook.':'Vidéo publique lisible dans Pass50.';
    });
  }

  function scheduleDecorate(){
    setTimeout(decorateCards,0);
    setTimeout(decorateCards,250);
    setTimeout(decorateCards,900);
  }

  function bind(){
    document.addEventListener('p50:profile-opened',event=>{
      closePlayer();
      state.profileId=String(event?.detail?.profileId||'').trim();
      state.videos.clear();
      if(state.profileId)fetchProfileVideos(state.profileId);
    });
    document.addEventListener('click',event=>{
      const play=event.target.closest('[data-p50fb-play]');
      if(play){event.preventDefault();openPlayer(play.dataset.url,play.dataset.title,play.dataset.embedType);return;}
      const alternate=event.target.closest('[data-p50fb-alternate]');
      if(alternate){event.preventDefault();state.embedType=state.embedType==='post'?'video':'post';renderPlayer();return;}
      const close=event.target.closest('[data-p50fb-close]');
      if(close){event.preventDefault();closePlayer();return;}
      const backdrop=event.target.closest('#p50FacebookVideoPlayer');
      if(backdrop&&event.target===backdrop){closePlayer();return;}
      if(event.target.closest('[data-p50ci-expand]'))scheduleDecorate();
    });
    document.addEventListener('keydown',event=>{if(event.key==='Escape')closePlayer();});
    const observer=new MutationObserver(()=>scheduleDecorate());
    observer.observe(document.body,{childList:true,subtree:true});
  }

  function init(){injectStyles();ensurePlayer();bind();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
