'use strict';

(() => {
  if (window.PASS50_DUEL_AUDIO_SHARE_INTERCEPT) return;
  const CONTRACT = 'PASS50-DUEL-AUDIO-SHARE-INTERCEPT-V1.0';

  function audioTokenFromModal() {
    const audio = document.querySelector('#p50ContextSharePreviewV2 audio');
    const src = String(audio?.currentSrc || audio?.src || '');
    if (!src) return '';
    try {
      const url = new URL(src, location.href);
      const token = decodeURIComponent(url.pathname.split('/').filter(Boolean).pop() || '');
      return /^[A-Za-z0-9._-]{12,180}$/.test(token) ? token : '';
    } catch (_) { return ''; }
  }

  function shortUrl(token) {
    const url = new URL('./a.php', location.href);
    url.searchParams.set('k', token.slice(0, 12).toLowerCase());
    return url.href;
  }

  function shareCopy() {
    const preview = document.getElementById('p50ContextSharePreviewV2');
    const duel = String(preview?.querySelector('h2')?.textContent || 'Les Coulés').replace(/\s+/g, ' ').trim();
    const author = String(preview?.querySelector('.p50-context-feed-copy-v2 strong')?.textContent || 'Un membre PASS50').replace(/\s+/g, ' ').trim();
    return `🎙 ${author} commente ${duel}.`;
  }

  function openWhatsApp(message) {
    const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(message)}`;
    location.href = url;
  }

  async function shareShort(token, whatsappOnly = false) {
    const url = shortUrl(token);
    const text = shareCopy();
    if (whatsappOnly) {
      openWhatsApp(`${text}\n${url}`);
      return;
    }
    try {
      if (navigator.share) {
        await navigator.share({ title: 'PASS50 · Les Coulés', text, url });
        return;
      }
    } catch (error) {
      if (error?.name === 'AbortError') return;
    }
    openWhatsApp(`${text}\n${url}`);
  }

  window.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const native = target.closest('[data-p50-v2-native]');
    const whatsapp = target.closest('[data-p50-v2-whatsapp]');
    if (!native && !whatsapp) return;
    const token = audioTokenFromModal();
    if (!token) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    shareShort(token, Boolean(whatsapp));
  }, true);

  window.PASS50_DUEL_AUDIO_SHARE_INTERCEPT = Object.freeze({ contract: CONTRACT, mode: 'one-short-link-no-files' });
})();
