'use strict';

(() => {
  const CONTRACT = 'PASS50-FOLLOW-FEED-PAGE-V2.3';
  const API_BASE = './api';
  const APP_KEY = 'pass50.ionos.v1';
  const MAX_FOLLOWED = 5;
  const NEWS_PER_PROFILE = 2;
  const DUEL_AUDIO_LIMIT = 12;
  const PERIODS = { '2H': '2h', '24H': '24h', '48H': '48h', '7J': '7d', '15J': '15d' };
  const PERIOD_LABELS = { '2H': '2 h', '24H': '24 h', '48H': '48 h', '7J': '7 jours', '15J': '15 jours' };
  const state = { period: '24H', user: null, profiles: [], following: [], news: [], liveStreams: [] };
  const $ = selector => document.querySelector(selector);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const attr = value => esc(value).replace(/`/g, '&#96;');

  function toast(message) {
    const node = $('#toast');
    if (!node) return;
    node.textContent = message;
    node.classList.add('show');
    clearTimeout(node._timer);
    node._timer = setTimeout(() => node.classList.remove('show'), 2200);
  }

  function localDb() {
    try { return JSON.parse(localStorage.getItem(APP_KEY) || '{}'); } catch (_) { return {}; }
  }

  function localUser() {
    const database = localDb();
    const sessionId = sessionStorage.getItem('pass50_session');
    return Array.isArray(database.users) ? database.users.find(user => user.id === sessionId) || null : null;
  }

  async function apiFetch(path, { auth = false } = {}) {
    const headers = { Accept: 'application/json' };
    const token = localStorage.getItem('pass50_api_token') || '';
    if (auth && token) headers.Authorization = `Bearer ${token}`;
    const response = await fetch(`${API_BASE}/${String(path).replace(/^\//, '')}`, { headers, cache: 'no-store' });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Service momentanément indisponible.');
    return data;
  }

  function profileMap() {
    return new Map(state.profiles.map(profile => [String(profile.id), profile]));
  }

  function profileFor(profileId) {
    return profileMap().get(String(profileId)) || null;
  }

  function scoreFor(profile) {
    const value = Number(profile?.scores?.[state.period] ?? profile?.scores?.['24H'] ?? 0);
    return Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
  }

  function isClassable(profile) {
    return Boolean(profile?.alive !== false && profile?.eligible !== false && profile?.classable !== false);
  }

  function ranking() {
    const alive = state.profiles.filter(profile => profile?.alive !== false);
    const classable = alive.filter(isClassable).sort((a, b) => scoreFor(b) - scoreFor(a) || String(a.name || '').localeCompare(String(b.name || ''), 'fr'));
    const pending = alive.filter(profile => !isClassable(profile)).sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'fr'));
    return [...classable, ...pending];
  }

  function rankFor(profileId) {
    const ordered = ranking();
    const index = ordered.findIndex(profile => String(profile.id) === String(profileId));
    if (index < 0 || !isClassable(ordered[index])) return null;
    return index + 1;
  }

  function movement(profile) {
    const delta = Number(profile?.delta || 0);
    if (delta > 0) return { className: 'up', text: `▲ +${delta}` };
    if (delta < 0) return { className: 'down', text: `▼ ${Math.abs(delta)}` };
    return { className: 'flat', text: '— stable' };
  }

  function initials(profile) {
    if (profile?.initials) return String(profile.initials).slice(0, 2).toUpperCase();
    return String(profile?.name || 'P50').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'P50';
  }

  function photo(profile) {
    return String(profile?.photoUrl || profile?.photoCandidateUrl || '').trim();
  }

  function avatarHtml(profile) {
    const image = photo(profile);
    return `<div class="avatar">${image ? `<img src="${attr(image)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">` : esc(initials(profile))}</div>`;
  }

  function relativeDate(value) {
    if (!value) return 'Date non précisée';
    const timestamp = Date.parse(value);
    if (!Number.isFinite(timestamp)) return 'Date non précisée';
    const seconds = Math.max(0, (Date.now() - timestamp) / 1000);
    if (seconds < 3600) return `il y a ${Math.max(1, Math.round(seconds / 60))} min`;
    if (seconds < 86400) return `il y a ${Math.round(seconds / 3600)} h`;
    if (seconds < 7 * 86400) return `il y a ${Math.round(seconds / 86400)} j`;
    return new Date(timestamp).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function durationLabel(durationMs) {
    const seconds = Math.max(1, Math.round(Number(durationMs || 0) / 1000));
    return `00:${String(Math.min(59, seconds)).padStart(2, '0')}`;
  }

  async function loadUser() {
    const token = localStorage.getItem('pass50_api_token') || '';
    if (token) {
      try {
        const data = await apiFetch('me.php', { auth: true });
        if (data?.user) return data.user;
      } catch (error) {
        console.warn('Session PASS50 indisponible pour Mon fil', error);
      }
    }
    return localUser();
  }

  async function loadProfiles() {
    let publicProfiles = [];
    try {
      const data = await apiFetch('state.php');
      if (Array.isArray(data?.data?.profiles)) publicProfiles = data.data.profiles;
    } catch (error) {
      console.warn('État public indisponible pour Mon fil', error);
    }
    const localProfiles = Array.isArray(localDb().profiles) ? localDb().profiles : [];
    const merged = new Map(localProfiles.map(profile => [String(profile.id), profile]));
    publicProfiles.forEach(profile => merged.set(String(profile.id), { ...(merged.get(String(profile.id)) || {}), ...profile }));
    return [...merged.values()];
  }

  async function loadNewsFor(profileId) {
    const query = new URLSearchParams({ period: PERIODS[state.period] || '24h', profileId: String(profileId), newsLimit: String(NEWS_PER_PROFILE) });
    try {
      const data = await apiFetch(`content-feed.php?${query}`);
      const items = Array.isArray(data?.news) ? data.news.slice(0, NEWS_PER_PROFILE) : [];
      return items.map(item => ({ ...item, profileId: String(profileId), feedType: 'news' }));
    } catch (error) {
      console.warn('Actualité indisponible', profileId, error);
      return [];
    }
  }

  async function loadDuelAudios() {
    if (!state.following.length) return [];
    const query = new URLSearchParams({ profileIds: state.following.join(','), limit: String(DUEL_AUDIO_LIMIT), _: String(Date.now()) });
    try {
      const data = await apiFetch(`duel-audio.php?${query}`);
      return (Array.isArray(data?.items) ? data.items : []).map(item => ({ ...item, feedType: 'duel_audio' }));
    } catch (error) {
      console.warn('Audios des duels indisponibles', error);
      return [];
    }
  }

  async function loadPronoStatuses() {
    try {
      const data = await apiFetch(`prono-statuses-feed.php?limit=20&_=${Date.now()}`, { auth: true });
      return (Array.isArray(data?.items) ? data.items : []).map(item => ({ ...item, feedType: 'prono_status' }));
    } catch (error) {
      console.warn('Statuts prono indisponibles', error);
      return [];
    }
  }

  async function loadFeedNews() {
    const [batches, duelAudios, pronoStatuses] = await Promise.all([
      Promise.all(state.following.map(loadNewsFor)),
      loadDuelAudios(),
      loadPronoStatuses(),
    ]);
    const seen = new Set();
    state.news = [...batches.flat(), ...duelAudios, ...pronoStatuses].filter(item => {
      const key = item.feedType === 'duel_audio'
        ? `audio:${item.id}`
        : item.feedType === 'prono_status'
          ? `prono:${item.id}`
          : `news:${String(item.id || item.url || `${item.profileId}:${item.title}`)}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).sort((a, b) => Date.parse(b.publishedAt || 0) - Date.parse(a.publishedAt || 0));
  }

  function renderFollowStrip() {
    const strip = $('#followStrip');
    const count = $('#followCount');
    if (!strip || !count) return;
    count.textContent = `${state.following.length}/${MAX_FOLLOWED}`;
    if (!state.following.length) {
      strip.innerHTML = '';
      return;
    }
    strip.innerHTML = state.following.map(profileId => {
      const profile = profileFor(profileId);
      if (!profile) return '';
      const rank = rankFor(profile.id);
      const change = movement(profile);
      return `<a class="follow-chip" href="./?profile=${encodeURIComponent(profile.id)}">${avatarHtml(profile)}<div><strong>${esc(profile.name || 'Influenceur')}</strong><span class="rank">${rank ? `#${rank}` : 'À vérifier'} · Score ${Math.round(scoreFor(profile))}</span><span class="${change.className}">${esc(change.text)}</span></div></a>`;
    }).join('');
  }

  function duelAudioCard(item) {
    const a = item.candidateA || {};
    const b = item.candidateB || {};
    const profileA = profileFor(a.profileId) || { id: a.profileId, name: a.name || 'Influenceur', initials: 'A' };
    const profileB = profileFor(b.profileId) || { id: b.profileId, name: b.name || 'Influenceur', initials: 'B' };
    const selected = String(item.selectedProfileId) === String(a.profileId) ? profileA : profileB;
    const matched = [profileA, profileB].filter(profile => state.following.includes(String(profile.id)));
    const because = matched.length ? `Parce que vous suivez ${matched.map(profile => profile.name).join(' et ')}` : 'Duel lié à vos suivis';
    const author = String(item.authorPseudo || 'Membre PASS50').trim() || 'Membre PASS50';
    return `<article class="feed-card duel-audio-feed-card"><div class="duel-audio-feed-head"><div class="duel-audio-avatars">${avatarHtml(profileA)}<span>VS</span>${avatarHtml(profileB)}</div><div><div class="duel-audio-kicker">🎙 LES COULÉS · ${esc(author)}</div><strong>${esc(profileA.name)} VS ${esc(profileB.name)}</strong><div class="feed-meta">${esc(because)} · ${esc(relativeDate(item.publishedAt))}</div></div></div><div class="duel-audio-feed-body"><h2>${esc(author)} commente son vote pour ${esc(selected.name)}</h2><div class="duel-audio-player"><span>${durationLabel(item.durationMs)}</span><audio controls preload="metadata" src="${attr(item.audioUrl)}" aria-label="Commentaire audio de ${attr(author)}"></audio></div><div class="feed-meta">Pseudo issu de son compte utilisateur PASS50 · Audio publié volontairement lors du partage</div><div class="feed-actions"><a class="btn primary" href="./?section=coules">Voir le duel</a><a class="btn" href="./?profile=${encodeURIComponent(selected.id || '')}">Voir la fiche de ${esc(selected.name)}</a></div></div></article>`;
  }

  function pronoStatusCard(item) {
    const expires = relativeDate(item.expiresAt);
    const liked = item.likedByMe ? 'liked' : '';
    return `<article class="feed-card prono-status-card" data-prono-status="${esc(item.id)}">
      <div class="feed-head">
        <div class="avatar">🎯</div>
        <div class="feed-person"><strong>${esc(item.authorPseudo || 'Membre')}</strong><span>Prono · statut ${esc(item.durationHours)} h</span></div>
        <div class="feed-position"><span class="up">PRONO</span><br>expire ${esc(expires)}</div>
      </div>
      <div class="feed-body">
        <h2>${esc(item.questionTitle || 'Pronostic')}</h2>
        <div class="feed-meta">Choix : <strong>${esc(item.optionLabel || item.optionKey)}</strong> · Sans argent réel</div>
        <div class="feed-actions">
          <button type="button" class="btn ${liked}" data-prono-like="${esc(item.id)}">${item.likedByMe ? '♥ Liké' : '♡ Like'} · ${esc(item.likeCount || 0)}</button>
          <a class="btn" href="./pronostics.html">Voir les pronos</a>
        </div>
      </div>
    </article>`;
  }

  function feedCard(item) {
    if (item.feedType === 'duel_audio') return duelAudioCard(item);
    if (item.feedType === 'prono_status') return pronoStatusCard(item);
    const profile = profileFor(item.profileId) || { id: item.profileId, name: 'Influenceur', initials: 'P50' };
    const rank = rankFor(profile.id);
    const change = movement(profile);
    const cover = String(item.thumbnailUrl || '').trim();
    const source = item.official ? 'SOURCE OFFICIELLE' : 'INFORMATION VALIDÉE';
    const meta = [item.platform || '', relativeDate(item.publishedAt), item.trendBadge || ''].filter(Boolean).join(' · ');
    const original = /^https?:\/\//i.test(String(item.url || '')) ? `<a class="btn primary" href="${attr(item.url)}" target="_blank" rel="noopener">Voir le contenu original ↗</a>` : '';
    return `<article class="feed-card"><div class="feed-head">${avatarHtml(profile)}<div class="feed-person"><strong>${esc(profile.name || 'Influenceur')}</strong><span>${esc(profile.handle || '')}</span></div><div class="feed-position"><span class="${change.className}">${esc(change.text)}</span><br>${rank ? `#${rank}` : 'À vérifier'} · ${Math.round(scoreFor(profile))}/100</div></div><div class="feed-media ${cover ? '' : 'no-image'}">${cover ? `<img src="${attr(cover)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentElement.classList.add('no-image');this.remove()">` : '<span>📰</span>'}<div class="source-badge">${source}</div></div><div class="feed-body"><h2>${esc(item.title || `Actualité récente de ${profile.name}`)}</h2><div class="feed-meta">${esc(meta)}</div><div class="feed-actions">${original}<a class="btn" href="./?profile=${encodeURIComponent(profile.id)}">Voir sa fiche et son classement</a></div></div></article>`;
  }

  function renderFeed() {
    const list = $('#feedList');
    const end = $('#feedEnd');
    if (!list || !end) return;
    if (!state.user) {
      list.innerHTML = '<div class="empty"><strong>Connexion nécessaire.</strong>Connectez-vous pour retrouver les influenceurs que vous suivez.<div style="margin-top:13px"><a class="btn primary" href="./?open=account">Se connecter</a></div></div>';
      end.classList.add('hidden');
      return;
    }
    if (!state.following.length) {
      list.innerHTML = '<div class="empty"><strong>Votre fil est vide.</strong>Depuis le classement ou une fiche, cliquez sur « Suivre » pour choisir jusqu’à cinq influenceurs.<div style="margin-top:13px"><a class="btn primary" href="./">Voir le classement</a></div></div>';
      end.classList.add('hidden');
      return;
    }
    if (!state.news.length) {
      list.innerHTML = '<div class="empty"><strong>Aucune actualité ou audio récent.</strong>PASS50 ne remplit pas votre fil avec des contenus recommandés ou extérieurs à vos suivis.</div>';
      end.classList.remove('hidden');
      return;
    }
    list.innerHTML = state.news.map(feedCard).join('');
    end.classList.remove('hidden');
  }

  async function refreshFeed({ silent = false } = {}) {
    const list = $('#feedList');
    if (!silent && list) list.innerHTML = '<div class="loading">Actualisation de votre fil…</div>';
    renderFollowStrip();
    if (state.user && state.following.length) await loadFeedNews();
    else state.news = [];
    renderFollowStrip();
    renderFeed();
  }

  function normalizeLives(items) {
    const seen = new Set();
    return (Array.isArray(items) ? items : []).filter(item => {
      if (!item || item.status !== 'live' || !/^https?:\/\//i.test(String(item.url || ''))) return false;
      const key = [item.profileId || '', item.platform || '', String(item.url).replace(/\/+$/, '')].map(value => String(value).toLowerCase()).join('|');
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  async function refreshRadar() {
    try {
      const data = await apiFetch('live-status.php');
      state.liveStreams = normalizeLives(data?.liveStreams);
    } catch (error) {
      console.warn('Radar LIVE indisponible', error);
      state.liveStreams = [];
    }
    const count = $('#feedLiveCount');
    if (count) count.textContent = state.liveStreams.length ? `(${state.liveStreams.length})` : '';
    if ($('#feedLiveModal')?.classList.contains('show')) renderRadarModal();
  }

  function renderRadarModal() {
    const body = $('#feedLiveBody');
    if (!body) return;
    if (!state.liveStreams.length) {
      body.innerHTML = '<div class="empty"><strong>Aucun direct détecté pour le moment.</strong>Le Radar LIVE continue sa vérification automatique des comptes officiels.</div>';
      return;
    }
    body.innerHTML = `<div class="radar-list">${state.liveStreams.map(live => {
      const profile = profileFor(live.profileId) || { name: live.profileName || 'Influenceur', initials: 'LV' };
      const confirmed = live.lastConfirmedAt || live.lastSeenAt || '';
      const details = [live.title || 'Direct en cours', relativeDate(confirmed), Number(live.viewers || 0) > 0 ? `${Number(live.viewers).toLocaleString('fr-FR')} spectateur(s)` : ''].filter(Boolean).join(' · ');
      return `<article class="radar-card">${avatarHtml(profile)}<div><div class="radar-platform">EN DIRECT SUR ${esc(live.platform || 'UNE PLATEFORME')}</div><strong>${esc(profile.name || 'Influenceur')}</strong><p>${esc(details)}</p></div><a class="btn primary small" href="${attr(live.url)}" target="_blank" rel="noopener">Regarder ↗</a></article>`;
    }).join('')}</div>`;
  }

  async function openRadar() {
    const modal = $('#feedLiveModal');
    const body = $('#feedLiveBody');
    if (!modal || !body) return;
    modal.classList.add('show');
    body.innerHTML = '<div class="loading">Actualisation du Radar LIVE…</div>';
    await refreshRadar();
    renderRadarModal();
  }

  function closeRadar() {
    $('#feedLiveModal')?.classList.remove('show');
  }

  function installEvents() {
    $('#periodFilters')?.addEventListener('click', async event => {
      const button = event.target.closest('[data-period]');
      if (!button || !PERIODS[button.dataset.period]) return;
      state.period = button.dataset.period;
      document.querySelectorAll('[data-period]').forEach(node => node.classList.toggle('active', node === button));
      toast(`Période : ${PERIOD_LABELS[state.period]}`);
      await refreshFeed();
    });
    $('#feedLiveRadarBtn')?.addEventListener('click', openRadar);
    $('[data-close-live]')?.addEventListener('click', closeRadar);
    $('#feedLiveModal')?.addEventListener('click', event => { if (event.target.id === 'feedLiveModal') closeRadar(); });
    $('#feedList')?.addEventListener('click', async event => {
      const btn = event.target.closest('[data-prono-like]');
      if (!btn) return;
      const statusId = btn.getAttribute('data-prono-like');
      try {
        const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
        const token = localStorage.getItem('pass50_api_token') || '';
        if (!token) return toast('Connecte-toi pour liker');
        headers.Authorization = `Bearer ${token}`;
        const response = await fetch('./api/prono-status-like.php', { method: 'POST', headers, body: JSON.stringify({ statusId }), cache: 'no-store' });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || 'Like impossible');
        const item = state.news.find(row => row.feedType === 'prono_status' && String(row.id) === String(statusId));
        if (item) {
          item.likedByMe = true;
          item.likeCount = Number(data.likeCount || item.likeCount || 0);
        }
        renderFeed();
        toast(data.alreadyLiked ? 'Déjà liké' : 'Like envoyé · +0,25 pt pour l’auteur');
      } catch (error) {
        toast(error.message || 'Like impossible');
      }
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeRadar(); });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshFeed({ silent: true }); });
  }

  async function init() {
    installEvents();
    const [user, profiles] = await Promise.all([loadUser(), loadProfiles()]);
    state.user = user;
    state.profiles = profiles;
    state.following = [...new Set(Array.isArray(user?.following) ? user.following.map(String) : [])].slice(0, MAX_FOLLOWED);
    await Promise.all([refreshFeed(), refreshRadar()]);
    setInterval(refreshRadar, 60000);
    setInterval(() => refreshFeed({ silent: true }), 60000);
    window.PASS50_FOLLOW_FEED_PAGE = Object.freeze({ contract: CONTRACT, maxFollowed: MAX_FOLLOWED, newsPerProfile: NEWS_PER_PROFILE, duelAudio: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
