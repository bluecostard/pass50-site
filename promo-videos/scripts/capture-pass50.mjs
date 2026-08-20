#!/usr/bin/env node
/**
 * Capture mobile screenshots + scroll frames from the live PASS50 site.
 * Uses Chrome DevTools Protocol (no puppeteer dependency).
 */
import { spawn } from 'node:child_process';
import { createWriteStream, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';
import http from 'node:http';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const OUT = join(ROOT, 'captures');
const CHROME = process.env.CHROME_PATH || 'google-chrome';
const TARGET = process.env.PASS50_URL || 'https://pass50.store/';
const WIDTH = Number(process.env.VW || 390);
const HEIGHT = Number(process.env.VH || 844);

mkdirSync(OUT, { recursive: true });

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

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

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

class Cdp {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 0;
    this.pending = new Map();
    this.events = new Map();
  }

  async connect() {
    const { default: WebSocket } = await import('ws').catch(() => ({ default: null }));
    if (!WebSocket) {
      // Minimal WS via undici/global if available — fallback: use chrome remote over HTTP only
      throw new Error('WebSocket client missing — will install ws');
    }
    this.ws = new WebSocket(this.wsUrl);
    await new Promise((resolve, reject) => {
      this.ws.once('open', resolve);
      this.ws.once('error', reject);
    });
    this.ws.on('message', (raw) => {
      const msg = JSON.parse(String(raw));
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) reject(new Error(JSON.stringify(msg.error)));
        else resolve(msg.result);
      } else if (msg.method) {
        const handlers = this.events.get(msg.method) || [];
        handlers.forEach((h) => h(msg.params));
      }
    });
  }

  on(method, handler) {
    if (!this.events.has(method)) this.events.set(method, []);
    this.events.get(method).push(handler);
  }

  send(method, params = {}) {
    const id = ++this.id;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  close() {
    try {
      this.ws?.close();
    } catch {}
  }
}

