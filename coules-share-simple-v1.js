(function(){
'use strict';

if(window.__pass50CoulesShareSimpleV1)return;
window.__pass50CoulesShareSimpleV1=true;

function install(){
  try{
    if(typeof voteSharePanel!=='function'||typeof VOTE_SHARE==='undefined'||typeof drawVoteShareCard!=='function')return false;
  }catch{return false;}

  // Garder le cœur index.html (évite la récursion nativeVoteShare → nativeVoteShare)
  if(typeof window.__pass50NativeVoteShareCore!=='function' && typeof nativeVoteShare==='function'){
    window.__pass50NativeVoteShareCore=nativeVoteShare;
  }
  if(typeof window.__pass50ShareVoteWhatsappCore!=='function' && typeof shareVoteWhatsapp==='function'){
    window.__pass50ShareVoteWhatsappCore=shareVoteWhatsapp;
  }

  function paintPreview(card){
    const canvas=document.getElementById('voteShareCanvas');
    if(!canvas||!card)return;
    canvas.width=1080;
    canvas.height=1350;
    drawVoteShareCard(canvas,card,{vertical:true});
  }

  function mediaHint(){
    const type=String(VOTE_SHARE.mediaFile?.type||'');
    if(type.startsWith('video/')&&typeof isPlayableShareVideo==='function'&&isPlayableShareVideo(VOTE_SHARE.mediaFile)){
      return 'Vidéo prête : 1 seul message WhatsApp avec son.';
    }
    if(type.startsWith('audio/')||VOTE_SHARE.audioBlob){
      return VOTE_SHARE.audioBlob&&!type.startsWith('video/')
        ? 'Audio prêt. WhatsApp / Partager enverront 1 seul fichier (vidéo si possible).'
        : 'Média prêt.';
    }
    if(VOTE_SHARE.mediaFile)return 'Carte prête à partager.';
    return '';
  }

  function simpleVoteSharePanel(){
    const card=VOTE_SHARE.card;if(!card)return;
    const audio=Boolean(VOTE_SHARE.audioBlob);
    const recording=Boolean(VOTE_SHARE.recorder&&VOTE_SHARE.recorder.state==='recording');
    const videoReady=String(VOTE_SHARE.mediaFile?.type||'').startsWith('video/')
      && (typeof isPlayableShareVideo!=='function'||isPlayableShareVideo(VOTE_SHARE.mediaFile));
    const body=document.getElementById('voteShareBody');if(!body)return;
    const shortLink=String(card.campaignUrl||card.shortUrl||'https://pass50.store').replace(/^https?:\/\//,'');

    body.innerHTML=`<div class="vote-share-shell"><div class="vote-share-preview"><canvas id="voteShareCanvas" width="1080" height="1350" aria-label="Aperçu de la carte Les Coulés"></canvas></div><div class="vote-share-panel"><div class="vote-share-facts"><div class="eyebrow">MON VOTE PASS50</div><div class="muted">${new Date(card.voteDate).toLocaleDateString('fr-FR')} · ${voteShareEscape(shortLink)}</div></div><div class="vote-share-audio"><strong>Audio facultatif · 15 s max</strong><div class="share-note" style="margin-top:6px">WhatsApp et Partager envoient <strong>un seul</strong> fichier (vidéo carte+son, sinon audio). Jamais image + m4a, jamais lien séparé.</div><div class="vote-share-actions" style="margin-top:10px">${recording?`<button class="btn danger" id="voteShareStop"><span class="recording-dot"></span>Arrêter · ${VOTE_SHARE.seconds}s</button>`:`<button class="btn" id="voteShareRecord">${audio?'Recommencer':'Ajouter un audio'}</button>`}${audio&&!recording?'<button class="btn danger" id="voteShareDeleteAudio">Supprimer</button>':''}</div>${audio&&!recording?`<audio controls src="${voteShareEscape(VOTE_SHARE.audioUrl)}"></audio>${videoReady?'':'<button class="btn primary" id="voteShareConfirmVideo" style="margin-top:10px">Préparer la vidéo</button>'}`:''}</div><div class="vote-share-actions"><button class="btn primary" id="voteShareNative">Partager</button><button class="btn" id="voteShareWhatsapp">WhatsApp</button><button class="btn" id="voteShareCopy">Copier</button></div><div class="vote-share-status" id="voteShareStatus">${mediaHint()}</div></div></div>`;
    paintPreview(card);
  }

  async function simpleNativeVoteShare(){
    const core=window.__pass50NativeVoteShareCore;
    if(typeof core==='function')return core.apply(this,arguments);
    if(typeof shareVoteMediaFile==='function'&&typeof prepareVoteShareFile==='function'){
      let file=null;
      try{file=await prepareVoteShareFile({preferVideo:true});}catch(_){}
      if(!file){if(typeof toast==='function')toast('Partage indisponible');return;}
      voteShareAnalytics('native_share_triggered');
      return shareVoteMediaFile(file,{whatsapp:false});
    }
    if(typeof toast==='function')toast('Partage indisponible');
  }

  async function simpleShareVoteWhatsapp(){
    const core=window.__pass50ShareVoteWhatsappCore;
    if(typeof core==='function')return core.apply(this,arguments);
    if(typeof toast==='function')toast('WhatsApp indisponible');
  }

  try{voteSharePanel=simpleVoteSharePanel;}catch{}
  try{nativeVoteShare=simpleNativeVoteShare;}catch{}
  try{shareVoteWhatsapp=simpleShareVoteWhatsapp;}catch{}
  window.voteSharePanel=simpleVoteSharePanel;
  window.nativeVoteShare=simpleNativeVoteShare;
  window.shareVoteWhatsapp=simpleShareVoteWhatsapp;
  window.PASS50_COULES_SHARE_SIMPLE_VERSION='1.3';
  return true;
}

if(!install()){
  const timer=setInterval(()=>{if(install())clearInterval(timer);},100);
  setTimeout(()=>clearInterval(timer),20000);
}
})();
