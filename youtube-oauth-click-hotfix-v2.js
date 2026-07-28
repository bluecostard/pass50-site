'use strict';

(function () {
  const SECTION_ID = 'p50YoutubeOauthSection';
  const TOKEN_KEY = 'pass50_api_token';
  const REQUEST_TIMEOUT_MS = 15000;
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
    if (document.getElementById('p50YoutubeClickHotfixV2Styles')) return;
    const style = document.createElement('style');
    style.id = 'p50YoutubeClickHotfixV2Styles';
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
    try { return raw ? JSON.parse(raw) : {}; } catch (_) {
      throw new Error('Le serveur PASS50 a retourné une réponse illisible.');
    }
  }

  async function fetchWithTimeout(url, options, timeoutMs = REQUEST_TIMEOUT_MS) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } catch (error) {
      if (error?.name === 'AbortError') {
        throw new Error('Le serveur met trop de temps à répondre. Réessaie dans quelques secondes.');
      }
      throw new Error('Impossible de joindre le serveur PASS50. Vérifie ta connexion puis réessaie.');
    } finally {
      clearTimeout(timeout);
    }
  }

  function openWaitingPopup() {
    const popup = window.open('', 'pass50_youtube_oauth', 'popup=yes,width=560,height=760,resizable=yes,scrollbars=yes');
    if (!popup) return null;
    try {
      popup.document.open();
      popup.document.write('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>PASS50 · YouTube</title></head><body style="background:#050705;color:#fff;font-family:Arial;padding:30px"><h2>PASS50</h2><p>Connexion sécurisée avec Google en préparation…</p></body></html>');
      popup.document.close();
    } catch (_) {}
    return popup;
  }

  async function startOAuth(button) {
    if (busy) return;
    busy = true;
    clearInline();

    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Connexion…';
    showInline('Demande d’autorisation envoyée au serveur PASS50…');

    const popup = openWaitingPopup();

    try {
      const token = String(localStorage.getItem(TOKEN_KEY) || '').trim();
      if (!token) throw new Error('Ta session PASS50 a expiré. Déconnecte-toi puis reconnecte-toi.');

      const response = await fetchWithTimeout(`${apiBase()}/youtube-oauth-start.php`, {
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
      if (!response.ok) {
        if (response.status === 401) throw new Error('Ta session PASS50 a expiré. Déconnecte-toi puis reconnecte-toi.');
        throw new Error(data.error || data.message || `Erreur serveur (${response.status}).`);
      }
      if (!data.authorizationUrl || !/^https:\/\/accounts\.google\.com\//.test(String(data.authorizationUrl))) {
        throw new Error('Le serveur n’a pas retourné une adresse Google valide.');
      }

      showInline('Google s’ouvre maintenant…', 'ok');
      if (popup && !popup.closed) popup.location.href = data.authorizationUrl;
      else window.location.href = data.authorizationUrl;
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
