'use strict';

(() => {
  const CONTRACT = 'PASS50-APP-CLIENT-V1.1';
  const TOKEN_KEY = 'pass50_api_token';

  function readToken() {
    if (typeof window.P50Auth !== 'undefined') return window.P50Auth.getToken();
    try {
      return localStorage.getItem(TOKEN_KEY) || '';
    } catch (_) {
      return '';
    }
  }
  const API = './api/';
  const PERIODS = ['2H', '24H', '48H', '7J', '15J'];
  const FEED_PERIODS = [
    { key: '2h', label: '2H' },
    { key: '24h', label: '24H' },
    { key: '48h', label: '48H' },
    { key: '7d', label: '7J' },
    { key: '15d', label: '15J' },
  ];

  function isNativeShell() {
    try {
      const cap = window.Capacitor;
      if (cap && typeof cap.isNativePlatform === 'function') return Boolean(cap.isNativePlatform());
      if (cap && typeof cap.getPlatform === 'function') {
        return ['ios', 'android'].includes(String(cap.getPlatform() || '').toLowerCase());
      }
    } catch (_) {}
    return /source=native/i.test(location.search || '');
  }

  const state = {
    tab: 'ranking',
    period: '24H',
    feedPeriod: '24h',
    bootstrap: null,
    ranking: null,
    feed: null,
    live: null,
    user: null,
    token: readToken(),
    loading: { ranking: false, feed: false, live: false, auth: false },
    error: { ranking: '', feed: '', live: '', auth: '' },
    installEvent: null,
  };

  const el = {
    brand: null,
    status: null,
    content: null,
    nav: null,
    toast: null,
  };

  function $(id) {
    return document.getElementById(id);
  }

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function toast(message) {
    if (!el.toast) return;
    el.toast.textContent = message;
    el.toast.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.toast.classList.remove('show'), 2400);
  }

  async function api(path, options = {}) {
    const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    if (options.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
    if (options.auth !== false && state.token) headers.Authorization = 'Bearer ' + state.token;
    const response = await fetch(API + path.replace(/^\.?\/?api\//, ''), {
      method: options.method || 'GET',
      headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
      cache: 'no-store',
    });
    let data = null;
    try {
      data = await response.json();
    } catch (_) {
      data = null;
    }
    if (!response.ok) {
      const err = new Error((data && (data.error || data.message)) || ('HTTP ' + response.status));
      err.status = response.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  function setToken(token) {
    state.token = String(token || '');
    if (typeof window.P50Auth !== 'undefined') window.P50Auth.setToken(state.token);
    else if (state.token) localStorage.setItem(TOKEN_KEY, state.token);
    else localStorage.removeItem(TOKEN_KEY);
  }

  async function loadBootstrap() {
    try {
      const data = await api('app-bootstrap.php');
      state.bootstrap = data;
      state.user = data.user || null;
      if (!data.authenticated) state.user = null;
      renderChrome();
    } catch (error) {
      if (typeof window.P50Auth !== 'undefined' && window.P50Auth.isAuthExpiredError(error)) {
        setToken('');
        state.user = null;
      }
      state.bootstrap = { ok: false, error: String(error.message || error) };
      renderChrome();
    }
  }

  async function loadRanking(force) {
    if (state.loading.ranking && !force) return;
    state.loading.ranking = true;
    state.error.ranking = '';
    render();
    try {
      state.ranking = await api('public-ranking.php');
    } catch (error) {
      state.error.ranking = error.message || 'Classement indisponible';
    } finally {
      state.loading.ranking = false;
      render();
    }
  }

  async function loadFeed(force) {
    if (state.loading.feed && !force) return;
    state.loading.feed = true;
    state.error.feed = '';
    render();
    try {
      state.feed = await api(
        'public-feed.php?period=' + encodeURIComponent(state.feedPeriod) + '&newsLimit=18'
      );
    } catch (error) {
      state.error.feed = error.message || 'Fil indisponible';
    } finally {
      state.loading.feed = false;
      render();
    }
  }

  async function loadLive(force) {
    if (state.loading.live && !force) return;
    state.loading.live = true;
    state.error.live = '';
    render();
    try {
      state.live = await api('live-status.php?mode=status');
    } catch (error) {
      state.error.live = error.message || 'Live indisponible';
    } finally {
      state.loading.live = false;
      render();
    }
  }

  function avatarHtml(row) {
    const photo = String(row.photoUrl || row.thumbnailUrl || '');
    const initials = esc(row.initials || String(row.name || '?').slice(0, 2).toUpperCase());
    const pos = esc(row.photoPosition || '50% 18%');
    if (photo) {
      return `<span class="avatar"><img src="${esc(photo)}" alt="" style="object-position:${pos}" loading="lazy"></span>`;
    }
    return `<span class="avatar fallback">${initials}</span>`;
  }

  function deltaHtml(delta) {
    const n = Number(delta) || 0;
    if (n > 0) return `<span class="delta up">+${n}</span>`;
    if (n < 0) return `<span class="delta down">${n}</span>`;
    return `<span class="delta flat">=</span>`;
  }

  function renderRanking() {
    if (state.loading.ranking && !state.ranking) {
      return `<div class="panel"><p class="muted">Chargement du classement…</p></div>`;
    }
    if (state.error.ranking && !state.ranking) {
      return `<div class="panel"><p class="error">${esc(state.error.ranking)}</p><button type="button" data-action="reload-ranking" class="btn">Réessayer</button></div>`;
    }
    const periods = (state.ranking && state.ranking.periods) || {};
    const rows = Array.isArray(periods[state.period]) ? periods[state.period] : [];
    const chips = PERIODS.map(
      (period) =>
        `<button type="button" class="chip${period === state.period ? ' active' : ''}" data-period="${period}">${period}</button>`
    ).join('');

    const list = rows.length
      ? rows
          .map((row) => {
            const href = './?profile=' + encodeURIComponent(row.id || '');
            return `<a class="rank-row" href="${href}">
              <span class="rank-pos">${esc(row.rank)}</span>
              ${avatarHtml(row)}
              <span class="rank-meta">
                <strong>${esc(row.name)}</strong>
                <span>${esc(row.handle || row.category || '')}</span>
              </span>
              <span class="rank-score">
                <b>${esc(Number(row.score || 0).toFixed(1))}</b>
                ${deltaHtml(row.delta)}
              </span>
            </a>`;
          })
          .join('')
      : `<p class="muted">Aucun profil classé pour ${esc(state.period)}.</p>`;

    return `<section class="hero-block">
        <p class="eyebrow">Classement public</p>
        <h2>Qui monte maintenant</h2>
        <p class="lede">Top ${rows.length || 50} · période ${esc(state.period)} · API ranking V1</p>
      </section>
      <div class="chips">${chips}</div>
      <div class="panel list">${list}</div>`;
  }

  function renderFeed() {
    if (state.loading.feed && !state.feed) {
      return `<div class="panel"><p class="muted">Chargement du fil…</p></div>`;
    }
    if (state.error.feed && !state.feed) {
      return `<div class="panel"><p class="error">${esc(state.error.feed)}</p><button type="button" data-action="reload-feed" class="btn">Réessayer</button></div>`;
    }
    const chips = FEED_PERIODS.map(
      (period) =>
        `<button type="button" class="chip${period.key === state.feedPeriod ? ' active' : ''}" data-feed-period="${period.key}">${period.label}</button>`
    ).join('');
    const trends = Array.isArray(state.feed && state.feed.trends) ? state.feed.trends : [];
    const news = Array.isArray(state.feed && state.feed.news) ? state.feed.news : [];
    const items = news.length ? news : trends;

    const cards = items.length
      ? items
          .map((item) => {
            const title = item.title || 'Publication';
            const name = item.name || item.profileName || item.profileId || '';
            const platform = item.platform || '';
            const url = item.url || '#';
            const thumb = item.thumbnailUrl || '';
            const badge = item.badge ? `<span class="badge">${esc(item.badge)}</span>` : '';
            const media = thumb
              ? `<div class="card-media"><img src="${esc(thumb)}" alt="" loading="lazy"></div>`
              : '';
            return `<article class="feed-card">
              ${media}
              <div class="card-body">
                <div class="card-kicker">${esc(platform)} · ${esc(name)} ${badge}</div>
                <h3>${esc(title)}</h3>
                <a class="btn ghost" href="${esc(url)}" target="_blank" rel="noopener">Ouvrir</a>
              </div>
            </article>`;
          })
          .join('')
      : `<div class="panel"><p class="muted">Rien de frais sur cette période. Revenez dans quelques minutes.</p></div>`;

    return `<section class="hero-block">
        <p class="eyebrow">Fil public</p>
        <h2>Ce qui circule</h2>
        <p class="lede">Tendances et publications · feed V1</p>
      </section>
      <div class="chips">${chips}</div>
      <div class="feed-list">${cards}</div>
      <p class="foot-link"><a href="./mon-fil.html">Ouvrir Mon fil complet (suivis)</a></p>`;
  }

  function liveUrl(stream) {
    return stream.url || stream.watchUrl || stream.canonicalUrl || stream.link || '';
  }

  function renderLive() {
    if (state.loading.live && !state.live) {
      return `<div class="panel"><p class="muted">Lecture du radar live…</p></div>`;
    }
    if (state.error.live && !state.live) {
      return `<div class="panel"><p class="error">${esc(state.error.live)}</p><button type="button" data-action="reload-live" class="btn">Réessayer</button></div>`;
    }
    const streams = Array.isArray(state.live && state.live.liveStreams) ? state.live.liveStreams : [];
    const radar = (state.live && state.live.radar) || {};
    const meta = `Cache only · dernier scan ${esc(radar.lastScanAt || '—')}`;

    const cards = streams.length
      ? streams
          .map((stream) => {
            const name = stream.profileName || stream.name || stream.profileId || 'Live';
            const platform = stream.platform || '';
            const url = liveUrl(stream);
            const title = stream.title || 'En direct';
            return `<article class="live-card">
              <div class="live-dot" aria-hidden="true"></div>
              <div>
                <strong>${esc(name)}</strong>
                <span>${esc(platform)} · ${esc(title)}</span>
              </div>
              ${url ? `<a class="btn small" href="${esc(url)}" target="_blank" rel="noopener">Voir</a>` : ''}
            </article>`;
          })
          .join('')
      : `<div class="panel"><p class="muted">Aucun live confirmé pour le moment.</p></div>`;

    return `<section class="hero-block">
        <p class="eyebrow">Radar LIVE</p>
        <h2>Qui est en direct</h2>
        <p class="lede">${meta}</p>
      </section>
      <div class="live-list">${cards}</div>
      <p class="foot-link"><a href="./">Radar complet sur le desktop</a></p>`;
  }

  function renderAccount() {
    const user = state.user;
    if (user) {
      const name = user.displayName || user.name || user.pseudo || user.email || 'Membre';
      return `<section class="hero-block">
          <p class="eyebrow">Mon espace</p>
          <h2>${esc(name)}</h2>
          <p class="lede">${esc(user.email || '')}</p>
        </section>
        <div class="panel stack">
          <a class="btn" href="./mon-espace.html">Compte complet</a>
          <a class="btn ghost" href="./pronostics.html">Pronostics</a>
          <a class="btn ghost" href="./mon-fil.html">Mon fil (suivis)</a>
          <a class="btn ghost" href="./">Classement desktop</a>
          <button type="button" class="btn danger" data-action="logout">Se déconnecter</button>
        </div>`;
    }

    return `<section class="hero-block">
        <p class="eyebrow">Mon espace</p>
        <h2>Connexion</h2>
        <p class="lede">Même compte que sur le site desktop.</p>
      </section>
      <form class="panel stack" id="loginForm">
        <label>E-mail<input name="email" type="email" autocomplete="username" required></label>
        <label>Mot de passe<input name="password" type="password" autocomplete="current-password" required></label>
        ${state.error.auth ? `<p class="error">${esc(state.error.auth)}</p>` : ''}
        <button class="btn" type="submit"${state.loading.auth ? ' disabled' : ''}>${state.loading.auth ? 'Connexion…' : 'Se connecter'}</button>
        <a class="btn ghost" href="./mon-espace.html">Créer un compte</a>
      </form>
      <div class="panel stack">
        ${isNativeShell()
          ? '<p class="muted">Coque native PASS50 · store.pass50.app</p>'
          : `<button type="button" class="btn ghost" data-action="install"${state.installEvent ? '' : ' hidden'}>Installer PASS50</button>`}
        <a class="btn ghost" href="./">Ouvrir le site complet</a>
      </div>`;
  }

  function renderChrome() {
    if (el.status) {
      const auth = state.user ? 'Connecté' : 'Invité';
      const contract = (state.bootstrap && state.bootstrap.contract) || CONTRACT;
      const shell = isNativeShell() ? ' · App native' : '';
      el.status.textContent = auth + ' · ' + contract + shell;
    }
    if (el.nav) {
      el.nav.querySelectorAll('[data-tab]').forEach((node) => {
        node.classList.toggle('active', node.getAttribute('data-tab') === state.tab);
      });
    }
    document.body.dataset.tab = state.tab;
  }

  function render() {
    renderChrome();
    if (!el.content) return;
    let html = '';
    if (state.tab === 'ranking') html = renderRanking();
    else if (state.tab === 'feed') html = renderFeed();
    else if (state.tab === 'live') html = renderLive();
    else html = renderAccount();
    el.content.innerHTML = html;
  }

  function ensureTabData(tab) {
    if (tab === 'ranking') loadRanking();
    if (tab === 'feed') loadFeed();
    if (tab === 'live') loadLive();
  }

  function setTab(tab) {
    state.tab = tab;
    history.replaceState({ tab }, '', '#' + tab);
    render();
    ensureTabData(tab);
  }

  async function onLogin(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const email = String(form.email.value || '').trim();
    const password = String(form.password.value || '');
    state.loading.auth = true;
    state.error.auth = '';
    render();
    try {
      const data = await api('login.php', {
        method: 'POST',
        body: {
          email,
          password,
          deviceId: typeof window.P50Auth !== 'undefined' ? window.P50Auth.getDeviceId() : undefined,
        },
        auth: false,
      });
      setToken(data.token || '');
      state.user = data.user || null;
      toast('Connexion réussie');
      await loadBootstrap();
      setTab('ranking');
    } catch (error) {
      state.error.auth = error.message || 'Connexion impossible';
      render();
    } finally {
      state.loading.auth = false;
    }
  }

  function onLogout() {
    setToken('');
    state.user = null;
    toast('Déconnecté');
    loadBootstrap();
    setTab('account');
  }

  async function onInstall() {
    if (!state.installEvent) return;
    state.installEvent.prompt();
    try {
      await state.installEvent.userChoice;
    } catch (_) {}
    state.installEvent = null;
    render();
  }

  function bind() {
    el.nav.addEventListener('click', (event) => {
      const tab = event.target.closest('[data-tab]');
      if (!tab) return;
      event.preventDefault();
      setTab(tab.getAttribute('data-tab'));
    });

    el.content.addEventListener('click', (event) => {
      const periodBtn = event.target.closest('[data-period]');
      if (periodBtn) {
        state.period = periodBtn.getAttribute('data-period');
        render();
        return;
      }
      const feedPeriodBtn = event.target.closest('[data-feed-period]');
      if (feedPeriodBtn) {
        state.feedPeriod = feedPeriodBtn.getAttribute('data-feed-period');
        loadFeed(true);
        return;
      }
      const action = event.target.closest('[data-action]');
      if (!action) return;
      const name = action.getAttribute('data-action');
      if (name === 'reload-ranking') loadRanking(true);
      if (name === 'reload-feed') loadFeed(true);
      if (name === 'reload-live') loadLive(true);
      if (name === 'logout') onLogout();
      if (name === 'install') onInstall();
    });

    el.content.addEventListener('submit', (event) => {
      if (event.target && event.target.id === 'loginForm') onLogin(event);
    });

    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      state.installEvent = event;
      if (state.tab === 'account') render();
    });

    window.addEventListener('hashchange', () => {
      const tab = String(location.hash || '').replace(/^#/, '');
      if (['ranking', 'feed', 'live', 'account'].includes(tab) && tab !== state.tab) setTab(tab);
    });
  }

  async function boot() {
    el.brand = $('appBrand');
    el.status = $('appStatus');
    el.content = $('appContent');
    el.nav = $('appNav');
    el.toast = $('appToast');
    window.PASS50_APP_CLIENT = Object.freeze({
      contract: CONTRACT,
      version: '1.1',
      native: isNativeShell(),
    });

    if (!isNativeShell() && 'serviceWorker' in navigator && location.protocol.startsWith('http')) {
      navigator.serviceWorker.register('./sw.js?v=91').catch(() => {});
    }

    bind();
    const initial = String(location.hash || '').replace(/^#/, '');
    state.tab = ['ranking', 'feed', 'live', 'account'].includes(initial) ? initial : 'ranking';
    render();
    await loadBootstrap();
    ensureTabData(state.tab);
    // Prefetch the other light surfaces after first paint.
    setTimeout(() => {
      if (state.tab !== 'live') loadLive();
      if (state.tab !== 'feed') loadFeed();
      if (state.tab !== 'ranking') loadRanking();
    }, 1200);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
