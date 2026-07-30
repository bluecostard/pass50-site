'use strict';

(function () {
  const SECTION_ID = 'p50TiktokOauthSection';
  const API_TOKEN_KEY = 'pass50_api_token';
  let currentStatus = null;
  let loading = false;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function apiBase() {
    return String(window.PASS50_API?.baseUrl || './api').replace(/\/+$/, '') || './api';
  }

  function token() {
    return String(localStorage.getItem(API_TOKEN_KEY) || '').trim();
  }

  async function apiRequest(path, options = {}) {
    const accessToken = token();
    if (!accessToken) throw new Error('Connecte-toi d’abord à ton compte PASS50.');
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${accessToken}`);
    let body = options.body;
    if (body && typeof body !== 'string') {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(body);
    }
    const response = await fetch(`${apiBase()}/${path}`, {
      method: options.method || 'GET', headers, body, cache: 'no-store', credentials: 'same-origin'
    });
    const raw = await response.text();
    let data = {};
    try { data = raw ? JSON.parse(raw) : {}; } catch (_) {}
    if (!response.ok) throw new Error(data.error || data.message || `Erreur serveur (${response.status}).`);
    return data;
  }

  function notify(message) {
    if (typeof window.toast === 'function') return window.toast(message);
    const node = document.getElementById('toast');
    if (!node) return;
    node.textContent = message;
    node.classList.add('show');
    setTimeout(() => node.classList.remove('show'), 2800);
  }

  function formatNumber(value) {
    if (value == null || Number.isNaN(Number(value))) return '—';
    return new Intl.NumberFormat('fr-FR', { notation: Number(value) >= 10000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(Number(value));
  }

  function safeHttpsUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw);
      return url.protocol === 'https:' ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  function injectStyles() {
    if (document.getElementById('p50TiktokOauthStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50TiktokOauthStyles';
    style.textContent = `
      #${SECTION_ID} .p50-tt-card{padding:16px;border:1px solid #293129;border-radius:17px;background:linear-gradient(145deg,#171b17,#090c09)}
      #${SECTION_ID} .p50-tt-head{display:flex;align-items:center;justify-content:space-between;gap:14px}
      #${SECTION_ID} .p50-tt-main{display:flex;align-items:center;gap:13px;min-width:0}
      #${SECTION_ID} .p50-tt-logo,#${SECTION_ID} .p50-tt-avatar{width:54px;height:54px;flex:0 0 54px;border-radius:15px;display:grid;place-items:center;background:#050505;color:#fff;font-size:24px;font-weight:1000;overflow:hidden;border:1px solid #444}
      #${SECTION_ID} .p50-tt-avatar img{width:100%;height:100%;object-fit:cover}
      #${SECTION_ID} .p50-tt-copy{min-width:0}
      #${SECTION_ID} .p50-tt-title{font-weight:1000;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #${SECTION_ID} .p50-tt-meta{margin-top:4px;color:#9da79b;font-size:12px;line-height:1.45}
      #${SECTION_ID} .p50-tt-actions{display:flex;gap:8px;flex-wrap:wrap}
      #${SECTION_ID} .p50-tt-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:14px}
      #${SECTION_ID} .p50-tt-stat{padding:10px;border-radius:12px;background:#0b0e0b;border:1px solid #232923;text-align:center}
      #${SECTION_ID} .p50-tt-stat b{display:block;font-size:16px}.p50-tt-stat span{font-size:10px;color:#9da79b}
      #${SECTION_ID} .p50-tt-videos{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:14px}
      #${SECTION_ID} .p50-tt-video{position:relative;aspect-ratio:9/14;border-radius:12px;overflow:hidden;background:#111;border:1px solid #252b25}
      #${SECTION_ID} .p50-tt-video img{width:100%;height:100%;object-fit:cover}
      #${SECTION_ID} .p50-tt-video span{position:absolute;left:5px;right:5px;bottom:5px;padding:4px;border-radius:7px;background:rgba(0,0,0,.7);font-size:9px;color:#fff}
      #${SECTION_ID} .p50-tt-loading{padding:16px;border:1px dashed #536052;border-radius:15px;color:#cbd3c8}
      @media(max-width:720px){#${SECTION_ID} .p50-tt-head{align-items:stretch;flex-direction:column}#${SECTION_ID} .p50-tt-actions .btn{flex:1}#${SECTION_ID} .p50-tt-stats{grid-template-columns:repeat(2,1fr)}#${SECTION_ID} .p50-tt-videos{grid-template-columns:repeat(3,1fr)}}
    `;
    document.head.appendChild(style);
  }

  function ensureSection() {
    const userBody = document.getElementById('userBody');
    if (!userBody || !userBody.innerHTML.trim()) return null;
    const grid = userBody.querySelector('.user-grid');
    if (!grid) return null;
    let section = document.getElementById(SECTION_ID);
    if (!section) {
      section = document.createElement('section');
      section.id = SECTION_ID;
      section.className = 'user-section full';
      section.innerHTML = '<div class="user-title"><span>♪ TikTok</span><span class="muted">Lecture seule</span></div><div class="p50-tt-loading">Vérification de la connexion TikTok…</div>';
      const account = [...grid.querySelectorAll('.user-section')].find(node => node.textContent.includes('Mon compte'));
      if (account) grid.insertBefore(section, account);
      else grid.appendChild(section);
    }
    return section;
  }

  function render() {
    const section = ensureSection();
    if (!section) return;
    if (!token()) { section.remove(); return; }
    if (loading) {
      section.innerHTML = '<div class="user-title"><span>♪ TikTok</span><span class="muted">Lecture seule</span></div><div class="p50-tt-loading">Vérification de la connexion TikTok…</div>';
      return;
    }
    if (!currentStatus?.connected) {
      section.innerHTML = `
        <div class="user-title"><span>♪ TikTok</span><span class="muted">Lecture seule · Bac à sable</span></div>
        <div class="p50-tt-card"><div class="p50-tt-head">
          <div class="p50-tt-main"><div class="p50-tt-logo">♪</div><div class="p50-tt-copy">
            <div class="p50-tt-title">Connecter mon compte TikTok</div>
            <div class="p50-tt-meta">Autorise PASS50 à afficher ton profil, tes statistiques et tes vidéos publiques. Aucune publication ni modification de ton compte n’est possible.</div>
          </div></div>
          <div class="p50-tt-actions"><button class="btn primary" type="button" data-p50-tiktok-connect>Connecter TikTok</button></div>
        </div></div>`;
      return;
    }
    const p = currentStatus.profile || {};
    const videos = Array.isArray(currentStatus.videos) ? currentStatus.videos : [];
    const avatarUrl = safeHttpsUrl(p.avatarUrl);
    const profileUrl = safeHttpsUrl(p.profileUrl);
    const avatar = avatarUrl
      ? `<div class="p50-tt-avatar"><img src="${escapeHtml(avatarUrl)}" alt="" referrerpolicy="no-referrer"></div>`
      : '<div class="p50-tt-logo">♪</div>';
    const username = p.username ? `@${p.username}` : 'Compte autorisé';
    const videoHtml = videos.slice(0, 10).map(video => {
      const url = safeHttpsUrl(video.shareUrl || video.embedLink);
      if (!url) return '';
      const coverUrl = safeHttpsUrl(video.coverImageUrl);
      const cover = coverUrl ? `<img src="${escapeHtml(coverUrl)}" alt="" referrerpolicy="no-referrer">` : '';
      return `<a class="p50-tt-video" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${cover}<span>${formatNumber(video.viewCount)} vues</span></a>`;
    }).filter(Boolean).join('');
    section.innerHTML = `
      <div class="user-title"><span>♪ TikTok</span><span class="muted">Lecture seule · ${escapeHtml(currentStatus.environment || 'sandbox')}</span></div>
      <div class="p50-tt-card">
        <div class="p50-tt-head">
          <div class="p50-tt-main">${avatar}<div class="p50-tt-copy">
            <div class="p50-tt-title">${escapeHtml(p.displayName || 'Compte TikTok')}${p.verified ? ' ✓' : ''}</div>
            <div class="p50-tt-meta">${escapeHtml(username)}${p.bio ? ` · ${escapeHtml(p.bio)}` : ''}</div>
          </div></div>
          <div class="p50-tt-actions">
            ${profileUrl ? `<a class="btn" href="${escapeHtml(profileUrl)}" target="_blank" rel="noopener noreferrer">Voir le profil</a>` : ''}
            ${currentStatus.requiresReauthorization ? '<button class="btn primary" type="button" data-p50-tiktok-connect>Reconnecter</button>' : ''}
            <button class="btn danger" type="button" data-p50-tiktok-disconnect>Déconnecter</button>
          </div>
        </div>
        <div class="p50-tt-stats">
          <div class="p50-tt-stat"><b>${formatNumber(p.followerCount)}</b><span>ABONNÉS</span></div>
          <div class="p50-tt-stat"><b>${formatNumber(p.followingCount)}</b><span>ABONNEMENTS</span></div>
          <div class="p50-tt-stat"><b>${formatNumber(p.likesCount)}</b><span>J’AIME REÇUS</span></div>
          <div class="p50-tt-stat"><b>${formatNumber(p.videoCount)}</b><span>VIDÉOS</span></div>
        </div>
        ${videoHtml ? `<div class="p50-tt-videos">${videoHtml}</div>` : '<div class="p50-tt-meta" style="margin-top:14px">Aucune vidéo publique retournée par TikTok.</div>'}
      </div>`;
  }

  async function refreshStatus(showError = false) {
    if (!token()) return;
    loading = true; render();
    try { currentStatus = await apiRequest('tiktok-oauth-status.php'); }
    catch (error) { currentStatus = { connected: false }; if (showError) notify(error.message || 'Connexion TikTok indisponible.'); }
    finally { loading = false; render(); }
  }

  async function connectTikTok() {
    try {
      const data = await apiRequest('tiktok-oauth-start.php', { method: 'POST', body: {} });
      if (!data.authorizationUrl) throw new Error('Adresse d’autorisation TikTok absente.');
      window.location.assign(data.authorizationUrl);
    } catch (error) {
      notify(error.message || 'Impossible de démarrer la connexion TikTok.');
    }
  }

  async function disconnectTikTok() {
    if (!window.confirm('Déconnecter ce compte TikTok de PASS50 ?')) return;
    try {
      const data = await apiRequest('tiktok-oauth-disconnect.php', { method: 'POST', body: {} });
      currentStatus = { connected: false }; render();
      notify(data.warning || 'Compte TikTok déconnecté.');
    } catch (error) {
      notify(error.message || 'Déconnexion TikTok impossible.');
    }
  }

  function consumeReturnQuery() {
    const url = new URL(window.location.href);
    const status = url.searchParams.get('tiktok_oauth');
    if (!status) return;
    url.searchParams.delete('tiktok_oauth');
    url.searchParams.delete('code');
    history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
    setTimeout(() => {
      document.getElementById('accountBtn')?.click();
      if (status === 'connected') notify('Compte TikTok connecté avec succès.');
      else if (status === 'cancelled') notify('Connexion TikTok annulée.');
      else notify('La connexion TikTok n’a pas pu être finalisée.');
      refreshStatus(status !== 'cancelled');
    }, 450);
  }

  function install() {
    injectStyles();
    document.addEventListener('click', event => {
      const connect = event.target.closest('[data-p50-tiktok-connect]');
      if (connect) { event.preventDefault(); connectTikTok(); return; }
      const disconnect = event.target.closest('[data-p50-tiktok-disconnect]');
      if (disconnect) { event.preventDefault(); disconnectTikTok(); return; }
      if (event.target.closest('#accountBtn')) setTimeout(() => refreshStatus(false), 100);
    });
    const userBody = document.getElementById('userBody');
    if (userBody) {
      new MutationObserver(() => {
        if (userBody.innerHTML.trim() && token()) {
          ensureSection();
          if (!loading && currentStatus === null) refreshStatus(false);
        }
      }).observe(userBody, { childList: true, subtree: false });
    }
    window.addEventListener('storage', event => {
      if (event.key === API_TOKEN_KEY) {
        currentStatus = null;
        if (event.newValue) refreshStatus(false);
        else document.getElementById(SECTION_ID)?.remove();
      }
    });
    consumeReturnQuery();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
  else install();
}());
