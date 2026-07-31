(function(){
'use strict';

if(window.__pass50CoulesShareSimpleV1)return;
window.__pass50CoulesShareSimpleV1=true;

function install(){
  try{
    if(typeof voteSharePanel!=='function'||typeof VOTE_SHARE==='undefined'||typeof drawVoteShareCard!=='function')return false;
  }catch{return false;}

  function simpleVoteSharePanel(){
    const card=VOTE_SHARE.card;if(!card)return;
    const audio=Boolean(VOTE_SHARE.audioBlob);
    const recording=Boolean(VOTE_SHARE.recorder&&VOTE_SHARE.recorder.state==='recording');
    const ready=Boolean(VOTE_SHARE.mediaFile);
    const body=document.getElementById('voteShareBody');if(!body)return;

    body.innerHTML=`<div class="vote-share-shell"><div class="vote-share-preview"><canvas id="voteShareCanvas" width="1080" height="1350" aria-label="Aperçu de la carte Les Coulés"></canvas></div><div class="vote-share-panel"><div class="vote-share-facts"><div class="eyebrow">MON VOTE PASS50</div><div class="muted">${new Date(card.voteDate).toLocaleDateString('fr-FR')}</div></div><div class="vote-share-audio"><strong>Audio facultatif · 15 s max</strong><div class="vote-share-actions" style="margin-top:10px">${recording?`<button class="btn danger" id="voteShareStop"><span class="recording-dot"></span>Arrêter · ${VOTE_SHARE.seconds}s</button>`:`<button class="btn" id="voteShareRecord">${audio?'Recommencer':'Ajouter un audio'}</button>`}${audio&&!recording?'<button class="btn danger" id="voteShareDeleteAudio">Supprimer</button>':''}</div>${audio&&!recording?`<audio controls src="${voteShareEscape(VOTE_SHARE.audioUrl)}"></audio><button class="btn primary" id="voteShareConfirmVideo" style="margin-top:10px">Créer la vidéo</button>`:''}</div><div class="vote-share-actions"><button class="btn primary" id="voteShareNative">Partager</button><button class="btn" id="voteShareWhatsapp">WhatsApp</button><button class="btn" id="voteShareCopy">Copier</button></div><div class="vote-share-status" id="voteShareStatus">${ready?'Prêt à partager.':''}</div></div></div>`;
    drawVoteShareCard(document.getElementById('voteShareCanvas'),card);
  }

  async function simpleNativeVoteShare(){
    let file=VOTE_SHARE.mediaFile;
    try{
      if(!file){
        if(VOTE_SHARE.audioBlob){await generateVoteShareVideo();file=VOTE_SHARE.mediaFile;}
        else file=await generateVoteShareImage(false);
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
  window.PASS50_COULES_SHARE_SIMPLE_VERSION='1.0';
  return true;
}

if(!install()){
  const timer=setInterval(()=>{if(install())clearInterval(timer);},100);
  setTimeout(()=>clearInterval(timer),20000);
}
})();
