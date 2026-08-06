'use strict';

(() => {
  const CONTRACT = 'PASS50-PRONO-SHARE-V1.0';
  if (window.PASS50_PRONO_SHARE) return;

  const W = 1080;
  const H = 1350;
  const COLORS = {
    bg: '#050705',
    panel: '#0c100c',
    panel2: '#121812',
    ink: '#f4f7f0',
    muted: '#8f998c',
    lime: '#b7ff00',
    line: 'rgba(183,255,0,0.22)',
    dim: 'rgba(183,255,0,0.10)',
  };

  let modal = null;
  let previewUrl = null;
  let currentFile = null;
  let currentPayload = null;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

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

  function draw(ctx, payload) {
    const title = String(payload.title || 'Pronostic PASS50');
    const choice = String(payload.choice || '—');
    const odd = fmtOdd(payload.odd);
    const payout = Math.round(Number(payload.payout) || 0);
    const stake = Math.round(Number(payload.stake) || 100);
    const author = String(payload.author || '').trim();
    const mode = payload.mode === 'status' ? 'status' : 'mine';

    // Background
    ctx.fillStyle = COLORS.bg;
    ctx.fillRect(0, 0, W, H);
    const glow = ctx.createRadialGradient(W * 0.5, -40, 40, W * 0.5, 80, 720);
    glow.addColorStop(0, 'rgba(183,255,0,0.22)');
    glow.addColorStop(0.45, 'rgba(183,255,0,0.06)');
    glow.addColorStop(1, 'rgba(183,255,0,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, W, 760);

    const orb = ctx.createRadialGradient(W - 80, H - 220, 20, W - 80, H - 220, 340);
    orb.addColorStop(0, 'rgba(183,255,0,0.10)');
    orb.addColorStop(1, 'rgba(183,255,0,0)');
    ctx.fillStyle = orb;
    ctx.fillRect(W - 480, H - 560, 480, 560);

    // Brand
    ctx.fillStyle = COLORS.lime;
    ctx.fillRect(64, 72, 28, 28);
    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 54px Arial Black, Impact, Arial';
    ctx.fillText('PASS50', 110, 100);
    ctx.fillStyle = COLORS.muted;
    ctx.font = '800 22px Arial';
    ctx.fillText('PRONOSTICS', 110, 136);

    // Kicker
    ctx.fillStyle = COLORS.lime;
    ctx.font = '900 24px Arial';
    ctx.fillText(mode === 'status' ? 'STATUT PRONO' : 'MON PRONO', 64, 220);

    // Question
    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 58px Arial Black, Impact, Arial';
    const titleLines = wrapLines(ctx, title, W - 128, 4);
    let y = 290;
    titleLines.forEach((line) => {
      ctx.fillText(line, 64, y);
      y += 72;
    });

    // Ticket card
    const ticketY = Math.max(560, y + 48);
    const ticketH = 420;
    roundRect(ctx, 64, ticketY, W - 128, ticketH, 36);
    ctx.fillStyle = COLORS.panel;
    ctx.fill();
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 3;
    ctx.stroke();

    // Ticket inner glow bar
    ctx.fillStyle = COLORS.dim;
    roundRect(ctx, 64, ticketY, W - 128, 12, 0);
    ctx.fill();
    ctx.fillStyle = COLORS.lime;
    ctx.fillRect(64, ticketY, 18, ticketH);

    ctx.fillStyle = COLORS.muted;
    ctx.font = '900 22px Arial';
    ctx.fillText(mode === 'status' && author ? `CHOIX DE ${author.toUpperCase().slice(0, 28)}` : 'MON CHOIX', 110, ticketY + 58);

    ctx.fillStyle = COLORS.ink;
    ctx.font = '1000 52px Arial Black, Impact, Arial';
    const choiceLines = wrapLines(ctx, choice, W - 280, 2);
    let cy = ticketY + 128;
    choiceLines.forEach((line) => {
      ctx.fillText(line, 110, cy);
      cy += 62;
    });

    // Odd block
    const oddBoxX = W - 64 - 250;
    const oddBoxY = ticketY + 86;
    roundRect(ctx, oddBoxX, oddBoxY, 210, 210, 28);
    ctx.fillStyle = COLORS.panel2;
    ctx.fill();
    ctx.strokeStyle = COLORS.lime;
    ctx.lineWidth = 3;
    ctx.stroke();

    ctx.fillStyle = COLORS.muted;
    ctx.font = '900 20px Arial';
    ctx.textAlign = 'center';
    ctx.fillText('COTE', oddBoxX + 105, oddBoxY + 52);
    ctx.fillStyle = COLORS.lime;
    ctx.font = '1000 72px Arial Black, Impact, Arial';
    ctx.fillText(odd, oddBoxX + 105, oddBoxY + 130);
    ctx.fillStyle = COLORS.ink;
    ctx.font = '800 22px Arial';
    ctx.fillText(`mise ${stake}`, oddBoxX + 105, oddBoxY + 172);
    ctx.textAlign = 'left';

    // Payout line
    const payY = ticketY + ticketH - 58;
    ctx.fillStyle = COLORS.lime;
    ctx.font = '1000 36px Arial';
    ctx.fillText(payout > 0 ? `Gain pot. +${payout} pts` : 'Sans argent réel', 110, payY);
    ctx.fillStyle = COLORS.muted;
    ctx.font = '700 22px Arial';
    ctx.fillText('si le prono est correct', 110, payY + 36);

    // Disclaimer + CTA
    ctx.fillStyle = COLORS.muted;
    ctx.font = '800 24px Arial';
    ctx.fillText('Sans argent réel · points PASS50 uniquement', 64, H - 168);

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
    draw(ctx, payload);
    const blob = await new Promise((resolve, reject) => {
      canvas.toBlob((b) => (b && b.size ? resolve(b) : reject(new Error('Image impossible'))), 'image/png', 0.95);
    });
    return new File([blob], 'pass50-prono.png', { type: 'image/png' });
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
      <div class="p50-prono-share-box" role="dialog" aria-modal="true" aria-label="Partager mon prono">
        <div class="p50-prono-share-head">
          <strong>Carte prono</strong>
          <button type="button" class="p50-prono-share-close" data-prono-share-close aria-label="Fermer">×</button>
        </div>
        <div class="p50-prono-share-preview"><img alt="Aperçu carte prono PASS50"></div>
        <div class="p50-prono-share-actions">
          <button type="button" class="primary" data-prono-share-native>Partager</button>
          <button type="button" data-prono-share-download>Télécharger</button>
        </div>
        <div class="p50-prono-share-note">Sans argent réel · image prête pour Stories / WhatsApp</div>
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
    a.download = currentFile.name || 'pass50-prono.png';
    a.click();
  }

  async function nativeShare() {
    if (!currentPayload || !currentFile) return;
    const text = shareText(currentPayload);
    try {
      if (navigator.canShare?.({ files: [currentFile] })) {
        await navigator.share({ title: 'Prono PASS50', text, files: [currentFile] });
        close();
        return;
      }
      if (navigator.share) {
        await navigator.share({ title: 'Prono PASS50', text, url: currentPayload.url });
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

    // Mobile: try native share with image first (skip modal when file share works)
    const preferNative = window.matchMedia('(max-width:680px)').matches
      && navigator.canShare?.({ files: [file] });
    if (preferNative) {
      try {
        await navigator.share({ title: 'Prono PASS50', text: shareText(payload), files: [file] });
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
