'use strict';

(() => {
  const API = './api';
  const DEMO = new URLSearchParams(location.search).has('demo');
  const state = {
    items: [],
    results: [],
    statuses: [],
    balance: { balance: 0, streak: 0 },
    auth: false,
    pendingQuestionId: null,
    durationHours: 24,
    demo: DEMO,
    diapoIndex: 0,
    diapoTimer: null,
    seen: new Set(JSON.parse(sessionStorage.getItem('p50_prono_seen') || '[]')),
  };
  const $ = (s) => document.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function initials(name) {
    return String(name || 'P50').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || 'P50';
  }

  function demoStatuses() {
    return [
      { id: 'st-1', authorPseudo: 'fan_abidjan', questionTitle: 'Himra — perte d’abonnés TikTok en 7 jours ?', optionLabel: '+ de 300 000', likeCount: 18, likedByMe: false, durationHours: 24 },
      { id: 'st-2', authorPseudo: 'koffi_buzz', questionTitle: 'Josey finit-il dans le Top 3 PASS50 sur 24 h ?', optionLabel: 'Oui, Top 3', likeCount: 42, likedByMe: false, durationHours: 12 },
      { id: 'st-3', authorPseudo: 'aya_ci', questionTitle: 'Lo Père Daloa passera-t-il en LIVE sous 24 h ?', optionLabel: 'Oui', likeCount: 7, likedByMe: true, durationHours: 48 },
      { id: 'st-4', authorPseudo: 'diaspora_tv', questionTitle: 'Himra — perte d’abonnés TikTok en 7 jours ?', optionLabel: '+ de 400 000', likeCount: 11, likedByMe: false, durationHours: 24 },
      { id: 'st-5', authorPseudo: 'yves_rank', questionTitle: 'Josey finit-il dans le Top 3 PASS50 sur 24 h ?', optionLabel: 'Non, hors Top 3', likeCount: 3, likedByMe: false, durationHours: 24 },
    ];
  }

  function demoFeed() {
    const closes3 = new Date(Date.now() + 3 * 86400000).toISOString();
    const measureFar = new Date(Date.now() + 180 * 86400000).toISOString();
    return {
      auth: true,
      balance: { balance: 12450.5, streak: 4 },
      items: [
        {
          id: 'demo-1',
          title: 'Himra — perte d’abonnés TikTok en 7 jours ?',
          context: 'Après la polémique de la semaine, quel scénario te semble le plus probable ?',
          options: [
            { key: 'a', label: '+ de 400 000' },
            { key: 'b', label: '+ de 300 000' },
            { key: 'c', label: 'moins de 250 000' },
          ],
          closesAt: new Date(Date.now() + 7 * 86400000).toISOString(),
          measureAt: new Date(Date.now() + 7 * 86400000).toISOString(),
          pointsCorrect: 500,
          myVote: null,
        },
        {
          id: 'demo-2',
          title: 'Josey finit-il dans le Top 3 PASS50 sur 24 h ?',
          context: 'Classement public Côte d’Ivoire + diaspora.',
          options: [
            { key: 'yes', label: 'Oui, Top 3' },
            { key: 'no', label: 'Non, hors Top 3' },
          ],
          closesAt: new Date(Date.now() + 2 * 86400000).toISOString(),
          measureAt: new Date(Date.now() + 2 * 86400000).toISOString(),
          pointsCorrect: 500,
          myVote: { optionKey: 'yes' },
        },
        {
          id: 'demo-3',
          title: 'Lo Père Daloa finit-il sa 2ᵉ maison dans 6 mois ?',
          context: 'Votes ouverts 3 jours — mesure dans 6 mois.',
          options: [
            { key: 'y', label: 'Oui' },
            { key: 'n', label: 'Non' },
          ],
          closesAt: closes3,
          measureAt: measureFar,
          pointsCorrect: 500,
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

  function card(item) {
    const selected = item.myVote?.optionKey || '';
    const opts = (item.options || []).map((opt) =>
      `<button type="button" class="opt ${selected === opt.key ? 'selected' : ''}" data-vote="${esc(item.id)}" data-opt="${esc(opt.key)}">${esc(opt.label)}</button>`
    ).join('');
    const voted = Boolean(selected);
    return `<article class="card" data-qid="${esc(item.id)}">
      <h2>${esc(item.title)}</h2>
      ${item.context ? `<div class="ctx">${esc(item.context)}</div>` : ''}
      <div class="opts">${opts}</div>
      <div class="meta">${esc(timingMeta(item))} · +${esc(item.pointsCorrect || 500)} pts si correct · Sans argent réel</div>
      <div class="actions">
        ${voted ? `<button type="button" class="btn primary" data-publish="${esc(item.id)}">Publier en statut</button>
        <button type="button" class="btn" data-share="${esc(item.id)}">Partager</button>` : '<span class="meta">Choisis une réponse</span>'}
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
        <span class="story-ring ${seen ? 'seen' : ''}"><span class="story-avatar">${esc(initials(s.authorPseudo))}</span></span>
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
  }

  function renderDiapo() {
    const item = state.statuses[state.diapoIndex];
    if (!item) return closeDiapo();
    markSeen(item.id);
    $('#diapoAuthor').innerHTML = `${esc(item.authorPseudo || 'Membre')}<small>Prono · ${esc(item.durationHours)} h</small>`;
    $('#diapoQuestion').textContent = item.questionTitle || 'Pronostic';
    $('#diapoChoice').textContent = item.optionLabel || item.optionKey || '—';
    $('#diapoLike').textContent = `${item.likedByMe ? '♥ Liké' : '♡ Like'} · ${item.likeCount || 0}`;
    $('#diapoMeta').textContent = 'Sans argent réel · sans abonnement';
    const bars = $('#diapoBars');
    bars.innerHTML = state.statuses.map((_, i) => {
      const cls = i < state.diapoIndex ? 'done' : i === state.diapoIndex ? 'active' : '';
      return `<i class="${cls}"><b></b></i>`;
    }).join('');
    renderStories();
    stopDiapoTimer();
    state.diapoTimer = setTimeout(() => nextDiapo(), 5000);
  }

  function openDiapo(index = 0) {
    if (!state.statuses.length) return;
    state.diapoIndex = Math.max(0, Math.min(index, state.statuses.length - 1));
    $('#diapo')?.classList.add('show');
    renderDiapo();
  }

  function closeDiapo() {
    stopDiapoTimer();
    $('#diapo')?.classList.remove('show');
    renderStories();
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
    if (!item || item.likedByMe) return;
    if (state.demo) {
      item.likedByMe = true;
      item.likeCount = Number(item.likeCount || 0) + 1;
      renderDiapo();
      toast('+0,25 pt pour l’auteur (démo)');
      return;
    }
    try {
      const data = await api('prono-status-like.php', { method: 'POST', body: { statusId: item.id } });
      item.likedByMe = true;
      item.likeCount = Number(data.likeCount || item.likeCount || 0);
      renderDiapo();
      toast(data.alreadyLiked ? 'Déjà liké' : 'Like envoyé');
    } catch (error) {
      toast(error.message);
    }
  }

  function resultCard(item) {
    const mine = item.options?.find((o) => o.key === item.myVote?.optionKey)?.label || item.myVote?.optionKey || '—';
    const win = item.options?.find((o) => o.key === item.winningOptionKey)?.label || item.winningOptionKey || '—';
    const badge = item.won ? `+${item.pointsEarned || 0} pts` : '0 pt';
    return `<article class="card">
      <h2>${esc(item.title)}</h2>
      <div class="ctx">Ton choix : ${esc(mine)} · Gagnant : ${esc(win)}</div>
      <div class="meta">${esc(badge)} · ${item.won ? 'Bon prono' : 'À côté'} · Sans argent réel</div>
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
      list.innerHTML = '<div class="empty"><strong>Aucun prono ouvert</strong><div>Reviens bientôt — PASS50 publie les questions sur l’actu.</div></div>';
      return;
    }
    list.innerHTML = state.items.map(card).join('');
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
      state.statuses = Array.isArray(data.items) ? data.items : [];
      renderStories();
    } catch (_) {
      state.statuses = [];
      renderStories();
    }
  }

  async function loadFeed() {
    if (state.demo) {
      const data = demoFeed();
      state.auth = true;
      state.items = data.items;
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
      state.balance = data.balance || state.balance;
      renderBalance();
      renderList();
    } catch (error) {
      $('#pronoList').innerHTML = `<div class="error"><strong>Indisponible</strong><div>${esc(error.message)}</div></div>`;
    }
    await loadStatuses();
    await loadResults();
  }

  async function vote(questionId, optionKey) {
    if (state.demo) {
      const item = state.items.find((row) => row.id === questionId);
      if (item) item.myVote = { optionKey, updatedAt: new Date().toISOString() };
      renderList();
      toast('Prono enregistré (démo) — sans argent réel');
      state.pendingQuestionId = questionId;
      $('#statusModal')?.classList.add('show');
      return;
    }
    try {
      const data = await api('prono-vote.php', { method: 'POST', body: { questionId, optionKey } });
      state.balance = data.balance || state.balance;
      const item = state.items.find((row) => row.id === questionId);
      if (item) item.myVote = { optionKey, updatedAt: new Date().toISOString() };
      renderBalance();
      renderList();
      toast(data.message || 'Prono enregistré');
      state.pendingQuestionId = questionId;
      $('#statusModal')?.classList.add('show');
    } catch (error) {
      toast(error.message);
    }
  }

  async function publishStatus() {
    if (!state.pendingQuestionId) return;
    if (state.demo) {
      $('#statusModal')?.classList.remove('show');
      toast(`Statut démo publié · ${state.durationHours} h · visible dans Mon fil`);
      state.pendingQuestionId = null;
      return;
    }
    try {
      await api('prono-status-publish.php', {
        method: 'POST',
        body: { questionId: state.pendingQuestionId, durationHours: state.durationHours },
      });
      $('#statusModal')?.classList.remove('show');
      toast('Statut publié dans Mon fil');
      state.pendingQuestionId = null;
      await loadStatuses();
    } catch (error) {
      toast(error.message);
    }
  }

  function shareProno(questionId) {
    const item = state.items.find((row) => row.id === questionId);
    if (!item) return;
    const text = `Mon prono PASS50 : ${item.title}${item.myVote ? ` → ${item.options.find((o) => o.key === item.myVote.optionKey)?.label || ''}` : ''}\nSans argent réel · pass50.store/pronostics.html`;
    const url = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}pronostics.html`;
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
        $('#statusModal')?.classList.add('show');
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
      $('#statusModal')?.classList.remove('show');
      state.pendingQuestionId = null;
    });
    $('#statusModal')?.addEventListener('click', (event) => {
      if (event.target.id === 'statusModal') $('#statusModal').classList.remove('show');
    });
  }

  bind();
  loadFeed();
})();
