(function(){
'use strict';

if(window.__pass50CoulesShareSimpleV1)return;
window.__pass50CoulesShareSimpleV1=true;

function install(){
  try{
    if(typeof voteSharePanel!=='function'||typeof VOTE_SHARE==='undefined'||typeof drawVoteShareCard!=='function')return false;
  }catch{return false;}

  function paintPreview(card){
    const canvas=document.getElementById('voteShareCanvas');
    if(!canvas||!card)return;
    canvas.width=1080;
    canvas.height=1920;
    drawVoteShareCard(canvas,card,{vertical:true});
  }

  function simpleVoteSharePanel(){
    const card=VOTE_SHARE.card;if(!card)return;
    const audio=Boolean(VOTE_SHARE.audioBlob);
    const recording=Boolean(VOTE_SHARE.recorder&&VOTE_SHARE.recorder.state==='recording');
    const ready=Boolean(VOTE_SHARE.mediaFile);
    const videoReady=String(VOTE_SHARE.mediaFile?.type||'').startsWith('video/');
    const body=document.getElementById('voteShareBody');if(!body)return;
    const shortLink=String(card.campaignUrl||card.shortUrl||'https://pass50.store').replace(/^https?:\/\//,'');

    body.innerHTML=`<div class="vote-share-shell"><div class="vote-share-preview"><canvas id="voteShareCanvas" width="1080" height="1920" aria-label="Aperçu de la carte Les Coulés"></canvas></div><div class="vote-share-panel"><div class="vote-share-facts"><div class="eyebrow">MON VOTE PASS50</div><div class="muted">${new Date(card.voteDate).toLocaleDateString('fr-FR')} · ${voteShareEscape(shortLink)}</div></div><div class="vote-share-audio"><strong>Audio facultatif · 15 s max</strong><div class="share-note" style="margin-top:6px">Sur WhatsApp, l’audio est envoyé dans une vidéo de la carte pour pouvoir le lire.</div><div class="vote-share-actions" style="margin-top:10px">${recording?`<button class="btn danger" id="voteShareStop"><span class="recording-dot"></span>Arrêter · ${VOTE_SHARE.seconds}s</button>`:`<button class="btn" id="voteShareRecord">${audio?'Recommencer':'Ajouter un audio'}</button>`}${audio&&!recording?'<button class="btn danger" id="voteShareDeleteAudio">Supprimer</button>':''}</div>${audio&&!recording?`<audio controls src="${voteShareEscape(VOTE_SHARE.audioUrl)}"></audio>${videoReady?'':'<button class="btn primary" id="voteShareConfirmVideo" style="margin-top:10px">Créer la vidéo WhatsApp</button>'}`:''}</div><div class="vote-share-actions"><button class="btn primary" id="voteShareNative">Partager</button><button class="btn" id="voteShareWhatsapp">WhatsApp</button><button class="btn" id="voteShareCopy">Copier</button></div><div class="vote-share-status" id="voteShareStatus">${videoReady?'Vidéo prête : l’audio sera lisible sur WhatsApp.':(ready?'Carte prête à partager.':'')}</div></div></div>`;
    paintPreview(card);
  }

  async function simpleNativeVoteShare(){
    let file=VOTE_SHARE.mediaFile;
    try{
      if(VOTE_SHARE.audioBlob&&!String(file?.type||'').startsWith('video/')){
        await generateVoteShareVideo();
        file=VOTE_SHARE.mediaFile;
      }else if(!file){
        file=await generateVoteShareImage(false);
      }
      if(!file)return;
      const shareText=voteShareMessage(VOTE_SHARE.card);
      voteShareAnalytics('native_share_triggered');
      if(navigator.share&&navigator.canShare?.({files:[file]})){
        try{await navigator.share({title:'Mon vote PASS50',text:shareText,files:[file]});return;}
        catch(error){if(error?.name==='AbortError')return;}
      }
      downloadVoteShare();
      if(typeof toast==='function')toast('Carte téléchargée');
    }catch{
      if(typeof toast==='function')toast('Partage indisponible');
    }
  }

  try{voteSharePanel=simpleVoteSharePanel;}catch{}
  try{nativeVoteShare=simpleNativeVoteShare;}catch{}
  window.voteSharePanel=simpleVoteSharePanel;
  window.nativeVoteShare=simpleNativeVoteShare;
  window.PASS50_COULES_SHARE_SIMPLE_VERSION='1.1';
  return true;
}

if(!install()){
  const timer=setInterval(()=>{if(install())clearInterval(timer);},100);
  setTimeout(()=>clearInterval(timer),20000);
}
})();
