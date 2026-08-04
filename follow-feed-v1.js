'use strict';

(() => {
  const CONTRACT = 'PASS50-FOLLOW-WATCH-V1.0';
  const MAX_FOLLOWED = 5;
  const state = { news: new Map(), loading: false };
  const PERIOD_MAP = { '2H': '2h', '24H': '24h', '48H': '48h', '7J': '7d', '15J': '15d' };
  const PERIOD_LABELS = { '2H': '2 h', '24H': '24 h', '48H': '48 h', '7J': '7 jours', '15J': '15 jours' };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const attr = value => esc(value).replace(/`/g, '&#96;');

  function getUser() {
    try {
      if (typeof userPrefs === 'function') return userPrefs();
      if (typeof currentUser === 'function') return currentUser();
    } catch (_) {}
    return null;
  }

  function getProfile(profileId) {
    try {
      if (typeof profile === 'function') return profile(profileId);
      if (typeof db !== 'undefined' && Array.isArray(db.profiles)) return db.profiles.find(item => item.id === profileId) || null;
    } catch (_) {}
    return null;
  }

  function currentPeriod() {
    try {
      return typeof ui !== 'undefined' && PERIOD_MAP[ui.period] ? ui.period : '24H';
    } catch (_) {
      return '24H';
    }
  }

  function scoreFor(profileItem, period = currentPeriod()) {
    const value = Number(profileItem?.scores?.[period] ?? profileItem?.scores?.['24H'] ?? 0);
    return Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
  }

  function rankingSnapshot() {
    try {
      if (typeof completeRanking === 'function') return completeRanking();
      if (typeof ranking === 'function') return ranking();
    } catch (_) {}
    return [];
  }

  function rankFor(profileId, snapshot) {
    const index = snapshot.findIndex(item => item?.id === profileId);
    if (index < 0) return null;
    const item = snapshot[index];
    try {
      if (typeof isClassableProfile === 'function' && !isClassableProfile(item)) return null;
    } catch (_) {}
    return index + 1;
  }

  function movementFor(profileItem) {
    const delta = Number(profileItem?.delta || 0);
    if (delta > 0) return { className: 'up', text: `▲ +${delta}` };
    if (delta < 0) return { className: 'down', text: `▼ ${Math.abs(delta)}` };
    return { className: 'flat', text: '— stable' };
  }

  function activeLiveFor(profileId) {
    try {
      if (typeof activeLives !== 'function') return null;
      return activeLives().find(item => item?.profileId === profileId) || null;
    } catch (_) {
      return null;
    }
  }

  function liveUrl(item) {
    try {
      if (typeof liveWatchUrl === 'function') return liveWatchUrl(item);
    } catch (_) {}
    const url = String(item?.url || '').trim();
    return /^https?:\/\//i.test(url) ? url : '';
  }

  function directOfficialLinks(profileItem) {
    return Object.entries(profileItem?.links || {}).filter(([platform, url]) => {
      if (!/^https?:\/\//i.test(String(url || ''))) return false;
      try {
        return typeof p50RecoverableDirectLink === 'function' ? p50RecoverableDirectLink(platform, url) : true;
      } catch (_) {
        return true;
      }
    }).slice(0, 7);
  }

  function reasonFor(profileItem) {
    try {
      if (typeof primaryEvent !== 'function') return null;
      const event = primaryEvent(profileItem.id);
      if (!event) return null;
      return {
        title: event.title || '',
        reason: event.reason || '',
        url: /^https?:\/\//i.test(String(event.url || '')) ? event.url : ''
      };
    } catch (_) {
      return null;
    }
  }

  function relativeDate(value) {
    if (!value) return '';
    const timestamp = Date.parse(value);
    if (!Number.isFinite(timestamp)) return '';
    const seconds = Math.max(0, (Date.now() - timestamp) / 1000);
    if (seconds < 3600) return `il y a ${Math.max(1, Math.round(seconds / 60))} min`;
    if (seconds < 86400) return `il y a ${Math.round(seconds / 3600)} h`;
    if (seconds < 7 * 86400) return `il y a ${Math.round(seconds / 86400)} j`;
    return new Date(timestamp).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' });
  }

  async function fetchLatestNews(profileId, periodKey) {
    const apiPeriod = PERIOD_MAP[periodKey] || '24h';
    const cacheKey = `${profileId}:${apiPeriod}`;
    if (state.news.has(cacheKey)) return state.news.get(cacheKey);

    let item = null;
    try {
      const query = new URLSearchParams({ period: apiPeriod, profileId });
      const response = await fetch(`./api/content-feed.php?${query}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Actualité indisponible');
      const news = Array.isArray(data.news) ? [...data.news] : [];
      news.sort((a, b) => Date.parse(b?.publishedAt || 0) - Date.parse(a?.publishedAt || 0));
      item = news[0] || null;
    } catch (error) {
      console.warn('PASS50 follow watch news', profileId, error);
    }
    state.news.set(cacheKey, item);
    return item;
  }

  function avatar(profileItem) {
    try {
      if (typeof avatarHtml === 'function') return avatarHtml(profileItem, 'p50-follow-avatar');
    } catch (_) {}
    return `<div class="avatar p50-follow-avatar"><span>${esc(profileItem?.initials || 'P50')}</span></div>`;
  }

  function newsHtml(news, profileItem) {
    if (!news) {
      return `<div class="p50-follow-news is-empty"><div class="p50-follow-kicker">ACTUALITÉ</div><strong>Aucune actualité récente validée.</strong><span>PASS50 n’ajoute aucun contenu extérieur ou suggéré pour remplir cette page.</span></div>`;
    }
    const source = news.official ? 'Source officielle' : (news.sourceType || 'Source validée');
    const meta = [source, news.platform || '', relativeDate(news.publishedAt)].filter(Boolean).join(' · ');
    const action = /^https?:\/\//i.test(String(news.url || ''))
      ? `<a class="btn small" href="${attr(news.url)}" target="_blank" rel="noopener">Voir l’original ↗</a>`
      : '';
    return `<div class="p50-follow-news"><div class="p50-follow-kicker">DERNIÈRE ACTUALITÉ VALIDÉE</div><strong>${esc(news.title || `Actualité récente de ${profileItem.name}`)}</strong><span>${esc(meta)}</span>${action}</div>`;
  }

  function reasonHtml(profileItem, rank) {
    const explanation = reasonFor(profileItem);
    const label = rank && rank <= 5 ? 'POURQUOI DANS LE TOP 5 ?' : 'SIGNAL LIÉ À SA POSITION';
    if (!explanation?.reason) {
      return `<div class="p50-follow-reason is-empty"><div class="p50-follow-kicker">${label}</div><span>L’explication sera affichée lorsqu’un élément déclencheur aura été validé par PASS50.</span></div>`;
    }
    const link = explanation.url ? `<a href="${attr(explanation.url)}" target="_blank" rel="noopener">Vérifier la source ↗</a>` : '';
    return `<div class="p50-follow-reason"><div class="p50-follow-kicker">${label}</div>${explanation.title ? `<strong>${esc(explanation.title)}</strong>` : ''}<span>${esc(explanation.reason)}</span>${link}</div>`;
  }

  function liveHtml(live, profileItem) {
    if (!live) return '<div class="p50-follow-live-off">Pas en direct actuellement</div>';
    const url = liveUrl(live);
    return `<div class="p50-follow-live"><span class="p50-follow-live-dot">●</span><div><strong>EN DIRECT SUR ${esc(live.platform || 'UNE PLATEFORME')}</strong><span>${esc(live.title || `${profileItem.name} est en direct`)}</span></div>${url ? `<a class="btn small primary" href="${attr(url)}" target="_blank" rel="noopener">Regarder ↗</a>` : ''}</div>`;
  }

  function linksHtml(profileItem) {
    const links = directOfficialLinks(profileItem);
    if (!links.length) return '<span class="p50-follow-links-empty">Liens officiels en cours de vérification.</span>';
    return links.map(([platform, url]) => `<a class="p50-follow-link" href="${attr(url)}" target="_blank" rel="noopener">${esc(platform)} ✓</a>`).join('');
  }

  function cardHtml(profileItem, news, snapshot, periodKey) {
    const rank = rankFor(profileItem.id, snapshot);
    const movement = movementFor(profileItem);
    const live = activeLiveFor(profileItem.id);
    const rankText = rank ? `#${rank}` : 'À vérifier';
    return `<article class="p50-follow-card" data-p50-follow-profile="${attr(profileItem.id)}">
      <div class="p50-follow-card-head">
        ${avatar(profileItem)}
        <div class="p50-follow-identity"><div class="p50-follow-rank">${esc(rankText)} · ${esc(profileItem.category || 'Influenceur')}</div><h3>${esc(profileItem.name)}</h3><span>${esc(profileItem.handle || '')}</span></div>
        <div class="p50-follow-score"><strong>${Math.round(scoreFor(profileItem, periodKey))}</strong><span>Trend Score · ${esc(PERIOD_LABELS[periodKey] || periodKey)}</span><b class="${movement.className}">${esc(movement.text)}</b></div>
      </div>
      ${liveHtml(live, profileItem)}
      <div class="p50-follow-information">${newsHtml(news, profileItem)}${reasonHtml(profileItem, rank)}</div>
      <div class="p50-follow-links"><span>COMPTES OFFICIELS ACTIFS ET VÉRIFIÉS</span><div>${linksHtml(profileItem)}</div></div>
      <div class="p50-follow-actions"><button class="btn primary p50-follow-open-profile" data-profile-id="${attr(profileItem.id)}">Voir la fiche complète</button><button class="btn p50-follow-remove" data-profile-id="${attr(profileItem.id)}">Ne plus suivre</button></div>
    </article>`;
  }

  function ensureStyles() {
    if (document.getElementById('p50FollowFeedStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50FollowFeedStyles';
    style.textContent = `
      #p50FollowFeedModal{z-index:70}#profileModal{z-index:80}
      .p50-follow-modal-box{width:min(1040px,96vw);max-height:92vh;overflow:auto}
      .p50-follow-intro{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:15px;border:1px solid rgba(183,255,0,.24);border-radius:18px;background:linear-gradient(135deg,rgba(183,255,0,.09),rgba(255,255,255,.015));margin-bottom:14px}
      .p50-follow-intro h2{margin:3px 0 5px;font-size:25px}.p50-follow-intro p{margin:0;max-width:680px;color:var(--muted);line-height:1.45}.p50-follow-contract{font-size:10px;color:var(--muted);white-space:nowrap}
      .p50-follow-entry{border-color:rgba(183,255,0,.34)!important;background:linear-gradient(135deg,rgba(183,255,0,.08),rgba(255,255,255,.015))!important}.p50-follow-entry .pref{gap:14px}.p50-follow-entry-copy{max-width:650px;line-height:1.4}
      .p50-follow-list{display:grid;gap:14px}.p50-follow-card{border:1px solid var(--line);border-radius:20px;padding:15px;background:linear-gradient(180deg,#111711,#090c09);box-shadow:0 14px 36px rgba(0,0,0,.22)}
      .p50-follow-card-head{display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:13px;align-items:center}.p50-follow-avatar{width:72px;height:72px;border-radius:18px;font-size:24px}.p50-follow-identity h3{margin:2px 0;font-size:22px}.p50-follow-identity>span{font-size:12px;color:var(--muted)}.p50-follow-rank{font-size:11px;font-weight:1000;color:var(--lime);letter-spacing:.3px}
      .p50-follow-score{text-align:right;display:grid;justify-items:end}.p50-follow-score>strong{font-size:33px;line-height:1}.p50-follow-score>span{font-size:9px;color:var(--muted);font-weight:900;margin:3px 0}.p50-follow-score>b{font-size:12px}
      .p50-follow-live,.p50-follow-live-off{margin-top:12px;border-radius:14px;padding:11px 12px}.p50-follow-live{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;border:1px solid rgba(255,75,75,.5);background:rgba(255,75,75,.08)}.p50-follow-live>div{display:grid;gap:2px}.p50-follow-live>div span{font-size:11px;color:#dfcaca}.p50-follow-live-dot{color:var(--red);font-size:18px}.p50-follow-live-off{border:1px dashed var(--line);color:var(--muted);font-size:11px}
      .p50-follow-information{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:12px}.p50-follow-news,.p50-follow-reason{border:1px solid var(--line);border-radius:15px;padding:12px;background:#0c100c;display:grid;gap:6px;align-content:start}.p50-follow-news strong,.p50-follow-reason strong{line-height:1.32}.p50-follow-news>span,.p50-follow-reason>span{font-size:11px;color:var(--muted);line-height:1.45}.p50-follow-news>a,.p50-follow-reason>a{width:max-content;font-size:11px;color:var(--lime);font-weight:900}.p50-follow-kicker{font-size:9px;letter-spacing:.8px;color:var(--lime);font-weight:1000}.p50-follow-news.is-empty,.p50-follow-reason.is-empty{border-style:dashed}
      .p50-follow-links{margin-top:12px;padding-top:11px;border-top:1px solid var(--line);display:grid;gap:8px}.p50-follow-links>span{font-size:9px;color:var(--muted);font-weight:1000;letter-spacing:.6px}.p50-follow-links>div{display:flex;gap:7px;flex-wrap:wrap}.p50-follow-link{border:1px solid rgba(183,255,0,.26);border-radius:999px;padding:6px 9px;font-size:10px;font-weight:900;color:#dce6d8}.p50-follow-links-empty{font-size:11px;color:var(--muted)}
      .p50-follow-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}.p50-follow-empty{padding:35px 20px;text-align:center;border:1px dashed var(--line);border-radius:18px;color:var(--muted)}.p50-follow-empty strong{display:block;color:var(--text);font-size:19px;margin-bottom:7px}.p50-follow-end{margin-top:14px;text-align:center;border-top:1px solid var(--line);padding:16px 10px 2px;color:var(--muted);font-size:11px}.p50-follow-loading{padding:34px;text-align:center;color:var(--muted)}
      @media(max-width:700px){.p50-follow-intro{display:grid}.p50-follow-contract{white-space:normal}.p50-follow-card-head{grid-template-columns:58px minmax(0,1fr)}.p50-follow-avatar{width:58px;height:58px}.p50-follow-score{grid-column:1/-1;grid-template-columns:auto 1fr auto;justify-items:start;align-items:end;text-align:left;gap:8px;padding-top:9px;border-top:1px solid var(--line)}.p50-follow-score>strong{font-size:28px}.p50-follow-score>span{align-self:center}.p50-follow-information{grid-template-columns:1fr}.p50-follow-live{grid-template-columns:auto minmax(0,1fr)}.p50-follow-live .btn{grid-column:1/-1;width:100%;text-align:center}.p50-follow-actions{display:grid;grid-template-columns:1fr}.p50-follow-actions .btn{width:100%}}
    `;
    document.head.appendChild(style);
  }

  function ensureModal() {
    let modal = document.getElementById('p50FollowFeedModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'p50FollowFeedModal';
    modal.setAttribute('aria-label', 'Ma veille PASS50');
    modal.innerHTML = `<div class="modal-box p50-follow-modal-box"><div class="modal-head"><strong>MA VEILLE PASS50</strong><button class="close" data-close="p50FollowFeedModal" aria-label="Fermer">×</button></div><div class="modal-body" id="p50FollowFeedBody"></div></div>`;
    modal.addEventListener('click', event => {
      if (event.target === modal) modal.classList.remove('show');
    });
    const profileModal = document.getElementById('profileModal');
    if (profileModal?.parentNode) profileModal.parentNode.insertBefore(modal, profileModal);
    else document.body.appendChild(modal);
    return modal;
  }

  function injectUserEntry() {
    const body = document.getElementById('userBody');
    const grid = body?.querySelector('.user-grid');
    const user = getUser();
    if (!grid || !user) return;
    const count = Array.isArray(user.following) ? user.following.length : 0;
    const existing = grid.querySelector('#p50FollowFeedEntry');
    if (existing) {
      const counter = existing.querySelector('.user-title span:last-child');
      if (counter) counter.textContent = `${count}/${MAX_FOLLOWED}`;
      return;
    }
    const section = document.createElement('section');
    section.className = 'user-section full p50-follow-entry';
    section.id = 'p50FollowFeedEntry';
    section.innerHTML = `<div class="user-title"><span>◎ Ma veille Pass50</span><span>${count}/${MAX_FOLLOWED}</span></div><div class="pref"><div class="p50-follow-entry-copy"><strong>Le classement de mes influenceurs suivis</strong><div class="muted">Position, mouvement, direct actif, dernière actualité validée et comptes officiels. Aucun contenu suggéré et aucune page infinie.</div></div><button class="btn primary" id="p50OpenFollowFeed">Ouvrir ma veille</button></div>`;
    grid.prepend(section);
  }

  async function renderFeed({ force = false } = {}) {
    const body = document.getElementById('p50FollowFeedBody');
    const user = getUser();
    if (!body || !user) return;
    const followedIds = [...new Set(Array.isArray(user.following) ? user.following : [])].slice(0, MAX_FOLLOWED);
    const periodKey = currentPeriod();
    const snapshot = rankingSnapshot();
    if (force) followedIds.forEach(id => state.news.delete(`${id}:${PERIOD_MAP[periodKey] || '24h'}`));

    body.innerHTML = `<div class="p50-follow-intro"><div><div class="eyebrow">UNE VEILLE, PAS UN RÉSEAU SOCIAL</div><h2>Mes influenceurs suivis</h2><p>Cette page s’arrête après votre sélection. Elle ne montre que les influenceurs que vous avez choisis et les informations utiles à leur place dans le classement ivoirien et diaspora.</p></div><div class="p50-follow-contract">${CONTRACT}<br>${followedIds.length}/${MAX_FOLLOWED} suivis · période ${esc(PERIOD_LABELS[periodKey])}</div></div>`;

    if (!followedIds.length) {
      body.insertAdjacentHTML('beforeend', '<div class="p50-follow-empty"><strong>Aucun influenceur suivi.</strong>Depuis le classement ou une fiche, utilisez « Suivre » pour ajouter jusqu’à cinq influenceurs à cette veille.</div>');
      return;
    }

    body.insertAdjacentHTML('beforeend', '<div class="p50-follow-loading">Chargement de votre veille limitée…</div>');
    state.loading = true;
    const profiles = followedIds.map(getProfile).filter(Boolean);
    const news = await Promise.all(profiles.map(item => fetchLatestNews(item.id, periodKey)));
    state.loading = false;
    if (!document.body.contains(body)) return;
    const loading = body.querySelector('.p50-follow-loading');
    loading?.remove();
    const list = document.createElement('div');
    list.className = 'p50-follow-list';
    list.innerHTML = profiles.map((item, index) => cardHtml(item, news[index], snapshot, periodKey)).join('');
    body.appendChild(list);
    body.insertAdjacentHTML('beforeend', '<div class="p50-follow-end" id="p50FollowFeedEnd"><strong>Fin de votre veille.</strong><br>PASS50 ne complète pas cette page avec des recommandations ou des contenus venus d’ailleurs.<div style="margin-top:10px"><button class="btn small" id="p50RefreshFollowFeed">Actualiser les informations</button></div></div>');
  }

  async function openFeed() {
    const user = getUser();
    if (!user) {
      try {
        if (typeof openAuth === 'function') openAuth('login');
      } catch (_) {}
      return;
    }
    try {
      if (typeof close === 'function' && document.getElementById('userModal')?.classList.contains('show')) close('userModal');
    } catch (_) {}
    ensureModal().classList.add('show');
    await renderFeed();
  }

  function installObservers() {
    const userBody = document.getElementById('userBody');
    if (userBody) {
      const observer = new MutationObserver(injectUserEntry);
      observer.observe(userBody, { childList: true, subtree: true });
      injectUserEntry();
    }
  }

  function installEvents() {
    document.addEventListener('click', async event => {
      const openButton = event.target.closest('#p50OpenFollowFeed');
      if (openButton) {
        event.preventDefault();
        await openFeed();
        return;
      }

      const refreshButton = event.target.closest('#p50RefreshFollowFeed');
      if (refreshButton) {
        event.preventDefault();
        refreshButton.disabled = true;
        state.news.clear();
        await renderFeed({ force: true });
        return;
      }

      const profileButton = event.target.closest('.p50-follow-open-profile');
      if (profileButton) {
        event.preventDefault();
        try {
          if (typeof openProfile === 'function') openProfile(profileButton.dataset.profileId);
        } catch (_) {}
        return;
      }

      const removeButton = event.target.closest('.p50-follow-remove');
      if (removeButton) {
        event.preventDefault();
        try {
          if (typeof toggleFollow === 'function') toggleFollow(removeButton.dataset.profileId);
        } catch (_) {}
        await renderFeed();
        injectUserEntry();
      }
    });

    document.addEventListener('p50:profile-opened', () => {
      const modal = document.getElementById('p50FollowFeedModal');
      if (modal?.classList.contains('show')) modal.setAttribute('data-profile-open', '1');
    });
  }

  function init() {
    ensureStyles();
    ensureModal();
    installObservers();
    installEvents();
    window.PASS50_FOLLOW_WATCH = Object.freeze({ contract: CONTRACT, maxFollowed: MAX_FOLLOWED, open: openFeed, refresh: renderFeed });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
