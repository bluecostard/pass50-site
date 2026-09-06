#!/usr/bin/env node
/**
 * Capture mobile sequences from live PASS50:
 * - home classement scroll
 * - fiches influenceurs (?profile=)
 * - pronostics scroll
 */
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import net from 'node:net';
import http from 'node:http';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const OUT = join(ROOT, 'captures');
const CHROME = process.env.CHROME_PATH || 'google-chrome';
const TARGET = process.env.PASS50_URL || 'https://pass50.store/';
const PRONO_URL = process.env.PASS50_PRONO_URL || 'http://127.0.0.1:8080/pronostics.html';
const PROMO_TOKEN = process.env.PASS50_PROMO_TOKEN || '';
const WIDTH = Number(process.env.VW || 390);
const HEIGHT = Number(process.env.VH || 844);

mkdirSync(OUT, { recursive: true });

const require = createRequire(join(ROOT, '.deps', 'package.json'));
const WebSocket = require('ws');

function findFreePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => {
      const { port } = s.address();
      s.close(() => resolve(port));
    });
    s.on('error', reject);
  });
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function httpGetJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, (res) => {
      let data = '';
      res.on('data', (c) => (data += c));
      res.on('end', () => {
        try {
          resolve(JSON.parse(data));
        } catch (e) {
          reject(e);
        }
      });
    }).on('error', reject);
  });
}

