(function(){
'use strict';

if(window.PASS50_SHARE_CENTER)return;

const VERSION='1.2.0';
const PAPER='#eef1ec';
const INK='#0b0f0b';
const MUTED='#5c665c';
const LIME='#b7ff00';
const THEMES={
  site:{accent:'#0e7c7b',soft:'rgba(14,124,123,.10)',label:'Le buzz',kicker:'Classement',cta:'Découvrir'},
  profile:{accent:'#3d5a1f',soft:'rgba(61,90,31,.10)',label:'Profil',kicker:'Fiche',cta:'Voir la fiche'},
  live:{accent:'#b42318',soft:'rgba(180,35,24,.10)',label:'Live',kicker:'En direct',cta:'Regarder'},
  coules:{accent:'#b45309',soft:'rgba(180,83,9,.10)',label:'Duel',kicker:'Les Coulés',cta:'Voir le duel'},
  'coules-audio':{accent:'#1d4e89',soft:'rgba(29,78,137,.10)',label:'Audio',kicker:'Les Coulés',cta:'Écouter'}
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
  let title='Qui fait le buzz ?';
  let subtitle='Classement des influenceurs ivoiriens.';
  if(type==='profile'){
    title=name;
    subtitle=[handle,'Fiche influenceur'].filter(Boolean).join(' · ');
  }else if(type==='live'){
    title=name;
    subtitle=`En direct${data.platform?` sur ${data.platform}`:''}`;
  }else if(type==='coules'||type==='coules-audio'){
    title=data.title||name||'Mon vote';
    subtitle=type==='coules-audio'?'Vote avec commentaire audio':'Choix dans le duel';
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
  .p50-share-box{--share-accent:#0e7c7b;--share-soft:rgba(14,124,123,.10);width:min(440px,100%);padding:12px;border:1px solid #d5dbd2;border-radius:18px;background:#eef1ec;box-shadow:0 24px 80px rgba(0,0,0,.28)}
  .p50-share-close{display:grid;place-items:center;margin-left:auto;width:42px;height:42px;border:1px solid #cfd6cb;border-radius:50%;background:#fff;color:#0b0f0b;font-size:23px}
  .p50-share-card{position:relative;min-height:474px;padding:28px;overflow:hidden;border-radius:14px;background:#eef1ec;display:flex;flex-direction:column;border-top:8px solid var(--share-accent)}
  .p50-share-card::before{display:none}
  .p50-share-brand{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:1000;letter-spacing:-.8px;color:#0b0f0b}
  .p50-share-brand::before{content:"";width:14px;height:14px;background:#b7ff00;flex:0 0 auto}
  .p50-share-brand span{color:inherit}
  .p50-share-kicker{margin-top:34px;color:var(--share-accent);font-size:12px;font-weight:900;letter-spacing:2px;text-transform:uppercase}
  .p50-share-pill{align-self:flex-start;margin-top:8px;padding:0;border:0;border-radius:0;color:#5c665c;background:transparent;font-size:13px;font-weight:700;letter-spacing:.2px}
  .p50-share-person{display:grid;grid-template-columns:88px minmax(0,1fr);gap:16px;align-items:center;margin-top:22px}
  .p50-share-avatar{width:88px;height:88px;border:2px solid #0b0f0b;border-radius:12px;display:grid;place-items:center;overflow:hidden;background:#dde3d8;color:#0b0f0b;font-size:28px;font-weight:1000}
  .p50-share-avatar img{width:100%;height:100%;object-fit:cover}
  .p50-share-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-1.2px;color:#0b0f0b;overflow-wrap:anywhere}
  .p50-share-subtitle{margin-top:7px;color:#5c665c;font-weight:700;line-height:1.35}
  .p50-share-rule{height:1px;margin:24px 0;background:#cfd6cb}
  .p50-share-cta{margin-top:auto;padding:15px;border-radius:10px;background:#b7ff00;color:#0b0f0b;text-align:center;font-weight:1000;letter-spacing:.2px}
  .p50-share-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:11px}
  .p50-share-actions button{min-height:52px;border:1px solid #cfd6cb;border-radius:12px;background:#fff;color:#0b0f0b;font-weight:950}
  .p50-share-actions button:first-child{background:#0b0f0b;border-color:#0b0f0b;color:#eef1ec}
  .p50-share-code{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:9px 11px;border:1px solid #cfd6cb;border-radius:10px;color:#0b0f0b;background:#fff;font-size:11px;font-weight:900;letter-spacing:.4px}
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
      <div class="p50-share-brand">PASS50</div>
      <div class="p50-share-kicker">CLASSEMENT</div>
      <div class="p50-share-pill"></div>
      <div class="p50-share-person">
        <div class="p50-share-avatar">P50</div>
        <div><h2 class="p50-share-title">Qui fait le buzz ?</h2><div class="p50-share-subtitle"></div></div>
      </div>
      <div class="p50-share-rule"></div>
      <div class="p50-share-cta">Découvrir</div>
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
  modal.querySelector('.p50-share-pill').textContent=payload.theme.label;
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
    const accent=payload.theme.accent;
    ctx.fillStyle=PAPER;ctx.fillRect(0,0,1080,1350);
    ctx.fillStyle=accent;ctx.fillRect(0,0,1080,18);
    ctx.fillStyle=LIME;ctx.fillRect(64,56,22,22);
    ctx.fillStyle=INK;ctx.font='1000 42px Arial';ctx.fillText('PASS50',100,76);
    ctx.fillStyle=accent;ctx.font='800 22px Arial';ctx.fillText(String(payload.theme.kicker||'').toUpperCase(),64,140);
    ctx.fillStyle=INK;ctx.font='1000 68px Arial';
    const titleLines=wrapLines(ctx,payload.title,920,3);
    titleLines.forEach((line,index)=>ctx.fillText(line,64,280+index*78));
    const subtitleY=280+titleLines.length*78+24;
    ctx.fillStyle=MUTED;ctx.font='600 30px Arial';
    wrapLines(ctx,payload.subtitle,900,3).forEach((line,index)=>ctx.fillText(line,64,subtitleY+index*42));
    ctx.fillStyle='#d5dbd2';ctx.fillRect(64,1080,952,2);
    ctx.fillStyle=LIME;ctx.beginPath();
    if(ctx.roundRect)ctx.roundRect(64,1130,952,100,12);else ctx.rect(64,1130,952,100);
    ctx.fill();
    ctx.fillStyle=INK;ctx.textAlign='center';ctx.font='1000 34px Arial';ctx.fillText(payload.theme.cta,540,1194);ctx.textAlign='left';
    ctx.fillStyle=MUTED;ctx.font='600 24px Arial';ctx.fillText('pass50.store',64,1295);
    canvas.toBlob(blob=>resolve(blob?new File([blob],`pass50-${payload.type}.png`,{type:'image/png'}):null),'image/png',.94);
  });
}

function message(payload){
  if(payload.text)return `${payload.text}\n${payload.url}`;
  if(payload.type==='profile')return `Découvre la fiche de ${payload.title}.\n${payload.url}`;
  if(payload.type==='live')return `🔴 ${payload.title} est en direct${payload.platform?` sur ${payload.platform}`:''}.\n${payload.url}`;
  if(payload.type==='coules-audio')return `🎙 Mon vote commenté dans Les Coulés.\n${payload.url}`;
  if(payload.type==='coules')return `⚔ Mon vote dans Les Coulés.\n${payload.url}`;
  return `Qui fait le buzz maintenant ?\n${payload.url}`;
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
  const url=`https://api.whatsapp.com/send?text=${encodeURIComponent(message(current))}`;
  const mobile=/iPhone|iPad|iPod|Android/i.test(navigator.userAgent||'');
  if(mobile){location.href=url;return;}
  const a=document.createElement('a');a.href=url;a.target='_blank';a.rel='noopener noreferrer';document.body.appendChild(a);a.click();a.remove();
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
        ctx.save();ctx.fillStyle=accent;ctx.fillRect(0,0,canvas.width,10);ctx.restore();
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
