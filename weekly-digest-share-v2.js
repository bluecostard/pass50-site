'use strict';

(() => {
  const ACCENT = '#0e7c7b';
  const PAPER = '#eef1ec';
  const INK = '#0b0f0b';
  const MUTED = '#5c665c';
  const LIME = '#b7ff00';
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

  function drawAvatar(ctx, image, x, y, size, fallback) {
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
      ctx.fillStyle = INK;
      ctx.textAlign = 'center';
      ctx.font = `1000 ${Math.max(13, Math.round(size * .31))}px Arial, sans-serif`;
      ctx.fillText(fallback || 'P50', x + size / 2, y + size * .62);
      ctx.textAlign = 'left';
    }
    ctx.restore();
    ctx.strokeStyle = ACCENT;
    ctx.lineWidth = Math.max(2, Math.round(size * .035));
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2 - ctx.lineWidth / 2, 0, Math.PI * 2);
    ctx.stroke();
  }

  function photoUrl(profileId, size = 240) {
    const id = String(profileId || '').trim();
    if (!/^[A-Za-z0-9._:-]{1,100}$/.test(id)) return '';
    return `./partage-photo.php?id=${encodeURIComponent(id)}&size=${size}`;
  }

  function drawBase(ctx, weekLabel) {
    ctx.fillStyle = PAPER;
    ctx.fillRect(0, 0, 1080, 1350);
    ctx.fillStyle = ACCENT;
    ctx.fillRect(0, 0, 1080, 18);
    ctx.fillStyle = LIME;
    ctx.fillRect(64, 56, 22, 22);
    ctx.fillStyle = INK;
    ctx.font = '1000 42px Arial, sans-serif';
    ctx.fillText('PASS50', 100, 76);
    ctx.fillStyle = ACCENT;
    ctx.font = '800 22px Arial, sans-serif';
    ctx.fillText('BILAN DE LA SEMAINE', 64, 140);
    ctx.fillStyle = INK;
    ctx.font = '1000 58px Arial, sans-serif';
    ctx.fillText('Bilan du vendredi', 64, 250);
    ctx.fillStyle = MUTED;
    ctx.font = '600 28px Arial, sans-serif';
    ctx.fillText(`Semaine ${weekLabel}`, 64, 300);
  }

  async function drawWeeklyDigestCard(ctx, view) {
    drawBase(ctx, view.weekLabel || '');
    const sections = Array.isArray(view.sections) ? view.sections.slice(0, 3) : [];
    const images = await Promise.all(sections.map(section => loadImage(photoUrl(section.profileId, 240))));
    const startY = 360;
    const rowHeight = 210;

    sections.forEach((section, index) => {
      const y = startY + index * rowHeight;
      roundedRect(ctx, 60, y, 960, rowHeight - 18, 16);
      ctx.fillStyle = '#ffffff';
      ctx.fill();
      ctx.strokeStyle = '#d5dbd2';
      ctx.lineWidth = 2;
      ctx.stroke();

      const avatarSize = 108;
      drawAvatar(ctx, images[index], 92, y + 36, avatarSize, initials(section.name));

      ctx.fillStyle = ACCENT;
      ctx.font = '800 20px Arial, sans-serif';
      ctx.fillText(String(section.title || '').toUpperCase(), 230, y + 58);
      ctx.fillStyle = INK;
      ctx.font = '1000 38px Arial, sans-serif';
      wrapCanvas(ctx, section.name, 620, 2).forEach((line, lineIndex) => {
        ctx.fillText(line, 230, y + 104 + lineIndex * 42);
      });
      ctx.fillStyle = MUTED;
      ctx.font = '700 24px Arial, sans-serif';
      wrapCanvas(ctx, section.detail, 700, 2).forEach((line, lineIndex) => {
        ctx.fillText(line, 230, y + 156 + lineIndex * 30);
      });

      ctx.fillStyle = ACCENT;
      ctx.font = '1000 34px Arial, sans-serif';
      ctx.textAlign = 'right';
      ctx.fillText(String(section.num || index + 1), 990, y + 92);
      ctx.textAlign = 'left';
    });

    ctx.fillStyle = LIME;
    roundedRect(ctx, 60, 1230, 960, 78, 12);
    ctx.fill();
    ctx.fillStyle = INK;
    ctx.textAlign = 'center';
    ctx.font = '1000 28px Arial, sans-serif';
    ctx.fillText('Voir le bilan complet', 540, 1280);
    ctx.textAlign = 'left';
    ctx.fillStyle = MUTED;
    ctx.font = '600 22px Arial, sans-serif';
    ctx.fillText('pass50.store', 64, 1330);
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
