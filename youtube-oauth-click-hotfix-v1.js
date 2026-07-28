'use strict';

(function () {
  const SECTION_ID = 'p50YoutubeOauthSection';
  const TOKEN_KEY = 'pass50_api_token';
  let busy = false;

  function apiBase() {
    return String(window.PASS50_API?.baseUrl || './api').replace(/\/+$/, '') || './api';
  }

  function showInline(message, tone = 'info') {
    const section = document.getElementById(SECTION_ID);
    if (!section) return;
    let node = section.querySelector('.p50-yt-inline-feedback');
    if (!node) {
      node = document.createElement('div');
      node.className = 'p50-yt-inline-feedback';
      section.appendChild(node);
    }
    node.dataset.tone = tone;
    node.textContent = message;
  }

  function clearInline() {
    document.querySelector(`#${SECTION_ID} .p50-yt-inline-feedback`)?.remove();
  }

  function installStyles() {
    if (document.getElementById('p50YoutubeClickHotfixStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50YoutubeClickHotfixStyles';
    style.textContent = `
      #${SECTION_ID} .p50-yt-inline-feedback{margin-top:10px;padding:11px 13px;border:1px solid #536052;border-radius:12px;background:#111611;color:#dbe2d8;font-size:12px;line-height:1.45}
      #${SECTION_ID} .p50-yt-inline-feedback[data-tone="error"]{border-color:#8a3434;background:#241010;color:#ffb4b4}
      #${SECTION_ID} .p50-yt-inline-feedback[data-tone="ok"]{border-color:#5f8500;background:#11200a;color:#d9ff94}
      #${SECTION_ID} [data-p50-youtube-connect][disabled]{opacity:.72;cursor:wait}
    `;
    document.head.appendChild(style);
  }

  async function readJson(response) {
    const raw = await response.text();
    try { return raw ? JSON.parse(raw) : {}; } catch (_) { return {}; }
  }

  async function checkServer() {
    const response = await fetch(`${apiBase()}/health.php`, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const data = await readJson(response);
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'Le serveur PASS50 n’est pas disponible.');
    }
    if (data.googleOauthConfigured === false) {
      throw new Error('La configuration OAuth Google est absente ou incomplète dans api/config.php.');
    }
  }

  async function startOAuth(button) {
    if (busy) return;
    busy = true;
    clearInline();

    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Connexion…';
    showInline('Préparation de la connexion sécurisée avec Google…');

    const popup = window.open('', 'pass50_youtube_oauth', 'popup=yes,width=560,height=760,resizable=yes,scrollbars=yes');
    if (popup) {
      try {
        popup.document.write('<!doctype html><html lang="fr"><meta charset="utf-8"><title>PASS50 · YouTube</title><body style="background:#050705;color:#fff;font-family:Arial;padding:30px"><h2>PASS50</h2><p>Préparation de la connexion YouTube…</p></body></html>');
      } catch (_) {}
    }

    try {
      const token = String(localStorage.getItem(TOKEN_KEY) || '').trim();
      if (!token) throw new Error('Ta session PASS50 a expiré. Reconnecte-toi puis réessaie.');

      await checkServer();

      const response = await fetch(`${apiBase()}/youtube-oauth-start.php`, {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`
        },
        body: '{}'
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(data.error || data.message || `Erreur serveur (${response.status}).`);
      if (!data.authorizationUrl) throw new Error('Google n’a pas retourné de lien d’autorisation.');

      showInline('Ouverture de Google…', 'ok');
      if (popup) popup.location.replace(data.authorizationUrl);
      else window.location.assign(data.authorizationUrl);
    } catch (error) {
      if (popup && !popup.closed) popup.close();
      showInline(error?.message || 'La connexion YouTube n’a pas pu démarrer.', 'error');
    } finally {
      busy = false;
      button.disabled = false;
      button.textContent = originalLabel;
    }
  }

  function install() {
    installStyles();
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const button = target?.closest('[data-p50-youtube-connect]');
      if (!button) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      startOAuth(button);
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
  else install();
}());
