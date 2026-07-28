'use strict';

(function () {
  const SECTION_ID = 'p50YoutubeOauthSection';
  const API_TOKEN_KEY = 'pass50_api_token';
  let currentStatus = null;
  let loading = false;
  let pollingTimer = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function apiBase() {
    const configured = String(window.PASS50_API?.baseUrl || './api').replace(/\/+$/, '');
    return configured || './api';
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
    if (body && !(body instanceof FormData) && typeof body !== 'string') {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(body);
    }

    const response = await fetch(`${apiBase()}/${path}`, {
      method: options.method || 'GET',
      headers,
      body,
      cache: 'no-store',
      credentials: 'same-origin'
    });

    const raw = await response.text();
    let data = {};
    try { data = raw ? JSON.parse(raw) : {}; } catch (_) {}

    if (!response.ok) {
      const message = data.error || data.message || `Erreur serveur (${response.status}).`;
      throw new Error(message);
    }
    return data;
  }

  function notify(message) {
    if (typeof window.toast === 'function') {
      window.toast(message);
      return;
    }
    const toastNode = document.getElementById('toast');
    if (!toastNode) return;
    toastNode.textContent = message;
    toastNode.classList.add('show');
    setTimeout(() => toastNode.classList.remove('show'), 2600);
  }

  function injectStyles() {
    if (document.getElementById('p50YoutubeOauthStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50YoutubeOauthStyles';
    style.textContent = `
      #${SECTION_ID} .p50-yt-card{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border:1px solid #293129;border-radius:17px;background:linear-gradient(145deg,#171b17,#090c09)}
      #${SECTION_ID} .p50-yt-main{display:flex;align-items:center;gap:13px;min-width:0}
      #${SECTION_ID} .p50-yt-logo,#${SECTION_ID} .p50-yt-avatar{width:54px;height:54px;flex:0 0 54px;border-radius:15px;display:grid;place-items:center;background:#ff0033;color:#fff;font-size:24px;font-weight:1000;overflow:hidden}
      #${SECTION_ID} .p50-yt-avatar img{width:100%;height:100%;object-fit:cover}
      #${SECTION_ID} .p50-yt-copy{min-width:0}
      #${SECTION_ID} .p50-yt-title{font-weight:1000;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #${SECTION_ID} .p50-yt-meta{margin-top:4px;color:#9da79b;font-size:12px;line-height:1.45}
      #${SECTION_ID} .p50-yt-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
      #${SECTION_ID} .p50-yt-status{display:inline-flex;align-items:center;border:1px solid #293129;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:950;margin-left:8px}
      #${SECTION_ID} .p50-yt-status.ok{border-color:#5f8500;color:#b7ff00;background:rgba(183,255,0,.08)}
      #${SECTION_ID} .p50-yt-status.warn{border-color:#8a5b19;color:#ffc065;background:rgba(255,157,29,.08)}
      #${SECTION_ID} .p50-yt-loading{padding:16px;border:1px dashed #536052;border-radius:15px;color:#cbd3c8}
      @media(max-width:720px){#${SECTION_ID} .p50-yt-card{align-items:stretch;flex-direction:column}#${SECTION_ID} .p50-yt-actions{justify-content:stretch}#${SECTION_ID} .p50-yt-actions .btn{flex:1}}
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
      section.innerHTML = '<div class="user-title"><span>▶ YouTube Analytics</span><span class="muted">Lecture seule</span></div><div class="p50-yt-loading">Vérification de la connexion YouTube…</div>';
      const accountSection = [...grid.querySelectorAll('.user-section')].find((node) => node.textContent.includes('Mon compte'));
      if (accountSection) grid.insertBefore(section, accountSection);
      else grid.appendChild(section);
    }
    return section;
  }

  function render() {
    const section = ensureSection();
    if (!section) return;

    if (!token()) {
      section.remove();
      return;
    }

    if (loading) {
      section.innerHTML = '<div class="user-title"><span>▶ YouTube Analytics</span><span class="muted">Lecture seule</span></div><div class="p50-yt-loading">Vérification de la connexion YouTube…</div>';
      return;
    }

    if (!currentStatus?.connected) {
      section.innerHTML = `
        <div class="user-title"><span>▶ YouTube Analytics</span><span class="muted">Lecture seule</span></div>
        <div class="p50-yt-card">
          <div class="p50-yt-main">
            <div class="p50-yt-logo">▶</div>
            <div class="p50-yt-copy">
              <div class="p50-yt-title">Connecter ma chaîne YouTube</div>
              <div class="p50-yt-meta">Autorise PASS50 à lire les données de ta chaîne et ses statistiques Analytics. Aucune publication ni modification n’est possible.</div>
            </div>
          </div>
          <div class="p50-yt-actions"><button class="btn primary" type="button" data-p50-youtube-connect>Connecter ma chaîne</button></div>
        </div>`;
      return;
    }

    const channel = currentStatus.channel || {};
    const requiresReauthorization = Boolean(currentStatus.requiresReauthorization);
    const badgeClass = requiresReauthorization ? 'warn' : 'ok';
    const badgeText = requiresReauthorization ? 'À reconnecter' : 'Connectée';
    const thumbnail = channel.thumbnailUrl
      ? `<div class="p50-yt-avatar"><img src="${escapeHtml(channel.thumbnailUrl)}" alt="" referrerpolicy="no-referrer"></div>`
      : '<div class="p50-yt-logo">▶</div>';
    const subtitle = [channel.customUrl, channel.id ? `ID ${channel.id}` : ''].filter(Boolean).join(' · ');

    section.innerHTML = `
      <div class="user-title"><span>▶ YouTube Analytics</span><span class="muted">Lecture seule</span></div>
      <div class="p50-yt-card">
        <div class="p50-yt-main">
          ${thumbnail}
          <div class="p50-yt-copy">
            <div class="p50-yt-title">${escapeHtml(channel.title || 'Chaîne YouTube')}<span class="p50-yt-status ${badgeClass}">${badgeText}</span></div>
            <div class="p50-yt-meta">${escapeHtml(subtitle || 'Chaîne autorisée par Google')}${currentStatus.canRefresh ? ' · Actualisation automatique active' : ''}</div>
          </div>
        </div>
        <div class="p50-yt-actions">
          ${requiresReauthorization ? '<button class="btn primary" type="button" data-p50-youtube-connect>Reconnecter</button>' : ''}
          <button class="btn danger" type="button" data-p50-youtube-disconnect>Déconnecter</button>
        </div>
      </div>`;
  }

  async function refreshStatus(showError = false) {
    if (!token()) return;
    loading = true;
    render();
    try {
      currentStatus = await apiRequest('youtube-oauth-status.php');
    } catch (error) {
      currentStatus = { connected: false };
      if (showError) notify(error.message || 'Connexion YouTube indisponible.');
    } finally {
      loading = false;
      render();
    }
  }

  async function connectYouTube() {
    const popup = window.open('', 'pass50_youtube_oauth', 'popup=yes,width=560,height=760,resizable=yes,scrollbars=yes');
    if (popup) {
      popup.document.write('<!doctype html><html lang="fr"><meta charset="utf-8"><title>PASS50 · YouTube</title><body style="background:#050705;color:#fff;font-family:Arial;padding:30px"><h2>PASS50</h2><p>Préparation de la connexion YouTube…</p></body></html>');
    }

    try {
      const data = await apiRequest('youtube-oauth-start.php', { method: 'POST', body: {} });
      if (!data.authorizationUrl) throw new Error('Adresse d’autorisation Google absente.');
      if (popup) popup.location.replace(data.authorizationUrl);
      else window.location.assign(data.authorizationUrl);
      startPolling();
    } catch (error) {
      if (popup && !popup.closed) popup.close();
      notify(error.message || 'Impossible de démarrer la connexion YouTube.');
    }
  }

  async function disconnectYouTube() {
    if (!window.confirm('Déconnecter cette chaîne YouTube de PASS50 ?')) return;
    try {
      await apiRequest('youtube-oauth-disconnect.php', { method: 'POST', body: {} });
      currentStatus = { connected: false };
      render();
      notify('Chaîne YouTube déconnectée.');
    } catch (error) {
      notify(error.message || 'Déconnexion YouTube impossible.');
    }
  }

  function startPolling() {
    clearInterval(pollingTimer);
    let attempts = 0;
    pollingTimer = setInterval(async () => {
      attempts += 1;
      await refreshStatus(false);
      if (currentStatus?.connected || attempts >= 45) clearInterval(pollingTimer);
    }, 2000);
  }

  function handleOAuthResult(status) {
    clearInterval(pollingTimer);
    if (status === 'connected') notify('Chaîne YouTube connectée avec succès.');
    else if (status === 'cancelled') notify('Connexion YouTube annulée.');
    else notify('La connexion YouTube n’a pas pu être finalisée.');
    setTimeout(() => refreshStatus(status !== 'cancelled'), 250);
  }

  function consumeReturnQuery() {
    const url = new URL(window.location.href);
    const status = url.searchParams.get('youtube_oauth');
    if (!status) return;
    url.searchParams.delete('youtube_oauth');
    url.searchParams.delete('code');
    history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
    setTimeout(() => {
      document.getElementById('accountBtn')?.click();
      handleOAuthResult(status);
    }, 500);
  }

  function install() {
    injectStyles();

    document.addEventListener('click', (event) => {
      const connectButton = event.target.closest('[data-p50-youtube-connect]');
      if (connectButton) {
        event.preventDefault();
        connectYouTube();
        return;
      }
      const disconnectButton = event.target.closest('[data-p50-youtube-disconnect]');
      if (disconnectButton) {
        event.preventDefault();
        disconnectYouTube();
        return;
      }
      if (event.target.closest('#accountBtn')) setTimeout(() => refreshStatus(false), 80);
    });

    window.addEventListener('message', (event) => {
      if (event.origin !== window.location.origin) return;
      if (event.data?.source !== 'PASS50_YOUTUBE_OAUTH') return;
      handleOAuthResult(String(event.data.status || 'error'));
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

    window.addEventListener('storage', (event) => {
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
