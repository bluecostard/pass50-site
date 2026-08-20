#!/usr/bin/env node
/**
 * Render PASS50 promo HTML compositions to MP4 via Chrome CDP + ffmpeg.
 */
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, rmSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import net from 'node:net';
import http from 'node:http';
import { pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const OUT = join(ROOT, 'output');
const TMP = join('/tmp', 'pass50-promo-render');
const CHROME = process.env.CHROME_PATH || 'google-chrome';
const WIDTH = 1080;
const HEIGHT = 1920;
const FPS = 24;

mkdirSync(OUT, { recursive: true });
mkdirSync(TMP, { recursive: true });

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

function run(cmd, args, opts = {}) {
  return new Promise((resolve, reject) => {
    const p = spawn(cmd, args, { stdio: ['ignore', 'pipe', 'pipe'], ...opts });
    let out = '';
    let err = '';
    p.stdout.on('data', (d) => (out += d));
    p.stderr.on('data', (d) => (err += d));
    p.on('close', (code) => {
      if (code === 0) resolve({ out, err });
      else reject(new Error(`${cmd} failed (${code}): ${err.slice(-800)}`));
    });
  });
}

async function withChrome(fn) {
  const port = await findFreePort();
  const userData = join('/tmp', `pass50-render-chrome-${port}`);
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
      '--font-render-hinting=none',
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
        await sleep(150);
      }
    }
    if (!version?.webSocketDebuggerUrl) throw new Error('CDP not ready: ' + stderr.slice(-400));

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
      deviceScaleFactor: 1,
      mobile: false,
    });

    const api = { send, rootSend, ws };
    await fn(api);
    ws.close();
  } finally {
    chrome.kill('SIGTERM');
    await sleep(200);
    try {
      chrome.kill('SIGKILL');
    } catch {}
  }
}

async function renderComposition({
  name,
  htmlFile,
  query = '',
  introHoldSec = 1.2,
  endHoldSec = 1.4,
  scrollFrameHold = 2,
}) {
  const framesDir = join(TMP, name);
  rmSync(framesDir, { recursive: true, force: true });
  mkdirSync(framesDir, { recursive: true });

  const fileUrl = pathToFileURL(htmlFile).href + (query ? `?${query}` : '');
  console.log(`\n▶ Rendering ${name}`);
  console.log('  ', fileUrl);

  let videoFrame = 0;

  await withChrome(async ({ send }) => {
    await send('Page.navigate', { url: fileUrl });
    await sleep(800);

    // Wait for promo API
    for (let i = 0; i < 40; i++) {
      const r = await send('Runtime.evaluate', {
        expression: `!!(window.__PASS50_PROMO__ && document.documentElement.dataset.ready === '1')`,
        returnByValue: true,
      });
      if (r.result?.value) break;
      await sleep(150);
    }

    await send('Runtime.evaluate', {
      expression: `window.__PASS50_PROMO__.preload()`,
      awaitPromise: true,
    });

    const meta = await send('Runtime.evaluate', {
      expression: `({ frameCount: window.__PASS50_PROMO__.frameCount })`,
      returnByValue: true,
    });
    const scrollCount = meta.result?.value?.frameCount || 48;

    const shot = async () => {
      const { data } = await send('Page.captureScreenshot', {
        format: 'jpeg',
        quality: 90,
        fromSurface: true,
        clip: { x: 0, y: 0, width: WIDTH, height: HEIGHT, scale: 1 },
      });
      const path = join(framesDir, `v-${String(videoFrame).padStart(5, '0')}.jpg`);
      writeFileSync(path, Buffer.from(data, 'base64'));
      videoFrame += 1;
    };

    // Intro hold on first scroll frame
    await send('Runtime.evaluate', {
      expression: `window.__PASS50_PROMO__.setFrame(0)`,
    });
    const introFrames = Math.round(introHoldSec * FPS);
    for (let i = 0; i < introFrames; i++) await shot();

    // Scroll through real Pass50 frames
    for (let s = 0; s < scrollCount; s++) {
      await send('Runtime.evaluate', {
        expression: `window.__PASS50_PROMO__.setFrame(${s})`,
      });
      // tiny paint settle
      await sleep(20);
      for (let h = 0; h < scrollFrameHold; h++) await shot();
      if (s % 8 === 0) process.stdout.write(`\r  scroll ${s + 1}/${scrollCount} → video frames ${videoFrame}`);
    }
    process.stdout.write('\n');

    // End hold
    const endFrames = Math.round(endHoldSec * FPS);
    for (let i = 0; i < endFrames; i++) await shot();
  });

  const mp4 = join(OUT, `${name}.mp4`);
  // Vidéo muette volontairement : la voix / musique sera ajoutée à la publication.
  await run('ffmpeg', [
    '-y',
    '-framerate',
    String(FPS),
    '-i',
    join(framesDir, 'v-%05d.jpg'),
    '-an',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-profile:v',
    'high',
    '-crf',
    '18',
    '-movflags',
    '+faststart',
    '-r',
    String(FPS),
    mp4,
  ]);
  console.log('  ✓', mp4, `(${videoFrame} frames)`);
  return mp4;
}

async function alsoEncodeFromScrollFramesOnly() {
  // Pure real-UI scroll (9:16 crop already mobile) — fast secondary asset
  const frames = join(ROOT, 'captures', 'scroll-frames');
  const mp4 = join(OUT, 'pass50-scroll-reel.mp4');
  // Pad intro/end by duplicating first/last via ffmpeg filter
  await run('ffmpeg', [
    '-y',
    '-framerate',
    '12',
    '-i',
    join(frames, 'frame-%03d.jpg'),
    '-an',
    '-vf',
    "scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,tpad=start_mode=clone:start_duration=1:stop_mode=clone:stop_duration=1.2",
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-crf',
    '18',
    '-movflags',
    '+faststart',
    '-r',
    '24',
    mp4,
  ]);
  console.log('  ✓', mp4);
  return mp4;
}

async function main() {
  const template = join(ROOT, 'templates', 'person-using-pass50.html');
  if (!existsSync(join(ROOT, 'captures', 'scroll-frames', 'frame-000.jpg'))) {
    throw new Error('Missing captures — run scripts/capture-pass50.mjs first');
  }

  const outputs = [];

  outputs.push(
    await renderComposition({
      name: 'pass50-personne-defilement',
      htmlFile: template,
      query: 'mode=person&count=48&title=' + encodeURIComponent('Le buzz|maintenant') + '&sub=' + encodeURIComponent('Vraies images · vrai classement PASS50'),
      introHoldSec: 1.3,
      endHoldSec: 1.6,
      scrollFrameHold: 2,
    })
  );

  outputs.push(
    await renderComposition({
      name: 'pass50-fullscreen-scroll',
      htmlFile: template,
      query: 'mode=fullscreen&count=48',
      introHoldSec: 0.6,
      endHoldSec: 0.8,
      scrollFrameHold: 2,
    })
  );

  outputs.push(await alsoEncodeFromScrollFramesOnly());

  writeFileSync(
    join(OUT, 'manifest.json'),
    JSON.stringify(
      {
        generatedAt: new Date().toISOString(),
        source: 'https://pass50.store/',
        videos: outputs.map((p) => p.replace(ROOT + '/', '')),
        note: 'Captures réelles du site + composition personne/téléphone. Vidéos muettes (sans voix) — audio à ajouter à la publication.',
      },
      null,
      2
    )
  );

  console.log('\nDone:', outputs.length, 'videos');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
