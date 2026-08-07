'use strict';

(() => {
  const CONTRACT = 'PASS50-PRONO-SHARE-V1.6';
  if (window.PASS50_PRONO_SHARE) return;

  const W = 1080;
  const H = 1350;

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

  function sameOrigin(url) {
    try {
      return new URL(url, location.href).origin === location.origin;
    } catch (_) {
      return false;
    }
  }

  /** Photo FI canvas-safe : priorise le proxy same-origin */
  function shareSafeCover(input) {
    const profileId = String(input?.profileId || '').trim();
    const direct = absUrl(input?.coverPhoto || input?.coverUrl || '');
    // 1) Proxy FI (fiable pour canvas / WhatsApp)
    if (/^[A-Za-z0-9._:-]{1,100}$/.test(profileId)) {
      return `${location.origin}/partage-photo.php?id=${encodeURIComponent(profileId)}&size=512&v=1`;
    }
    // 2) Cover déjà sur pass50.store
    if (direct && sameOrigin(direct)) return direct;
    return direct;
  }

  function loadAsDataUrl(url) {
    return new Promise((resolve) => {
      const src = absUrl(url);
      if (!src) return resolve('');
      if (src.startsWith('data:')) return resolve(src);

      let settled = false;
      const finish = (value) => {
        if (settled) return;
        settled = true;
        resolve(value || '');
      };

      const fromImage = (useCors) => {
        const image = new Image();
        if (useCors) image.crossOrigin = 'anonymous';
        image.onload = () => {
          try {
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth || image.width;
            canvas.height = image.naturalHeight || image.height;
            const ctx = canvas.getContext('2d');
            if (!ctx || !canvas.width) return finish('');
            ctx.drawImage(image, 0, 0);
            finish(canvas.toDataURL('image/jpeg', 0.92));
          } catch (_) {
            finish('');
          }
        };
        image.onerror = () => finish('');
        image.src = src;
      };

      // Same-origin : fetch → data URL (ne taint pas le canvas)
      if (sameOrigin(src) || src.startsWith(location.origin)) {
        fetch(src, { credentials: 'omit', cache: 'force-cache', mode: 'same-origin' })
          .then((res) => {
            if (!res.ok) throw new Error(String(res.status));
            return res.blob();
          })
          .then((blob) => {
            if (!blob || !blob.size) throw new Error('empty');
            const reader = new FileReader();
            reader.onload = () => finish(String(reader.result || ''));
            reader.onerror = () => fromImage(false);
            reader.readAsDataURL(blob);
          })
          .catch(() => fromImage(false));
        setTimeout(() => finish(''), 8000);
        return;
      }

      fromImage(true);
      setTimeout(() => finish(''), 6000);
    });
  }

  function initials(name) {
    return (String(name || 'P').trim().split(/\s+/).map((p) => p[0]).join('') || 'P').slice(0, 2).toUpperCase();
  }

  /** Styles extraits du diapo publié (pronostics / mon-fil) */
  const DIAPO_CSS = `
    *{box-sizing:border-box;margin:0;padding:0}
    .shot{
      width:${W}px;height:${H}px;padding:40px 36px;background:#050705;color:#f6f8f4;
      font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
      display:flex;align-items:stretch;
    }
    .frame{
      flex:1;min-height:0;display:grid;grid-template-rows:42fr 58fr;
      border:2.5px solid rgba(183,255,0,.22);border-radius:48px;overflow:hidden;background:#0a0d0a;
    }
    .media{position:relative;min-height:0;background:#101610;overflow:hidden}
    .media img.cover{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
    .fallback{
      position:absolute;inset:0;padding:48px;display:flex;flex-direction:column;justify-content:flex-end;gap:16px;
      background:linear-gradient(180deg,#151a15,#050705);
    }
    .fallback b{font-size:26px;font-weight:800;letter-spacing:.14em;color:#6b7568}
    .fallback span{font-family:"Archivo Black",Impact,"Arial Black",sans-serif;font-size:44px;line-height:1.1;color:#c9d2c4}
    .shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,7,5,.2),rgba(5,7,5,.94) 90%)}
    .brand{
      position:absolute;left:36px;top:36px;z-index:2;
      font-size:22px;font-weight:1000;letter-spacing:.14em;color:rgba(246,248,244,.7);
    }
    .body{padding:40px;display:flex;flex-direction:column;gap:28px;min-height:0}
    .top{display:flex;justify-content:space-between;gap:20px;align-items:center}
    .author{display:grid;grid-template-columns:88px minmax(0,1fr);gap:22px;align-items:center;font-weight:800}
    .avatar{
      width:88px;height:88px;border-radius:50%;overflow:hidden;border:3px solid #050705;
      background:linear-gradient(160deg,#1a211a,#0a0d0a);display:grid;place-items:center;
      font-family:"Archivo Black",Impact,sans-serif;font-size:30px;letter-spacing:-.02em;
    }
    .avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .author strong{display:block;font-size:34px;font-weight:800}
    .author small{display:block;color:#8f998c;font-weight:700;margin-top:6px;font-size:24px}
    .q{
      font-family:"Archivo Black",Impact,"Arial Black",sans-serif;
      font-size:52px;letter-spacing:-.03em;line-height:1.15;font-weight:900;
    }
    .ticket{margin-top:auto;padding:32px;border-radius:32px;background:#0a0d0a;border:2px solid rgba(183,255,0,.14)}
    .lbl{font-size:20px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#8f998c}
    .pick{display:flex;justify-content:space-between;gap:24px;align-items:flex-end;margin-top:18px}
    .pick strong{font-size:40px;line-height:1.2;font-weight:800;max-width:68%}
    .pick em{
      font-style:normal;font-family:"Archivo Black",Impact,"Arial Black",sans-serif;
      font-size:56px;color:#b7ff00;letter-spacing:-.03em;font-weight:900;white-space:nowrap;
    }
    .ret{margin-top:18px;font-size:26px;font-weight:800;color:#c9d2c4}
    .foot{display:flex;gap:16px}
    .btn{
      flex:1;min-height:84px;border-radius:24px;border:2px solid rgba(255,255,255,.10);
      background:#0a0d0a;color:#f6f8f4;font-size:28px;font-weight:900;
      display:grid;place-items:center;
    }
    .btn.primary{background:#b7ff00;border-color:#b7ff00;color:#050705}
  `;

  function statusMarkup(payload, coverData, avatarData) {
    const author = esc(payload.author || 'Membre');
    const hours = Number(payload.durationHours) > 0 ? Number(payload.durationHours) : 24;
    const payout = Math.round(Number(payload.payout) || 0);
    const ret = payout > 0 ? `Gain pot. ${payout} pts · sans argent réel` : 'Sans argent réel';
    const avatarInner = avatarData
      ? `<img src="${avatarData}" alt="">`
      : `<span>${esc(initials(payload.author))}</span>`;
    const mediaInner = coverData
      ? `<img class="cover" src="${coverData}" alt="">`
      : `<div class="fallback"><span>${esc(String(payload.choice || 'PRONO').slice(0, 32))}</span></div>`;

    return `
      <div class="shot">
        <div class="frame">
          <div class="media">
            ${mediaInner}
            <div class="shade"></div>
            <div class="brand">PARIS EN COURS</div>
          </div>
          <div class="body">
            <div class="top">
              <div class="author">
                <div class="avatar">${avatarInner}</div>
                <div><strong>${author}</strong><small>Statut · ${hours} h</small></div>
              </div>
            </div>
            <h1 class="q">${esc(payload.title || 'Pronostic')}</h1>
            <div class="ticket">
              <div class="lbl">Son choix</div>
              <div class="pick">
                <strong>${esc(payload.choice || '—')}</strong>
                <em>x ${esc(fmtOdd(payload.odd))}</em>
              </div>
              <div class="ret">${esc(ret)}</div>
            </div>
            <div class="foot">
              <div class="btn">♡ Like</div>
              <div class="btn">Partager</div>
              <div class="btn primary">Jouer</div>
            </div>
          </div>
        </div>
      </div>`;
  }

  function waitFrames(n = 2) {
    return new Promise((resolve) => {
      const step = () => {
        if (n <= 0) return resolve();
        n -= 1;
        requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }

  async function rasterizeDom(html, css) {
    const host = document.createElement('div');
    host.setAttribute('aria-hidden', 'true');
    host.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + W + 'px;height:' + H + 'px;opacity:0;pointer-events:none;z-index:-1';
    host.innerHTML = `<style>${css}</style>${html}`;
    document.body.appendChild(host);

    // Attendre images
    const imgs = [...host.querySelectorAll('img')];
    await Promise.all(imgs.map((img) => {
      if (img.complete) return Promise.resolve();
      return new Promise((r) => {
        img.onload = () => r();
        img.onerror = () => r();
        setTimeout(r, 4000);
      });
    }));
    await waitFrames(3);

    try {
      // SVG foreignObject à partir du nœud réel (même CSS appliqué)
      const clone = host.cloneNode(true);
      clone.style.cssText = `width:${W}px;height:${H}px;background:#050705`;
      const serializer = new XMLSerializer();
      const xhtml = serializer.serializeToString(clone);
      // serializeToString sur HTML élément peut produire HTML — forcer xhtml wrapper
      const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">
  <foreignObject width="100%" height="100%">
    ${xhtml.replace(/iframe/gi, 'div')}
  </foreignObject>
</svg>`;
      const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
      const image = await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('SVG render failed'));
        img.src = url;
      });
      const canvas = document.createElement('canvas');
      canvas.width = W;
      canvas.height = H;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#050705';
      ctx.fillRect(0, 0, W, H);
      ctx.drawImage(image, 0, 0);
      URL.revokeObjectURL(url);
      host.remove();
      return canvas;
    } catch (error) {
      host.remove();
      throw error;
    }
  }

  /** Fallback canvas si FO échoue — proportions diapo */
  async function drawStatusCanvas(payload, coverData, avatarData) {
    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');
    const load = (src) => new Promise((resolve) => {
      if (!src) return resolve(null);
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
      img.src = src;
    });
    const [cover, avatar] = await Promise.all([load(coverData), load(avatarData)]);

    ctx.fillStyle = '#050705';
    ctx.fillRect(0, 0, W, H);

    const fx = 36;
    const fy = 40;
    const fw = W - 72;
    const fh = H - 80;
    const r = 48;
    const rr = (x, y, w, h, rad) => {
      ctx.beginPath();
      ctx.moveTo(x + rad, y);
      ctx.arcTo(x + w, y, x + w, y + h, rad);
      ctx.arcTo(x + w, y + h, x, y + h, rad);
      ctx.arcTo(x, y + h, x, y, rad);
      ctx.arcTo(x, y, x + w, y, rad);
      ctx.closePath();
    };

    rr(fx, fy, fw, fh, r);
    ctx.fillStyle = '#0a0d0a';
    ctx.fill();
    ctx.strokeStyle = 'rgba(183,255,0,0.22)';
    ctx.lineWidth = 2.5;
    ctx.stroke();

    ctx.save();
    rr(fx, fy, fw, fh, r);
    ctx.clip();

    const mediaH = Math.round(fh * 0.42);
    ctx.fillStyle = '#101610';
    ctx.fillRect(fx, fy, fw, mediaH);
    if (cover) {
      const ratio = Math.max(fw / cover.width, mediaH / cover.height);
      const dw = cover.width * ratio;
      const dh = cover.height * ratio;
      ctx.drawImage(cover, fx + (fw - dw) / 2, fy + (mediaH - dh) / 2, dw, dh);
    }
    const g = ctx.createLinearGradient(fx, fy + mediaH * 0.2, fx, fy + mediaH);
    g.addColorStop(0, 'rgba(5,7,5,0.2)');
    g.addColorStop(1, 'rgba(5,7,5,0.94)');
    ctx.fillStyle = g;
    ctx.fillRect(fx, fy, fw, mediaH);

    ctx.fillStyle = 'rgba(246,248,244,0.7)';
    ctx.font = '1000 22px Inter, Arial';
    ctx.fillText('PARIS EN COURS', fx + 36, fy + 52);

    const bodyX = fx + 40;
    let y = fy + mediaH + 40;
    const av = 88;
    ctx.save();
    ctx.beginPath();
    ctx.arc(bodyX + av / 2, y + av / 2, av / 2, 0, Math.PI * 2);
    ctx.clip();
    if (avatar) {
      const ratio = Math.max(av / avatar.width, av / avatar.height);
      const dw = avatar.width * ratio;
      const dh = avatar.height * ratio;
      ctx.drawImage(avatar, bodyX + (av - dw) / 2, y + (av - dh) / 2, dw, dh);
    } else {
      ctx.fillStyle = '#1a211a';
      ctx.fillRect(bodyX, y, av, av);
    }
    ctx.restore();

    const author = String(payload.author || 'Membre');
    ctx.fillStyle = '#f6f8f4';
    ctx.font = '800 34px Inter, Arial';
    ctx.fillText(author.slice(0, 26), bodyX + av + 22, y + 36);
    ctx.fillStyle = '#8f998c';
    ctx.font = '700 24px Inter, Arial';
    ctx.fillText(`Statut · ${Number(payload.durationHours) || 24} h`, bodyX + av + 22, y + 70);
    y += av + 40;

    const wrap = (text, font, maxW, maxLines) => {
      ctx.font = font;
      const words = String(text || '').split(/\s+/);
      const lines = [];
      let line = '';
      for (const word of words) {
        const next = line ? `${line} ${word}` : word;
        if (ctx.measureText(next).width <= maxW) line = next;
        else {
          if (line) lines.push(line);
          line = word;
          if (lines.length >= maxLines - 1) break;
        }
      }
      if (line && lines.length < maxLines) lines.push(line);
      return lines;
    };

    ctx.fillStyle = '#f6f8f4';
    const qFont = '900 52px "Archivo Black", Impact, Arial Black, Arial';
    wrap(payload.title || 'Pronostic', qFont, fw - 80, 4).forEach((line) => {
      ctx.font = qFont;
      ctx.fillText(line, bodyX, y);
      y += 60;
    });

    const ticketH = 210;
    const ticketY = Math.min(Math.max(y + 24, fy + fh - ticketH - 140), fy + fh - ticketH - 140);
    rr(bodyX, ticketY, fw - 80, ticketH, 32);
    ctx.fillStyle = '#0a0d0a';
    ctx.fill();
    ctx.strokeStyle = 'rgba(183,255,0,0.14)';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = '#8f998c';
    ctx.font = '900 20px Inter, Arial';
    ctx.fillText('SON CHOIX', bodyX + 32, ticketY + 46);
    ctx.fillStyle = '#f6f8f4';
    ctx.font = '800 40px Inter, Arial';
    const choice = String(payload.choice || '—');
    const choiceLine = ctx.measureText(choice).width > fw - 320 ? `${choice.slice(0, 22)}…` : choice;
    ctx.fillText(choiceLine, bodyX + 32, ticketY + 110);
    ctx.fillStyle = '#b7ff00';
    ctx.font = '900 56px "Archivo Black", Impact, Arial';
    ctx.textAlign = 'right';
    ctx.fillText(`x ${fmtOdd(payload.odd)}`, bodyX + fw - 80 - 32, ticketY + 118);
    ctx.textAlign = 'left';
    const payout = Math.round(Number(payload.payout) || 0);
    ctx.fillStyle = '#c9d2c4';
    ctx.font = '800 26px Inter, Arial';
    ctx.fillText(payout > 0 ? `Gain pot. ${payout} pts · sans argent réel` : 'Sans argent réel', bodyX + 32, ticketY + ticketH - 40);

    // Foot buttons
    const by = fy + fh - 120;
    const bw = (fw - 80 - 32) / 3;
    const labels = [['♡ Like', false], ['Partager', false], ['Jouer', true]];
    labels.forEach(([label, primary], i) => {
      const bx = bodyX + i * (bw + 16);
      rr(bx, by, bw, 84, 24);
      ctx.fillStyle = primary ? '#b7ff00' : '#0a0d0a';
      ctx.fill();
      ctx.strokeStyle = primary ? '#b7ff00' : 'rgba(255,255,255,0.10)';
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.fillStyle = primary ? '#050705' : '#f6f8f4';
      ctx.font = '900 28px Inter, Arial';
      ctx.textAlign = 'center';
      ctx.fillText(label, bx + bw / 2, by + 54);
      ctx.textAlign = 'left';
    });

    ctx.restore();
    return canvas;
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
      profileId: String(input.profileId || '').trim(),
      coverPhoto: absUrl(input.coverPhoto || input.coverUrl || input.image || ''),
      durationHours: Number(input.durationHours) || 24,
      url: String(input.url || `${location.origin}${location.pathname.replace(/[^/]*$/, '')}pronostics.html`),
    };
  }

  function shareText(payload) {
    const odd = fmtOdd(payload.odd);
    const who = payload.mode === 'status' && payload.author ? `${payload.author} · ` : '';
    const line = payload.mode === 'status'
      ? `Statut prono PASS50 — ${who}${payload.title} → ${payload.choice}${odd !== '—' ? ` x ${odd}` : ''}`
      : `Mon prono PASS50 : ${payload.title} → ${payload.choice}${odd !== '—' ? ` x ${odd}` : ''}`;
    return `${line}\nSans argent réel · ${payload.url.replace(/^https?:\/\//, '')}`;
  }

  async function imageFile(payload) {
    if (payload.mode === 'status') {
      let coverData = '';

      // 1) Capture directe de l’image déjà visible dans le diapo (si non tainted)
      const liveCover = document.querySelector('#diapoCover, #pronoDiapoCover');
      if (liveCover && liveCover.complete && liveCover.naturalWidth > 0) {
        try {
          const c = document.createElement('canvas');
          c.width = liveCover.naturalWidth;
          c.height = liveCover.naturalHeight;
          c.getContext('2d').drawImage(liveCover, 0, 0);
          coverData = c.toDataURL('image/jpeg', 0.92);
        } catch (_) {
          coverData = '';
        }
      }

      // 2) Proxy FI + cover API
      if (!coverData) {
        const tryUrls = [
          shareSafeCover(payload),
          absUrl(liveCover?.currentSrc || liveCover?.src || ''),
          payload.coverPhoto,
        ].filter(Boolean);
        const unique = [...new Set(tryUrls)];
        for (const candidate of unique) {
          if (coverData) break;
          // eslint-disable-next-line no-await-in-loop
          coverData = await loadAsDataUrl(candidate);
        }
      }

      const avatarData = await loadAsDataUrl(payload.authorPhoto);
      const canvas = await drawStatusCanvas(payload, coverData, avatarData);
      const blob = await new Promise((resolve, reject) => {
        canvas.toBlob((b) => (b && b.size ? resolve(b) : reject(new Error('Image impossible'))), 'image/png', 0.95);
      });
      return new File([blob], 'pass50-statut-prono.png', { type: 'image/png' });
    }

    // Mon prono — ticket simple aligné diapo-ticket
    const canvas = await drawStatusCanvas({
      ...payload,
      author: 'Moi',
      durationHours: 24,
      title: payload.title,
    }, '', '');
    // Override label path — use dedicated mine paint
    const ctx = canvas.getContext('2d');
    // Actually reuse a cleaner mine layout
    ctx.fillStyle = '#050705';
    ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#b7ff00';
    ctx.fillRect(64, 80, 28, 28);
    ctx.fillStyle = '#f6f8f4';
    ctx.font = '1000 54px Arial Black, Arial';
    ctx.fillText('PASS50', 110, 108);
    ctx.fillStyle = '#8f998c';
    ctx.font = '800 22px Arial';
    ctx.fillText('PRONOSTICS', 110, 144);
    ctx.fillStyle = '#b7ff00';
    ctx.font = '900 24px Arial';
    ctx.fillText('MON PRONO', 64, 230);
    ctx.fillStyle = '#f6f8f4';
    ctx.font = '900 54px "Archivo Black", Impact, Arial';
    const words = String(payload.title || '').split(/\s+/);
    let line = '';
    let y = 310;
    words.forEach((word) => {
      const next = line ? `${line} ${word}` : word;
      if (ctx.measureText(next).width > W - 128) {
        ctx.fillText(line, 64, y);
        y += 64;
        line = word;
      } else line = next;
    });
    if (line) ctx.fillText(line, 64, y);
    y += 80;
    const ty = Math.max(y, 620);
    const rr = (x, yy, w, h, rad) => {
      ctx.beginPath();
      ctx.moveTo(x + rad, yy);
      ctx.arcTo(x + w, yy, x + w, yy + h, rad);
      ctx.arcTo(x + w, yy + h, x, yy + h, rad);
      ctx.arcTo(x, yy + h, x, yy, rad);
      ctx.arcTo(x, yy, x + w, yy, rad);
      ctx.closePath();
    };
    rr(64, ty, W - 128, 320, 32);
    ctx.fillStyle = '#0a0d0a';
    ctx.fill();
    ctx.strokeStyle = 'rgba(183,255,0,0.22)';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.fillStyle = '#8f998c';
    ctx.font = '900 20px Arial';
    ctx.fillText('MON CHOIX', 100, ty + 52);
    ctx.fillStyle = '#f6f8f4';
    ctx.font = '800 42px Arial';
    ctx.fillText(String(payload.choice || '—').slice(0, 28), 100, ty + 120);
    ctx.fillStyle = '#b7ff00';
    ctx.font = '900 56px "Archivo Black", Impact, Arial';
    ctx.textAlign = 'right';
    ctx.fillText(`x ${fmtOdd(payload.odd)}`, W - 100, ty + 130);
    ctx.textAlign = 'left';
    const payout = Math.round(Number(payload.payout) || 0);
    ctx.fillStyle = '#c9d2c4';
    ctx.font = '800 26px Arial';
    ctx.fillText(payout > 0 ? `Gain pot. +${payout} pts · mise ${Math.round(payload.stake || 100)}` : 'Sans argent réel', 100, ty + 220);
    rr(64, H - 140, W - 128, 84, 24);
    ctx.fillStyle = '#b7ff00';
    ctx.fill();
    ctx.fillStyle = '#050705';
    ctx.font = '1000 30px Arial';
    ctx.fillText('Pronostique aussi sur pass50.store', 100, H - 86);

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
      .p50-prono-share-box{width:min(420px,100%);max-height:94vh;overflow:auto;border:1px solid rgba(183,255,0,.22);border-radius:22px;background:#0a0d0a;padding:14px}
      .p50-prono-share-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;color:#f4f7f0}
      .p50-prono-share-head strong{font-size:15px;font-weight:950}
      .p50-prono-share-close{width:42px;height:42px;border:1px solid rgba(183,255,0,.2);border-radius:12px;background:#0a0d0a;color:#fff;font-size:22px}
      .p50-prono-share-preview{border-radius:16px;overflow:hidden;border:1px solid rgba(183,255,0,.16);background:#050705}
      .p50-prono-share-preview img{display:block;width:100%;height:auto}
      .p50-prono-share-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
      .p50-prono-share-actions button{min-height:48px;border-radius:14px;border:1px solid rgba(183,255,0,.2);background:#0a0d0a;color:#f4f7f0;font-weight:900}
      .p50-prono-share-actions button.primary{background:#b7ff00;border-color:#b7ff00;color:#050705}
      .p50-prono-share-note{margin-top:10px;color:#8f998c;font-size:11px;font-weight:700;text-align:center}
      @media(max-width:680px){
        .p50-prono-share-modal{align-items:flex-end;padding:0}
        .p50-prono-share-box{width:100vw;border-radius:22px 22px 0 0;padding-bottom:calc(14px + env(safe-area-inset-bottom))}
      }`;
    document.head.appendChild(style);
    modal = document.createElement('div');
    modal.className = 'p50-prono-share-modal';
    modal.innerHTML = `
      <div class="p50-prono-share-box" role="dialog" aria-modal="true" aria-label="Partager le statut prono">
        <div class="p50-prono-share-head"><strong>Statut prono</strong>
          <button type="button" class="p50-prono-share-close" data-prono-share-close aria-label="Fermer">×</button>
        </div>
        <div class="p50-prono-share-preview"><img alt="Aperçu statut prono"></div>
        <div class="p50-prono-share-actions">
          <button type="button" class="primary" data-prono-share-native>Partager</button>
          <button type="button" data-prono-share-whatsapp>WhatsApp</button>
          <button type="button" data-prono-share-download>Télécharger</button>
        </div>
        <div class="p50-prono-share-note">Même carte que le statut publié</div>
      </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', (event) => {
      if (event.target === modal || event.target.closest('[data-prono-share-close]')) close();
      if (event.target.closest('[data-prono-share-native]')) nativeShare().catch(() => {});
      if (event.target.closest('[data-prono-share-whatsapp]')) whatsappShare();
      if (event.target.closest('[data-prono-share-download]')) download();
    });
    return modal;
  }

  function close() { modal?.classList.remove('show'); }

  function download() {
    if (!currentFile || !previewUrl) return;
    const a = document.createElement('a');
    a.href = previewUrl;
    a.download = currentFile.name || 'pass50-statut-prono.png';
    a.click();
  }

  function whatsappShare() {
    if (!currentPayload) return;
    const text = shareText(currentPayload);
    const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`;
    const mobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent || '');
    if (mobile) {
      location.href = url;
      return;
    }
    const a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    document.body.appendChild(a);
    a.click();
    a.remove();
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
