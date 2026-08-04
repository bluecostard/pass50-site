'use strict';

(() => {
  if (window.PASS50_CONTEXT_SHARE) return;

  const CONTRACT = 'PASS50-CONTEXT-SHARE-V1.0';
  const ENDPOINT = './partage-contexte.php';
  const MAX_RANKING_ROWS = 50;
  const isFeedPage = /(?:^|\/)mon-fil\.html$/i.test(location.pathname) || document.body?.dataset.pass50Page === 'feed';
  const state = { current: null, observer: null, deepLinkApplied: false };

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
    clearTimeout(node._p50ContextTimer);
    node._p50ContextTimer = setTimeout(() => node.classList.remove('show'), 2200);
  }

  function basePath() {
    const path = location.pathname.replace(/[^/]*$/, '');
    return `${location.origin}${path}`;
  }

  function currentPeriod() {
    try {
      if (typeof ui !== 'undefined' && ui?.period) return String(ui.period);
    } catch (_) {}
    const active = document.querySelector('#periodFilters [data-period].active');
    return String(active?.dataset.period || '24H');
  }

  function currentRegion() {
    try {
      if (typeof ui !== 'undefined' && ui?.region) return String(ui.region);
    } catch (_) {}
    const active = document.querySelector('#regionFilters [data-region].active');
    return String(active?.dataset.region || 'ALL');
  }

  function periodLabel(period = currentPeriod()) {
    return ({ '2H': '2 h', '24H': '24 h', '48H': '48 h', '7J': '7 jours', '15J': '15 jours' })[period] || period;
  }

  function regionLabel(region = currentRegion()) {
    return ({ ALL: 'Côte d’Ivoire + diaspora', CI: 'Côte d’Ivoire', DIASPORA: 'Diaspora' })[region] || region;
  }

  function profileScore(profile, period = currentPeriod()) {
    const value = Number(profile?.scores?.[period] ?? profile?.scores?.['24H'] ?? 0);
    return Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
  }

  function classable(profile) {
    try {
      if (typeof isClassableProfile === 'function') return Boolean(isClassableProfile(profile));
    } catch (_) {}
    return Boolean(profile?.alive !== false && profile?.eligible !== false && profile?.classable !== false);
  }

  function rankingRows() {
    let source = [];
    try {
      if (typeof ranking === 'function') source = ranking();
      else if (typeof completeRanking === 'function') source = completeRanking().filter(classable);
      else if (typeof db !== 'undefined' && Array.isArray(db?.profiles)) {
        const period = currentPeriod();
        const region = currentRegion();
        source = db.profiles
          .filter(profile => profile?.alive !== false && classable(profile))
          .filter(profile => region === 'ALL' || profile.region === region || profile.region === 'BOTH')
          .sort((a, b) => profileScore(b, period) - profileScore(a, period) || String(a.name || '').localeCompare(String(b.name || ''), 'fr'));
      }
    } catch (error) {
      console.warn('PASS50 context share ranking', error);
    }
    const period = currentPeriod();
    return (Array.isArray(source) ? source : []).filter(classable).slice(0, MAX_RANKING_ROWS).map((profile, index) => {
      const delta = Number(profile?.delta || 0);
      return {
        rank: index + 1,
        id: String(profile?.id || ''),
        name: compact(profile?.name || 'Influenceur', 46),
        handle: compact(profile?.handle || '', 38),
        category: compact(profile?.category || '', 34),
        score: Math.round(profileScore(profile, period)),
        delta,
        movement: delta > 0 ? `▲ +${delta}` : delta < 0 ? `▼ ${Math.abs(delta)}` : '— stable'
      };
    });
  }

  function publishedLabel() {
    let value = '';
    try {
      if (typeof db !== 'undefined') value = String(db?.publishedAt || '');
    } catch (_) {}
    const timestamp = Date.parse(value);
    const date = Number.isFinite(timestamp) ? new Date(timestamp) : new Date();
    return date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  }

  function contextUrl(payload) {
    const url = new URL(ENDPOINT, basePath());
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
    const rows = rankingRows().slice(0, normalized);
    const period = currentPeriod();
    const region = currentRegion();
    const payload = {
      kind: 'ranking',
      type: `ranking-top${normalized}`,
      size: normalized,
      rows,
      period,
      region,
      periodLabel: periodLabel(period),
      regionLabel: regionLabel(region),
      publishedAt: publishedLabel(),
      title: `Top ${normalized} PASS50`,
      subtitle: `${periodLabel(period)} · ${regionLabel(region)}`,
      accent: '#b7ff00'
    };
    payload.url = contextUrl(payload);
    return payload;
  }

  function profileIdFromCard(card) {
    const link = [...card.querySelectorAll('a[href*="profile="]')].find(node => {
      try { return new URL(node.href, location.href).searchParams.has('profile'); } catch (_) { return false; }
    });
    if (!link) return '';
    try { return String(new URL(link.href, location.href).searchParams.get('profile') || ''); } catch (_) { return ''; }
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

  function feedPayload(card) {
    if (!(card instanceof Element)) return null;
    if (card.classList.contains('duel-audio-feed-card')) {
      const audio = card.querySelector('audio');
      const audioUrl = String(audio?.currentSrc || audio?.src || '');
      const audioToken = audioTokenFromUrl(audioUrl);
      const kicker = compact(card.querySelector('.duel-audio-kicker')?.textContent || '', 100);
      const author = compact(kicker.split('·').pop() || 'Membre PASS50', 48);
      const duel = compact(card.querySelector('.duel-audio-feed-head strong')?.textContent || 'Duel Les Coulés', 100);
      const statement = compact(card.querySelector('.duel-audio-feed-body h2')?.textContent || `${author} commente son vote`, 150);
      const duration = compact(card.querySelector('.duel-audio-player > span')?.textContent || '', 12);
      const profileId = profileIdFromCard(card);
      const payload = {
        kind: 'duel-audio',
        type: 'duel-audio',
        author,
        duel,
        statement,
        duration,
        audioUrl,
        audioToken,
        profileId,
        title: statement,
        subtitle: `${duel}${duration ? ` · ${duration}` : ''}`,
        accent: '#a66cff'
      };
      payload.url = contextUrl(payload);
      return payload;
    }

    const profileId = profileIdFromCard(card);
    const name = compact(card.querySelector('.feed-person strong')?.textContent || 'Influenceur PASS50', 70);
    const title = compact(card.querySelector('.feed-body h2')?.textContent || 'Actualité récente', 150);
    const meta = compact(card.querySelector('.feed-meta')?.textContent || '', 100);
    const platform = compact(meta.split('·')[0] || '', 32);
    const position = compact(card.querySelector('.feed-position')?.textContent || '', 60);
    const originalUrl = String([...card.querySelectorAll('a[target="_blank"]')][0]?.href || '');
    const payload = {
      kind: 'feed-post',
      type: 'feed-post',
      profileId,
      name,
      title,
      platform,
      position,
      originalUrl,
      subtitle: [platform, position].filter(Boolean).join(' · '),
      accent: '#1ee5ff'
    };
    payload.url = contextUrl(payload);
    return payload;
  }

  function injectStyles() {
    if (document.getElementById('p50ContextShareStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50ContextShareStyles';
    style.textContent = `
      .p50-context-share-ranking{position:relative;z-index:5;display:inline-flex;align-items:center;justify-content:center;gap:6px}
      #buzz .p50-context-share-ranking{align-self:flex-start;margin-top:12px}
      #top10 .section-head{flex-wrap:wrap}
      #top10 .p50-context-share-ranking{margin-left:auto}
      #top50Modal .modal-head .p50-context-share-ranking{margin-left:auto;margin-right:7px}
      .p50-context-share-post{display:inline-flex!important;align-items:center;justify-content:center;gap:6px}
      .p50-context-share-modal{position:fixed;inset:0;z-index:13000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.84);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px)}
      .p50-context-share-modal.show{display:flex}
      .p50-context-share-box{--p50-context-accent:#b7ff00;width:min(560px,100%);max-height:94vh;overflow:auto;padding:11px;border:1px solid color-mix(in srgb,var(--p50-context-accent) 48%,#293129);border-radius:27px;background:#080b08;box-shadow:0 34px 110px rgba(0,0,0,.75)}
      .p50-context-share-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:4px 3px 10px}.p50-context-share-head strong{font-size:14px}.p50-context-share-close{width:42px;height:42px;border:1px solid #293129;border-radius:50%;background:#111611;color:#fff;font-size:23px}
      .p50-context-share-preview{position:relative;overflow:hidden;border-radius:21px;padding:21px;background:radial-gradient(circle at 93% 3%,color-mix(in srgb,var(--p50-context-accent) 22%,transparent),transparent 38%),linear-gradient(150deg,#151b15,#050705 76%);border-left:7px solid var(--p50-context-accent)}
      .p50-context-share-brand{font-size:25px;font-weight:1000;letter-spacing:-1.3px}.p50-context-share-brand span{color:var(--p50-context-accent)}.p50-context-share-kicker{margin-top:20px;color:var(--p50-context-accent);font-size:10px;font-weight:1000;letter-spacing:1px}.p50-context-share-preview h2{margin:7px 0 5px;font-size:31px;line-height:1.04;letter-spacing:-1.2px}.p50-context-share-subtitle{color:#aeb8aa;font-size:12px;font-weight:850;line-height:1.4}
      .p50-context-ranking-list{display:grid;gap:6px;margin-top:17px}.p50-context-ranking-row{display:grid;grid-template-columns:34px minmax(0,1fr) 56px 65px;gap:7px;align-items:center;padding:8px 9px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(5,7,5,.52);font-size:11px}.p50-context-ranking-row>strong:first-child{color:var(--p50-context-accent);font-size:14px}.p50-context-ranking-row .name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:950}.p50-context-ranking-row .score{text-align:right;font-weight:1000}.p50-context-ranking-row .move{text-align:right;color:#b9c3b6;font-size:9px;font-weight:900}.p50-context-ranking-more{margin-top:8px;text-align:center;color:#aeb8aa;font-size:10px}
      .p50-context-feed-card{margin-top:17px;padding:14px;border:1px solid rgba(255,255,255,.1);border-radius:15px;background:rgba(5,7,5,.5)}.p50-context-feed-card strong{display:block;color:var(--p50-context-accent);font-size:12px;margin-bottom:6px}.p50-context-feed-card p{margin:0;font-size:17px;line-height:1.35;font-weight:900}.p50-context-feed-card audio{width:100%;margin-top:12px}
      .p50-context-share-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px}.p50-context-share-actions button{min-height:50px;border:1px solid #293129;border-radius:13px;background:#111611;color:#fff;font-size:11px;font-weight:950}.p50-context-share-actions button:first-child{background:var(--p50-context-accent);border-color:var(--p50-context-accent);color:#050705}
      .p50-shared-highlight{outline:3px solid #a66cff!important;box-shadow:0 0 34px rgba(166,108,255,.38)!important;animation:p50ContextPulse 1.2s ease-out 2}@keyframes p50ContextPulse{50%{transform:scale(1.01)}}
      @media(max-width:680px){
        .p50-context-share-modal{align-items:flex-end;padding:0}.p50-context-share-box{width:100vw;max-width:100vw;border-radius:23px 23px 0 0;padding:9px;padding-bottom:calc(9px + env(safe-area-inset-bottom))}.p50-context-share-preview{padding:18px}.p50-context-share-preview h2{font-size:27px}.p50-context-share-actions{grid-template-columns:1fr 1fr}.p50-context-ranking-row{grid-template-columns:29px minmax(0,1fr) 48px}.p50-context-ranking-row .move{display:none}#buzz .p50-context-share-ranking{width:100%}#top10 .p50-context-share-ranking{margin-left:0}.feed-actions .p50-context-share-post{width:100%}
      }
    `;
    document.head.appendChild(style);
  }

  function ensureModal() {
    injectStyles();
    let modal = document.getElementById('p50ContextShareModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'p50ContextShareModal';
    modal.className = 'p50-context-share-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `<div class="p50-context-share-box" role="dialog" aria-modal="true" aria-label="Partager ce contenu PASS50"><div class="p50-context-share-head"><strong>PARTAGER SUR PASS50</strong><button class="p50-context-share-close" type="button" aria-label="Fermer">×</button></div><div class="p50-context-share-preview" id="p50ContextSharePreview"></div><div class="p50-context-share-actions"><button type="button" data-p50-context-native>Partager</button><button type="button" data-p50-context-whatsapp>WhatsApp</button><button type="button" data-p50-context-copy>Copier</button><button type="button" data-p50-context-download>Télécharger</button></div></div>`;
    document.body.appendChild(modal);
    return modal;
  }

  function rankingPreview(payload) {
    const visible = payload.size === 50 ? payload.rows.slice(0, 10) : payload.rows;
    const rows = visible.map(row => `<div class="p50-context-ranking-row"><strong>#${row.rank}</strong><span class="name">${esc(row.name)}</span><span class="score">${row.score}</span><span class="move">${esc(row.movement)}</span></div>`).join('');
    const more = payload.size === 50 && payload.rows.length > visible.length ? `<div class="p50-context-ranking-more">Aperçu des 10 premiers · l’image partagée contient jusqu’aux 50 positions</div>` : '';
    return `<div class="p50-context-share-brand">PASS<span>50</span></div><div class="p50-context-share-kicker">CLASSEMENT OFFICIEL</div><h2>${esc(payload.title)}</h2><div class="p50-context-share-subtitle">${esc(payload.subtitle)} · mis à jour ${esc(payload.publishedAt)}</div><div class="p50-context-ranking-list">${rows || '<div class="p50-context-ranking-more">Classement momentanément indisponible.</div>'}</div>${more}`;
  }

  function feedPreview(payload) {
    const audio = payload.kind === 'duel-audio' && payload.audioUrl ? `<audio controls preload="metadata" src="${attr(payload.audioUrl)}"></audio>` : '';
    const label = payload.kind === 'duel-audio' ? `🎙 ${payload.author}` : `📰 ${payload.name}`;
    return `<div class="p50-context-share-brand">PASS<span>50</span></div><div class="p50-context-share-kicker">${payload.kind === 'duel-audio' ? 'AUDIO PUBLIC · LES COULÉS' : 'POST DE MON FIL'}</div><h2>${esc(payload.kind === 'duel-audio' ? payload.duel : payload.name)}</h2><div class="p50-context-share-subtitle">${esc(payload.subtitle || '')}</div><div class="p50-context-feed-card"><strong>${esc(label)}</strong><p>${esc(payload.kind === 'duel-audio' ? payload.statement : payload.title)}</p>${audio}</div>`;
  }

  function openPayload(payload) {
    if (!payload) return;
    state.current = payload;
    const modal = ensureModal();
    const box = modal.querySelector('.p50-context-share-box');
    box.style.setProperty('--p50-context-accent', payload.accent || '#b7ff00');
    const preview = modal.querySelector('#p50ContextSharePreview');
    preview.innerHTML = payload.kind === 'ranking' ? rankingPreview(payload) : feedPreview(payload);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    const modal = document.getElementById('p50ContextShareModal');
    if (modal) {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
    }
    state.current = null;
  }

  function shareMessage(payload) {
    if (payload.kind === 'ranking') {
      const leader = payload.rows[0];
      const lead = leader ? `\nN°1 : ${leader.name} · ${leader.score}/100` : '';
      return `📊 ${payload.title} — ${payload.periodLabel}, ${payload.regionLabel}.${lead}\n${payload.url}`;
    }
    if (payload.kind === 'duel-audio') return `🎙 ${payload.author} commente son vote dans ${payload.duel} sur PASS50.\n${payload.url}`;
    return `📰 ${payload.name} sur PASS50 : ${payload.title}\n${payload.url}`;
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

  function roundedRect(ctx, x, y, width, height, radius) {
    const r = Math.min(radius, width / 2, height / 2);
    ctx.beginPath();
    if (typeof ctx.roundRect === 'function') {
      ctx.roundRect(x, y, width, height, r);
      return;
    }
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + width, y, x + width, y + height, r);
    ctx.arcTo(x + width, y + height, x, y + height, r);
    ctx.arcTo(x, y + height, x, y, r);
    ctx.arcTo(x, y, x + width, y, r);
    ctx.closePath();
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

  function drawBase(ctx, accent, kicker, title, subtitle) {
    const gradient = ctx.createLinearGradient(0, 0, 1080, 1350);
    gradient.addColorStop(0, '#151b15');
    gradient.addColorStop(1, '#050705');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 1080, 1350);
    ctx.fillStyle = accent;
    ctx.fillRect(0, 0, 22, 1350);
    ctx.globalAlpha = 0.14;
    ctx.beginPath();
    ctx.arc(990, 80, 380, 0, Math.PI * 2);
    ctx.fill();
    ctx.globalAlpha = 1;
    ctx.fillStyle = '#fff';
    ctx.font = '1000 70px Arial, sans-serif';
    ctx.fillText('PASS', 64, 106);
    ctx.fillStyle = accent;
    ctx.fillText('50', 238, 106);
    ctx.font = '1000 27px Arial, sans-serif';
    ctx.fillText(kicker, 64, 185);
    ctx.fillStyle = '#fff';
    ctx.font = '1000 58px Arial, sans-serif';
    const titleLines = wrapCanvas(ctx, String(title || '').toUpperCase(), 930, 2);
    titleLines.forEach((line, index) => ctx.fillText(line, 64, 270 + index * 66));
    ctx.fillStyle = '#aeb8aa';
    ctx.font = '800 26px Arial, sans-serif';
    wrapCanvas(ctx, subtitle, 920, 2).forEach((line, index) => ctx.fillText(line, 64, 360 + index * 38));
  }

  function drawRankingImage(ctx, payload) {
    drawBase(ctx, payload.accent, 'CLASSEMENT OFFICIEL', payload.title, `${payload.subtitle} · ${payload.publishedAt}`);
    const rows = payload.rows.slice(0, payload.size);
    if (payload.size === 50) {
      const perColumn = 25;
      rows.forEach((row, index) => {
        const column = Math.floor(index / perColumn);
        const local = index % perColumn;
        const x = column === 0 ? 58 : 548;
        const y = 438 + local * 32;
        ctx.fillStyle = row.rank <= 3 ? payload.accent : '#dce5d8';
        ctx.font = '1000 20px Arial, sans-serif';
        ctx.fillText(`#${row.rank}`, x, y);
        ctx.fillStyle = '#fff';
        ctx.font = '900 18px Arial, sans-serif';
        ctx.fillText(compact(row.name, 24), x + 48, y);
        ctx.fillStyle = payload.accent;
        ctx.textAlign = 'right';
        ctx.font = '1000 18px Arial, sans-serif';
        ctx.fillText(String(row.score), x + 445, y);
        ctx.textAlign = 'left';
      });
    } else {
      const startY = payload.size === 3 ? 465 : 430;
      const rowHeight = payload.size === 3 ? 205 : 73;
      rows.forEach((row, index) => {
        const y = startY + index * rowHeight;
        roundedRect(ctx, 60, y - 42, 960, rowHeight - 12, 22);
        ctx.fillStyle = 'rgba(5,7,5,.58)';
        ctx.fill();
        ctx.strokeStyle = row.rank <= 3 ? payload.accent : 'rgba(255,255,255,.12)';
        ctx.lineWidth = row.rank <= 3 ? 4 : 2;
        ctx.stroke();
        ctx.fillStyle = payload.accent;
        ctx.font = `1000 ${payload.size === 3 ? 52 : 31}px Arial, sans-serif`;
        ctx.fillText(`#${row.rank}`, 88, y + (payload.size === 3 ? 32 : 8));
        ctx.fillStyle = '#fff';
        ctx.font = `1000 ${payload.size === 3 ? 43 : 28}px Arial, sans-serif`;
        ctx.fillText(compact(row.name, payload.size === 3 ? 30 : 42), payload.size === 3 ? 230 : 168, y + (payload.size === 3 ? 16 : 7));
        ctx.fillStyle = '#aeb8aa';
        ctx.font = `800 ${payload.size === 3 ? 25 : 18}px Arial, sans-serif`;
        ctx.fillText(row.movement, payload.size === 3 ? 230 : 168, y + (payload.size === 3 ? 58 : 31));
        ctx.fillStyle = payload.accent;
        ctx.textAlign = 'right';
        ctx.font = `1000 ${payload.size === 3 ? 48 : 30}px Arial, sans-serif`;
        ctx.fillText(`${row.score}/100`, 982, y + (payload.size === 3 ? 32 : 10));
        ctx.textAlign = 'left';
      });
    }
    ctx.fillStyle = payload.accent;
    roundedRect(ctx, 60, 1230, 960, 78, 24);
    ctx.fill();
    ctx.fillStyle = '#050705';
    ctx.textAlign = 'center';
    ctx.font = '1000 29px Arial, sans-serif';
    ctx.fillText(payload.size === 50 ? 'VOIR LE CLASSEMENT COMPLET SUR PASS50' : `VOIR LE TOP ${payload.size} SUR PASS50`, 540, 1280);
    ctx.textAlign = 'left';
  }

  function drawFeedImage(ctx, payload) {
    const kicker = payload.kind === 'duel-audio' ? 'AUDIO PUBLIC · LES COULÉS' : 'POST DE MON FIL';
    const heading = payload.kind === 'duel-audio' ? payload.duel : payload.name;
    drawBase(ctx, payload.accent, kicker, heading, payload.subtitle || 'PASS50');
    roundedRect(ctx, 64, 470, 952, 530, 30);
    ctx.fillStyle = 'rgba(5,7,5,.6)';
    ctx.fill();
    ctx.strokeStyle = payload.accent;
    ctx.lineWidth = 4;
    ctx.stroke();
    ctx.fillStyle = payload.accent;
    ctx.font = '1000 31px Arial, sans-serif';
    ctx.fillText(payload.kind === 'duel-audio' ? `🎙 ${compact(payload.author, 40)}` : `📰 ${compact(payload.name, 40)}`, 105, 545);
    ctx.fillStyle = '#fff';
    ctx.font = '1000 48px Arial, sans-serif';
    const text = payload.kind === 'duel-audio' ? payload.statement : payload.title;
    wrapCanvas(ctx, text, 850, 6).forEach((line, index) => ctx.fillText(line, 105, 655 + index * 61));
    if (payload.kind === 'duel-audio') {
      ctx.strokeStyle = payload.accent;
      ctx.lineWidth = 6;
      ctx.beginPath();
      for (let x = 120; x < 960; x += 20) {
        const amplitude = 18 + Math.abs(Math.sin(x * 0.035)) * 52;
        ctx.moveTo(x, 930 - amplitude);
        ctx.lineTo(x, 930 + amplitude);
      }
      ctx.stroke();
    }
    ctx.fillStyle = payload.accent;
    roundedRect(ctx, 64, 1120, 952, 120, 28);
    ctx.fill();
    ctx.fillStyle = '#050705';
    ctx.textAlign = 'center';
    ctx.font = '1000 34px Arial, sans-serif';
    ctx.fillText(payload.kind === 'duel-audio' ? 'ÉCOUTER L’AUDIO SUR PASS50' : 'VOIR LA FICHE ET LE CONTENU', 540, 1195);
    ctx.textAlign = 'left';
    ctx.fillStyle = '#aeb8aa';
    ctx.font = '800 24px Arial, sans-serif';
    ctx.fillText('pass50.store', 64, 1305);
  }

  function imageFile(payload) {
    return new Promise(resolve => {
      const canvas = document.createElement('canvas');
      canvas.width = 1080;
      canvas.height = 1350;
      const ctx = canvas.getContext('2d');
      if (!ctx) return resolve(null);
      if (payload.kind === 'ranking') drawRankingImage(ctx, payload);
      else drawFeedImage(ctx, payload);
      canvas.toBlob(blob => {
        if (!blob) return resolve(null);
        const suffix = payload.kind === 'ranking' ? `top-${payload.size}` : payload.kind;
        resolve(new File([blob], `pass50-${suffix}.png`, { type: 'image/png' }));
      }, 'image/png', 0.94);
    });
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
      notify('Lien copié');
    } catch (error) {
      if (error?.name === 'AbortError') return;
      try {
        await copyText(shareMessage(payload));
        notify('Lien copié');
      } catch (_) {
        notify('Partage indisponible');
      }
    }
  }

  function whatsappShare() {
    const payload = state.current;
    if (!payload) return;
    const opened = window.open(`https://wa.me/?text=${encodeURIComponent(shareMessage(payload))}`, '_blank');
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

  function shareButton(label, kind, size = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = kind === 'ranking' ? 'btn small p50-context-share-ranking' : 'btn p50-context-share-post';
    button.dataset.p50ContextShare = kind;
    if (size) button.dataset.rankingSize = String(size);
    button.innerHTML = `<span aria-hidden="true">↗</span> ${label}`;
    return button;
  }

  function injectRankingButtons() {
    if (isFeedPage) return;
    const heroIntro = document.querySelector('#buzz .hero-intro');
    if (heroIntro && !heroIntro.querySelector('[data-ranking-size="3"]')) heroIntro.appendChild(shareButton('Partager le Top 3', 'ranking', 3));

    const top10Head = document.querySelector('#top10 .section-head');
    if (top10Head && !top10Head.querySelector('[data-ranking-size="10"]')) {
      const button = shareButton('Partager le Top 10', 'ranking', 10);
      const top50 = top10Head.querySelector('#top50Btn');
      top10Head.insertBefore(button, top50 || null);
    }

    const top50Head = document.querySelector('#top50Modal .modal-head');
    if (top50Head && !top50Head.querySelector('[data-ranking-size="50"]')) {
      const button = shareButton('Partager le Top 50', 'ranking', 50);
      const close = top50Head.querySelector('.close');
      top50Head.insertBefore(button, close || null);
    }
  }

  function injectFeedButtons() {
    if (!isFeedPage) return;
    document.querySelectorAll('#feedList .feed-card').forEach(card => {
      if (card.querySelector('[data-p50-context-share="feed"]')) return;
      const actions = card.querySelector('.feed-actions');
      if (!actions) return;
      const label = card.classList.contains('duel-audio-feed-card') ? 'Partager cet audio' : 'Partager ce post';
      const button = shareButton(label, 'feed');
      actions.appendChild(button);
    });
  }

  function injectAll() {
    injectStyles();
    injectRankingButtons();
    injectFeedButtons();
  }

  function applyDeepLink() {
    if (state.deepLinkApplied) return;
    const params = new URLSearchParams(location.search);
    const section = params.get('section');
    const open = params.get('open');
    const period = params.get('period');
    const region = params.get('region');
    const audioToken = params.get('audio');
    if (!section && open !== 'top50' && !audioToken && !period && !region) return;

    let attempts = 0;
    const tryApply = () => {
      attempts += 1;
      if (period) {
        const button = document.querySelector(`#periodFilters [data-period="${period.replace(/[^A-Za-z0-9]/g, '')}"]`);
        if (button && !button.classList.contains('active')) button.click();
      }
      if (region) {
        const button = document.querySelector(`#regionFilters [data-region="${region.replace(/[^A-Za-z0-9]/g, '')}"]`);
        if (button && !button.classList.contains('active')) button.click();
      }
      if (open === 'top50') {
        const button = document.getElementById('top50Btn');
        if (button) {
          button.click();
          state.deepLinkApplied = true;
          return true;
        }
      }
      if (section) {
        const target = document.getElementById(section);
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          if (!audioToken) {
            state.deepLinkApplied = true;
            return true;
          }
        }
      }
      if (audioToken) {
        const audio = [...document.querySelectorAll('.p50-duel-audio-card audio')].find(node => audioTokenFromUrl(node.currentSrc || node.src) === audioToken);
        const card = audio?.closest('.p50-duel-audio-card');
        if (card) {
          card.classList.add('p50-shared-highlight');
          card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          state.deepLinkApplied = true;
          return true;
        }
      }
      return attempts > 120;
    };
    if (tryApply()) return;
    const timer = setInterval(() => {
      if (tryApply()) clearInterval(timer);
    }, 150);
  }

  function installEvents() {
    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const rankingButton = target.closest('[data-p50-context-share="ranking"]');
      if (rankingButton) {
        event.preventDefault();
        event.stopPropagation();
        openPayload(rankingPayload(Number(rankingButton.dataset.rankingSize || 3)));
        return;
      }
      const feedButton = target.closest('[data-p50-context-share="feed"]');
      if (feedButton) {
        event.preventDefault();
        event.stopPropagation();
        openPayload(feedPayload(feedButton.closest('.feed-card')));
        return;
      }
      if (target.closest('.p50-context-share-close') || target.id === 'p50ContextShareModal') {
        event.preventDefault();
        closeModal();
        return;
      }
      if (target.closest('[data-p50-context-native]')) {
        event.preventDefault();
        nativeShare();
        return;
      }
      if (target.closest('[data-p50-context-whatsapp]')) {
        event.preventDefault();
        whatsappShare();
        return;
      }
      if (target.closest('[data-p50-context-copy]')) {
        event.preventDefault();
        copyShare();
        return;
      }
      if (target.closest('[data-p50-context-download]')) {
        event.preventDefault();
        downloadImage();
      }
    }, true);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeModal();
    }, true);
  }

  function init() {
    ensureModal();
    installEvents();
    injectAll();
    state.observer = new MutationObserver(injectAll);
    state.observer.observe(document.body, { childList: true, subtree: true });
    setTimeout(applyDeepLink, 250);
    window.addEventListener('load', () => setTimeout(applyDeepLink, 300), { once: true });
    window.PASS50_CONTEXT_SHARE = Object.freeze({
      contract: CONTRACT,
      openRanking: size => openPayload(rankingPayload(size)),
      openFeedCard: card => openPayload(feedPayload(card)),
      contextUrl
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
