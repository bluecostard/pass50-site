'use strict';

(() => {
  const API = './api';
  const DEMO = new URLSearchParams(location.search).has('demo');
  const DIAPO_MS = 6000;
  const DIAPO_HOLD_MS = 200;
  const state = {
    items: [],
    themes: [],
    results: [],
    statuses: [],
    balance: { balance: 0, streak: 0 },
    auth: false,
    pendingQuestionId: null,
    durationHours: 24,
    demo: DEMO,
    diapoIndex: 0,
    diapoTimer: null,
    diapoPaused: false,
    diapoStartedAt: 0,
    diapoRemaining: DIAPO_MS,
    diapoHold: false,
    diapoHoldAt: 0,
    diapoHoldTimer: null,
    diapoSuppressClick: false,
    seen: new Set(JSON.parse(sessionStorage.getItem('p50_prono_seen') || '[]')),
  };
  const $ = (s) => document.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function initials(name) {
    const clean = String(name || 'P50').replace(/[^a-zA-Z0-9àâäéèêëïîôùûüç]/gi, ' ').trim();
    const parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return (clean.slice(0, 2) || 'P50').toUpperCase();
  }

  function hashHue(value) {
    let hash = 0;
    for (const char of String(value || '')) hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    return hash % 360;
  }

  function syntheticPhoto(pseudo) {
    const ini = initials(pseudo);
    const hue = hashHue(pseudo);
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="hsl(${hue} 58% 34%)"/><stop offset="1" stop-color="hsl(${(hue + 48) % 360} 42% 14%)"/></linearGradient></defs><rect width="128" height="128" rx="64" fill="url(#g)"/><text x="64" y="76" text-anchor="middle" fill="#f6f8f4" font-family="system-ui,sans-serif" font-size="42" font-weight="800">${ini}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
  }

  function storyAvatarHtml(item) {
    const src = String(item.authorPhoto || '').trim() || syntheticPhoto(item.authorPseudo);
    return `<span class="story-avatar"><img src="${esc(src)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.src='${esc(syntheticPhoto(item.authorPseudo))}'"></span>`;
  }

  function demoStatuses() {
    return [
      { id: 'st-1', authorPseudo: 'fan_abidjan', questionTitle: 'Himra — perte d’abonnés TikTok en 7 jours ?', optionLabel: '+ de 300 000', optionKey: 'b', odd: 2.1, stake: 100, potentialPayout: 210, likeCount: 18, likedByMe: false, durationHours: 24 },
      { id: 'st-2', authorPseudo: 'koffi_buzz', questionTitle: 'Josey finit-il dans le Top 3 PASS50 sur 24 h ?', optionLabel: 'Oui, Top 3', optionKey: 'yes', odd: 1.85, stake: 100, potentialPayout: 185, likeCount: 42, likedByMe: false, durationHours: 12 },
      { id: 'st-3', authorPseudo: 'aya_ci', questionTitle: 'Lo Père Daloa passera-t-il en LIVE sous 24 h ?', optionLabel: 'Oui', optionKey: 'y', odd: 1.7, stake: 100, potentialPayout: 170, likeCount: 7, likedByMe: true, durationHours: 48 },
      { id: 'st-4', authorPseudo: 'diaspora_tv', questionTitle: 'Himra — perte d’abonnés TikTok en 7 jours ?', optionLabel: '+ de 400 000', optionKey: 'a', odd: 3.4, stake: 100, potentialPayout: 340, likeCount: 11, likedByMe: false, durationHours: 24 },
      { id: 'st-5', authorPseudo: 'yves_rank', questionTitle: 'Josey finit-il dans le Top 3 PASS50 sur 24 h ?', optionLabel: 'Non, hors Top 3', optionKey: 'no', odd: 2.05, stake: 100, potentialPayout: 205, likeCount: 3, likedByMe: false, durationHours: 24 },
    ].map((item) => ({ ...item, authorPhoto: syntheticPhoto(item.authorPseudo), coverPhoto: statusCoverDataUri(item, 'feed') }));
  }

  function statusCoverDataUri(item, mode = 'feed') {
    const odd = fmtOdd(item.odd || 2);
    const hue = hashHue(item.questionTitle || item.authorPseudo);
    const title = String(item.optionLabel || 'PRONO').slice(0, 28);
    if (mode === 'share') {
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="720" height="900"><defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop stop-color="hsl(${hue} 55% 22%)"/><stop offset="1" stop-color="#050705"/></linearGradient></defs><rect width="720" height="900" fill="url(#bg)"/><circle cx="560" cy="160" r="180" fill="#b7ff00" opacity=".18"/><text x="48" y="110" fill="#b7ff00" font-family="Arial Black,Impact,sans-serif" font-size="30">PASS50</text><text x="48" y="400" fill="#b7ff00" font-family="Arial Black,Impact,sans-serif" font-size="72">${odd}</text><text x="48" y="450" fill="#f6f8f4" font-family="Arial,sans-serif" font-size="22" font-weight="700">COTE</text><text x="48" y="540" fill="#f6f8f4" font-family="Arial,sans-serif" font-size="36" font-weight="800">${title.replace(/[<>&]/g, '')}</text><text x="48" y="820" fill="#9da79b" font-family="Arial,sans-serif" font-size="20">Sans argent réel</text></svg>`;
      return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    }
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="720" height="900"><defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#151a15"/><stop offset="1" stop-color="#050705"/></linearGradient></defs><rect width="720" height="900" fill="url(#bg)"/><text x="48" y="120" fill="#6b7568" font-family="Arial,sans-serif" font-size="22" font-weight="800" letter-spacing="4">PASS50</text><text x="48" y="520" fill="#c9d2c4" font-family="Arial,sans-serif" font-size="40" font-weight="800">${title.replace(/[<>&]/g, '')}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
  }

  function statusCoverSrc(item) {
    const direct = String(item.coverPhoto || '').trim();
    if (direct && !direct.startsWith('data:image/svg')) return direct;
    return statusCoverDataUri(item, 'feed');
  }

  function demoFeed() {
    const closes12 = new Date(Date.now() + 12 * 3600000).toISOString();
    const measureFar = new Date(Date.now() + 180 * 86400000).toISOString();
    return {
      auth: true,
      balance: { balance: 1000, streak: 0, floor: 100 },
      themes: [
        { key: 'people_influenceurs', label: 'People influenceurs' },
        { key: 'people_artiste_sportif', label: 'People Artiste/sportif' },
        { key: 'people_actualite', label: 'People Actualité' },
      ],
      items: [
        {
          id: 'demo-1',
          theme: 'people_actualite',
          themeLabel: 'People Actualité',
          title: 'Himra — perte d’abonnés TikTok en 7 jours ?',
          context: 'Après la polémique de la semaine, quel scénario te semble le plus probable ?',
          stake: 100,
          options: [
            { key: 'a', label: '+ de 400 000', odd: 3.4, payout: 340, votePercent: 18, voteCount: 9 },
            { key: 'b', label: '+ de 300 000', odd: 2.1, payout: 210, votePercent: 41, voteCount: 21 },
            { key: 'c', label: 'moins de 250 000', odd: 1.65, payout: 165, votePercent: 41, voteCount: 21 },
          ],
          totalVotes: 51,
          closesAt: new Date(Date.now() + 6 * 3600000).toISOString(),
          measureAt: new Date(Date.now() + 7 * 86400000).toISOString(),
          pointsCorrect: 100,
          myVote: null,
        },
        {
          id: 'demo-2',
          theme: 'people_influenceurs',
          themeLabel: 'People influenceurs',
          title: 'Josey finit-il dans le Top 3 PASS50 sur 24 h ?',
          context: 'Classement public Côte d’Ivoire + diaspora.',
          stake: 100,
          options: [
            { key: 'yes', label: 'Oui, Top 3', odd: 1.85, payout: 185, votePercent: 62, voteCount: 31 },
            { key: 'no', label: 'Non, hors Top 3', odd: 2.05, payout: 205, votePercent: 38, voteCount: 19 },
          ],
          totalVotes: 50,
          closesAt: new Date(Date.now() + 24 * 3600000).toISOString(),
          measureAt: new Date(Date.now() + 2 * 86400000).toISOString(),
          pointsCorrect: 100,
          myVote: { optionKey: 'yes', oddLocked: 1.85, potentialPayout: 185 },
        },
        {
          id: 'demo-3',
          theme: 'people_artiste_sportif',
          themeLabel: 'People Artiste/sportif',
          title: 'Lo Père Daloa finit-il sa 2ᵉ maison dans 6 mois ?',
          context: 'Votes ouverts 12 h — mesure dans 6 mois.',
          stake: 100,
          options: [
            { key: 'y', label: 'Oui', odd: 1.55, payout: 155, votePercent: 0, voteCount: 0 },
            { key: 'n', label: 'Non', odd: 2.45, payout: 245, votePercent: 0, voteCount: 0 },
          ],
          totalVotes: 0,
          closesAt: closes12,
          measureAt: measureFar,
          pointsCorrect: 100,
          myVote: null,
        },
      ],
    };
  }

  function toast(msg) {
    const node = $('#toast');
    if (!node) return;
    node.textContent = msg;
    node.classList.add('show');
    clearTimeout(node._t);
    node._t = setTimeout(() => node.classList.remove('show'), 2400);
  }

  async function api(path, { method = 'GET', body = null, auth = true } = {}) {
    const headers = { Accept: 'application/json' };
    const token = localStorage.getItem('pass50_api_token') || '';
    if (auth && token) headers.Authorization = `Bearer ${token}`;
    if (body) headers['Content-Type'] = 'application/json';
    const res = await fetch(`${API}/${path.replace(/^\//, '')}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : null,
      cache: 'no-store',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Erreur réseau');
    return data;
  }

  function closesLabel(iso) {
    const ms = Date.parse(iso) - Date.now();
    if (!Number.isFinite(ms) || ms <= 0) return 'Votes clos';
    const d = Math.floor(ms / 86400000);
    const h = Math.floor((ms % 86400000) / 3600000);
    if (d >= 1) return `Votes clos dans ${d} j`;
    const m = Math.floor((ms % 3600000) / 60000);
    return h > 0 ? `Votes clos dans ${h} h ${m} min` : `Votes clos dans ${m} min`;
  }

  function measureLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (!Number.isFinite(d.getTime())) return '';
    return `Résolution le ${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })}`;
  }

  function timingMeta(item) {
    const parts = [closesLabel(item.closesAt)];
    const m = measureLabel(item.measureAt);
    if (m) parts.push(m);
    return parts.join(' · ');
  }

  function renderBalance() {
    $('#balPoints').textContent = String(Math.round(Number(state.balance.balance || 0) * 100) / 100);
    $('#balStreak').textContent = String(state.balance.streak || 0);
  }

  function fmtOdd(odd) {
    const n = Number(odd);
    return Number.isFinite(n) ? n.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') : '—';
  }

  function questionCoverSrc(item) {
    const direct = String(item.coverPhoto || '').trim();
    if (direct && /^https?:\/\//i.test(direct)) return direct;
    return statusCoverDataUri({ questionTitle: item.title || '', optionLabel: item.title || 'PRONO' }, 'feed');
  }

  function card(item) {
    const selected = item.myVote?.optionKey || '';
    const voted = Boolean(selected);
    const statusPublished = Boolean(item.statusPublished);
    const stake = item.stake || item.pointsCorrect || 100;
    const showTally = voted || Number(item.totalVotes || 0) > 0;
    const opts = (item.options || []).map((opt) => {
      const odd = opt.odd ?? 2;
      const payout = opt.payout ?? Math.round(stake * odd);
      const pct = Number(opt.votePercent || 0);
      const isSelected = selected === opt.key;
      const lockAttrs = voted ? ' disabled aria-disabled="true"' : ` data-vote="${esc(item.id)}" data-opt="${esc(opt.key)}"`;
      return `<button type="button" class="opt ${isSelected ? 'selected' : ''}${voted && !isSelected ? ' locked' : ''}"${lockAttrs}>
        <span>
          <span class="label">${esc(opt.label)}</span>
          ${showTally ? `<span class="pct">${esc(pct)}% · ${esc(opt.voteCount || 0)} joueurs</span><div class="bar"><i style="width:${Math.min(100, pct)}%"></i></div>` : ''}
        </span>
        <span class="odd-block"><span class="cote">${esc(fmtOdd(odd))}</span><span class="gain">+${esc(payout)} pts</span></span>
      </button>`;
    }).join('');
    const locked = item.myVote?.potentialPayout
      ? `Prono verrouillé · cote ${fmtOdd(item.myVote.oddLocked)} · mise ${item.myVote.stakeLocked ?? stake} · +${item.myVote.potentialPayout} si correct`
      : `Mise ${stake} pts · perdu = mise perdue (plancher 100) · gagné = mise × cote`;
    const publishBtn = statusPublished
      ? `<button type="button" class="btn" disabled>Statut publié</button>`
      : `<button type="button" class="btn primary" data-publish="${esc(item.id)}">Publier en statut</button>`;
    const cover = esc(questionCoverSrc(item));
    return `<article class="card${voted ? ' is-voted' : ''}" data-qid="${esc(item.id)}">
      <div class="card-media"><img src="${cover}" alt="" loading="lazy"></div>
      <p class="card-kicker">${esc(closesLabel(item.closesAt))}${item.totalVotes ? ` · ${esc(item.totalVotes)} joueurs` : ''}${voted ? ' · Pari validé' : ''}</p>
      <h2>${esc(item.title)}</h2>
      ${item.context ? `<div class="pub-context"><span class="pub-context-label">Contexte</span><p>${esc(item.context)}</p></div>` : ''}
      ${item.measureAt ? `<div class="pub-measure">${esc(measureLabel(item.measureAt))}</div>` : ''}
      <div class="opts">${opts}</div>
      <div class="meta">${esc(measureLabel(item.measureAt) || 'Résolution à la date de mesure')} · ${esc(locked)} · Sans argent réel</div>
      <div class="actions">
        ${voted ? `${publishBtn}
        <button type="button" class="btn" data-share="${esc(item.id)}">Partager</button>` : '<span class="meta" style="margin:0">Choisis une cote</span>'}
      </div>
    </article>`;
  }

  function renderStories() {
    const strip = $('#storiesStrip');
    if (!strip) return;
    if (!state.statuses.length) {
      strip.innerHTML = '<div class="empty" style="min-width:100%;padding:16px">Aucun statut prono en ce moment</div>';
      return;
    }
    strip.innerHTML = state.statuses.map((s, index) => {
      const seen = state.seen.has(s.id);
      return `<button type="button" class="story-chip" data-diapo="${index}">
        <span class="story-ring ${seen ? 'seen' : ''}">${storyAvatarHtml(s)}</span>
        <span>${esc(s.authorPseudo || 'Membre')}</span>
      </button>`;
    }).join('');
  }

  function markSeen(id) {
    state.seen.add(id);
    sessionStorage.setItem('p50_prono_seen', JSON.stringify([...state.seen].slice(-80)));
  }

  function stopDiapoTimer() {
    if (state.diapoTimer) {
      clearTimeout(state.diapoTimer);
      state.diapoTimer = null;
    }
    if (state.diapoHoldTimer) {
      clearTimeout(state.diapoHoldTimer);
      state.diapoHoldTimer = null;
    }
  }

  function setDiapoBarPaused(paused) {
    const root = $('#diapo');
    root?.classList.toggle('is-paused', paused);
    root?.querySelector('#diapoBars i.active')?.classList.toggle('paused', paused);
  }

  function pauseDiapo() {
    if (state.diapoPaused || !$('#diapo')?.classList.contains('show')) return;
    state.diapoPaused = true;
    if (state.diapoTimer) {
      clearTimeout(state.diapoTimer);
      state.diapoTimer = null;
    }
    const elapsed = Date.now() - (state.diapoStartedAt || Date.now());
    state.diapoRemaining = Math.max(120, (state.diapoRemaining || DIAPO_MS) - elapsed);
    setDiapoBarPaused(true);
  }

  function resumeDiapo() {
    if (!state.diapoPaused || !$('#diapo')?.classList.contains('show')) return;
    state.diapoPaused = false;
    setDiapoBarPaused(false);
    state.diapoStartedAt = Date.now();
    state.diapoTimer = setTimeout(() => nextDiapo(), state.diapoRemaining || DIAPO_MS);
  }

  function armDiapoTimer(ms = DIAPO_MS) {
    stopDiapoTimer();
    state.diapoPaused = false;
    state.diapoHold = false;
    state.diapoRemaining = ms;
    state.diapoStartedAt = Date.now();
    setDiapoBarPaused(false);
    state.diapoTimer = setTimeout(() => nextDiapo(), ms);
  }

  function renderDiapo() {
    const item = state.statuses[state.diapoIndex];
    if (!item) return closeDiapo();
    markSeen(item.id);
    const odd = fmtOdd(item.odd);
    const payout = Math.round(Number(item.potentialPayout || (Number(item.stake || 100) * Number(item.odd || 0))) || 0);
    const cover = $('#diapoCover');
    if (cover) {
      cover.src = statusCoverSrc(item);
      cover.onerror = () => { cover.src = statusCoverDataUri(item, 'feed'); };
    }
    $('#diapoAuthor').innerHTML = `${storyAvatarHtml(item)}<div>${esc(item.authorPseudo || 'Membre')}<small>Statut · ${esc(item.durationHours)} h</small></div>`;
    $('#diapoQuestion').textContent = item.questionTitle || 'Pronostic';
    $('#diapoChoice').textContent = item.optionLabel || item.optionKey || '—';
    const oddInline = $('#diapoOddInline');
    if (oddInline) oddInline.textContent = `x ${odd}`;
    const ret = $('#diapoReturn');
    if (ret) ret.textContent = payout > 0 ? `Gain pot. ${payout} pts · sans argent réel` : 'Sans argent réel';
    const like = $('#diapoLike');
    if (like) like.textContent = `${item.likedByMe ? '♥ Liké' : '♡ Like'} · ${item.likeCount || 0}`;
    const bars = $('#diapoBars');
    bars.innerHTML = state.statuses.map((_, i) => {
      const cls = i < state.diapoIndex ? 'done' : i === state.diapoIndex ? 'active' : '';
      return `<i class="${cls}"><b></b></i>`;
    }).join('');
    renderStories();
    armDiapoTimer(DIAPO_MS);
  }

  function shareStatus(item) {
    if (!item) return;
    const odd = Number(item.odd) || 0;
    const stake = Number(item.stake) || 100;
    const payout = Number(item.potentialPayout) || Math.round(stake * odd);
    const url = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}pronostics.html`;
    if (window.PASS50_PRONO_SHARE?.open) {
      window.PASS50_PRONO_SHARE.open({
        mode: 'status',
        title: item.questionTitle || 'Pronostic',
        choice: item.optionLabel || item.optionKey || '—',
        odd,
        stake,
        payout,
        author: item.authorPseudo || '',
        authorPhoto: item.authorPhoto || '',
        profileId: item.profileId || '',
        coverPhoto: statusCoverSrc(item),
        durationHours: item.durationHours || 24,
        url,
      }).catch(() => toast('Partage indisponible'));
      return;
    }
    const text = `Statut prono PASS50 — ${item.authorPseudo || 'Membre'} : ${item.questionTitle || 'Pronostic'} → ${item.optionLabel || item.optionKey || ''}${odd ? ` x ${fmtOdd(odd)}` : ''}\nSans argent réel\n\nJoue aussi sur PASS50 👇\n${url}`;
    if (navigator.share) {
      navigator.share({ title: 'Statut prono PASS50', text, url }).catch(() => {});
      return;
    }
    navigator.clipboard?.writeText(`${text}`).then(() => toast('Lien copié')).catch(() => toast(text));
  }

  function openDiapo(index = 0) {
    if (!state.statuses.length) return;
    state.diapoIndex = Math.max(0, Math.min(index, state.statuses.length - 1));
    $('#diapo')?.classList.add('show');
    renderDiapo();
  }

  function closeDiapo() {
    stopDiapoTimer();
    state.diapoPaused = false;
    state.diapoHold = false;
    state.diapoSuppressClick = false;
    setDiapoBarPaused(false);
    $('#diapo')?.classList.remove('show');
    renderStories();
  }

  function installDiapoHold() {
    const frame = document.querySelector('#diapoFrame') || document.querySelector('#diapo .diapo-frame');
    if (!frame || frame.dataset.holdBound === '1') return;
    frame.dataset.holdBound = '1';

    const isUi = (target) => {
      const el = target?.closest?.('button, a, .btn, input, textarea, select');
      if (!el) return false;
      if (el.id === 'diapoPrev' || el.id === 'diapoNext') return false;
      return true;
    };

    const endHold = (event) => {
      if (!state.diapoHold) return;
      const heldFor = Date.now() - (state.diapoHoldAt || 0);
      state.diapoHold = false;
      if (state.diapoHoldTimer) {
        clearTimeout(state.diapoHoldTimer);
        state.diapoHoldTimer = null;
      }
      try {
        if (event?.pointerId != null) frame.releasePointerCapture(event.pointerId);
      } catch (_) {}
      if (state.diapoPaused) {
        resumeDiapo();
        if (heldFor >= DIAPO_HOLD_MS) state.diapoSuppressClick = true;
      }
    };

    frame.addEventListener('pointerdown', (event) => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      if (isUi(event.target)) return;
      if (!$('#diapo')?.classList.contains('show')) return;
      state.diapoHold = true;
      state.diapoHoldAt = Date.now();
      try { frame.setPointerCapture(event.pointerId); } catch (_) {}
      if (state.diapoHoldTimer) clearTimeout(state.diapoHoldTimer);
      state.diapoHoldTimer = setTimeout(() => {
        if (state.diapoHold) pauseDiapo();
      }, DIAPO_HOLD_MS);
    });
    frame.addEventListener('pointerup', endHold);
    frame.addEventListener('pointercancel', endHold);
    frame.addEventListener('click', (event) => {
      if (!state.diapoSuppressClick) return;
      state.diapoSuppressClick = false;
      event.preventDefault();
      event.stopPropagation();
    }, true);
  }

  function nextDiapo() {
    if (state.diapoIndex >= state.statuses.length - 1) return closeDiapo();
    state.diapoIndex += 1;
    renderDiapo();
  }

  function prevDiapo() {
    if (state.diapoIndex <= 0) return;
    state.diapoIndex -= 1;
    renderDiapo();
  }

  async function likeCurrentDiapo() {
    const item = state.statuses[state.diapoIndex];
    if (!item) return;
    if (state.demo) {
      item.likedByMe = !item.likedByMe;
      item.likeCount = Math.max(0, Number(item.likeCount || 0) + (item.likedByMe ? 1 : -1));
      renderDiapo();
      toast(item.likedByMe ? '+0,25 pt pour l’auteur (démo)' : 'Like retiré (démo)');
      return;
    }
    try {
      const data = await api('prono-status-like.php', { method: 'POST', body: { statusId: item.id } });
      item.likedByMe = Boolean(data.liked);
      item.likeCount = Number(data.likeCount || item.likeCount || 0);
      renderDiapo();
      toast(item.likedByMe ? 'Like envoyé' : 'Like retiré');
    } catch (error) {
      toast(error.message);
    }
  }

  function resultCard(item) {
    const mine = item.options?.find((o) => o.key === item.myVote?.optionKey)?.label || item.myVote?.optionKey || '—';
    const win = item.options?.find((o) => o.key === item.winningOptionKey)?.label || item.winningOptionKey || '—';
    const badge = item.won ? `+${item.pointsEarned || 0} pts` : '0 pt';
    return `<article class="card">
      <p class="card-kicker">${item.won ? 'Bon prono' : 'À côté'} · ${esc(badge)}</p>
      <h2>${esc(item.title)}</h2>
      <div class="ctx">Ton choix : ${esc(mine)} · Gagnant : ${esc(win)}</div>
      <div class="meta">Sans argent réel</div>
    </article>`;
  }

  function renderResults() {
    const list = $('#resultsList');
    if (!list) return;
    if (!state.auth && !state.demo) {
      list.innerHTML = '';
      return;
    }
    if (!state.results.length) {
      list.innerHTML = '<div class="empty"><strong>Pas encore de résultat</strong><div>Les points arrivent à la date de mesure.</div></div>';
      return;
    }
    list.innerHTML = state.results.map(resultCard).join('');
  }

  const THEME_ORDER = [
    { key: 'people_influenceurs', label: 'People influenceurs' },
    { key: 'people_artiste_sportif', label: 'People Artiste/sportif' },
    { key: 'people_actualite', label: 'People Actualité' },
  ];

  function themeSections(items) {
    const catalog = Array.isArray(state.themes) && state.themes.length
      ? state.themes.map((t) => ({ key: t.key, label: t.label || t.key }))
      : THEME_ORDER;
    const byKey = {};
    items.forEach((item) => {
      const key = String(item.theme || '').trim() || '_other';
      if (!byKey[key]) byKey[key] = [];
      byKey[key].push(item);
    });
    const sections = [];
    catalog.forEach((theme) => {
      const list = byKey[theme.key] || [];
      if (!list.length) return;
      sections.push({ key: theme.key, label: theme.label, items: list });
      delete byKey[theme.key];
    });
    Object.keys(byKey).forEach((key) => {
      const list = byKey[key];
      if (!list.length) return;
      const label = key === '_other'
        ? 'Autres pronos'
        : (list[0].themeLabel || key);
      sections.push({ key, label, items: list });
    });
    return sections;
  }

  function renderList() {
    const list = $('#pronoList');
    const gate = $('#authGate');
    if (!state.auth && !state.demo) {
      gate?.classList.remove('hidden');
    } else {
      gate?.classList.add('hidden');
    }
    if (!state.auth && !state.demo) {
      list.innerHTML = '';
      return;
    }
    if (!state.items.length) {
      list.innerHTML = '<div class="empty"><strong>Aucun pronostic pour le moment.</strong><div>Reviens bientôt — PASS50 publie Qui fait quoi sur l’actu.</div></div>';
      return;
    }
    const sections = themeSections(state.items);
    if (sections.length <= 1) {
      list.innerHTML = state.items.map(card).join('');
      return;
    }
    list.innerHTML = sections.map((section, index) => `
      <div class="theme-block" data-theme="${esc(section.key)}">
        <div class="theme-head">
          <h3 class="theme-title">${index + 1}. ${esc(section.label)}</h3>
          <span class="theme-count">${section.items.length} prono${section.items.length > 1 ? 's' : ''}</span>
        </div>
        ${section.items.map(card).join('')}
      </div>
    `).join('');
  }

  async function loadResults() {
    if (state.demo) {
      state.results = [];
      renderResults();
      return;
    }
    if (!state.auth) {
      state.results = [];
      renderResults();
      return;
    }
    try {
      const data = await api('prono-results.php', { auth: true });
      state.results = Array.isArray(data.items) ? data.items : [];
      if (data.balance) state.balance = data.balance;
      renderBalance();
      renderResults();
    } catch (_) {
      state.results = [];
      renderResults();
    }
  }

  async function loadStatuses() {
    if (state.demo) {
      state.statuses = demoStatuses();
      renderStories();
      return;
    }
    try {
      const data = await api('prono-statuses-feed.php?limit=30', { auth: true });
      const items = Array.isArray(data.items) ? data.items : [];
      state.statuses = (items.length ? items : demoStatuses().map((s) => ({ ...s, sample: true }))).map((s) => ({
        ...s,
        authorPhoto: String(s.authorPhoto || '').trim() || syntheticPhoto(s.authorPseudo),
        coverPhoto: String(s.coverPhoto || '').trim() || statusCoverDataUri(s, 'feed'),
        odd: Number(s.odd) || 2,
        stake: Number(s.stake) || 100,
        potentialPayout: Number(s.potentialPayout) || Math.round((Number(s.stake) || 100) * (Number(s.odd) || 2)),
      }));
      renderStories();
    } catch (_) {
      state.statuses = demoStatuses().map((s) => ({ ...s, sample: true }));
      renderStories();
    }
  }

  async function loadFeed() {
    if (state.demo) {
      const data = demoFeed();
      state.auth = true;
      state.items = data.items;
      state.themes = data.themes || [];
      state.balance = data.balance;
      renderBalance();
      renderList();
      await loadStatuses();
      await loadResults();
      return;
    }
    try {
      const data = await api('prono-feed.php', { auth: true });
      state.auth = Boolean(data.auth);
      state.items = Array.isArray(data.items) ? data.items : [];
      state.themes = Array.isArray(data.themes) ? data.themes : [];
      state.balance = data.balance || state.balance;
      renderBalance();
      renderList();
    } catch (error) {
      $('#pronoList').innerHTML = `<div class="error"><strong>Indisponible</strong><div>${esc(error.message)}</div></div>`;
    }
    await loadStatuses();
    await loadResults();
  }


  function openStatusModal() {
    document.body.classList.add('p50-status-open');
    $('#statusModal')?.classList.add('show');
  }

  function closeStatusModal() {
    document.body.classList.remove('p50-status-open');
    $('#statusModal')?.classList.remove('show');
  }

  async function vote(questionId, optionKey) {
    const existing = state.items.find((row) => row.id === questionId);
    if (existing?.myVote) {
      toast('Prono déjà validé — modification impossible');
      return;
    }
    if (state.demo) {
      const item = state.items.find((row) => row.id === questionId);
      if (item) {
        const opt = (item.options || []).find((o) => o.key === optionKey);
        const odd = Number(opt?.odd || 2);
        const payout = opt?.payout ?? Math.round((item.stake || 100) * odd);
        item.myVote = { optionKey, oddLocked: odd, potentialPayout: payout, updatedAt: new Date().toISOString() };
        item.totalVotes = Number(item.totalVotes || 0) + 1;
        renderList();
        toast(`Prono enregistré · cote ${fmtOdd(odd)} · +${payout} pts si correct`);
      }
      state.pendingQuestionId = questionId;
      openStatusModal();
      return;
    }
    try {
      const data = await api('prono-vote.php', { method: 'POST', body: { questionId, optionKey } });
      state.balance = data.balance || state.balance;
      const item = state.items.find((row) => row.id === questionId);
      if (item) {
        item.myVote = {
          optionKey,
          oddLocked: data.oddLocked,
          stakeLocked: data.stakeLocked,
          potentialPayout: data.potentialPayout,
          updatedAt: new Date().toISOString(),
        };
        item.statusPublished = false;
        if (Array.isArray(data.tallies)) {
          item.totalVotes = data.totalVotes || 0;
          const byKey = Object.fromEntries(data.tallies.map((t) => [t.key, t]));
          item.options = (item.options || []).map((opt) => ({
            ...opt,
            voteCount: byKey[opt.key]?.count || 0,
            votePercent: byKey[opt.key]?.percent || 0,
          }));
        }
      }
      renderBalance();
      renderList();
      toast(data.message || 'Prono enregistré');
      state.pendingQuestionId = questionId;
      openStatusModal();
    } catch (error) {
      toast(error.message);
    }
  }

  async function publishStatus() {
    if (!state.pendingQuestionId) return;
    const qid = state.pendingQuestionId;
    if (state.demo) {
      const item = state.items.find((row) => row.id === qid);
      if (item) item.statusPublished = true;
      closeStatusModal();
      toast(`Statut démo publié · ${state.durationHours} h · visible dans Mon fil`);
      state.pendingQuestionId = null;
      renderList();
      return;
    }
    try {
      await api('prono-status-publish.php', {
        method: 'POST',
        body: { questionId: qid, durationHours: state.durationHours },
      });
      const item = state.items.find((row) => row.id === qid);
      if (item) item.statusPublished = true;
      closeStatusModal();
      toast('Statut publié dans Mon fil');
      state.pendingQuestionId = null;
      renderList();
      await loadStatuses();
    } catch (error) {
      toast(error.message);
    }
  }

  function shareProno(questionId) {
    const item = state.items.find((row) => row.id === questionId);
    if (!item) return;
    const opt = item.options?.find((o) => o.key === item.myVote?.optionKey);
    const cote = Number(item.myVote?.oddLocked ?? opt?.odd) || 0;
    const stake = Number(item.myVote?.stakeLocked ?? item.stake ?? item.pointsCorrect ?? 100) || 100;
    const payout = Number(item.myVote?.potentialPayout ?? opt?.payout) || Math.round(stake * cote);
    const url = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}pronostics.html`;
    if (window.PASS50_PRONO_SHARE?.open) {
      window.PASS50_PRONO_SHARE.open({
        mode: 'mine',
        title: item.title || 'Pronostic',
        choice: opt?.label || item.myVote?.optionKey || '—',
        odd: cote,
        stake,
        payout,
        url,
      }).catch(() => toast('Partage indisponible'));
      return;
    }
    const text = `Mon prono PASS50 : ${item.title}${opt ? ` → ${opt.label}` : ''}${cote ? ` @${fmtOdd(cote)}` : ''}\nSans argent réel · pass50.store/pronostics.html`;
    if (navigator.share) {
      navigator.share({ title: 'Prono PASS50', text, url }).catch(() => {});
      return;
    }
    navigator.clipboard?.writeText(`${text}\n${url}`).then(() => toast('Lien copié')).catch(() => toast(text));
  }

  function bind() {
    $('#pronoList')?.addEventListener('click', (event) => {
      const voteBtn = event.target.closest('[data-vote]');
      if (voteBtn) {
        vote(voteBtn.getAttribute('data-vote'), voteBtn.getAttribute('data-opt'));
        return;
      }
      const publish = event.target.closest('[data-publish]');
      if (publish) {
        state.pendingQuestionId = publish.getAttribute('data-publish');
        openStatusModal();
        return;
      }
      const share = event.target.closest('[data-share]');
      if (share) shareProno(share.getAttribute('data-share'));
    });
    $('#storiesStrip')?.addEventListener('click', (event) => {
      const chip = event.target.closest('[data-diapo]');
      if (!chip) return;
      openDiapo(Number(chip.getAttribute('data-diapo')));
    });
    $('#diapoPrev')?.addEventListener('click', prevDiapo);
    $('#diapoNext')?.addEventListener('click', nextDiapo);
    $('#diapoClose')?.addEventListener('click', closeDiapo);
    $('#diapoLike')?.addEventListener('click', likeCurrentDiapo);
    $('#diapoShare')?.addEventListener('click', () => shareStatus(state.statuses[state.diapoIndex]));
    installDiapoHold();
    document.addEventListener('keydown', (event) => {
      if (!$('#diapo')?.classList.contains('show')) return;
      if (event.key === 'Escape') closeDiapo();
      if (event.key === 'ArrowRight') nextDiapo();
      if (event.key === 'ArrowLeft') prevDiapo();
    });
    $('#durationChoices')?.addEventListener('click', (event) => {
      const btn = event.target.closest('button[data-h]');
      if (!btn) return;
      state.durationHours = Number(btn.getAttribute('data-h'));
      [...$('#durationChoices').querySelectorAll('button')].forEach((node) => node.classList.toggle('active', node === btn));
    });
    $('#confirmStatus')?.addEventListener('click', publishStatus);
    $('#skipStatus')?.addEventListener('click', () => {
      closeStatusModal();
      state.pendingQuestionId = null;
    });
    $('#statusModal')?.addEventListener('click', (event) => {
      if (event.target.id === 'statusModal') closeStatusModal();
    });
  }

  bind();
  loadFeed();
})();
