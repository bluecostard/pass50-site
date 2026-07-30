(function(){
'use strict';

if(window.PASS50_SHARE_CENTER)return;

const VERSION='1.0.0';
const THEMES={
  site:{accent:'#1ee5ff',soft:'rgba(30,229,255,.16)',label:'PASS50',icon:'↗',kicker:'LE SITE',cta:'DÉCOUVRIR PASS50'},
  profile:{accent:'#b7ff00',soft:'rgba(183,255,0,.16)',label:'FICHE INFLUENCEUR',icon:'★',kicker:'FICHE OFFICIELLE',cta:'VOIR LA FICHE'},
  live:{accent:'#ff4b4b',soft:'rgba(255,75,75,.16)',label:'EN DIRECT',icon:'●',kicker:'LIVE PASS50',cta:'REGARDER MAINTENANT'},
  coules:{accent:'#ff9d1d',soft:'rgba(255,157,29,.17)',label:'LES COULÉS',icon:'⚔',kicker:'MON VOTE',cta:'VOIR LE DUEL'},
  'coules-audio':{accent:'#a66cff',soft:'rgba(166,108,255,.18)',label:'LES COULÉS + AUDIO',icon:'🎙',kicker:'MON VOTE COMMENTÉ',cta:'ÉCOUTER ET VOIR'}
};
let current=null;
let deepLinkApplied=false;

const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const attr=esc;

function notify(message){
  try{if(typeof toast==='function')toast(message)}catch{}
}

function profiles(){
  try{return Array.isArray(db?.profiles)?db.profiles:[]}catch{return []}
}

function profileById(id){
  return profiles().find(item=>String(item.id)===String(id))||null;
}

function publicPhoto(profile){
  if(!profile)return '';
  try{
    if(typeof window.publicPhoto==='function')return String(window.publicPhoto(profile)||'');
  }catch{}
  return String(profile.photoUrl||profile.photoCandidateUrl||'');
}

function initials(name){
  const parts=String(name||'PASS50').trim().split(/\s+/).filter(Boolean);
  return parts.slice(0,2).map(part=>part[0]||'').join('').toUpperCase()||'P50';
}

function basePath(){
  const path=location.pathname.replace(/[^/]*$/,'');
  return `${location.origin}${path}`;
}

function shareUrl(type,data={}){
  const url=new URL('partage.php',basePath());
  url.searchParams.set('type',type);
  if(data.profileId)url.searchParams.set('id',String(data.profileId));
  if(data.platform)url.searchParams.set('platform',String(data.platform));
  if(data.choice)url.searchParams.set('choice',String(data.choice));
  return url.href;
}

function liveFor(profileId,platform=''){
  try{
    return (db.liveStreams||[]).find(item=>String(item.profileId)===String(profileId)&&item.status==='live'&&(!platform||String(item.platform)===String(platform)))||null;
  }catch{return null}
}

function payloadFor(type,data={}){
  const theme=THEMES[type]||THEMES.site;
  const profile=data.profile||profileById(data.profileId);
  const name=String(data.name||profile?.name||'PASS50');
  const handle=String(data.handle||profile?.handle||'');
  const photo=String(data.photo||publicPhoto(profile)||'');
  let title='Qui fait le buzz maintenant ?';
  let subtitle='Le classement du buzz et des influenceurs ivoiriens.';
  if(type==='profile'){
    title=name;
    subtitle=[handle,'Fiche influenceur PASS50'].filter(Boolean).join(' · ');
  }else if(type==='live'){
    title=name;
    subtitle=`En direct${data.platform?` sur ${data.platform}`:''}`;
  }else if(type==='coules'||type==='coules-audio'){
    title=data.title||name||'Mon vote PASS50';
    subtitle=type==='coules-audio'?'Mon vote avec commentaire audio':'Mon choix dans le duel';
  }
  return {
    type,theme,name,title,subtitle,photo,
    profileId:String(data.profileId||profile?.id||''),
    platform:String(data.platform||''),
    directUrl:String(data.directUrl||data.url||''),
    choice:String(data.choice||data.profileId||''),
    url:shareUrl(type,{profileId:data.profileId||profile?.id,platform:data.platform,choice:data.choice||data.profileId}),
    text:data.text||''
  };
}

function ensureModal(){
  if(document.getElementById('p50ShareCenter'))return;
  const style=document.createElement('style');
  style.id='p50ShareCenterStyle';
  style.textContent=`
  #shareBtn{display:inline-grid!important;place-items:center}
  .p50-share-center{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,0,0,.82);backdrop-filter:blur(14px)}
  .p50-share-center.show{display:flex}
  .p50-share-box{--share-accent:#1ee5ff;--share-soft:rgba(30,229,255,.16);width:min(440px,100%);padding:12px;border:1px solid color-mix(in srgb,var(--share-accent) 48%,#293129);border-radius:27px;background:#080b08;box-shadow:0 34px 110px rgba(0,0,0,.75)}
  .p50-share-close{display:grid;place-items:center;margin-left:auto;width:42px;height:42px;border:1px solid #293129;border-radius:50%;background:#111611;color:#fff;font-size:23px}
  .p50-share-card{position:relative;min-height:474px;padding:27px;overflow:hidden;border-radius:22px;background:radial-gradient(circle at 94% 4%,var(--share-soft),transparent 34%),linear-gradient(152deg,#151b15 0%,#060806 72%);display:flex;flex-direction:column}
  .p50-share-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:8px;background:var(--share-accent)}
  .p50-share-brand{font-size:27px;font-weight:1000;letter-spacing:-1.3px}.p50-share-brand span{color:var(--share-accent)}
  .p50-share-kicker{margin-top:42px;color:var(--share-accent);font-size:12px;font-weight:1000;letter-spacing:1.7px}
  .p50-share-pill{align-self:flex-start;margin-top:12px;padding:8px 13px;border:1px solid var(--share-accent);border-radius:999px;color:var(--share-accent);background:var(--share-soft);font-size:12px;font-weight:1000}
  .p50-share-person{display:grid;grid-template-columns:96px minmax(0,1fr);gap:17px;align-items:center;margin-top:25px}
  .p50-share-avatar{width:96px;height:96px;border:4px solid var(--share-accent);border-radius:24px;display:grid;place-items:center;overflow:hidden;background:#202820;color:#fff;font-size:32px;font-weight:1000}
  .p50-share-avatar img{width:100%;height:100%;object-fit:cover}
  .p50-share-title{margin:0;font-size:34px;line-height:1.02;letter-spacing:-1.5px;overflow-wrap:anywhere}
  .p50-share-subtitle{margin-top:7px;color:#aeb8aa;font-weight:850;line-height:1.35}
  .p50-share-rule{height:1px;margin:25px 0;background:linear-gradient(90deg,var(--share-accent),transparent)}
  .p50-share-cta{margin-top:auto;padding:16px;border-radius:16px;background:var(--share-accent);color:#050705;text-align:center;font-weight:1000;letter-spacing:.4px}
  .p50-share-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:11px}
  .p50-share-actions button{min-height:52px;border:1px solid #293129;border-radius:14px;background:#111611;color:#fff;font-weight:950}
  .p50-share-actions button:first-child{background:var(--share-accent);border-color:var(--share-accent);color:#050705}
  .p50-share-code{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:9px 11px;border:1px solid var(--share-accent);border-radius:12px;color:var(--share-accent);background:var(--share-soft);font-size:11px;font-weight:1000;letter-spacing:.7px}
  #voteShareModal.p50-coules-share{--coules-accent:#ff9d1d}#voteShareModal.p50-coules-audio-share{--coules-accent:#a66cff}
  #voteShareModal.p50-coules-share .modal-box,#voteShareModal.p50-coules-audio-share .modal-box{border-color:var(--coules-accent)}
  #voteShareModal.p50-coules-share .vote-share-preview,#voteShareModal.p50-coules-audio-share .vote-share-preview{border-color:var(--coules-accent);box-shadow:0 0 32px color-mix(in srgb,var(--coules-accent) 20%,transparent)}
  #voteShareModal.p50-coules-share .vote-share-actions .primary,#voteShareModal.p50-coules-audio-share .vote-share-actions .primary{background:var(--coules-accent);border-color:var(--coules-accent);color:#050705}
  @media(max-width:520px){
    .p50-share-center{padding:7px;align-items:flex-end}.p50-share-box{border-radius:23px 23px 0 0;padding:9px;padding-bottom:calc(9px + env(safe-area-inset-bottom))}
    .p50-share-card{min-height:410px;padding:22px}.p50-share-kicker{margin-top:28px}.p50-share-person{grid-template-columns:78px minmax(0,1fr)}.p50-share-avatar{width:78px;height:78px;border-radius:20px}.p50-share-title{font-size:29px}.p50-share-actions{grid-template-columns:1fr}.p50-share-actions button{min-height:48px}
  }`;
  document.head.appendChild(style);

  const modal=document.createElement('div');
  modal.id='p50ShareCenter';
  modal.className='p50-share-center';
  modal.setAttribute('aria-hidden','true');
  modal.innerHTML=`<div class="p50-share-box" role="dialog" aria-modal="true" aria-label="Partager sur PASS50">
    <button type="button" class="p50-share-close" aria-label="Fermer">×</button>
    <div class="p50-share-card">
      <div class="p50-share-brand">PASS<span>50</span></div>
      <div class="p50-share-kicker">LE SITE</div>
      <div class="p50-share-pill">↗ PASS50</div>
      <div class="p50-share-person">
        <div class="p50-share-avatar">P50</div>
        <div><h2 class="p50-share-title">PASS50</h2><div class="p50-share-subtitle"></div></div>
      </div>
      <div class="p50-share-rule"></div>
      <div class="p50-share-cta">DÉCOUVRIR PASS50</div>
    </div>
    <div class="p50-share-actions">
      <button type="button" data-p50-share-native>Partager</button>
      <button type="button" data-p50-share-whatsapp>WhatsApp</button>
      <button type="button" data-p50-share-copy>Copier</button>
    </div>
  </div>`;
  document.body.appendChild(modal);
}

function render(payload){
  ensureModal();
  current=payload;
  const modal=document.getElementById('p50ShareCenter');
  const box=modal.querySelector('.p50-share-box');
  box.style.setProperty('--share-accent',payload.theme.accent);
  box.style.setProperty('--share-soft',payload.theme.soft);
  modal.querySelector('.p50-share-kicker').textContent=payload.theme.kicker;
  modal.querySelector('.p50-share-pill').textContent=`${payload.theme.icon} ${payload.theme.label}`;
  modal.querySelector('.p50-share-title').textContent=payload.title;
  modal.querySelector('.p50-share-subtitle').textContent=payload.subtitle;
  modal.querySelector('.p50-share-cta').textContent=payload.theme.cta;
  const avatar=modal.querySelector('.p50-share-avatar');
  avatar.innerHTML=payload.photo?`<img src="${attr(payload.photo)}" alt="">`:esc(initials(payload.title));
  modal.classList.add('show');
  modal.setAttribute('aria-hidden','false');
}

function close(){
  const modal=document.getElementById('p50ShareCenter');
  if(modal){modal.classList.remove('show');modal.setAttribute('aria-hidden','true');}
  current=null;
}

function wrapLines(ctx,text,maxWidth,maxLines=3){
  const words=String(text||'').split(/\s+/),lines=[];let line='';
  for(const word of words){
    const next=line?`${line} ${word}`:word;
    if(ctx.measureText(next).width<=maxWidth)line=next;
    else{if(line)lines.push(line);line=word;if(lines.length>=maxLines-1)break;}
  }
  if(line&&lines.length<maxLines)lines.push(line);
  return lines;
}

function imageFile(payload){
  return new Promise(resolve=>{
    const canvas=document.createElement('canvas');
    canvas.width=1080;canvas.height=1350;
    const ctx=canvas.getContext('2d');
    const gradient=ctx.createLinearGradient(0,0,1080,1350);
    gradient.addColorStop(0,'#151b15');gradient.addColorStop(1,'#050705');
    ctx.fillStyle=gradient;ctx.fillRect(0,0,1080,1350);
    ctx.fillStyle=payload.theme.accent;ctx.fillRect(0,0,22,1350);
    ctx.globalAlpha=.14;ctx.fillStyle=payload.theme.accent;ctx.beginPath();ctx.arc(980,80,370,0,Math.PI*2);ctx.fill();ctx.globalAlpha=1;
    ctx.fillStyle='#fff';ctx.font='1000 76px Arial';ctx.fillText('PASS',70,112);
    ctx.fillStyle=payload.theme.accent;ctx.fillText('50',260,112);
    ctx.fillStyle=payload.theme.accent;ctx.font='1000 30px Arial';ctx.fillText(payload.theme.kicker,70,240);
    ctx.strokeStyle=payload.theme.accent;ctx.lineWidth=4;ctx.beginPath();ctx.roundRect(70,282,500,80,40);ctx.stroke();
    ctx.fillStyle=payload.theme.accent;ctx.font='1000 30px Arial';ctx.fillText(`${payload.theme.icon}  ${payload.theme.label}`,105,333);
    ctx.fillStyle='#fff';ctx.font='1000 82px Arial';
    const titleLines=wrapLines(ctx,payload.title.toUpperCase(),900,3);
    titleLines.forEach((line,index)=>ctx.fillText(line,70,540+index*94));
    const subtitleY=540+titleLines.length*94+35;
    ctx.fillStyle='#aeb8aa';ctx.font='800 37px Arial';
    wrapLines(ctx,payload.subtitle,880,3).forEach((line,index)=>ctx.fillText(line,70,subtitleY+index*52));
    ctx.fillStyle=payload.theme.accent;ctx.beginPath();ctx.roundRect(70,1110,940,130,36);ctx.fill();
    ctx.fillStyle='#050705';ctx.textAlign='center';ctx.font='1000 43px Arial';ctx.fillText(payload.theme.cta,540,1191);ctx.textAlign='left';
    ctx.fillStyle='#aeb8aa';ctx.font='800 30px Arial';ctx.fillText('pass50.store',70,1300);
    canvas.toBlob(blob=>resolve(blob?new File([blob],`pass50-${payload.type}.png`,{type:'image/png'}):null),'image/png',.94);
  });
}

function message(payload){
  if(payload.text)return `${payload.text}\n${payload.url}`;
  if(payload.type==='profile')return `Découvre la fiche influenceur PASS50 de ${payload.title}.\n${payload.url}`;
  if(payload.type==='live')return `🔴 ${payload.title} est en direct${payload.platform?` sur ${payload.platform}`:''}.\n${payload.url}`;
  if(payload.type==='coules-audio')return `🎙 Mon vote commenté dans Les Coulés sur PASS50.\n${payload.url}`;
  if(payload.type==='coules')return `⚔ Mon vote dans Les Coulés sur PASS50.\n${payload.url}`;
  return `Découvre PASS50 — qui fait le buzz maintenant ?\n${payload.url}`;
}

async function copyText(value){
  if(navigator.clipboard?.writeText)return navigator.clipboard.writeText(value);
  const area=document.createElement('textarea');
  area.value=value;area.style.position='fixed';area.style.opacity='0';
  document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();
}

async function nativeShare(){
  if(!current)return;
  try{
    const file=await imageFile(current);
    const data={title:`PASS50 · ${current.title}`,text:message(current),url:current.url};
    if(file&&navigator.canShare?.({files:[file]}))data.files=[file];
    if(navigator.share){await navigator.share(data);return;}
    await copyText(message(current));notify('Lien copié');
  }catch(error){
    if(error?.name!=='AbortError'){try{await copyText(message(current));notify('Lien copié')}catch{notify('Partage indisponible')}}
  }
}

function whatsapp(){
  if(!current)return;
  const url=`https://wa.me/?text=${encodeURIComponent(message(current))}`;
  const opened=window.open(url,'_blank');
  if(opened)try{opened.opener=null}catch{}
}

async function copy(){
  if(!current)return;
  try{await copyText(current.url);notify('Lien copié')}catch{notify('Copie impossible')}
}

function openSite(){render(payloadFor('site'))}
function openProfile(profileId){
  const profile=profileById(profileId);
  if(profile)render(payloadFor('profile',{profileId,profile}));
}
function openLive(data={}){
  const profileId=String(data.profileId||data.id||'');
  const platform=String(data.platform||'');
  const live=liveFor(profileId,platform)||{};
  render(payloadFor('live',{
    profileId,
    profile:profileById(profileId),
    platform:platform||live.platform||'',
    directUrl:data.directUrl||data.url||live.url||''
  }));
}
function openCoules(data={},audio=false){
  render(payloadFor(audio?'coules-audio':'coules',data));
}

function decorateCoules(){
  const modal=document.getElementById('voteShareModal');
  const body=document.getElementById('voteShareBody');
  if(!modal||!body)return;
  let audio=false;
  try{audio=Boolean(VOTE_SHARE?.audioBlob)}catch{}
  modal.classList.toggle('p50-coules-share',!audio);
  modal.classList.toggle('p50-coules-audio-share',audio);
  const accent=audio?THEMES['coules-audio'].accent:THEMES.coules.accent;
  modal.style.setProperty('--coules-accent',accent);
  body.querySelector('.p50-share-code')?.remove();
  const code=document.createElement('div');
  code.className='p50-share-code';
  code.style.setProperty('--share-accent',accent);
  code.style.setProperty('--share-soft',audio?THEMES['coules-audio'].soft:THEMES.coules.soft);
  code.textContent=audio?'🟣 LES COULÉS · PARTAGE AVEC AUDIO':'🟠 LES COULÉS · PARTAGE SANS AUDIO';
  body.prepend(code);
}

function installCoulesBridge(){
  if(typeof window.voteSharePanel==='function'&&!window.voteSharePanel.__p50Unified){
    const core=window.voteSharePanel;
    const wrapped=function(){const result=core.apply(this,arguments);requestAnimationFrame(decorateCoules);return result};
    wrapped.__p50Unified=true;window.voteSharePanel=wrapped;
  }
  if(typeof window.voteShareMessage==='function'&&!window.voteShareMessage.__p50Unified){
    const core=window.voteShareMessage;
    const wrapped=function(card){
      let text=core.apply(this,arguments),audio=false;
      try{audio=Boolean(VOTE_SHARE?.audioBlob)}catch{}
      const target=audio?(card.campaignAudioUrl||card.campaignUrl):card.campaignUrl;
      if(target){
        const urls=[card.campaignUrl,card.campaignAudioUrl].filter(Boolean);
        urls.forEach(url=>{text=text.split(url).join(target)});
        if(!text.includes(target))text+=`\n${target}`;
      }
      return text;
    };
    wrapped.__p50Unified=true;window.voteShareMessage=wrapped;
  }
  if(typeof window.drawVoteShareCard==='function'&&!window.drawVoteShareCard.__p50Unified){
    const core=window.drawVoteShareCard;
    const wrapped=async function(canvas,card,options){
      const result=await core.apply(this,arguments);
      let audio=false;try{audio=Boolean(VOTE_SHARE?.audioBlob)}catch{}
      const accent=audio?THEMES['coules-audio'].accent:THEMES.coules.accent;
      const ctx=canvas?.getContext?.('2d');
      if(ctx){
        ctx.save();ctx.fillStyle=accent;ctx.fillRect(0,0,canvas.width,14);
        ctx.fillStyle=accent;ctx.font=`1000 ${Math.max(24,Math.round(canvas.width*.027))}px Arial`;
        ctx.fillText(audio?'LES COULÉS · VOTE COMMENTÉ':'LES COULÉS · MON VOTE',24,Math.max(48,Math.round(canvas.height*.045)));ctx.restore();
      }
      return result;
    };
    wrapped.__p50Unified=true;window.drawVoteShareCard=wrapped;
  }
}

function applyDeepLink(){
  if(deepLinkApplied)return;
  const params=new URLSearchParams(location.search);
  const profileId=params.get('profile');
  const liveId=params.get('live');
  const section=params.get('section');
  if(!profileId&&!liveId&&!section)return;
  const ready=()=>{
    if(profileId){
      const profile=profileById(profileId);
      if(profile&&typeof window.openProfile==='function'){deepLinkApplied=true;window.openProfile(profileId);return true;}
    }
    if(liveId){
      const profile=profileById(liveId);
      if(profile){
        deepLinkApplied=true;
        const live=liveFor(liveId,params.get('platform')||'');
        if(typeof window.openLives==='function')window.openLives();
        setTimeout(()=>{
          const link=document.querySelector(`.live-watch-link[data-live-profile="${CSS.escape(liveId)}"]`);
          const card=link?.closest('.live-card');
          if(card){card.style.outline='3px solid #ff4b4b';card.scrollIntoView({behavior:'smooth',block:'center'});}
          else{window.openProfile?.(liveId);notify(live?'Direct disponible depuis la fiche':'Ce direct est terminé');}
        },180);
        return true;
      }
    }
    if(section==='coules'){
      const target=document.getElementById('coules');
      if(target){deepLinkApplied=true;target.scrollIntoView({behavior:'smooth',block:'start'});return true;}
    }
    return false;
  };
  if(ready())return;
  let attempts=0;
  const timer=setInterval(()=>{attempts++;if(ready()||attempts>80)clearInterval(timer)},250);
}

ensureModal();
installCoulesBridge();
setInterval(installCoulesBridge,600);
document.addEventListener('DOMContentLoaded',()=>{ensureModal();installCoulesBridge();setTimeout(applyDeepLink,250)});
window.addEventListener('load',()=>setTimeout(applyDeepLink,300),{once:true});

window.addEventListener('click',event=>{
  const target=event.target instanceof Element?event.target:null;
  if(!target)return;
  const globalShare=target.closest('#shareBtn');
  if(globalShare){event.preventDefault();event.stopImmediatePropagation();openSite();return;}
  const profileShare=target.closest('.p50-share-fi');
  if(profileShare){event.preventDefault();event.stopImmediatePropagation();openProfile(profileShare.dataset.id);return;}
  const liveShare=target.closest('.p50-share-live');
  if(liveShare){
    event.preventDefault();event.stopImmediatePropagation();
    let directUrl='';
    try{directUrl=decodeURIComponent(liveShare.dataset.live||'')}catch{}
    openLive({
      profileId:liveShare.dataset.liveProfile||liveShare.dataset.id,
      platform:liveShare.dataset.livePlatform||liveShare.dataset.platform||'',
      directUrl
    });
    return;
  }
  if(target.closest('.p50-share-close')||target.id==='p50ShareCenter'){event.preventDefault();close();return;}
  if(target.closest('[data-p50-share-native]')){event.preventDefault();nativeShare();return;}
  if(target.closest('[data-p50-share-whatsapp]')){event.preventDefault();whatsapp();return;}
  if(target.closest('[data-p50-share-copy]')){event.preventDefault();copy();return;}
},true);

window.addEventListener('keydown',event=>{if(event.key==='Escape')close()},true);

window.PASS50_SHARE_CENTER={version:VERSION,openSite,openProfile,openLive,openCoules,shareUrl,payloadFor};
})();
