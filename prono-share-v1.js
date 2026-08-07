'use strict';

(() => {
  const CONTRACT = 'PASS50-PRONO-SHARE-V1.2';
  if (window.PASS50_PRONO_SHARE) return;

  // Même proportions que .diapo-frame / .prono-diapo-frame (Stories 1080×1350)
  const W = 1080;
  const H = 1350;
  const COLORS = {
    bg: '#050705',
    frame: '#0a0d0a',
    media: '#101610',
    ticket: '#0a0d0a',
    ink: '#f6f8f4',
    muted: '#8f998c',
    soft: '#c9d2c4',
    lime: '#b7ff00',
    line: 'rgba(183,255,0,0.22)',
    lineSoft: 'rgba(255,255,255,0.12)',
  };

  let modal = null;
  let previewUrl = null;
  let currentFile = null;
  let currentPayload = null;

  function fmtOdd(odd) {
    const n = Number(odd);
    if (!Number.isFinite(n) || n <= 0) return '—';
    return n.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  }

  function wrapLines(ctx, text, maxWidth, maxLines = 4) {
    const words = String(text || '').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return [''];
    const lines = [];
    let line = '';
    for (const word of words) {
      const next = line ? `${line} ${word}` : word;
      if (ctx.measureText(next).width <= maxWidth) {
        line = next;
        continue;
      }
      if (line) lines.push(line);
      line = word;
      if (lines.length >= maxLines - 1) break;
    }
    if (lines.length < maxLines && line) lines.push(line);
    if (words.length && lines.length === maxLines) {
      let last = lines[maxLines - 1];
      while (ctx.measureText(`${last}…`).width > maxWidth && last.length > 3) {
        last = last.slice(0, -1);
      }
      lines[maxLines - 1] = `${last}…`;
    }
    return lines;
  }

  function roundRect(ctx, x, y, w, h, r) {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
  }

  function absUrl(url) {
    let value = String(url || '').trim();
    if (!value || value.startsWith('data:image/svg')) return '';
    if (value.startsWith('//')) value = `${location.protocol}${value}`;
    else if (value.startsWith('/')) value = `${location.origin}${value}`;
    else if (!/^https?:|^data:|^blob:/i.test(value)) {
      try { value = new URL(value, location.href).href; } catch (_) { return ''; }
    }
    return value;
  }

  function loadImage(url) {
    return new Promise((resolve) => {
      const src = absUrl(url);
      if (!src) return resolve(null);
      const image = new Image();
      let settled = false;
      const finish = (value) => {
        if (settled) return;
        settled = true;
        resolve(value);
      };
      if (!src.startsWith('data:')) image.crossOrigin = 'anonymous';
      image.onload = () => finish(image);
      image.onerror = () => finish(null);
      image.src = src;
      setTimeout(() => finish(null), 6000);
    });
  }

  function drawImageCover(ctx, image, x, y, w, h) {
    const ratio = Math.max(w / image.width, h / image.height);
    const dw = image.width * ratio;
    const dh = image.height * ratio;
    ctx.drawImage(image, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
  }

  function drawAvatar(ctx, image, x, y, size, fallback) {
    ctx.save();
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2, 0, Math.PI * 2);
    ctx.closePath();
    ctx.clip();
    if (image) {
      drawImageCover(ctx, image, x, y, size, size);
    } else {
      ctx.fillStyle = '#1a211a';
      ctx.fillRect(x, y, size, size);
      ctx.fillStyle = COLORS.ink;
      ctx.font = `1000 ${Math.round(size * 0.34)}px Arial`;
      ctx.textAlign = 'center';
      ctx.fillText((fallback || 'P50').slice(0, 2).toUpperCase(), x + size / 2, y + size * 0.64);
      ctx.textAlign = 'left';
    }
    ctx.restore();
    ctx.beginPath();
    ctx.arc(x + size / 2, y + size / 2, size / 2, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(183,255,0,0.35)';
    ctx.lineWidth = 3;
    ctx.stroke();
  }

  function drawFallbackCover(ctx, x, y, w, h, title) {
    ctx.fillStyle = COLORS.media;
    ctx.fillRect(x, y, w, h);
    const g = ctx.createLinearGradient(x, y, x + w, y + h);
    g.addColorStop(0, '#151a15');
    g.addColorStop(1, '#050705');
    ctx.fillStyle = g;
    ctx.fillRect(x, y, w, h);
    ctx.fillStyle = '#6b7568';
    ctx.font = '800 28px Arial';
    ctx.fillText('PASS50', x + 48, y + 80);
    ctx.fillStyle = '#c9d2c4';
    ctx.font = '800 42px Arial';
    const lines = wrapLines(ctx, String(title || 'PRONO').slice(0, 40), w - 96, 2);
    let ty = y + h * 0.55;
    lines.forEach((line) => {
      ctx.fillText(line, x + 48, ty);
      ty += 52;
    });
  }

  /** Carte = clone visuel du statut diapo publié */
  function drawStatusDiapo(ctx, payload, coverImage, avatarImage) {
    const title = String(payload.title || 'Pronostic');
    const choice = String(payload.choice || '—');
    const odd = fmtOdd(payload.odd);
    const payout = Math.round(Number(payload.payout) || 0);
    const author = String(payload.author || 'Membre').trim() || 'Membre';
    const hours = Number(payload.durationHours) > 0 ? Number(payload.durationHours) : 24;

    // Fond page
    ctx.fillStyle = COLORS.bg;
    ctx.fillRect(0, 0, W, H);

    // Frame (comme .diapo-frame)
    const fx = 36;
    const fy = 48;
    const fw = W - 72;
    const fh = H - 96;
    const radius = 48;
    roundRect(ctx, fx, fy, fw, fh, radius);
    ctx.fillStyle = COLORS.frame;
    ctx.fill();
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 4;
    ctx.stroke();

    ctx.save();
    roundRect(ctx, fx, fy, fw, fh, radius);
    ctx.clip();

    // Media top ~42%
    const mediaH = Math.round(fh * 0.42);
    ctx.fillStyle = COLORS.media;
    ctx.fillRect(fx, fy, fw, mediaH);
    if (coverImage) {
      drawImageCover(ctx, coverImage, fx, fy, fw, mediaH);
    } else {
      drawFallbackCover(ctx, fx, fy, fw, mediaH, choice);
    }

    const shade = ctx.createLinearGradient(fx, fy + mediaH * 0.15, fx, fy + mediaH);
    shade.addColorStop(0, 'rgba(5,7,5,0.18)');
    shade.addColorStop(1, 'rgba(5,7,5,0.94)');
    ctx.fillStyle = shade;
    ctx.fillRect(fx, fy, fw, mediaH);

    // Brand badge
    ctx.fillStyle = 'rgba(246,248,244,0.72)';
    ctx.font = '1000 22px Arial';
    ctx.fillText('PASS50 · STATUT', fx + 36, fy + 52);

    // Body
    const bodyX = fx + 40;
    const bodyW = fw - 80;
    let y = fy + mediaH + 40;

    // Author row
    const avatarSize = 88;
    drawAvatar(ctx, avatarImage, bodyX, y, avatarSize, author);
    ctx.fillStyle = COLORS.ink;
    ctx.font = '900 34px Arial';
    ctx.fillText(author.slice(0, 28), bodyX + avatarSize + 24, y + 38);
    ctx.fillStyle = COLORS.muted;
    ctx.font = '700 24px Arial';
    ctx.fillText(`Statut · ${hours} h`, bodyX + avatarSize + 24, y + 72);
    y += avatarSize + 48;

    // Question
    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 52px Arial Black, Impact, Arial';
    const qLines = wrapLines(ctx, title, bodyW, 4);
    qLines.forEach((line) => {
      ctx.fillText(line, bodyX, y);
      y += 62;
    });
    y += 28;

    // Ticket (comme .diapo-ticket)
    const ticketH = 220;
    const ticketY = Math.min(y, fy + fh - ticketH - 140);
    roundRect(ctx, bodyX, ticketY, bodyW, ticketH, 32);
    ctx.fillStyle = COLORS.ticket;
    ctx.fill();
    ctx.strokeStyle = 'rgba(183,255,0,0.16)';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = COLORS.muted;
    ctx.font = '900 22px Arial';
    ctx.fillText('SON CHOIX', bodyX + 32, ticketY + 48);

    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 40px Arial';
    const choiceLines = wrapLines(ctx, choice, bodyW - 220, 2);
    let cy = ticketY + 100;
    choiceLines.forEach((line) => {
      ctx.fillText(line, bodyX + 32, cy);
      cy += 48;
    });

    ctx.fillStyle = COLORS.lime;
    ctx.font = '1000 54px Arial Black, Impact, Arial';
    ctx.textAlign = 'right';
    ctx.fillText(`@${odd}`, bodyX + bodyW - 32, ticketY + 118);
    ctx.textAlign = 'left';

    ctx.fillStyle = COLORS.soft;
    ctx.font = '800 26px Arial';
    ctx.fillText(
      payout > 0 ? `Gain pot. ${payout} pts · sans argent réel` : 'Sans argent réel',
      bodyX + 32,
      ticketY + ticketH - 36,
    );

    // CTA bas (remplace Like / Partager / Jouer)
    const ctaY = fy + fh - 108;
    roundRect(ctx, bodyX, ctaY, bodyW, 72, 22);
    ctx.fillStyle = COLORS.lime;
    ctx.fill();
    ctx.fillStyle = COLORS.bg;
    ctx.font = '1000 28px Arial';
    ctx.fillText('Pronostique aussi sur pass50.store', bodyX + 36, ctaY + 46);

    ctx.restore();
  }

  /** Fallback « mon prono » (hors statut) — ticket simple */
  function drawMine(ctx, payload) {
    const title = String(payload.title || 'Pronostic PASS50');
    const choice = String(payload.choice || '—');
    const odd = fmtOdd(payload.odd);
    const payout = Math.round(Number(payload.payout) || 0);
    const stake = Math.round(Number(payload.stake) || 100);

    ctx.fillStyle = COLORS.bg;
    ctx.fillRect(0, 0, W, H);

    ctx.fillStyle = COLORS.lime;
    ctx.fillRect(64, 72, 28, 28);
    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 54px Arial Black, Impact, Arial';
    ctx.fillText('PASS50', 110, 100);
    ctx.fillStyle = COLORS.muted;
    ctx.font = '800 22px Arial';
    ctx.fillText('PRONOSTICS', 110, 136);

    ctx.fillStyle = COLORS.lime;
    ctx.font = '900 24px Arial';
    ctx.fillText('MON PRONO', 64, 220);

    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 56px Arial Black, Impact, Arial';
    const titleLines = wrapLines(ctx, title, W - 128, 4);
    let y = 290;
    titleLines.forEach((line) => {
      ctx.fillText(line, 64, y);
      y += 68;
    });

    const ticketY = Math.max(560, y + 40);
    roundRect(ctx, 64, ticketY, W - 128, 400, 36);
    ctx.fillStyle = COLORS.frame;
    ctx.fill();
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 3;
    ctx.stroke();

    ctx.fillStyle = COLORS.muted;
    ctx.font = '900 22px Arial';
    ctx.fillText('MON CHOIX', 110, ticketY + 58);
    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 48px Arial';
    wrapLines(ctx, choice, W - 280, 2).forEach((line, i) => {
      ctx.fillText(line, 110, ticketY + 130 + i * 58);
    });

    roundRect(ctx, W - 314, ticketY + 86, 210, 210, 28);
    ctx.fillStyle = '#121812';
    ctx.fill();
    ctx.strokeStyle = COLORS.lime;
    ctx.lineWidth = 3;
    ctx.stroke();
    ctx.textAlign = 'center';
    ctx.fillStyle = COLORS.muted;
    ctx.font = '900 20px Arial';
    ctx.fillText('COTE', W - 209, ticketY + 138);
    ctx.fillStyle = COLORS.lime;
    ctx.font = '1000 72px Arial Black, Impact, Arial';
    ctx.fillText(odd, W - 209, ticketY + 216);
    ctx.fillStyle = COLORS.ink;
    ctx.font = '800 22px Arial';
    ctx.fillText(`mise ${stake}`, W - 209, ticketY + 258);
    ctx.textAlign = 'left';

    ctx.fillStyle = COLORS.lime;
    ctx.font = '1000 34px Arial';
    ctx.fillText(payout > 0 ? `Gain pot. +${payout} pts` : 'Sans argent réel', 110, ticketY + 340);

    roundRect(ctx, 64, H - 128, W - 128, 72, 20);
    ctx.fillStyle = COLORS.lime;
    ctx.fill();
    ctx.fillStyle = COLORS.bg;
    ctx.font = '1000 30px Arial';
    ctx.fillText('Pronostique aussi sur pass50.store', 96, H - 80);
  }

  function buildPayload(input = {}) {
    return {
      mode: input.mode === 'status' ? 'status' : 'mine',
      title: String(input.title || 'Pronostic PASS50').trim(),
      choice: String(input.choice || input.optionLabel || '—').trim(),
      odd: Number(input.odd) || 0,
      stake: Number(input.stake) || 100,
      payout: Number(input.payout) || Math.round((Number(input.stake) || 100) * (Number(input.odd) || 0)),
      author: String(input.author || input.authorPseudo || '').trim(),
      authorPhoto: absUrl(input.authorPhoto || input.authorAvatar || ''),
      coverPhoto: absUrl(input.coverPhoto || input.coverUrl || input.image || ''),
      durationHours: Number(input.durationHours) || 24,
      url: String(input.url || `${location.origin}${location.pathname.replace(/[^/]*$/, '')}pronostics.html`),
    };
  }

  function shareText(payload) {
    const odd = fmtOdd(payload.odd);
    const who = payload.mode === 'status' && payload.author ? `${payload.author} · ` : '';
    const line = payload.mode === 'status'
      ? `Statut prono PASS50 — ${who}${payload.title} → ${payload.choice}${odd !== '—' ? ` @${odd}` : ''}`
      : `Mon prono PASS50 : ${payload.title} → ${payload.choice}${odd !== '—' ? ` @${odd}` : ''}`;
    return `${line}\nSans argent réel · ${payload.url.replace(/^https?:\/\//, '')}`;
  }

  async function imageFile(payload) {
    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Canvas indisponible');

    if (payload.mode === 'status') {
      const [coverImage, avatarImage] = await Promise.all([
        loadImage(payload.coverPhoto),
        loadImage(payload.authorPhoto),
      ]);
      drawStatusDiapo(ctx, payload, coverImage, avatarImage);
    } else {
      drawMine(ctx, payload);
    }

    const blob = await new Promise((resolve, reject) => {
      canvas.toBlob((b) => (b && b.size ? resolve(b) : reject(new Error('Image impossible'))), 'image/png', 0.95);
    });
    return new File([blob], 'pass50-statut-prono.png', { type: 'image/png' });
  }

  function ensureModal() {
    if (modal) return modal;
    const style = document.createElement('style');
    style.id = 'p50PronoShareStyles';
    style.textContent = `
      .p50-prono-share-modal{position:fixed;inset:0;z-index:16000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(5,7,5,.78);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px)}
      .p50-prono-share-modal.show{display:flex}
      .p50-prono-share-box{width:min(420px,100%);max-height:94vh;overflow:auto;border:1px solid rgba(183,255,0,.22);border-radius:22px;background:linear-gradient(180deg,#101610,#070a07);box-shadow:0 24px 70px rgba(0,0,0,.5);padding:14px}
      .p50-prono-share-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;color:#f4f7f0}
      .p50-prono-share-head strong{font-size:15px;font-weight:950;letter-spacing:.02em}
      .p50-prono-share-close{width:42px;height:42px;border:1px solid rgba(183,255,0,.2);border-radius:12px;background:#0a0d0a;color:#fff;font-size:22px}
      .p50-prono-share-preview{border-radius:16px;overflow:hidden;border:1px solid rgba(183,255,0,.16);background:#050705}
      .p50-prono-share-preview img{display:block;width:100%;height:auto}
      .p50-prono-share-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
      .p50-prono-share-actions button{min-height:48px;border-radius:14px;border:1px solid rgba(183,255,0,.2);background:#0a0d0a;color:#f4f7f0;font-weight:900}
      .p50-prono-share-actions button.primary{background:#b7ff00;border-color:#b7ff00;color:#050705}
      .p50-prono-share-note{margin-top:10px;color:#8f998c;font-size:11px;font-weight:700;line-height:1.4;text-align:center}
      @media(max-width:680px){
        .p50-prono-share-modal{align-items:flex-end;padding:0}
        .p50-prono-share-box{width:100vw;max-width:100vw;border-radius:22px 22px 0 0;padding-bottom:calc(14px + env(safe-area-inset-bottom))}
      }
    `;
    document.head.appendChild(style);

    modal = document.createElement('div');
    modal.className = 'p50-prono-share-modal';
    modal.id = 'p50PronoShareModal';
    modal.innerHTML = `
      <div class="p50-prono-share-box" role="dialog" aria-modal="true" aria-label="Partager le statut prono">
        <div class="p50-prono-share-head">
          <strong>Statut prono</strong>
          <button type="button" class="p50-prono-share-close" data-prono-share-close aria-label="Fermer">×</button>
        </div>
        <div class="p50-prono-share-preview"><img alt="Aperçu carte statut prono PASS50"></div>
        <div class="p50-prono-share-actions">
          <button type="button" class="primary" data-prono-share-native>Partager</button>
          <button type="button" data-prono-share-download>Télécharger</button>
        </div>
        <div class="p50-prono-share-note">Même rendu que le statut publié · sans argent réel</div>
      </div>`;
    document.body.appendChild(modal);

    modal.addEventListener('click', (event) => {
      if (event.target === modal || event.target.closest('[data-prono-share-close]')) close();
      if (event.target.closest('[data-prono-share-native]')) nativeShare().catch(() => {});
      if (event.target.closest('[data-prono-share-download]')) download();
    });
    return modal;
  }

  function close() {
    modal?.classList.remove('show');
  }

  function download() {
    if (!currentFile || !previewUrl) return;
    const a = document.createElement('a');
    a.href = previewUrl;
    a.download = currentFile.name || 'pass50-statut-prono.png';
    a.click();
  }

  async function nativeShare() {
    if (!currentPayload || !currentFile) return;
    const text = shareText(currentPayload);
    try {
      if (navigator.canShare?.({ files: [currentFile] })) {
        await navigator.share({ title: 'Statut prono PASS50', text, files: [currentFile] });
        close();
        return;
      }
      if (navigator.share) {
        await navigator.share({ title: 'Statut prono PASS50', text, url: currentPayload.url });
        close();
        return;
      }
    } catch (error) {
      if (error?.name === 'AbortError') return;
    }
    download();
    try {
      await navigator.clipboard?.writeText(`${text}\n${currentPayload.url}`);
    } catch (_) {}
  }

  async function open(input = {}) {
    const payload = buildPayload(input);
    currentPayload = payload;
    const file = await imageFile(payload);
    currentFile = file;
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);

    const preferNative = window.matchMedia('(max-width:680px)').matches
      && navigator.canShare?.({ files: [file] });
    if (preferNative) {
      try {
        await navigator.share({ title: 'Statut prono PASS50', text: shareText(payload), files: [file] });
        return { ok: true, shared: true, file };
      } catch (error) {
        if (error?.name === 'AbortError') return { ok: true, shared: false, aborted: true, file };
      }
    }

    const node = ensureModal();
    const img = node.querySelector('.p50-prono-share-preview img');
    if (img) img.src = previewUrl;
    node.classList.add('show');
    return { ok: true, shared: false, file };
  }

  window.PASS50_PRONO_SHARE = Object.freeze({
    contract: CONTRACT,
    open,
    close,
    imageFile: async (input) => imageFile(buildPayload(input)),
    shareText: (input) => shareText(buildPayload(input)),
  });
})();
