'use strict';

(() => {
  const BG = '#050705';
  const TEXT = '#f6f8f4';
  const MUTED = '#9da79b';
  const LIME = '#b7ff00';
  const LIVE = '#ff4b4b';
  const ORANGE = '#ff9d1d';
  const GOLD = '#f0d27a';
  const PURPLE = '#a66cff';
  const CI_GREEN = '#2e9e44';
  const PROD_ORIGIN = 'https://pass50.store';
  const API = './api/weekly-digest-card.php';
  const LAYOUT = {
    podiumY: 500,
    cardsY: 810,
    cardH: 126,
    cardGap: 138,
    footerY: 1238
  };

  const SECTION_THEMES = [
    { key: 'live', label: 'LIVE', headline: 'Le plus suivi', accent: LIVE, glow: 'rgba(255,75,75,.35)', icon: '●' },
    { key: 'rank', label: 'N°1', headline: 'Roi du classement', accent: GOLD, glow: 'rgba(240,210,122,.35)', icon: '♛' },
    { key: 'prono', label: 'PRONOS', headline: 'Le plus pronostiqué', accent: PURPLE, glow: 'rgba(166,108,255,.35)', icon: '◎' }
  ];

  const FALLBACK_VIEW = {
    weekKey: '2026-W34',
    weekLabel: '15/08 → 22/08/2026',
    sections: [
      { num: '1', title: 'Live le plus suivi', name: 'Samuella Kouassi', detail: '12 840 auditeurs · TikTok', metric: '12 840', metricLabel: 'auditeurs', profileId: 'census-samuella-kouassi', photoUrl: '/api/weekly-digest-photo.php?id=census-samuella-kouassi&size=480' },
      { num: '2', title: 'N°1 du classement le plus souvent', name: 'Roseline Layo', detail: '5 fois en tête (24H)', metric: '5×', metricLabel: 'en tête', profileId: 'census-roseline-layo', photoUrl: '/api/weekly-digest-photo.php?id=census-roseline-layo&size=480' },
      { num: '3', title: 'Influenceur le plus pronostiqué', name: 'Jordan Evraa', detail: '312 pronostics · 186 votants', metric: '312', metricLabel: 'pronostics', profileId: 'census-jordan-evraa', photoUrl: '/api/weekly-digest-photo.php?id=census-jordan-evraa&size=480' }
    ]
  };

  const compact = (value, max = 120) => {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();
    return text.length > max ? `${text.slice(0, Math.max(1, max - 1)).trim()}…` : text;
  };

  const initials = (value) => {
    const words = String(value || 'PASS50').trim().split(/\s+/).filter(Boolean);
    return words.slice(0, 2).map(word => word[0] || '').join('').toUpperCase() || 'P50';
  };

  function enrichSection(section, index) {
    const theme = SECTION_THEMES[index] || SECTION_THEMES[0];
    const metric = String(section.metric || '').trim();
    if (metric) return { ...section, ...theme, metric, metricLabel: section.metricLabel || '' };
    const detail = String(section.detail || '');
    const viewers = detail.match(/([\d\s]+)\s*auditeur/i);
    const times = detail.match(/(\d+)\s*fois/i);
    const votes = detail.match(/(\d+)\s*pronostic/i);
    if (viewers) return { ...section, ...theme, metric: viewers[1].trim(), metricLabel: 'auditeurs' };
    if (times) return { ...section, ...theme, metric: `${times[1]}×`, metricLabel: 'en tête' };
    if (votes) return { ...section, ...theme, metric: votes[1], metricLabel: 'pronostics' };
    return { ...section, ...theme, metric: '—', metricLabel: '' };
  }

  function roundedRect(ctx, x, y, w, h, r) {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
  }

  function wrapCanvas(ctx, text, maxWidth, maxLines = 2) {
    const words = String(text || '').split(/\s+/);
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
      const absolute = /^https?:\/\//i.test(url);
      const sameOrigin = !absolute || url.startsWith(location.origin);
      if (sameOrigin) image.crossOrigin = 'anonymous';
      image.referrerPolicy = 'no-referrer';
      image.onload = () => finish(image);
      image.onerror = () => finish(null);
      image.src = url;
      setTimeout(() => finish(null), 8000);
    });
  }

  function photoEndpoint(profileId, size = 480, photoUrl = '') {
    const direct = String(photoUrl || '').trim();
    if (direct) {
      if (/^https?:\/\//i.test(direct)) {
        return direct.includes('size=') ? direct : `${direct}${direct.includes('?') ? '&' : '?'}size=${size}`;
      }
      const rel = direct.startsWith('/') ? direct : `./${direct}`;
      return rel.includes('size=') ? rel : `${rel}${rel.includes('?') ? '&' : '?'}size=${size}`;
    }
    const id = String(profileId || '').trim();
    if (!/^[A-Za-z0-9._:-]{1,100}$/.test(id)) return '';
    return `./api/weekly-digest-photo.php?id=${encodeURIComponent(id)}&size=${size}`;
  }

  function prodPhotoEndpoint(profileId, size = 480) {
    const id = String(profileId || '').trim();
    if (!id) return '';
    return `${PROD_ORIGIN}/partage-photo.php?id=${encodeURIComponent(id)}&size=${size}`;
  }

  async function loadSectionImage(section, size = 480) {
    const primary = photoEndpoint(section.profileId, size, section.photoUrl);
    const image = primary ? await loadImage(primary) : null;
    if (image) return image;
    return loadImage(prodPhotoEndpoint(section.profileId, size));
  }

  function drawIvoryCoastFlag(ctx, x, y, w, h) {
    const stripe = w / 3;
    ctx.fillStyle = '#f77f00';
    ctx.fillRect(x, y, stripe, h);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(x + stripe, y, stripe, h);
    ctx.fillStyle = '#009e60';
    ctx.fillRect(x + stripe * 2, y, stripe, h);
    ctx.strokeStyle = 'rgba(255,255,255,.35)';
    ctx.lineWidth = 2;
    ctx.strokeRect(x + 1, y + 1, w - 2, h - 2);
  }

  function drawFestiveBackground(ctx) {
    const sky = ctx.createLinearGradient(0, 0, 1080, 1350);
    sky.addColorStop(0, '#1a1208');
    sky.addColorStop(0.22, '#120d06');
    sky.addColorStop(0.55, BG);
    sky.addColorStop(1, '#020302');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, 1080, 1350);

    const limeGlow = ctx.createRadialGradient(540, 180, 20, 540, 320, 620);
    limeGlow.addColorStop(0, 'rgba(183,255,0,.28)');
    limeGlow.addColorStop(0.45, 'rgba(255,157,29,.12)');
    limeGlow.addColorStop(1, 'rgba(183,255,0,0)');
    ctx.fillStyle = limeGlow;
    ctx.fillRect(0, 0, 1080, 700);

    const orangeGlow = ctx.createRadialGradient(180, 500, 10, 180, 500, 280);
    orangeGlow.addColorStop(0, 'rgba(255,157,29,.18)');
    orangeGlow.addColorStop(1, 'rgba(255,157,29,0)');
    ctx.fillStyle = orangeGlow;
    ctx.fillRect(0, 0, 1080, 1350);

    const greenGlow = ctx.createRadialGradient(900, 520, 10, 900, 520, 260);
    greenGlow.addColorStop(0, 'rgba(46,158,68,.16)');
    greenGlow.addColorStop(1, 'rgba(46,158,68,0)');
    ctx.fillStyle = greenGlow;
    ctx.fillRect(0, 0, 1080, 1350);

    ctx.save();
    ctx.globalAlpha = 0.08;
    ctx.strokeStyle = LIME;
    ctx.lineWidth = 2;
    for (let i = -200; i < 1300; i += 46) {
      ctx.beginPath();
      ctx.moveTo(i, 0);
      ctx.lineTo(i + 320, 1350);
      ctx.stroke();
    }
    ctx.restore();

    drawConfetti(ctx);
  }

  function drawConfetti(ctx) {
    const pieces = [
      [84, 120, LIME], [220, 88, ORANGE], [940, 110, LIVE], [1010, 180, GOLD],
      [160, 260, PURPLE], [890, 240, CI_GREEN], [70, 420, ORANGE], [1000, 390, LIME],
      [130, 680, GOLD], [960, 720, LIVE], [48, 980, LIME], [1020, 1040, ORANGE],
      [300, 60, LIVE], [760, 74, GOLD], [540, 40, LIME], [410, 150, ORANGE]
    ];
    pieces.forEach(([x, y, color], index) => {
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate((index % 7) * 0.4);
      ctx.fillStyle = color;
      if (index % 3 === 0) {
        ctx.fillRect(-5, -12, 10, 24);
      } else if (index % 3 === 1) {
        ctx.beginPath();
        ctx.arc(0, 0, 7, 0, Math.PI * 2);
        ctx.fill();
      } else {
        ctx.beginPath();
        ctx.moveTo(0, -10);
        ctx.lineTo(9, 8);
        ctx.lineTo(-9, 8);
        ctx.closePath();
        ctx.fill();
      }
      ctx.restore();
    });
  }

  function drawHeader(ctx, weekLabel) {
    ctx.fillStyle = LIME;
    ctx.fillRect(0, 0, 1080, 8);
    ctx.fillStyle = ORANGE;
    ctx.fillRect(0, 8, 1080, 4);
    ctx.fillStyle = CI_GREEN;
    ctx.fillRect(0, 12, 1080, 4);

    ctx.fillStyle = LIME;
    ctx.fillRect(48, 42, 16, 16);
    ctx.fillStyle = TEXT;
    ctx.font = '1000 30px Arial, sans-serif';
    ctx.fillText('PASS', 72, 58);
    ctx.fillStyle = LIME;
    ctx.fillText('50', 168, 58);

    drawIvoryCoastFlag(ctx, 248, 36, 54, 36);

    roundedRect(ctx, 314, 34, 290, 34, 17);
    ctx.fillStyle = 'rgba(255,157,29,.14)';
    ctx.fill();
    ctx.strokeStyle = ORANGE;
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.fillStyle = ORANGE;
    ctx.font = '800 16px Arial, sans-serif';
    ctx.fillText('CÔTE D’IVOIRE · INFLUENCEURS', 332, 57);

    ctx.fillStyle = LIME;
    ctx.font = '1000 74px Arial, sans-serif';
    ctx.fillText('TOP 3', 48, 150);
    ctx.fillStyle = TEXT;
    ctx.fillText('DE LA SEMAINE', 48, 228);
    ctx.fillStyle = ORANGE;
    ctx.font = '1000 42px Arial, sans-serif';
    ctx.fillText('Ils ont fait le buzz à Abidjan', 48, 278);

    ['★', '✦', '★'].forEach((star, index) => {
      ctx.fillStyle = index === 1 ? LIME : ORANGE;
      ctx.font = `1000 ${index === 1 ? 34 : 26}px Arial, sans-serif`;
      ctx.fillText(star, 720 + index * 42, 140 + index * 18);
    });

    ctx.textAlign = 'right';
    ctx.fillStyle = MUTED;
    ctx.font = '700 18px Arial, sans-serif';
    ctx.fillText('VENDREDI SOIR', 1032, 58);
    ctx.fillStyle = TEXT;
    ctx.font = '800 22px Arial, sans-serif';
    ctx.fillText(`Semaine ${weekLabel}`, 1032, 88);
    ctx.textAlign = 'left';
  }

  function drawSpotlight(ctx, x, y, radius, glowColor) {
    const spot = ctx.createRadialGradient(x, y, 8, x, y, radius);
    spot.addColorStop(0, glowColor);
    spot.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = spot;
    ctx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
  }

  function drawPhotoCircle(ctx, image, x, y, size, fallback, accent, glow, rankLabel) {
    drawSpotlight(ctx, x + size / 2, y + size / 2, size * 0.95, glow);

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
      const grad = ctx.createLinearGradient(x, y, x + size, y + size);
      grad.addColorStop(0, '#2a2218');
      grad.addColorStop(1, '#0d110d');
      ctx.fillStyle = grad;
      ctx.fillRect(x, y, size, size);
      ctx.fillStyle = TEXT;
      ctx.textAlign = 'center';
      ctx.font = `1000 ${Math.max(24, Math.round(size * .22))}px Arial, sans-serif`;
      ctx.fillText(fallback, x + size / 2, y + size * .58);
      ctx.textAlign = 'left';
    }
    ctx.restore();

    ctx.strokeStyle = accent;
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2 - 3, 0, Math.PI * 2);
    ctx.stroke();

    if (rankLabel) {
      const label = String(rankLabel).toUpperCase();
      ctx.font = '1000 20px Arial, sans-serif';
      const badgeW = Math.max(74, ctx.measureText(label).width + 28);
      const badgeH = 40;
      const bx = x + size / 2 - badgeW / 2;
      const by = y - 22;
      roundedRect(ctx, bx, by, badgeW, badgeH, 20);
      ctx.fillStyle = accent;
      ctx.fill();
      ctx.strokeStyle = TEXT;
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.fillStyle = BG;
      ctx.textAlign = 'center';
      ctx.font = '1000 20px Arial, sans-serif';
      ctx.fillText(label, bx + badgeW / 2, by + 27);
      ctx.textAlign = 'left';
    }
  }

  function drawPodium(ctx, sections, images) {
    const podiumY = LAYOUT.podiumY;
    const slots = [
      { x: 108, y: podiumY - 68, size: 198, badge: 'N°1', accent: GOLD, glow: 'rgba(240,210,122,.28)', sectionIndex: 1, podiumH: 100 },
      { x: 372, y: podiumY - 122, size: 296, badge: 'LIVE', accent: LIVE, glow: 'rgba(255,75,75,.32)', sectionIndex: 0, podiumH: 158 },
      { x: 742, y: podiumY - 48, size: 182, badge: 'PRONOS', accent: PURPLE, glow: 'rgba(166,108,255,.28)', sectionIndex: 2, podiumH: 82 }
    ];

    slots.forEach(slot => {
      roundedRect(ctx, slot.x - 16, podiumY, slot.size + 32, slot.podiumH, 10);
      const grad = ctx.createLinearGradient(slot.x, podiumY, slot.x, podiumY + slot.podiumH);
      grad.addColorStop(0, slot.accent);
      grad.addColorStop(1, '#24180a');
      ctx.fillStyle = grad;
      ctx.fill();
      ctx.strokeStyle = 'rgba(255,255,255,.28)';
      ctx.lineWidth = 2;
      ctx.stroke();
    });

    [1, 0, 2].forEach(drawOrder => {
      const slot = slots[drawOrder];
      const section = sections[slot.sectionIndex];
      if (!section) return;
      drawPhotoCircle(
        ctx,
        images[slot.sectionIndex],
        slot.x,
        slot.y,
        slot.size,
        initials(section.name),
        slot.accent,
        slot.glow,
        slot.badge
      );

      const name = compact(section.name, 16).toUpperCase();
      ctx.textAlign = 'center';
      ctx.fillStyle = TEXT;
      ctx.font = '1000 22px Arial, sans-serif';
      ctx.fillText(name, slot.x + slot.size / 2, slot.y + slot.size + 32);
      ctx.textAlign = 'left';
    });
  }

  function drawMiniPhoto(ctx, image, x, y, size, fallback, accent) {
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
      ctx.fillStyle = '#1a221a';
      ctx.fillRect(x, y, size, size);
      ctx.fillStyle = TEXT;
      ctx.textAlign = 'center';
      ctx.font = `1000 ${Math.max(14, Math.round(size * .28))}px Arial, sans-serif`;
      ctx.fillText(fallback, x + size / 2, y + size * .62);
      ctx.textAlign = 'left';
    }
    ctx.restore();
    ctx.strokeStyle = accent;
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2 - 1.5, 0, Math.PI * 2);
    ctx.stroke();
  }

  function drawHeroStatCard(ctx, section, y, image) {
    const cardH = LAYOUT.cardH;
    roundedRect(ctx, 40, y, 1000, cardH, 22);
    const cardGrad = ctx.createLinearGradient(40, y, 1040, y + cardH);
    cardGrad.addColorStop(0, 'rgba(255,255,255,.07)');
    cardGrad.addColorStop(1, 'rgba(255,255,255,.02)');
    ctx.fillStyle = cardGrad;
    ctx.fill();
    ctx.strokeStyle = section.accent;
    ctx.lineWidth = 3;
    ctx.stroke();

    drawMiniPhoto(ctx, image, 58, y + 20, 86, initials(section.name), section.accent);

    ctx.fillStyle = section.accent;
    ctx.font = '1000 22px Arial, sans-serif';
    ctx.fillText(section.label, 168, y + 38);
    ctx.fillStyle = TEXT;
    ctx.font = '1000 28px Arial, sans-serif';
    ctx.fillText(section.headline, 168, y + 72);
    ctx.fillStyle = MUTED;
    ctx.font = '700 18px Arial, sans-serif';
    wrapCanvas(ctx, section.title, 360, 2).forEach((line, index) => {
      ctx.fillText(line, 168, y + 100 + index * 22);
    });

    ctx.textAlign = 'right';
    ctx.fillStyle = section.accent;
    ctx.font = '1000 56px Arial, sans-serif';
    ctx.fillText(section.metric, 1010, y + 72);
    ctx.fillStyle = TEXT;
    ctx.font = '800 20px Arial, sans-serif';
    ctx.fillText(section.metricLabel, 1010, y + 100);
    ctx.fillStyle = TEXT;
    ctx.font = '1000 24px Arial, sans-serif';
    ctx.fillText(compact(section.name, 22), 1010, y + 126);
    ctx.textAlign = 'left';
  }

  function drawFooter(ctx) {
    const y = LAYOUT.footerY;
    roundedRect(ctx, 40, y, 1000, 88, 18);
    ctx.fillStyle = LIME;
    ctx.fill();
    ctx.fillStyle = BG;
    ctx.font = '1000 38px Arial, sans-serif';
    ctx.fillText('PASS50.STORE', 68, y + 56);
    ctx.textAlign = 'right';
    ctx.font = '800 22px Arial, sans-serif';
    ctx.fillText('CLASSEMENT · LIVE · PRONOS', 1010, y + 42);
    ctx.font = '700 18px Arial, sans-serif';
    ctx.fillText('Qui dit quoi, qui va où ?', 1010, y + 70);
    ctx.textAlign = 'left';
  }

  async function drawWeeklyDigestCard(ctx, view) {
    const sections = Array.isArray(view.sections) ? view.sections.slice(0, 3).map(enrichSection) : [];
    const images = await Promise.all(sections.map(section => loadSectionImage(section, 480)));

    drawFestiveBackground(ctx);
    drawHeader(ctx, view.weekLabel || '');
    drawPodium(ctx, sections, images);

    sections.forEach((section, index) => {
      drawHeroStatCard(ctx, section, LAYOUT.cardsY + index * LAYOUT.cardGap, images[index]);
    });

    drawFooter(ctx);
  }

  async function renderPreview(canvas, view) {
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Canvas indisponible');
    await drawWeeklyDigestCard(ctx, view);
    return canvas;
  }

  async function fetchView(preview = true, week = '') {
    try {
      const url = new URL(API, location.href);
      if (preview) url.searchParams.set('preview', '1');
      if (week) url.searchParams.set('week', week);
      const response = await fetch(url.toString(), { credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok || !data?.ok || !data?.view) {
        throw new Error(data?.error || 'Impossible de charger le bilan');
      }
      const view = data.view;
      view.sections = (view.sections || []).map(enrichSection);
      return view;
    } catch (error) {
      if (preview) {
        return {
          ...FALLBACK_VIEW,
          sections: FALLBACK_VIEW.sections.map(enrichSection)
        };
      }
      throw error;
    }
  }

  async function downloadPng(canvas, weekKey = 'demo') {
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png', 0.94));
    if (!blob) throw new Error('Export PNG impossible');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `pass50-bilan-${weekKey || 'demo'}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }

  async function boot(options = {}) {
    const canvas = document.getElementById('weeklyDigestCanvas');
    const status = document.getElementById('weeklyDigestStatus');
    if (!canvas) return null;
    try {
      if (status) status.textContent = 'Génération de l’aperçu…';
      const view = await fetchView(options.preview !== false, options.week || '');
      await renderPreview(canvas, view);
      if (status) status.textContent = 'Aperçu prêt — validez le rendu avant export.';
      return view;
    } catch (error) {
      if (status) status.textContent = error?.message || 'Aperçu indisponible';
      throw error;
    }
  }

  window.PASS50_WEEKLY_DIGEST_SHARE_V2 = {
    boot,
    renderPreview,
    fetchView,
    downloadPng,
    drawWeeklyDigestCard
  };
})();