async function main() {
  const port = await findFreePort();
  const userData = join('/tmp', `pass50-chrome-cap-${port}`);
  mkdirSync(userData, { recursive: true });
  const chrome = spawn(
    CHROME,
    [
      `--remote-debugging-port=${port}`,
      `--user-data-dir=${userData}`,
      '--headless=new',
      '--disable-gpu',
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--hide-scrollbars',
      `--window-size=${WIDTH},${HEIGHT}`,
      'about:blank',
    ],
    { stdio: ['ignore', 'pipe', 'pipe'] }
  );
  let stderr = '';
  chrome.stderr.on('data', (d) => (stderr += d.toString()));

  try {
    let version;
    for (let i = 0; i < 50; i++) {
      try {
        version = await httpGetJson(`http://127.0.0.1:${port}/json/version`);
        break;
      } catch {
        await sleep(200);
      }
    }
    if (!version?.webSocketDebuggerUrl) throw new Error('CDP not ready: ' + stderr.slice(-500));

    const ws = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.once('open', resolve);
      ws.once('error', reject);
    });
    let id = 0;
    const pending = new Map();
    ws.on('message', (raw) => {
      const msg = JSON.parse(String(raw));
      if (msg.id && pending.has(msg.id)) {
        const { resolve, reject } = pending.get(msg.id);
        pending.delete(msg.id);
        if (msg.error) reject(new Error(JSON.stringify(msg.error)));
        else resolve(msg.result);
      }
    });
    const rootSend = (method, params = {}) => {
      const mid = ++id;
      return new Promise((resolve, reject) => {
        pending.set(mid, { resolve, reject });
        ws.send(JSON.stringify({ id: mid, method, params }));
      });
    };
    const { targetId } = await rootSend('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await rootSend('Target.attachToTarget', { targetId, flatten: true });
    const send = (method, params = {}) => {
      const mid = ++id;
      return new Promise((resolve, reject) => {
        pending.set(mid, { resolve, reject });
        ws.send(JSON.stringify({ id: mid, method, params, sessionId }));
      });
    };

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', {
      width: WIDTH,
      height: HEIGHT,
      deviceScaleFactor: 2,
      mobile: true,
    });
    await send('Emulation.setUserAgentOverride', {
      userAgent:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    });
    if (PROMO_TOKEN) {
      await send('Page.addScriptToEvaluateOnNewDocument', {
        source: `try{localStorage.setItem('pass50_api_token',${JSON.stringify(PROMO_TOKEN)});sessionStorage.setItem('pass50_session','1');}catch(e){}
          const block=(u)=>String(u||'').includes('open=account');
          const _a=location.assign.bind(location), _r=location.replace.bind(location);
          location.assign=(u)=>{if(block(u))return;return _a(u);};
          location.replace=(u)=>{if(block(u))return;return _r(u);};`,
      });
    }

    const closeAuth = async () => {
      await send('Runtime.evaluate', {
        expression: `(() => {
          [...document.querySelectorAll('button,.close,[data-close]')].forEach(b => {
            const t = (b.textContent||'').trim();
            const dc = b.getAttribute('data-close')||'';
            if (t === '×' || t === 'X' || /authModal|login/i.test(dc)) try { b.click(); } catch(e) {}
          });
          ['authModal','loginModal'].forEach(id => {
            const m = document.getElementById(id);
            if (m) { m.classList.remove('show'); m.style.display='none'; }
          });
          return true;
        })()`,
      });
      await sleep(300);
    };

    const shotStill = async (name) => {
      const { data } = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
      writeFileSync(join(OUT, name), Buffer.from(data, 'base64'));
      console.log('wrote', name);
    };

    const captureScrollSequence = async (dirName, frameCount = 36) => {
      const dir = join(OUT, dirName);
      rmSync(dir, { recursive: true, force: true });
      mkdirSync(dir, { recursive: true });
      await send('Runtime.evaluate', { expression: `window.scrollTo({top:0,behavior:'instant'})` });
      await sleep(200);
      const metrics = await send('Page.getLayoutMetrics');
      const contentHeight = Math.ceil(
        metrics.cssContentSize?.height || metrics.contentSize?.height || HEIGHT * 3
      );
      const maxScroll = Math.max(0, Math.min(contentHeight - HEIGHT, 3200));
      for (let i = 0; i < frameCount; i++) {
        const y = Math.round((i / Math.max(1, frameCount - 1)) * maxScroll);
        await send('Runtime.evaluate', {
          expression: `window.scrollTo({top:${y},behavior:'instant'})`,
        });
        await sleep(70);
        const { data } = await send('Page.captureScreenshot', {
          format: 'jpeg',
          quality: 84,
          fromSurface: true,
        });
        writeFileSync(join(dir, `frame-${String(i).padStart(3, '0')}.jpg`), Buffer.from(data, 'base64'));
        process.stdout.write(`\r${dirName} ${i + 1}/${frameCount}`);
      }
      console.log(`\n${dirName} done (scroll ${maxScroll}px)`);
      return { frameCount, maxScroll, contentHeight };
    };

    // ——— HOME ———
    console.log('Navigating home', TARGET);
    await send('Page.navigate', { url: TARGET });
    await sleep(4500);
    await closeAuth();
    await shotStill('01-home.png');
    const homeMeta = await captureScrollSequence('scroll-frames', 48);

    // Rank cards visible — collect top profile ids from DOM
    const idsResult = await send('Runtime.evaluate', {
      expression: `(() => {
        const ids = [];
        document.querySelectorAll('[data-profile],[data-id]').forEach(el => {
          const id = el.getAttribute('data-profile') || el.getAttribute('data-id');
          if (id && !ids.includes(id)) ids.push(id);
        });
        // also try rank cards / fav buttons
        document.querySelectorAll('.rank-card .fav,.rank-card .follow,button.fav,button.follow').forEach(el => {
          const id = el.getAttribute('data-id');
          if (id && !ids.includes(id)) ids.push(id);
        });
        return ids.slice(0, 12);
      })()`,
      returnByValue: true,
    });
    let profileIds = idsResult.result?.value || [];
    console.log('profile ids from DOM', profileIds);

    // Fallback known popular ids if DOM empty
    if (profileIds.length < 3) {
      profileIds = ['emma', 'lopere', 'apoutchou', 'paulyves', 'didi-b', 'sarara-messan'];
    }

    // ——— FICHES INFLUENCEURS ———
    const ficheDir = join(OUT, 'fiche-frames');
    rmSync(ficheDir, { recursive: true, force: true });
    mkdirSync(ficheDir, { recursive: true });
    let ficheFrame = 0;
    const ficheMeta = [];

    for (const pid of profileIds.slice(0, 4)) {
      const url = new URL(TARGET);
      url.searchParams.set('profile', pid);
      console.log('Opening fiche', pid);
      await send('Page.navigate', { url: url.href });
      await sleep(4200);
      await closeAuth();

      // Ensure profile modal is open
      await send('Runtime.evaluate', {
        expression: `(() => {
          if (typeof openProfile === 'function') { try { openProfile(${JSON.stringify(pid)}); } catch(e) {} }
          const m = document.getElementById('profileModal');
          if (m) { m.classList.add('show'); m.style.display='grid'; }
          return !!(m && m.classList.contains('show'));
        })()`,
      });
      await sleep(1200);
      await closeAuth();

      // Still of fiche
      await shotStill(`fiche-${pid}.png`);

      // Scroll inside profile modal body if possible, else page
      const scrollFiche = async (steps) => {
        for (let i = 0; i < steps; i++) {
          await send('Runtime.evaluate', {
            expression: `(() => {
              const body = document.querySelector('#profileBody, #profileModal .modal-body, #profileModal .modal-box');
              const host = body || document.scrollingElement || document.documentElement;
              const max = Math.max(0, (host.scrollHeight || 0) - (host.clientHeight || ${HEIGHT}));
              const y = Math.round((${i} / ${Math.max(1, steps - 1)}) * Math.min(max, 1400));
              if (host === document.scrollingElement || host === document.documentElement) window.scrollTo(0, y);
              else host.scrollTop = y;
              // also nudge modal box
              const box = document.querySelector('#profileModal .modal-box');
              if (box) box.scrollTop = y;
              return y;
            })()`,
          });
          await sleep(80);
          const { data } = await send('Page.captureScreenshot', {
            format: 'jpeg',
            quality: 84,
            fromSurface: true,
          });
          writeFileSync(
            join(ficheDir, `frame-${String(ficheFrame).padStart(3, '0')}.jpg`),
            Buffer.from(data, 'base64')
          );
          ficheFrame += 1;
        }
      };
      await scrollFiche(12);
      ficheMeta.push({ id: pid, frames: 12 });
    }
    console.log('fiche frames', ficheFrame);

    // ——— PRONOSTICS (auth token / URL locale si fournis) ———
    const pronoUrl = new URL(PRONO_URL);
    pronoUrl.searchParams.set('v', String(Date.now()));
    console.log('Navigating pronostics', pronoUrl.href);
    await send('Page.navigate', { url: pronoUrl.href });
    await sleep(5500);
    await closeAuth();
    await shotStill('05-pronostics.png');
    await send('Runtime.evaluate', {
      expression: `window.scrollTo({top:0,behavior:'instant'})`,
    });
    await sleep(400);
    const pronoMeta = await captureScrollSequence('prono-frames', 40);
    await send('Runtime.evaluate', {
      expression: `window.scrollTo({top:500,behavior:'instant'})`,
    });
    await sleep(500);
    await shotStill('05b-pronostics-mid.png');

    // Classement still (no auth)
    await send('Page.navigate', { url: TARGET });
    await sleep(3500);
    await closeAuth();
    await send('Runtime.evaluate', {
      expression: `(() => {
        const el = document.querySelector('.rank-card, #top10, .top10');
        if (el) el.scrollIntoView({block:'start'});
        else window.scrollTo(0, 650);
        return true;
      })()`,
    });
    await sleep(700);
    await shotStill('03-classement.png');

    writeFileSync(
      join(OUT, 'meta.json'),
      JSON.stringify(
        {
          url: TARGET,
          capturedAt: new Date().toISOString(),
          viewport: { width: WIDTH, height: HEIGHT, dpr: 2 },
          home: homeMeta,
          fiches: { frameCount: ficheFrame, profiles: ficheMeta },
          pronostics: pronoMeta,
          profileIds,
          audio: false,
          targetDurationSec: 15,
        },
        null,
        2
      )
    );

    ws.close();
  } finally {
    chrome.kill('SIGTERM');
    await sleep(250);
    try {
      chrome.kill('SIGKILL');
    } catch {}
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