async function main() {
  const { createRequire } = await import('node:module');
  const require = createRequire(join(ROOT, '.deps', 'package.json'));
  const WebSocket = require('ws');

  const port = await findFreePort();
  const userData = join('/tmp', `pass50-chrome-${port}`);
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
    for (let i = 0; i < 40; i++) {
      try {
        version = await httpGetJson(`http://127.0.0.1:${port}/json/version`);
        break;
      } catch {
        await sleep(200);
      }
    }
    if (!version?.webSocketDebuggerUrl) {
      throw new Error('Chrome CDP not ready: ' + stderr.slice(-500));
    }

    const cdp = new (class extends Cdp {
      async connect() {
        this.ws = new WebSocket(this.wsUrl);
        await new Promise((resolve, reject) => {
          this.ws.once('open', resolve);
          this.ws.once('error', reject);
        });
        this.ws.on('message', (raw) => {
          const msg = JSON.parse(String(raw));
          if (msg.id && this.pending.has(msg.id)) {
            const { resolve, reject } = this.pending.get(msg.id);
            this.pending.delete(msg.id);
            if (msg.error) reject(new Error(JSON.stringify(msg.error)));
            else resolve(msg.result);
          }
        });
      }
    })(version.webSocketDebuggerUrl);

    await cdp.connect();

    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

    const send = (method, params = {}) => {
      const id = ++cdp.id;
      return new Promise((resolve, reject) => {
        cdp.pending.set(id, { resolve, reject });
        cdp.ws.send(JSON.stringify({ id, method, params, sessionId }));
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

    console.log('Navigating to', TARGET);
    await send('Page.navigate', { url: TARGET });
    await send('Page.loadEventFired').catch(() => {});
    // Wait for load via Runtime
    await sleep(4500);

    // Dismiss cookie/demo banners if any
    await send('Runtime.evaluate', {
      expression: `(() => {
        document.querySelectorAll('button,[role=button]').forEach(b => {
          const t = (b.textContent||'').toLowerCase();
          if (/accepter|accept|ok|fermer|close|continuer/.test(t)) try { b.click(); } catch(e) {}
        });
        return true;
      })()`,
    });
    await sleep(800);

    // Hero / home screenshot
    const shot = async (name) => {
      const { data } = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
      writeFileSync(join(OUT, name), Buffer.from(data, 'base64'));
      console.log('wrote', name);
    };

    await shot('01-home.png');

    // Long full-page capture for scroll strip
    const metrics = await send('Page.getLayoutMetrics');
    const contentHeight = Math.min(
      Math.ceil(metrics.cssContentSize?.height || metrics.contentSize?.height || HEIGHT * 4),
      6000
    );
    console.log('content height', contentHeight);

    await send('Emulation.setDeviceMetricsOverride', {
      width: WIDTH,
      height: contentHeight,
      deviceScaleFactor: 2,
      mobile: true,
    });
    await sleep(600);
    await shot('02-fullpage.png');

    // Reset viewport and capture scroll frames
    await send('Emulation.setDeviceMetricsOverride', {
      width: WIDTH,
      height: HEIGHT,
      deviceScaleFactor: 2,
      mobile: true,
    });
    await sleep(400);

    const framesDir = join(OUT, 'scroll-frames');
    mkdirSync(framesDir, { recursive: true });
    const maxScroll = Math.max(0, contentHeight - HEIGHT);
    const frameCount = 48;
    for (let i = 0; i < frameCount; i++) {
      const y = Math.round((i / (frameCount - 1)) * maxScroll);
      await send('Runtime.evaluate', {
        expression: `window.scrollTo({ top: ${y}, behavior: 'instant' })`,
      });
      await sleep(80);
      const { data } = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82, fromSurface: true });
      writeFileSync(join(framesDir, `frame-${String(i).padStart(3, '0')}.jpg`), Buffer.from(data, 'base64'));
      process.stdout.write(`\rframe ${i + 1}/${frameCount}`);
    }
    console.log('\nscroll frames done');

    // Close auth modal if it appeared
    const closeAuth = async () => {
      await send('Runtime.evaluate', {
        expression: `(() => {
          const close = document.querySelector('.modal .close, .modal-box .close, [data-close], button[aria-label*="ferm" i], .modal button');
          const xBtns = [...document.querySelectorAll('button, a, span')].filter(b => (b.textContent||'').trim() === '×' || (b.textContent||'').trim() === 'X');
          (xBtns[0] || close)?.click?.();
          document.querySelectorAll('.modal, .modal-backdrop, [class*=modal]').forEach(m => {
            if (m.classList.contains('show') || getComputedStyle(m).display !== 'none') {
              m.classList.remove('show');
              m.style.display = 'none';
            }
          });
          return true;
        })()`,
      });
      await sleep(400);
    };
    await closeAuth();

    // Scroll to Top 10 / ranking list without triggering auth CTA
    await send('Runtime.evaluate', {
      expression: `(() => {
        const el = document.querySelector('#top10, .top10, #classement, .rank-card');
        if (el) el.scrollIntoView({ block: 'start' });
        else window.scrollTo(0, 700);
        return true;
      })()`,
    });
    await sleep(900);
    await shot('03-classement.png');

    // Capture duel / coules section
    await closeAuth();
    await send('Runtime.evaluate', {
      expression: `(() => {
        const el = document.querySelector('#coules, [id*=coule], .sunk-duel');
        if (el) el.scrollIntoView({ block: 'start' });
        else window.scrollTo(0, Math.min(1600, document.body.scrollHeight));
        return !!el;
      })()`,
    });
    await sleep(900);
    await shot('04-duel.png');

    // Pronostics page
    await send('Page.navigate', { url: new URL('/pronostics.html', TARGET).href });
    await sleep(3500);
    await closeAuth();
    await shot('05-pronostics.png');

    writeFileSync(
      join(OUT, 'meta.json'),
      JSON.stringify(
        {
          url: TARGET,
          capturedAt: new Date().toISOString(),
          viewport: { width: WIDTH, height: HEIGHT, dpr: 2 },
          contentHeight,
          frameCount,
        },
        null,
        2
      )
    );

    cdp.close();
  } finally {
    chrome.kill('SIGTERM');
    await sleep(300);
    try {
      chrome.kill('SIGKILL');
    } catch {}
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
