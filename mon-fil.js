'use strict';

(() => {
  const CONTRACT = 'PASS50-FOLLOW-FEED-PAGE-V2.8';
  const API_BASE = './api';
  const APP_KEY = 'pass50.ionos.v1';
  const MAX_FOLLOWED = 5;
  const NEWS_PER_PROFILE = 2;
  const DUEL_AUDIO_LIMIT = 12;
  const PERIODS = { '2H': '2h', '24H': '24h', '48H': '48h', '7J': '7d', '15J': '15d' };
  const PERIOD_LABELS = { '2H': '2 h', '24H': '24 h', '48H': '48 h', '7J': '7 jours', '15J': '15 jours' };
  const state = {
    period: '24H',
    user: null,
    profiles: [],
    following: [],
    news: [],
    pronoStatuses: [],
    liveStreams: [],
    diapoIndex: 0,
    diapoTimer: null,
    seenPronos: new Set(),
  };
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

  function pseudoInitials(pseudo) {
    const clean = String(pseudo || 'P50').replace(/[^a-zA-Z0-9àâäéèêëïîôùûüç]/gi, ' ').trim();
    const parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return clean.slice(0, 2).toUpperCase() || 'P50';
  }

  function hashHue(value) {
    let hash = 0;
    for (const char of String(value || '')) hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    return hash % 360;
  }

  function syntheticPhoto(pseudo) {
    const ini = pseudoInitials(pseudo);
    const hue = hashHue(pseudo);
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="hsl(${hue} 58% 34%)"/><stop offset="1" stop-color="hsl(${(hue + 48) % 360} 42% 14%)"/></linearGradient></defs><rect width="128" height="128" rx="64" fill="url(#g)"/><text x="64" y="76" text-anchor="middle" fill="#f6f8f4" font-family="system-ui,sans-serif" font-size="42" font-weight="800">${ini}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
  }

  function communityFaces() {
    return state.profiles.map(profile => photo(profile)).filter(Boolean);
  }

  function memberAvatarHtml(pseudo, photoUrl, className = 'prono-story-avatar') {
    const src = String(photoUrl || '').trim() || syntheticPhoto(pseudo);
    return `<span class="${className}"><img src="${attr(src)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.src='${attr(syntheticPhoto(pseudo))}'"></span>`;
  }

  function fmtOdd(odd) {
    const n = Number(odd);
    return Number.isFinite(n) && n > 0 ? n.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') : '—';
  }

  function statusCoverDataUri(item, mode = 'feed') {
    const odd = fmtOdd(item.odd || 2);
    const hue = hashHue(item.questionTitle || item.authorPseudo);
    const title = String(item.optionLabel || 'PRONO').slice(0, 28).replace(/[<>&]/g, '');
    if (mode === 'share') {
      // Palette partage hors app : plus vive, cote lisible en un coup d’œil
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="720" height="900"><defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop stop-color="hsl(${hue} 55% 22%)"/><stop offset="1" stop-color="#050705"/></linearGradient></defs><rect width="720" height="900" fill="url(#bg)"/><circle cx="560" cy="160" r="180" fill="#b7ff00" opacity=".18"/><text x="48" y="110" fill="#b7ff00" font-family="Arial Black,Impact,sans-serif" font-size="30">PASS50</text><text x="48" y="400" fill="#b7ff00" font-family="Arial Black,Impact,sans-serif" font-size="72">${odd}</text><text x="48" y="450" fill="#f6f8f4" font-family="Arial,sans-serif" font-size="22" font-weight="700">COTE</text><text x="48" y="540" fill="#f6f8f4" font-family="Arial,sans-serif" font-size="36" font-weight="800">${title}</text><text x="48" y="820" fill="#9da79b" font-family="Arial,sans-serif" font-size="20">Sans argent réel</text></svg>`;
      return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    }
    // Palette fil in-app : calme, charcoal — la cote n’est que dans le ticket
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="720" height="900"><defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#151a15"/><stop offset="1" stop-color="#050705"/></linearGradient></defs><rect width="720" height="900" fill="url(#bg)"/><text x="48" y="120" fill="#6b7568" font-family="Arial,sans-serif" font-size="22" font-weight="800" letter-spacing="4">PASS50</text><text x="48" y="520" fill="#c9d2c4" font-family="Arial,sans-serif" font-size="40" font-weight="800">${title}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
  }

  function statusCoverSrc(item) {
    if (item.profileId) {
      const fiPhoto = photo(profileFor(item.profileId));
      if (fiPhoto) return fiPhoto;
    }
    const direct = String(item.coverPhoto || item.authorPhoto || '').trim();
    if (direct && !String(direct).startsWith('data:image/svg')) return direct;
    return statusCoverDataUri(item, 'feed');
  }

  function enrichPronoPhoto(item, index = 0) {
    const odd = Number(item.odd) > 0 ? Number(item.odd) : 2;
    const stake = Number(item.stake) > 0 ? Number(item.stake) : 100;
    const payout = Number(item.potentialPayout) > 0 ? Number(item.potentialPayout) : Math.round(stake * odd);
    const direct = String(item.authorPhoto || item.authorAvatar || item.photoUrl || '').trim();
    const faces = communityFaces();
    const authorPhoto = direct || (faces.length ? faces[index % faces.length] : syntheticPhoto(item.authorPseudo || item.id || 'pass50'));
    const enriched = {
      ...item,
      odd,
      stake,
      potentialPayout: payout,
      authorPhoto,
    };
    enriched.coverPhoto = String(item.coverPhoto || '').trim() || statusCoverSrc(enriched);
    return enriched;
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

  function samplePronoStatuses() {
    const now = Date.now();
    const faces = communityFaces();
    const base = [
      { id: 'sample-prono-1', feedType: 'prono_status', sample: true, authorPseudo: 'fan_abidjan', questionTitle: 'Himra — perte d’abonnés TikTok en 7 jours ?', optionLabel: '+ de 300 000', optionKey: 'b', odd: 2.1, stake: 100, potentialPayout: 210, likeCount: 18, likedByMe: false, durationHours: 24, publishedAt: new Date(now - 20 * 60000).toISOString(), expiresAt: new Date(now + 20 * 3600000).toISOString() },
      { id: 'sample-prono-2', feedType: 'prono_status', sample: true, authorPseudo: 'koffi_buzz', questionTitle: 'Josey finit-il dans le Top 3 PASS50 ?', optionLabel: 'Oui, Top 3', optionKey: 'yes', odd: 1.85, stake: 100, potentialPayout: 185, likeCount: 42, likedByMe: false, durationHours: 12, publishedAt: new Date(now - 55 * 60000).toISOString(), expiresAt: new Date(now + 8 * 3600000).toISOString() },
      { id: 'sample-prono-3', feedType: 'prono_status', sample: true, authorPseudo: 'aya_ci', questionTitle: 'Lo Père finit-il sa 2ᵉ maison dans 6 mois ?', optionLabel: 'Oui', optionKey: 'y', odd: 1.7, stake: 100, potentialPayout: 170, likeCount: 7, likedByMe: false, durationHours: 48, publishedAt: new Date(now - 2 * 3600000).toISOString(), expiresAt: new Date(now + 40 * 3600000).toISOString() },
      { id: 'sample-prono-4', feedType: 'prono_status', sample: true, authorPseudo: 'diaspora_tv', questionTitle: 'Himra — perte d’abonnés TikTok en 7 jours ?', optionLabel: '+ de 400 000', optionKey: 'a', odd: 3.4, stake: 100, potentialPayout: 340, likeCount: 11, likedByMe: false, durationHours: 24, publishedAt: new Date(now - 3 * 3600000).toISOString(), expiresAt: new Date(now + 18 * 3600000).toISOString() },
      { id: 'sample-prono-5', feedType: 'prono_status', sample: true, authorPseudo: 'yves_rank', questionTitle: 'Josey finit-il dans le Top 3 PASS50 ?', optionLabel: 'Non, hors Top 3', optionKey: 'no', odd: 2.05, stake: 100, potentialPayout: 205, likeCount: 3, likedByMe: false, durationHours: 24, publishedAt: new Date(now - 4 * 3600000).toISOString(), expiresAt: new Date(now + 16 * 3600000).toISOString() },
    ];
    return base.map((item, index) => enrichPronoPhoto({
      ...item,
      authorPhoto: faces[index % Math.max(faces.length, 1)] || syntheticPhoto(item.authorPseudo),
    }, index));
  }

  async function loadPronoStatuses() {
    try {
      const data = await apiFetch(`prono-statuses-feed.php?limit=20&_=${Date.now()}`, { auth: true });
      const items = (Array.isArray(data?.items) ? data.items : []).map((item, index) => enrichPronoPhoto({ ...item, feedType: 'prono_status' }, index));
      return items.length ? items : samplePronoStatuses();
    } catch (error) {
      console.warn('Statuts prono indisponibles', error);
      return samplePronoStatuses();
    }
  }

  async function loadFeedNews() {
    const [batches, duelAudios, pronoStatuses] = await Promise.all([
      state.following.length ? Promise.all(state.following.map(loadNewsFor)) : Promise.resolve([]),
      loadDuelAudios(),
      loadPronoStatuses(),
    ]);
    state.pronoStatuses = pronoStatuses;
    const seen = new Set();
    state.news = [...batches.flat(), ...duelAudios].filter(item => {
      const key = item.feedType === 'duel_audio'
        ? `audio:${item.id}`
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

  function renderPronoStories() {
    const strip = $('#pronoStoriesStrip');
    const section = $('#pronoStoriesSection');
    if (!strip) return;
    if (!state.user) {
      if (section) section.classList.add('hidden');
      return;
    }
    if (section) section.classList.remove('hidden');
    if (!state.pronoStatuses.length) {
      strip.innerHTML = '<div class="prono-stories-empty">Aucun statut prono pour le moment · <a href="./pronostics.html" style="color:var(--lime);font-weight:900">Jouer</a></div>';
      return;
    }
    strip.innerHTML = state.pronoStatuses.map((item, index) => {
      const seen = state.seenPronos.has(String(item.id));
      return `<button type="button" class="prono-story" data-prono-diapo="${index}" aria-label="Voir le prono de ${attr(item.authorPseudo || 'membre')}">
        <span class="prono-story-ring ${seen ? 'seen' : ''}">${memberAvatarHtml(item.authorPseudo, item.authorPhoto)}</span>
        <span>${esc(item.authorPseudo || 'Membre')}</span>
      </button>`;
    }).join('');
  }

  function stopPronoDiapoTimer() {
    if (state.diapoTimer) {
      clearTimeout(state.diapoTimer);
      state.diapoTimer = null;
    }
  }

  function renderPronoDiapo() {
    const item = state.pronoStatuses[state.diapoIndex];
    if (!item) return closePronoDiapo();
    state.seenPronos.add(String(item.id));
    const odd = fmtOdd(item.odd);
    const payout = Math.round(Number(item.potentialPayout || 0));
    const cover = $('#pronoDiapoCover');
    if (cover) {
      cover.src = statusCoverSrc(item);
      cover.onerror = () => { cover.src = statusCoverDataUri(item, 'feed'); };
    }
    const author = $('#pronoDiapoAuthor');
    if (author) {
      author.innerHTML = `${memberAvatarHtml(item.authorPseudo, item.authorPhoto)}<div><strong>${esc(item.authorPseudo || 'Membre')}</strong><small>Statut · ${esc(item.durationHours)} h · expire ${esc(relativeDate(item.expiresAt))}</small></div>`;
    }
    const question = $('#pronoDiapoQuestion');
    if (question) question.textContent = item.questionTitle || 'Pronostic';
    const choice = $('#pronoDiapoChoice');
    if (choice) choice.textContent = item.optionLabel || item.optionKey || '—';
    const oddInline = $('#pronoDiapoOddInline');
    if (oddInline) oddInline.textContent = `@${odd}`;
    const ret = $('#pronoDiapoReturn');
    if (ret) ret.textContent = payout > 0 ? `Gain pot. ${payout} pts · sans argent réel` : 'Sans argent réel';
    const like = $('#pronoDiapoLike');
    if (like) {
      like.textContent = `${item.likedByMe ? '♥ Liké' : '♡ Like'} · ${item.likeCount || 0}`;
      like.classList.toggle('liked', Boolean(item.likedByMe));
      like.disabled = Boolean(item.sample || item.likedByMe);
    }
    const bars = $('#pronoDiapoBars');
    if (bars) {
      bars.innerHTML = state.pronoStatuses.map((_, index) => {
        const cls = index < state.diapoIndex ? 'done' : index === state.diapoIndex ? 'active' : '';
        return `<i class="${cls}"><b></b></i>`;
      }).join('');
    }
    renderPronoStories();
    stopPronoDiapoTimer();
    state.diapoTimer = setTimeout(() => nextPronoDiapo(), 6000);
  }

  function sharePronoStatus(item) {
    if (!item) return;
    const odd = fmtOdd(item.odd);
    const text = `Statut prono PASS50 — ${item.authorPseudo || 'Membre'} : ${item.questionTitle || 'Pronostic'} → ${item.optionLabel || item.optionKey || ''}${odd !== '—' ? ` @${odd}` : ''}\nSans argent réel · pass50.store/pronostics.html`;
    const url = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}pronostics.html`;
    // Hors app : on privilégie le texte + lien (carte partage = palette vive côté image si disponible)
    if (navigator.share) {
      const payload = { title: 'Statut prono PASS50', text, url };
      navigator.share(payload).catch(() => {});
      return;
    }
    navigator.clipboard?.writeText(`${text}\n${url}`).then(() => toast('Lien copié')).catch(() => toast(text));
  }

  function openPronoDiapo(index = 0) {
    if (!state.pronoStatuses.length) return;
    state.diapoIndex = Math.max(0, Math.min(index, state.pronoStatuses.length - 1));
    $('#pronoDiapo')?.classList.add('show');
    renderPronoDiapo();
  }

  function closePronoDiapo() {
    stopPronoDiapoTimer();
    $('#pronoDiapo')?.classList.remove('show');
    renderPronoStories();
  }

  function nextPronoDiapo() {
    if (state.diapoIndex >= state.pronoStatuses.length - 1) return closePronoDiapo();
    state.diapoIndex += 1;
    renderPronoDiapo();
  }

  function prevPronoDiapo() {
    if (state.diapoIndex <= 0) return;
    state.diapoIndex -= 1;
    renderPronoDiapo();
  }

  async function likeCurrentPronoDiapo() {
    const item = state.pronoStatuses[state.diapoIndex];
    if (!item || item.sample || item.likedByMe) return;
    try {
      const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
      const token = localStorage.getItem('pass50_api_token') || '';
      if (!token) return toast('Connecte-toi pour liker');
      headers.Authorization = `Bearer ${token}`;
      const response = await fetch('./api/prono-status-like.php', { method: 'POST', headers, body: JSON.stringify({ statusId: item.id }), cache: 'no-store' });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Like impossible');
      item.likedByMe = true;
      item.likeCount = Number(data.likeCount || item.likeCount || 0);
      renderPronoDiapo();
      toast(data.alreadyLiked ? 'Déjà liké' : 'Like envoyé · +0,25 pt pour l’auteur');
    } catch (error) {
      toast(error.message || 'Like impossible');
    }
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

  function feedCard(item) {
    if (item.feedType === 'duel_audio') return duelAudioCard(item);
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
    renderPronoStories();
    if (!state.user) {
      list.innerHTML = '<div class="empty"><strong>Connexion nécessaire.</strong>Connectez-vous pour retrouver les influenceurs que vous suivez.<div style="margin-top:13px"><a class="btn primary" href="./?open=account">Se connecter</a></div></div>';
      end.classList.add('hidden');
      return;
    }
    if (!state.following.length && !state.news.length) {
      list.innerHTML = '<div class="empty"><strong>Votre fil d’actualités est vide.</strong>Suis jusqu’à 5 influenceurs. Les statuts prono de la communauté restent au-dessus.<div style="margin-top:13px;display:flex;gap:8px;flex-wrap:wrap;justify-content:center"><a class="btn primary" href="./pronostics.html">Ouvrir Pronostics</a><a class="btn" href="./">Voir le classement</a></div></div>';
      end.classList.add('hidden');
      return;
    }
    if (!state.news.length) {
      list.innerHTML = '<div class="empty"><strong>Aucune actualité ou audio récent.</strong>Les statuts prono de la communauté sont au-dessus.<div style="margin-top:13px"><a class="btn primary" href="./pronostics.html">Voir les Pronostics</a></div></div>';
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
    if (state.user) await loadFeedNews();
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
    $('#pronoStoriesStrip')?.addEventListener('click', event => {
      const chip = event.target.closest('[data-prono-diapo]');
      if (!chip) return;
      openPronoDiapo(Number(chip.getAttribute('data-prono-diapo')));
    });
    $('#pronoDiapoPrev')?.addEventListener('click', prevPronoDiapo);
    $('#pronoDiapoNext')?.addEventListener('click', nextPronoDiapo);
    $('#pronoDiapoClose')?.addEventListener('click', closePronoDiapo);
    $('#pronoDiapoLike')?.addEventListener('click', likeCurrentPronoDiapo);
    $('#pronoDiapoShare')?.addEventListener('click', () => sharePronoStatus(state.pronoStatuses[state.diapoIndex]));
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        if ($('#pronoDiapo')?.classList.contains('show')) closePronoDiapo();
        else closeRadar();
      }
      if (!$('#pronoDiapo')?.classList.contains('show')) return;
      if (event.key === 'ArrowRight') nextPronoDiapo();
      if (event.key === 'ArrowLeft') prevPronoDiapo();
    });
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
