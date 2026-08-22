'use strict';

(() => {
  const BG = '#050705';
  const PANEL = '#0d110d';
  const LINE = '#293129';
  const TEXT = '#f6f8f4';
  const MUTED = '#9da79b';
  const LIME = '#b7ff00';
  const LIVE = '#ff4b4b';
  const API = './api/weekly-digest-card.php';
  const FALLBACK_VIEW = {
    weekKey: '2026-W34',
    weekLabel: '15/08 → 22/08/2026',
    sections: [
      { num: '1', title: 'Live le plus suivi', name: 'Samuella Kouassi', detail: '12 840 auditeurs · TikTok', profileId: 'census-samuella-kouassi' },
      { num: '2', title: 'N°1 du classement le plus souvent', name: 'Roseline Layo', detail: '5 fois en tête (24H)', profileId: 'census-roseline-layo' },
      { num: '3', title: 'Influenceur le plus pronostiqué', name: 'Jordan Evraa', detail: '312 pronostics · 186 votants', profileId: 'census-jordan-evraa' }
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
      image.crossOrigin = 'anonymous';
      image.onload = () => finish(image);
      image.onerror = () => finish(null);
      image.src = url;
      setTimeout(() => finish(null), 5000);
    });
  }

  function photoUrl(profileId, size = 480) {
    const id = String(profileId || '').trim();
    if (!/^[A-Za-z0-9._:-]{1,100}$/.test(id)) return '';
    return `./partage-photo.php?id=${encodeURIComponent(id)}&size=${size}`;
  }

  function drawPosterBackground(ctx) {
    const gradient = ctx.createLinearGradient(0, 0, 0, 1350);
    gradient.addColorStop(0, '#101510');
    gradient.addColorStop(0.45, BG);
    gradient.addColorStop(1, '#020302');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 1080, 1350);

    const glow = ctx.createRadialGradient(540, 260, 40, 540, 320, 520);
    glow.addColorStop(0, 'rgba(183,255,0,.22)');
    glow.addColorStop(0.55, 'rgba(183,255,0,.06)');
    glow.addColorStop(1, 'rgba(183,255,0,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, 1080, 720);

    ctx.fillStyle = 'rgba(5,7,5,.55)';
    ctx.fillRect(0, 500, 1080, 220);

    ctx.strokeStyle = LIME;
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(0, 18);
    ctx.lineTo(1080, 18);
    ctx.stroke();
  }

  function drawBrandHeader(ctx) {
    ctx.fillStyle = LIME;
    ctx.fillRect(54, 48, 18, 18);
    ctx.fillStyle = TEXT;
    ctx.font = '1000 34px Arial, sans-serif';
    ctx.fillText('PASS', 82, 66);
    ctx.fillStyle = LIME;
    ctx.fillText('50', 198, 66);
    ctx.fillStyle = MUTED;
    ctx.font = '700 18px Arial, sans-serif';
    ctx.fillText('BILAN DU VENDREDI SOIR', 54, 98);
  }

  function drawPhotoFrame(ctx, image, x, y, w, h, fallback, accent = LIME) {
    ctx.save();
    roundedRect(ctx, x, y, w, h, 18);
    ctx.clip();
    if (image) {
      const ratio = Math.max(w / image.width, h / image.height);
      const width = image.width * ratio;
      const height = image.height * ratio;
      ctx.drawImage(image, x + (w - width) / 2, y + (h - height) / 2, width, height);
    } else {
      const grad = ctx.createLinearGradient(x, y, x + w, y + h);
      grad.addColorStop(0, '#1a221a');
      grad.addColorStop(1, '#0a0d0a');
      ctx.fillStyle = grad;
      ctx.fillRect(x, y, w, h);
      ctx.fillStyle = TEXT;
      ctx.textAlign = 'center';
      ctx.font = `1000 ${Math.max(28, Math.round(w * .18))}px Arial, sans-serif`;
      ctx.fillText(fallback, x + w / 2, y + h * .56);
      ctx.textAlign = 'left';
    }
    ctx.restore();
    ctx.strokeStyle = accent;
    ctx.lineWidth = 4;
    roundedRect(ctx, x, y, w, h, 18);
    ctx.stroke();
  }

  function drawNameTag(ctx, name, x, y, maxWidth = 300) {
    const label = compact(name, 22).toUpperCase();
    ctx.font = '1000 22px Arial, sans-serif';
    const width = Math.min(maxWidth, ctx.measureText(label).width + 28);
    roundedRect(ctx, x, y, width, 38, 8);
    ctx.fillStyle = 'rgba(5,7,5,.82)';
    ctx.fill();
    ctx.strokeStyle = LIME;
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.fillStyle = TEXT;
    ctx.fillText(label, x + 14, y + 27);
  }

  function drawPhotoCollage(ctx, sections, images) {
    const slots = [
      { x: 300, y: 130, w: 480, h: 430, accent: LIVE },
      { x: 54, y: 250, w: 300, h: 360, accent: LIME },
      { x: 726, y: 250, w: 300, h: 360, accent: LIME }
    ];
    slots.forEach((slot, index) => {
      const section = sections[index];
      if (!section) return;
      drawPhotoFrame(ctx, images[index], slot.x, slot.y, slot.w, slot.h, initials(section.name), slot.accent);
      drawNameTag(ctx, section.name, slot.x + 12, slot.y + slot.h - 52, slot.w - 24);
    });
  }

  function drawStatRows(ctx, sections, weekLabel) {
    const startY = 640;
    sections.forEach((section, index) => {
      const y = startY + index * 118;
      const num = String(section.num || index + 1).padStart(2, '0');
      ctx.fillStyle = LIME;
      ctx.font = '1000 58px Arial, sans-serif';
      ctx.fillText(num, 54, y + 52);
      ctx.fillStyle = index === 0 ? LIVE : LIME;
      ctx.font = '800 18px Arial, sans-serif';
      ctx.fillText(String(section.title || '').toUpperCase(), 150, y + 10);
      ctx.fillStyle = TEXT;
      ctx.font = '1000 34px Arial, sans-serif';
      ctx.fillText(compact(section.name, 28), 150, y + 48);
      ctx.fillStyle = MUTED;
      ctx.font = '700 22px Arial, sans-serif';
      wrapCanvas(ctx, section.detail, 760, 2).forEach((line, lineIndex) => {
        ctx.fillText(line, 150, y + 82 + lineIndex * 28);
      });
      if (index < sections.length - 1) {
        ctx.strokeStyle = LINE;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(54, y + 98);
        ctx.lineTo(1026, y + 98);
        ctx.stroke();
      }
    });

    ctx.fillStyle = MUTED;
    ctx.font = '700 20px Arial, sans-serif';
    ctx.fillText(`Semaine ${weekLabel}`, 54, startY + sections.length * 118 + 18);
  }

  function drawPosterTitle(ctx) {
    ctx.save();
    ctx.translate(760, 1120);
    ctx.rotate(-0.08);
    ctx.fillStyle = 'rgba(183,255,0,.12)';
    ctx.font = '1000 118px Arial, sans-serif';
    ctx.fillText('BILAN', -10, 0);
    ctx.fillStyle = TEXT;
    ctx.font = '1000 108px Arial, sans-serif';
    ctx.fillText('BILAN', -14, -4);
    ctx.fillStyle = LIME;
    ctx.font = '1000 92px Arial, sans-serif';
    ctx.fillText('SEMAINE', 8, 92);
    ctx.restore();
  }

  function drawFooter(ctx, weekLabel) {
    ctx.fillStyle = LIME;
    ctx.fillRect(0, 1248, 1080, 102);
    ctx.fillStyle = BG;
    ctx.font = '1000 42px Arial, sans-serif';
    ctx.fillText('PASS50.STORE', 54, 1312);
    ctx.textAlign = 'right';
    ctx.fillStyle = BG;
    ctx.font = '800 24px Arial, sans-serif';
    ctx.fillText('3 TOPS · 1 AFFICHE', 1026, 1300);
    ctx.font = '700 20px Arial, sans-serif';
    ctx.fillText(compact(`Semaine ${weekLabel}`, 40), 1026, 1328);
    ctx.textAlign = 'left';
  }

  async function drawWeeklyDigestCard(ctx, view) {
    const sections = Array.isArray(view.sections) ? view.sections.slice(0, 3) : [];
    const images = await Promise.all(sections.map(section => loadImage(photoUrl(section.profileId, 480))));

    drawPosterBackground(ctx);
    drawBrandHeader(ctx);
    drawPhotoCollage(ctx, sections, images);
    drawStatRows(ctx, sections, view.weekLabel || '');
    drawPosterTitle(ctx);
    drawFooter(ctx, view.weekLabel || '');
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
      return data.view;
    } catch (error) {
      if (preview) return { ...FALLBACK_VIEW };
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
