'use strict';

(() => {
  if (window.PASS50_CONTEXT_SHARE_V2) return;

  const CONTRACT = 'PASS50-CONTEXT-SHARE-V2.0';
  const APP_KEY = 'pass50.ionos.v1';
  const CONTEXT_ENDPOINT = './partage-contexte-v2.php';
  const PHOTO_ENDPOINT = './partage-photo.php';
  const isFeedPage = /(?:^|\/)mon-fil\.html$/i.test(location.pathname) || document.body?.dataset.pass50Page === 'feed';
  const state = { current: null, menuOpen: false, deepLinkApplied: false, observer: null };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const attr = value => esc(value).replace(/`/g, '&#96;');
  const compact = (value, max = 120) => {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();
    return text.length > max ? `${text.slice(0, Math.max(1, max - 1)).trim()}…` : text;
  };

  function notify(message) {
    try {
      if (typeof toast === 'function') {
        toast(message);
        return;
      }
    } catch (_) {}
    const node = document.getElementById('toast');
    if (!node) return;
    node.textContent = message;
    node.classList.add('show');
    clearTimeout(node._p50ContextV2Timer);
    node._p50ContextV2Timer = setTimeout(() => node.classList.remove('show'), 2200);
  }

  function basePath() {
    const path = location.pathname.replace(/[^/]*$/, '');
    return `${location.origin}${path}`;
  }

  function profileStore() {
    try {
      if (typeof db !== 'undefined' && Array.isArray(db?.profiles)) return db.profiles;
    } catch (_) {}
    try {
      const parsed = JSON.parse(localStorage.getItem(APP_KEY) || 'null');
      if (Array.isArray(parsed?.profiles)) return parsed.profiles;
    } catch (_) {}
    return [];
  }

  function profileById(profileId) {
    return profileStore().find(profile => String(profile?.id || '') === String(profileId || '')) || null;
  }

  function normalizeName(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '');
  }

  function profileByName(name) {
    const key = normalizeName(name);
    if (!key) return null;
    return profileStore().find(profile => normalizeName(profile?.name) === key) || null;
  }

  function initials(value) {
    const words = String(value || 'PASS50').trim().split(/\s+/).filter(Boolean);
    return words.slice(0, 2).map(word => word[0] || '').join('').toUpperCase() || 'P50';
  }

  function photoUrl(profileOrId, size = 160) {
    const profile = typeof profileOrId === 'object' ? profileOrId : profileById(profileOrId);
    const profileId = String(profile?.id || profileOrId || '');
    if (!/^[A-Za-z0-9._:-]{1,100}$/.test(profileId)) return '';
    const url = new URL(PHOTO_ENDPOINT, basePath());
    url.searchParams.set('id', profileId);
    url.searchParams.set('size', String(Math.max(32, Math.min(512, Number(size) || 160))));
    const revision = compact(profile?.photoManualUpdatedAt || profile?.photoUrl || profile?.photoCandidateUrl || '1', 72);
    url.searchParams.set('v', revision);
    return url.href;
  }

  function currentPeriod() {
    try {
      if (typeof ui !== 'undefined' && ui?.period) return String(ui.period);
    } catch (_) {}
    return String(document.querySelector('#periodFilters [data-period].active')?.dataset.period || '24H');
  }

  function currentRegion() {
    try {
      if (typeof ui !== 'undefined' && ui?.region) return String(ui.region);
    } catch (_) {}
    return String(document.querySelector('#regionFilters [data-region].active')?.dataset.region || 'ALL');
  }

  function periodLabel(period = currentPeriod()) {
    return ({ '2H': '2 h', '24H': '24 h', '48H': '48 h', '7J': '7 jours', '15J': '15 jours' })[period] || period;
  }

  function regionLabel(region = currentRegion()) {
    return ({ ALL: 'Côte d’Ivoire + diaspora', CI: 'Côte d’Ivoire', DIASPORA: 'Diaspora' })[region] || region;
  }

  function scoreFor(profile, period = currentPeriod()) {
    const value = Number(profile?.scores?.[period] ?? profile?.scores?.['24H'] ?? 0);
    return Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
  }

  function classable(profile) {
    try {
      if (typeof isClassableProfile === 'function') return Boolean(isClassableProfile(profile));
    } catch (_) {}
    return Boolean(profile?.alive !== false && profile?.eligible !== false && profile?.classable !== false);
  }

  function rankingSource() {
    try {
      if (typeof ranking === 'function') return ranking();
      if (typeof completeRanking === 'function') return completeRanking().filter(classable);
    } catch (_) {}
    const period = currentPeriod();
    const region = currentRegion();
    return profileStore()
      .filter(profile => profile?.alive !== false && classable(profile))
      .filter(profile => region === 'ALL' || profile.region === region || profile.region === 'BOTH')
      .sort((a, b) => scoreFor(b, period) - scoreFor(a, period) || String(a.name || '').localeCompare(String(b.name || ''), 'fr'));
  }

  function contextUrl(payload) {
    const url = new URL(CONTEXT_ENDPOINT, basePath());
    url.searchParams.set('type', payload.type);
    if (payload.period) url.searchParams.set('period', payload.period);
    if (payload.region) url.searchParams.set('region', payload.region);
    if (payload.profileId) url.searchParams.set('id', payload.profileId);
    if (payload.title) url.searchParams.set('title', compact(payload.title, 150));
    if (payload.platform) url.searchParams.set('platform', compact(payload.platform, 32));
    if (payload.audioToken) url.searchParams.set('audio', payload.audioToken);
    return url.href;
  }

  function rankingPayload(size) {
    const normalized = [3, 10, 50].includes(Number(size)) ? Number(size) : 3;
    const period = currentPeriod();
    const region = currentRegion();
    const source = rankingSource();
    const rows = (Array.isArray(source) ? source : []).filter(classable).slice(0, normalized).map((profile, index) => {
      const delta = Number(profile?.delta || 0);
      return {
        rank: index + 1,
        id: String(profile?.id || ''),
        name: compact(profile?.name || 'Influenceur', 46),
        score: Math.round(scoreFor(profile, period)),
        movement: delta > 0 ? `▲ +${delta}` : delta < 0 ? `▼ ${Math.abs(delta)}` : '— stable',
        photoUrl: photoUrl(profile, normalized === 3 ? 240 : normalized === 10 ? 120 : 64),
        initials: initials(profile?.name)
      };
    });
    const payload = {
      kind: 'ranking',
      type: `ranking-top${normalized}`,
      size: normalized,
      rows,
      period,
      region,
      periodLabel: periodLabel(period),
      regionLabel: regionLabel(region),
      title: `Top ${normalized}`,
      subtitle: `${periodLabel(period)} · ${regionLabel(region)}`,
      accent: '#3d5a1f'
    };
    payload.url = contextUrl(payload);
    return payload;
  }

  function profileIdFromCard(card) {
    for (const link of [...card.querySelectorAll('a[href*="profile="]')]) {
      try {
        const id = new URL(link.href, location.href).searchParams.get('profile');
        if (id) return String(id);
      } catch (_) {}
    }
    return '';
  }

  function audioTokenFromUrl(value) {
    try {
      const url = new URL(String(value || ''), location.href);
      const token = decodeURIComponent(url.pathname.split('/').filter(Boolean).pop() || '');
      return /^[A-Za-z0-9._-]{1,180}$/.test(token) ? token : '';
    } catch (_) {
      return '';
    }
  }

  function splitDuel(value) {
    const parts = String(value || '').split(/\s+VS\s+/i).map(part => part.trim()).filter(Boolean);
    return [parts[0] || 'Influenceur A', parts[1] || 'Influenceur B'];
  }

  function feedPayload(card) {
    if (!(card instanceof Element)) return null;
    if (card.classList.contains('duel-audio-feed-card')) {
      const audio = card.querySelector('audio');
      const audioUrl = String(audio?.currentSrc || audio?.src || '');
      const audioToken = audioTokenFromUrl(audioUrl);
      const kicker = compact(card.querySelector('.duel-audio-kicker')?.textContent || '', 100);
      const author = compact(kicker.split('·').pop() || 'Membre', 48);
      const duel = compact(card.querySelector('.duel-audio-feed-head strong')?.textContent || 'Duel Les Coulés', 100);
      const [nameA, nameB] = splitDuel(duel);
      const profileA = profileByName(nameA);
      const profileB = profileByName(nameB);
      const statement = compact(card.querySelector('.duel-audio-feed-body h2')?.textContent || `${author} commente son vote`, 150);
      const duration = compact(card.querySelector('.duel-audio-player > span')?.textContent || '', 12);
      const payload = {
        kind: 'duel-audio',
        type: 'duel-audio',
        author,
        duel,
        statement,
        duration,
        audioUrl,
        audioToken,
        profileId: profileIdFromCard(card),
        candidateA: { id: String(profileA?.id || ''), name: nameA, photoUrl: photoUrl(profileA, 220), initials: initials(nameA) },
        candidateB: { id: String(profileB?.id || ''), name: nameB, photoUrl: photoUrl(profileB, 220), initials: initials(nameB) },
        title: statement,
        subtitle: `${duel}${duration ? ` · ${duration}` : ''}`,
        accent: '#1d4e89'
      };
      payload.url = contextUrl(payload);
      return payload;
    }

    const profileId = profileIdFromCard(card);
    const profile = profileById(profileId);
    const name = compact(card.querySelector('.feed-person strong')?.textContent || profile?.name || 'Influenceur', 70);
    const title = compact(card.querySelector('.feed-body h2')?.textContent || 'Actualité récente', 150);
    const meta = compact(card.querySelector('.feed-meta')?.textContent || '', 100);
    const platform = compact(meta.split('·')[0] || '', 32);
    const position = compact(card.querySelector('.feed-position')?.textContent || '', 60);
    const cover = String(card.querySelector('.feed-media img')?.currentSrc || card.querySelector('.feed-media img')?.src || '');
    const payload = {
      kind: 'feed-post',
      type: 'feed-post',
      profileId,
      name,
      title,
      platform,
      position,
      cover,
      photoUrl: photoUrl(profile || profileId, 260),
      initials: initials(name),
      subtitle: [platform, position].filter(Boolean).join(' · '),
      accent: '#0e7c7b'
    };
    payload.url = contextUrl(payload);
    return payload;
  }

  function injectStyles() {
    if (document.getElementById('p50ContextShareV2Styles')) return;
    const style = document.createElement('style');
    style.id = 'p50ContextShareV2Styles';
    style.textContent = `
      #shareBtn{display:none!important}
      [data-p50-context-share="ranking"]{display:none!important}
      .p50-ranking-share-fab{position:fixed;right:22px;top:50%;z-index:118;display:flex;align-items:center;gap:10px;transform:translateY(-50%)}
      .p50-ranking-share-toggle{width:56px;height:56px;border:1px solid #cfd6cb;border-radius:50%;background:#b7ff00;color:#0b0f0b;display:grid;place-items:center;box-shadow:0 10px 28px rgba(0,0,0,.22);transition:.18s}
      .p50-ranking-share-toggle:hover{transform:scale(1.04)}.p50-ranking-share-toggle svg{width:25px;height:25px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
      .p50-ranking-share-menu{display:none;min-width:210px;padding:9px;border:1px solid #d5dbd2;border-radius:16px;background:#eef1ec;box-shadow:0 18px 48px rgba(0,0,0,.28)}
      .p50-ranking-share-fab.open .p50-ranking-share-menu{display:grid;gap:7px;animation:p50ShareMenuIn .16s ease-out}@keyframes p50ShareMenuIn{from{opacity:0;transform:translateX(8px)}to{opacity:1;transform:none}}
      .p50-ranking-share-menu strong{padding:4px 7px 6px;color:#5c665c;font-size:9px;letter-spacing:.8px}.p50-ranking-share-option{border:1px solid #cfd6cb;border-radius:12px;background:#fff;color:#0b0f0b;padding:11px 12px;text-align:left;font-weight:950}.p50-ranking-share-option:hover{border-color:#0b0f0b;background:#0b0f0b;color:#eef1ec}
      .p50-context-share-post-v2{display:inline-flex!important;align-items:center;justify-content:center;gap:6px}
      .p50-context-share-modal-v2{position:fixed;inset:0;z-index:13000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(11,15,11,.55);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}.p50-context-share-modal-v2.show{display:flex}
      .p50-context-share-box-v2{--p50-accent:#0e7c7b;width:min(590px,100%);max-height:94vh;overflow:auto;padding:11px;border:1px solid #d5dbd2;border-radius:18px;background:#eef1ec;box-shadow:0 24px 80px rgba(0,0,0,.28)}
      .p50-context-share-head-v2{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:4px 3px 10px;color:#0b0f0b}.p50-context-share-close-v2{width:42px;height:42px;border:1px solid #cfd6cb;border-radius:50%;background:#fff;color:#0b0f0b;font-size:23px}
      .p50-context-share-preview-v2{position:relative;overflow:hidden;border-radius:14px;padding:22px;background:#eef1ec;border-top:8px solid var(--p50-accent);color:#0b0f0b}
      .p50-context-brand-v2{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:1000;letter-spacing:-.8px;color:#0b0f0b}.p50-context-brand-v2::before{content:"";width:14px;height:14px;background:#b7ff00;flex:0 0 auto}.p50-context-kicker-v2{margin-top:16px;color:var(--p50-accent);font-size:11px;font-weight:900;letter-spacing:.6px}.p50-context-share-preview-v2 h2{margin:7px 0 5px;font-size:28px;line-height:1.05;letter-spacing:-1px}.p50-context-subtitle-v2{color:#5c665c;font-size:12px;font-weight:700}
      .p50-context-ranking-list-v2{display:grid;gap:6px;margin-top:16px}.p50-context-ranking-row-v2{display:grid;grid-template-columns:34px 38px minmax(0,1fr) 50px;gap:7px;align-items:center;padding:7px 8px;border:1px solid #d5dbd2;border-radius:10px;background:#fff;font-size:11px;color:#0b0f0b}.p50-context-ranking-row-v2>strong{color:var(--p50-accent)}
      .p50-share-avatar-v2{position:relative;width:38px;height:38px;border:2px solid #0b0f0b;border-radius:10px;overflow:hidden;display:grid;place-items:center;background:#dde3d8;color:#0b0f0b;font-size:11px;font-weight:1000}.p50-share-avatar-v2 img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2}.p50-context-ranking-name-v2{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:950}.p50-context-ranking-score-v2{text-align:right;font-weight:1000}
      .p50-context-feed-v2{display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px;align-items:center;margin-top:17px;padding:14px;border:1px solid #d5dbd2;border-radius:12px;background:#fff}.p50-context-feed-v2 .p50-share-avatar-v2{width:82px;height:82px;font-size:22px}.p50-context-feed-v2.duel .p50-context-duel-avatars-v2{display:flex;align-items:center}.p50-context-feed-v2.duel .p50-share-avatar-v2+span{margin:0 -4px;z-index:3;width:31px;height:31px;border-radius:50%;display:grid;place-items:center;background:#0b0f0b;border:1px solid #0b0f0b;color:#eef1ec;font-size:9px;font-weight:1000}.p50-context-feed-v2.duel .p50-share-avatar-v2:last-child{margin-left:-4px}.p50-context-feed-copy-v2 strong{display:block;color:var(--p50-accent);font-size:11px}.p50-context-feed-copy-v2 p{margin:6px 0 0;font-size:17px;line-height:1.35;font-weight:900;color:#0b0f0b}.p50-context-feed-copy-v2 audio{width:100%;margin-top:10px}
      .p50-context-actions-v2{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px}.p50-context-actions-v2 button{min-height:50px;border:1px solid #cfd6cb;border-radius:12px;background:#fff;color:#0b0f0b;font-size:11px;font-weight:950}.p50-context-actions-v2 button:first-child{background:#0b0f0b;border-color:#0b0f0b;color:#eef1ec}
      #coules.section{padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important;overflow:visible!important}
      #coules .coules-banner{width:100%!important;max-height:none!important;margin:0 0 7px!important;border-radius:20px!important;object-fit:cover!important;box-shadow:0 14px 34px rgba(0,0,0,.25)}
      #coules .sunk-duel{margin:0!important;padding:14px!important;border:1px solid rgba(255,75,75,.38)!important;border-radius:20px!important;background:radial-gradient(circle at 50% -10%,rgba(255,75,75,.2),transparent 42%),linear-gradient(145deg,#2a1014,#12090b 72%)!important;box-shadow:0 16px 40px rgba(0,0,0,.32)!important}
      #coules .duel-question{margin-top:0!important}#coules .duel-sub{margin-bottom:11px!important}#coules .sunk{padding:12px!important;background:linear-gradient(145deg,rgba(87,28,34,.82),rgba(23,10,12,.96))!important}
      @media(max-width:680px){.p50-ranking-share-fab{right:12px;top:auto;bottom:calc(115px + env(safe-area-inset-bottom));transform:none;flex-direction:row}.p50-ranking-share-toggle{width:52px;height:52px}.p50-ranking-share-menu{min-width:190px}.p50-context-share-modal-v2{align-items:flex-end;padding:0}.p50-context-share-box-v2{width:100vw;max-width:100vw;border-radius:23px 23px 0 0;padding:9px;padding-bottom:calc(9px + env(safe-area-inset-bottom))}.p50-context-actions-v2{grid-template-columns:1fr 1fr}.p50-context-feed-v2{grid-template-columns:1fr}.p50-context-feed-v2 .p50-share-avatar-v2{width:72px;height:72px}.feed-actions .p50-context-share-post-v2{width:100%}#coules .coules-banner{border-radius:15px!important;margin-bottom:5px!important}#coules .sunk-duel{padding:10px!important;border-radius:16px!important}#coules .sunk{padding:10px!important}}
    `;
    document.head.appendChild(style);
  }

  function avatarPreview(profile, size = 160) {
    const name = String(profile?.name || 'Influenceur');
    const url = String(profile?.photoUrl || '');
    return `<span class="p50-share-avatar-v2"><span>${esc(profile?.initials || initials(name))}</span>${url ? `<img src="${attr(url)}" alt="" loading="lazy" onerror="this.remove()">` : ''}</span>`;
  }

  function ensureModal() {
    let modal = document.getElementById('p50ContextShareModalV2');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'p50ContextShareModalV2';
    modal.className = 'p50-context-share-modal-v2';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `<div class="p50-context-share-box-v2" role="dialog" aria-modal="true" aria-label="Partager ce contenu PASS50"><div class="p50-context-share-head-v2"><strong>PARTAGER</strong><button type="button" class="p50-context-share-close-v2" aria-label="Fermer">×</button></div><div class="p50-context-share-preview-v2" id="p50ContextSharePreviewV2"></div><div class="p50-context-actions-v2"><button type="button" data-p50-v2-native>Partager</button><button type="button" data-p50-v2-whatsapp>WhatsApp</button><button type="button" data-p50-v2-copy>Copier</button><button type="button" data-p50-v2-download>Télécharger</button></div></div>`;
    document.body.appendChild(modal);
    return modal;
  }

  function rankingPreview(payload) {
    const visible = payload.size === 50 ? payload.rows.slice(0, 10) : payload.rows;
    const rows = visible.map(row => `<div class="p50-context-ranking-row-v2"><strong>#${row.rank}</strong>${avatarPreview(row)}<span class="p50-context-ranking-name-v2">${esc(row.name)}</span><span class="p50-context-ranking-score-v2">${row.score}</span></div>`).join('');
    const more = payload.size === 50 && payload.rows.length > visible.length ? '<div class="p50-context-subtitle-v2" style="margin-top:8px;text-align:center">Aperçu des 10 premiers · l’image contient le Top 50 complet</div>' : '';
    return `<div class="p50-context-brand-v2">PASS50</div><div class="p50-context-kicker-v2">CLASSEMENT</div><h2>${esc(payload.title)}</h2><div class="p50-context-subtitle-v2">${esc(payload.subtitle)}</div><div class="p50-context-ranking-list-v2">${rows}</div>${more}`;
  }

  function feedPreview(payload) {
    if (payload.kind === 'duel-audio') {
      const audio = payload.audioUrl ? `<audio controls preload="metadata" src="${attr(payload.audioUrl)}"></audio>` : '';
      return `<div class="p50-context-brand-v2">PASS50</div><div class="p50-context-kicker-v2">LES COULÉS · AUDIO</div><h2>${esc(payload.duel)}</h2><div class="p50-context-subtitle-v2">${esc(payload.duration || '')}</div><div class="p50-context-feed-v2 duel"><div class="p50-context-duel-avatars-v2">${avatarPreview(payload.candidateA)}<span>VS</span>${avatarPreview(payload.candidateB)}</div><div class="p50-context-feed-copy-v2"><strong>${esc(payload.author)}</strong><p>${esc(payload.statement)}</p>${audio}</div></div>`;
    }
    return `<div class="p50-context-brand-v2">PASS50</div><div class="p50-context-kicker-v2">MON FIL</div><h2>${esc(payload.name)}</h2><div class="p50-context-subtitle-v2">${esc(payload.subtitle || '')}</div><div class="p50-context-feed-v2">${avatarPreview({ name: payload.name, initials: payload.initials, photoUrl: payload.photoUrl })}<div class="p50-context-feed-copy-v2"><strong>ACTUALITÉ</strong><p>${esc(payload.title)}</p></div></div>`;
  }

  function openPayload(payload) {
    if (!payload) return;
    state.current = payload;
    closeRankingMenu();
    const modal = ensureModal();
    modal.querySelector('.p50-context-share-box-v2').style.setProperty('--p50-accent', payload.accent || '#0e7c7b');
    modal.querySelector('#p50ContextSharePreviewV2').innerHTML = payload.kind === 'ranking' ? rankingPreview(payload) : feedPreview(payload);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    const modal = document.getElementById('p50ContextShareModalV2');
    if (modal) {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
    }
    state.current = null;
  }

  function removeLegacyShareUi() {
    document.getElementById('shareBtn')?.remove();
    document.querySelectorAll('[data-p50-context-share="ranking"]').forEach(node => node.remove());
    document.getElementById('p50ContextShareModal')?.remove();
  }

  function injectFloatingShare() {
    if (isFeedPage || document.getElementById('p50RankingShareFab')) return;
    const wrapper = document.createElement('div');
    wrapper.id = 'p50RankingShareFab';
    wrapper.className = 'p50-ranking-share-fab';
    wrapper.innerHTML = `<div class="p50-ranking-share-menu" role="menu"><strong>PARTAGER LE CLASSEMENT</strong><button type="button" class="p50-ranking-share-option" data-p50-ranking-share-size="3">Top 3</button><button type="button" class="p50-ranking-share-option" data-p50-ranking-share-size="10">Top 10</button><button type="button" class="p50-ranking-share-option" data-p50-ranking-share-size="50">Top 50</button></div><button type="button" class="p50-ranking-share-toggle" aria-label="Partager le classement" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="18" cy="5" r="2.5"></circle><circle cx="6" cy="12" r="2.5"></circle><circle cx="18" cy="19" r="2.5"></circle><path d="m8.2 10.9 7.6-4.5M8.2 13.1l7.6 4.5"></path></svg></button>`;
    document.body.appendChild(wrapper);
  }

  function toggleRankingMenu() {
    const wrapper = document.getElementById('p50RankingShareFab');
    if (!wrapper) return;
    state.menuOpen = !state.menuOpen;
    wrapper.classList.toggle('open', state.menuOpen);
    wrapper.querySelector('.p50-ranking-share-toggle')?.setAttribute('aria-expanded', state.menuOpen ? 'true' : 'false');
  }

  function closeRankingMenu() {
    state.menuOpen = false;
    const wrapper = document.getElementById('p50RankingShareFab');
    wrapper?.classList.remove('open');
    wrapper?.querySelector('.p50-ranking-share-toggle')?.setAttribute('aria-expanded', 'false');
  }

  function shareButton(label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn p50-context-share-post-v2';
    button.dataset.p50ShareFeedV2 = '1';
    button.innerHTML = `<span aria-hidden="true">↗</span> ${label}`;
    return button;
  }

  function injectFeedButtons() {
    if (!isFeedPage) return;
    document.querySelectorAll('#feedList .feed-card').forEach(card => {
      card.querySelectorAll('[data-p50-context-share="feed"]').forEach(node => node.remove());
      if (card.querySelector('[data-p50-share-feed-v2]')) return;
      const actions = card.querySelector('.feed-actions');
      if (!actions) return;
      actions.appendChild(shareButton(card.classList.contains('duel-audio-feed-card') ? 'Partager cet audio' : 'Partager ce post'));
    });
  }

  function shareMessage(payload) {
    if (payload.kind === 'ranking') {
      const leader = payload.rows[0];
      return `📊 ${payload.title} — ${payload.periodLabel}, ${payload.regionLabel}.${leader ? `\nN°1 : ${leader.name} · ${leader.score}/100` : ''}\n${payload.url}`;
    }
    if (payload.kind === 'duel-audio') return `🎙 ${payload.author} commente ${payload.duel}.\n${payload.url}`;
    return `📰 ${payload.name} : ${payload.title}\n${payload.url}`;
  }

  function roundedRect(ctx, x, y, width, height, radius) {
    ctx.beginPath();
    if (typeof ctx.roundRect === 'function') ctx.roundRect(x, y, width, height, Math.min(radius, width / 2, height / 2));
    else ctx.rect(x, y, width, height);
  }

  function wrapCanvas(ctx, text, maxWidth, maxLines = 4) {
    const words = String(text || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    for (const word of words) {
      const next = line ? `${line} ${word}` : word;
      if (ctx.measureText(next).width <= maxWidth) line = next;
      else {
        if (line) lines.push(line);
        line = word;
        if (lines.length >= maxLines - 1) break;
      }
    }
    if (line && lines.length < maxLines) lines.push(line);
    return lines;
  }

  function loadImage(url) {
    return new Promise(resolve => {
      if (!url) return resolve(null);
      const image = new Image();
      let settled = false;
      const finish = value => {
        if (settled) return;
        settled = true;
        resolve(value);
      };
      image.crossOrigin = 'anonymous';
      image.onload = () => finish(image);
      image.onerror = () => finish(null);
      image.src = url;
      setTimeout(() => finish(null), 5000);
    });
  }

  function drawAvatar(ctx, image, x, y, size, fallback, accent) {
    ctx.save();
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2, 0, Math.PI * 2);
    ctx.clip();
    if (image) {
      const ratio = Math.max(size / image.width, size / image.height);
      const width = image.width * ratio;
      const height = image.height * ratio;
      ctx.drawImage(image, x + (size - width) / 2, y + (size - height) / 2, width, height);
    } else {
      ctx.fillStyle = '#dde3d8';
      ctx.fillRect(x, y, size, size);
      ctx.fillStyle = '#0b0f0b';
      ctx.textAlign = 'center';
      ctx.font = `1000 ${Math.max(13, Math.round(size * .31))}px Arial, sans-serif`;
      ctx.fillText(fallback || 'P50', x + size / 2, y + size * .62);
      ctx.textAlign = 'left';
    }
    ctx.restore();
    ctx.strokeStyle = accent;
    ctx.lineWidth = Math.max(2, Math.round(size * .035));
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2 - ctx.lineWidth / 2, 0, Math.PI * 2);
    ctx.stroke();
  }

  function drawBase(ctx, accent, kicker, title, subtitle) {
    ctx.fillStyle = '#eef1ec';
    ctx.fillRect(0, 0, 1080, 1350);
    ctx.fillStyle = accent;
    ctx.fillRect(0, 0, 1080, 18);
    ctx.fillStyle = '#b7ff00';
    ctx.fillRect(64, 56, 22, 22);
    ctx.fillStyle = '#0b0f0b';
    ctx.font = '1000 42px Arial, sans-serif';
    ctx.fillText('PASS50', 100, 76);
    ctx.fillStyle = accent;
    ctx.font = '800 22px Arial, sans-serif';
    ctx.fillText(String(kicker || '').toUpperCase(), 64, 140);
    ctx.fillStyle = '#0b0f0b';
    ctx.font = '1000 56px Arial, sans-serif';
    wrapCanvas(ctx, String(title || ''), 930, 2).forEach((line, index) => ctx.fillText(line, 64, 250 + index * 66));
    ctx.fillStyle = '#5c665c';
    ctx.font = '600 26px Arial, sans-serif';
    wrapCanvas(ctx, subtitle, 920, 2).forEach((line, index) => ctx.fillText(line, 64, 360 + index * 36));
  }

  async function drawRankingImage(ctx, payload) {
    drawBase(ctx, payload.accent, 'CLASSEMENT', payload.title, payload.subtitle);
    const rows = payload.rows.slice(0, payload.size);
    const images = await Promise.all(rows.map(row => loadImage(row.photoUrl)));
    if (payload.size === 50) {
      rows.forEach((row, index) => {
        const column = Math.floor(index / 25);
        const local = index % 25;
        const x = column === 0 ? 52 : 548;
        const y = 420 + local * 32;
        drawAvatar(ctx, images[index], x + 39, y - 23, 25, row.initials, payload.accent);
        ctx.fillStyle = row.rank <= 3 ? payload.accent : '#5c665c';
        ctx.font = '1000 18px Arial, sans-serif';
        ctx.fillText(`#${row.rank}`, x, y);
        ctx.fillStyle = '#0b0f0b';
        ctx.font = '900 17px Arial, sans-serif';
        ctx.fillText(compact(row.name, 20), x + 72, y);
        ctx.fillStyle = payload.accent;
        ctx.textAlign = 'right';
        ctx.fillText(String(row.score), x + 440, y);
        ctx.textAlign = 'left';
      });
    } else {
      const startY = payload.size === 3 ? 452 : 425;
      const rowHeight = payload.size === 3 ? 205 : 77;
      rows.forEach((row, index) => {
        const y = startY + index * rowHeight;
        roundedRect(ctx, 60, y - 42, 960, rowHeight - 12, 16);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = row.rank <= 3 ? payload.accent : '#d5dbd2';
        ctx.lineWidth = row.rank <= 3 ? 3 : 1;
        ctx.stroke();
        const avatarSize = payload.size === 3 ? 120 : 50;
        drawAvatar(ctx, images[index], payload.size === 3 ? 140 : 112, y - (payload.size === 3 ? 20 : 29), avatarSize, row.initials, payload.accent);
        ctx.fillStyle = payload.accent;
        ctx.font = `1000 ${payload.size === 3 ? 48 : 29}px Arial, sans-serif`;
        ctx.fillText(`#${row.rank}`, 82, y + (payload.size === 3 ? 25 : 8));
        ctx.fillStyle = '#0b0f0b';
        ctx.font = `1000 ${payload.size === 3 ? 40 : 27}px Arial, sans-serif`;
        ctx.fillText(compact(row.name, payload.size === 3 ? 27 : 38), payload.size === 3 ? 300 : 184, y + (payload.size === 3 ? 10 : 7));
        ctx.fillStyle = '#5c665c';
        ctx.font = `700 ${payload.size === 3 ? 23 : 17}px Arial, sans-serif`;
        ctx.fillText(row.movement, payload.size === 3 ? 300 : 184, y + (payload.size === 3 ? 50 : 30));
        ctx.fillStyle = payload.accent;
        ctx.textAlign = 'right';
        ctx.font = `1000 ${payload.size === 3 ? 45 : 29}px Arial, sans-serif`;
        ctx.fillText(`${row.score}/100`, 982, y + (payload.size === 3 ? 25 : 10));
        ctx.textAlign = 'left';
      });
    }
    ctx.fillStyle = '#b7ff00';
    roundedRect(ctx, 60, 1230, 960, 78, 12);
    ctx.fill();
    ctx.fillStyle = '#0b0f0b';
    ctx.textAlign = 'center';
    ctx.font = '1000 28px Arial, sans-serif';
    ctx.fillText(payload.size === 50 ? 'Voir le classement' : `Voir le Top ${payload.size}`, 540, 1280);
    ctx.textAlign = 'left';
    ctx.fillStyle = '#5c665c';
    ctx.font = '600 22px Arial, sans-serif';
    ctx.fillText('pass50.store', 64, 1330);
  }

  async function drawFeedImage(ctx, payload) {
    const kicker = payload.kind === 'duel-audio' ? 'LES COULÉS · AUDIO' : 'MON FIL';
    const heading = payload.kind === 'duel-audio' ? payload.duel : payload.name;
    drawBase(ctx, payload.accent, kicker, heading, payload.subtitle || 'Contenu du moment');
    roundedRect(ctx, 64, 450, 952, 560, 16);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.strokeStyle = '#d5dbd2';
    ctx.lineWidth = 2;
    ctx.stroke();
    if (payload.kind === 'duel-audio') {
      const [imageA, imageB] = await Promise.all([loadImage(payload.candidateA.photoUrl), loadImage(payload.candidateB.photoUrl)]);
      drawAvatar(ctx, imageA, 112, 520, 190, payload.candidateA.initials, payload.accent);
      drawAvatar(ctx, imageB, 325, 520, 190, payload.candidateB.initials, payload.accent);
      ctx.fillStyle = payload.accent;
      ctx.font = '800 24px Arial, sans-serif';
      ctx.fillText(compact(payload.author, 34), 560, 550);
      ctx.fillStyle = '#0b0f0b';
      ctx.font = '800 36px Arial, sans-serif';
      wrapCanvas(ctx, payload.statement, 390, 6).forEach((line, index) => ctx.fillText(line, 560, 625 + index * 48));
      ctx.strokeStyle = payload.accent;
      ctx.lineWidth = 5;
      ctx.beginPath();
      for (let x = 120; x < 960; x += 20) {
        const amplitude = 14 + Math.abs(Math.sin(x * .035)) * 34;
        ctx.moveTo(x, 940 - amplitude);
        ctx.lineTo(x, 940 + amplitude);
      }
      ctx.stroke();
    } else {
      const image = await loadImage(payload.photoUrl);
      drawAvatar(ctx, image, 105, 540, 250, payload.initials, payload.accent);
      ctx.fillStyle = payload.accent;
      ctx.font = '800 24px Arial, sans-serif';
      ctx.fillText(compact(payload.name, 30), 410, 565);
      ctx.fillStyle = '#0b0f0b';
      ctx.font = '800 40px Arial, sans-serif';
      wrapCanvas(ctx, payload.title, 535, 6).forEach((line, index) => ctx.fillText(line, 410, 650 + index * 52));
    }
    ctx.fillStyle = '#b7ff00';
    roundedRect(ctx, 64, 1120, 952, 100, 12);
    ctx.fill();
    ctx.fillStyle = '#0b0f0b';
    ctx.textAlign = 'center';
    ctx.font = '1000 30px Arial, sans-serif';
    ctx.fillText(payload.kind === 'duel-audio' ? 'Écouter l’audio' : 'Voir la fiche', 540, 1184);
    ctx.textAlign = 'left';
    ctx.fillStyle = '#5c665c';
    ctx.font = '600 22px Arial, sans-serif';
    ctx.fillText('pass50.store', 64, 1295);
  }

  async function imageFile(payload) {
    const canvas = document.createElement('canvas');
    canvas.width = 1080;
    canvas.height = 1350;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    if (payload.kind === 'ranking') await drawRankingImage(ctx, payload);
    else await drawFeedImage(ctx, payload);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png', .94));
    if (!blob) return null;
    const suffix = payload.kind === 'ranking' ? `top-${payload.size}` : payload.kind;
    return new File([blob], `pass50-${suffix}.png`, { type: 'image/png' });
  }

  async function audioFile(payload) {
    if (payload.kind !== 'duel-audio' || !/^https?:\/\//i.test(String(payload.audioUrl || ''))) return null;
    try {
      const response = await fetch(payload.audioUrl, { cache: 'no-store' });
      if (!response.ok) return null;
      const blob = await response.blob();
      if (!blob.size || blob.size > 5 * 1024 * 1024) return null;
      const extension = audioTokenFromUrl(payload.audioUrl).split('.').pop() || 'webm';
      return new File([blob], `pass50-audio-duel.${extension}`, { type: blob.type || 'audio/webm' });
    } catch (_) {
      return null;
    }
  }

  async function nativeShare() {
    const payload = state.current;
    if (!payload) return;
    try {
      const image = await imageFile(payload);
      const audio = await audioFile(payload);
      let files = image ? [image] : [];
      if (audio && navigator.canShare?.({ files: image ? [image, audio] : [audio] })) files = image ? [image, audio] : [audio];
      else if (audio && navigator.canShare?.({ files: [audio] })) files = [audio];
      const data = { title: `PASS50 · ${payload.title || payload.duel || 'Partage'}`, text: shareMessage(payload), url: payload.url };
      if (files.length && navigator.canShare?.({ files })) data.files = files;
      if (navigator.share) {
        await navigator.share(data);
        return;
      }
      await copyText(shareMessage(payload));
      notify('Message et lien copiés');
    } catch (error) {
      if (error?.name !== 'AbortError') notify('Partage indisponible');
    }
  }

  async function copyText(value) {
    if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(value);
    const area = document.createElement('textarea');
    area.value = value;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    area.remove();
  }

  function whatsappShare() {
    if (!state.current) return;
    const opened = window.open(`https://wa.me/?text=${encodeURIComponent(shareMessage(state.current))}`, '_blank');
    if (opened) try { opened.opener = null; } catch (_) {}
  }

  async function copyShare() {
    if (!state.current) return;
    try {
      await copyText(shareMessage(state.current));
      notify('Message et lien copiés');
    } catch (_) {
      notify('Copie impossible');
    }
  }

  async function downloadImage() {
    if (!state.current) return;
    const file = await imageFile(state.current);
    if (!file) return notify('Image indisponible');
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function applyDeepLink() {
    if (state.deepLinkApplied || isFeedPage) return;
    const params = new URLSearchParams(location.search);
    const period = params.get('period');
    const region = params.get('region');
    const section = params.get('section');
    const open = params.get('open');
    const audioToken = params.get('audio');
    if (!period && !region && !section && open !== 'top50' && !audioToken) return;
    let attempts = 0;
    const apply = () => {
      attempts += 1;
      if (period) document.querySelector(`#periodFilters [data-period="${period.replace(/[^A-Za-z0-9]/g, '')}"]:not(.active)`)?.click();
      if (region) document.querySelector(`#regionFilters [data-region="${region.replace(/[^A-Za-z0-9]/g, '')}"]:not(.active)`)?.click();
      if (open === 'top50' && document.getElementById('top50Btn')) {
        document.getElementById('top50Btn').click();
        state.deepLinkApplied = true;
        return true;
      }
      if (section && document.getElementById(section)) {
        document.getElementById(section).scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (!audioToken) {
          state.deepLinkApplied = true;
          return true;
        }
      }
      if (audioToken) {
        const audio = [...document.querySelectorAll('.p50-duel-audio-card audio')].find(node => audioTokenFromUrl(node.currentSrc || node.src) === audioToken);
        const card = audio?.closest('.p50-duel-audio-card');
        if (card) {
          card.style.outline = '3px solid #a66cff';
          card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          state.deepLinkApplied = true;
          return true;
        }
      }
      return attempts > 120;
    };
    if (apply()) return;
    const timer = setInterval(() => {
      if (apply()) clearInterval(timer);
    }, 150);
  }

  function installEvents() {
    window.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const toggle = target.closest('.p50-ranking-share-toggle');
      if (toggle) {
        event.preventDefault();
        event.stopImmediatePropagation();
        toggleRankingMenu();
        return;
      }
      const option = target.closest('[data-p50-ranking-share-size]');
      if (option) {
        event.preventDefault();
        event.stopImmediatePropagation();
        openPayload(rankingPayload(Number(option.dataset.p50RankingShareSize)));
        return;
      }
      const feedButton = target.closest('[data-p50-share-feed-v2]');
      if (feedButton) {
        event.preventDefault();
        event.stopImmediatePropagation();
        openPayload(feedPayload(feedButton.closest('.feed-card')));
        return;
      }
      if (target.closest('.p50-context-share-close-v2') || target.id === 'p50ContextShareModalV2') {
        event.preventDefault();
        closeModal();
        return;
      }
      if (target.closest('[data-p50-v2-native]')) {
        event.preventDefault();
        nativeShare();
        return;
      }
      if (target.closest('[data-p50-v2-whatsapp]')) {
        event.preventDefault();
        whatsappShare();
        return;
      }
      if (target.closest('[data-p50-v2-copy]')) {
        event.preventDefault();
        copyShare();
        return;
      }
      if (target.closest('[data-p50-v2-download]')) {
        event.preventDefault();
        downloadImage();
        return;
      }
      if (state.menuOpen && !target.closest('#p50RankingShareFab')) closeRankingMenu();
    }, true);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        closeRankingMenu();
        closeModal();
      }
    }, true);
  }

  function refreshUi() {
    removeLegacyShareUi();
    injectFloatingShare();
    injectFeedButtons();
  }

  function init() {
    injectStyles();
    ensureModal();
    refreshUi();
    installEvents();
    state.observer = new MutationObserver(refreshUi);
    state.observer.observe(document.body, { childList: true, subtree: true });
    setTimeout(applyDeepLink, 250);
    window.addEventListener('load', () => setTimeout(applyDeepLink, 300), { once: true });
    window.PASS50_CONTEXT_SHARE_V2 = Object.freeze({
      contract: CONTRACT,
      openRanking: size => openPayload(rankingPayload(size)),
      openFeedCard: card => openPayload(feedPayload(card)),
      contextUrl
    });
    window.PASS50_CONTEXT_SHARE = window.PASS50_CONTEXT_SHARE_V2;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
