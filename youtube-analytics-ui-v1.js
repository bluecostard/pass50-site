'use strict';

(function () {
  const SECTION_ID = 'p50YoutubeOauthSection';
  const PANEL_ID = 'p50YoutubeAnalyticsPanel';
  const TOKEN_KEY = 'pass50_api_token';
  const DAYS = 28;
  let summary = null;
  let channel = null;
  let connected = false;
  let loading = false;
  let initialized = false;
  let scheduled = false;

  function apiBase() {
    return String(window.PASS50_API?.baseUrl || './api').replace(/\/+$/, '') || './api';
  }

  function token() {
    return String(localStorage.getItem(TOKEN_KEY) || '').trim();
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  async function request(path, options = {}) {
    const accessToken = token();
    if (!accessToken) throw new Error('Connexion PASS50 requise.');
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
    if (!response.ok) throw new Error(data.error || `Erreur serveur (${response.status}).`);
    return data;
  }

  function number(value, digits = 0) {
    return value == null ? '—' : Number(value).toLocaleString('fr-FR', { maximumFractionDigits: digits });
  }

  function hours(minutes) {
    return minutes == null ? '—' : `${number(Number(minutes) / 60, 1)} h`;
  }

  function duration(seconds) {
    if (seconds == null) return '—';
    const rounded = Math.max(0, Math.round(Number(seconds)));
    const minutes = Math.floor(rounded / 60);
    const remaining = rounded % 60;
    return `${minutes}:${String(remaining).padStart(2, '0')}`;
  }

  function signed(value) {
    if (value == null) return '—';
    const numeric = Number(value);
    return `${numeric > 0 ? '+' : ''}${number(numeric)}`;
  }

  function installStyles() {
    if (document.getElementById('p50YoutubeAnalyticsStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50YoutubeAnalyticsStyles';
    style.textContent = `
      #${PANEL_ID}{margin-top:12px;padding:15px;border:1px solid #293129;border-radius:15px;background:#0b0f0b}
      #${PANEL_ID} .p50-ya-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
      #${PANEL_ID} .p50-ya-title{font-weight:1000;font-size:14px}
      #${PANEL_ID} .p50-ya-note{margin-top:3px;color:#9da79b;font-size:11px;line-height:1.4}
      #${PANEL_ID} .p50-ya-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
      #${PANEL_ID} .p50-ya-metric{padding:10px;border:1px solid #202820;border-radius:12px;background:#111611;min-width:0}
      #${PANEL_ID} .p50-ya-metric strong{display:block;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #${PANEL_ID} .p50-ya-metric span{display:block;margin-top:3px;color:#9da79b;font-size:10px;text-transform:uppercase}
      #${PANEL_ID} .p50-ya-foot{margin-top:10px;color:#788276;font-size:10px}
      #${PANEL_ID} .p50-ya-error{margin-top:10px;color:#ffb4b4;font-size:12px}
      @media(max-width:720px){#${PANEL_ID} .p50-ya-head{flex-direction:column}#${PANEL_ID} .p50-ya-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    `;
    document.head.appendChild(style);
  }

  function render() {
    const section = document.getElementById(SECTION_ID);
    if (!section) return;
    let panel = document.getElementById(PANEL_ID);
    if (!connected) {
      panel?.remove();
      return;
    }
    if (!panel) {
      panel = document.createElement('div');
      panel.id = PANEL_ID;
      section.appendChild(panel);
    }
    const refresh = `<button class="btn" type="button" data-p50-youtube-analytics-refresh ${loading ? 'disabled' : ''}>${loading ? 'Actualisation…' : 'Actualiser'}</button>`;
    if (loading && !summary) {
      panel.innerHTML = `<div class="p50-ya-head"><div><div class="p50-ya-title">Statistiques privées YouTube</div><div class="p50-ya-note">Lecture des 28 derniers jours complets…</div></div>${refresh}</div>`;
      return;
    }
    if (!summary) {
      panel.innerHTML = `<div class="p50-ya-head"><div><div class="p50-ya-title">Statistiques privées YouTube</div><div class="p50-ya-note">Aucun rapport Analytics enregistré. Ces données restent privées et ne modifient pas le classement public.</div></div>${refresh}</div>`;
      return;
    }
    const metrics = summary.metrics || {};
    const period = summary.period || {};
    panel.innerHTML = `
      <div class="p50-ya-head"><div><div class="p50-ya-title">Statistiques privées · ${escapeHtml(channel?.title || 'YouTube')}</div><div class="p50-ya-note">${escapeHtml(period.startDate || '')} au ${escapeHtml(period.endDate || '')} · Ces données ne modifient pas le classement public.</div></div>${refresh}</div>
      <div class="p50-ya-grid">
        <div class="p50-ya-metric"><strong>${number(metrics.views)}</strong><span>Vues</span></div>
        <div class="p50-ya-metric"><strong>${hours(metrics.estimatedMinutesWatched)}</strong><span>Visionnage</span></div>
        <div class="p50-ya-metric"><strong>${duration(metrics.averageViewDuration)}</strong><span>Durée moyenne</span></div>
        <div class="p50-ya-metric"><strong>${signed(metrics.netSubscribers)}</strong><span>Abonnés nets</span></div>
        <div class="p50-ya-metric"><strong>${number(metrics.likes)}</strong><span>J’aime</span></div>
        <div class="p50-ya-metric"><strong>${number(metrics.comments)}</strong><span>Commentaires</span></div>
        <div class="p50-ya-metric"><strong>${number(metrics.shares)}</strong><span>Partages</span></div>
        <div class="p50-ya-metric"><strong>${number(metrics.averageViewPercentage, 1)}${metrics.averageViewPercentage == null ? '' : ' %'}</strong><span>Visionnage moyen</span></div>
      </div>
      <div class="p50-ya-foot">Dernière lecture : ${escapeHtml(summary.fetchedAt ? new Date(summary.fetchedAt).toLocaleString('fr-FR') : '—')}</div>`;
  }

  async function loadLatest() {
    if (loading || !token()) return;
    loading = true;
    render();
    try {
      const status = await request('youtube-oauth-status.php');
      connected = Boolean(status.connected);
      if (!connected) return;
      channel = status.channel || null;
      const data = await request(`youtube-analytics-summary.php?days=${DAYS}`);
      summary = data.summary || null;
      channel = data.channel || channel;
    } catch (_) {
      summary = null;
    } finally {
      loading = false;
      render();
    }
  }

  async function refresh() {
    if (loading) return;
    loading = true;
    render();
    let errorMessage = '';
    try {
      const data = await request('youtube-analytics-summary.php', { method: 'POST', body: { days: DAYS } });
      summary = data.summary || null;
      channel = data.channel || channel;
    } catch (error) {
      errorMessage = error.message || 'Statistiques indisponibles.';
    } finally {
      loading = false;
      render();
      const panel = document.getElementById(PANEL_ID);
      if (errorMessage && panel) panel.insertAdjacentHTML('beforeend', `<div class="p50-ya-error">${escapeHtml(errorMessage)}</div>`);
    }
  }

  function scheduleEnsure() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(() => {
      scheduled = false;
      const section = document.getElementById(SECTION_ID);
      if (!section || !token()) return;
      if (!document.getElementById(PANEL_ID)) render();
      if (!initialized) {
        initialized = true;
        loadLatest();
      }
    }, 80);
  }

  function install() {
    installStyles();
    document.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('[data-p50-youtube-analytics-refresh]')) {
        event.preventDefault();
        refresh();
      }
      if (event.target instanceof Element && event.target.closest('#accountBtn')) scheduleEnsure();
    });
    const userBody = document.getElementById('userBody');
    if (userBody) new MutationObserver(scheduleEnsure).observe(userBody, { childList: true, subtree: true });
    window.addEventListener('message', (event) => {
      if (event.origin === window.location.origin && event.data?.source === 'PASS50_YOUTUBE_OAUTH' && event.data.status === 'connected') {
        connected = true;
        initialized = false;
        scheduleEnsure();
      }
    });
    scheduleEnsure();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
  else install();
}());
