#!/usr/bin/env node
/**
 * Render 15s PASS50 promo videos (mute) with:
 * classement scroll + fiches influenceurs + pronostics.
 */
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import net from 'node:net';
import http from 'node:http';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const OUT = join(ROOT, 'output');
const TMP = join('/tmp', 'pass50-promo-render');
const CHROME = process.env.CHROME_PATH || 'google-chrome';
const WIDTH = 1080;
const HEIGHT = 1920;
const FPS = 24;
const DURATION_SEC = 15;
const TOTAL_FRAMES = DURATION_SEC * FPS; // 360

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
function run(cmd, args) {
  return new Promise((resolve, reject) => {
    const p = spawn(cmd, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let err = '';
    p.stderr.on('data', (d) => (err += d));
    p.on('close', (code) => {
      if (code === 0) resolve();
      else reject(new Error(`${cmd} failed (${code}): ${err.slice(-800)}`));
    });
  });
}

/**
 * 15s timeline (mute):
 * 0.0–1.2s  intro hold home
 * 1.2–5.5s  scroll classement
 * 5.5–6.0s  hold end of classement
 * 6.0–10.2s fiches influenceurs
 * 10.2–14.0s pronostics scroll
 * 14.0–15.0s end hold CTA
 */
function buildTimeline(seqCounts) {
  const homeN = seqCounts.home || 48;
  const ficheN = seqCounts.fiche || 1;
  const pronoN = seqCounts.prono || 1;

  const plan = [];
  const pushHold = (seq, frame, frames) => {
    for (let i = 0; i < frames; i++) plan.push({ seq, frame });
  };
  const pushScroll = (seq, count, frames) => {
    for (let i = 0; i < frames; i++) {
      const t = frames === 1 ? 0 : i / (frames - 1);
      const frame = Math.min(count - 1, Math.round(t * (count - 1)));
      plan.push({ seq, frame });
    }
  };

  // Exact frame budgets summing to 360
  pushHold('home', 0, 29); // ~1.2s
  pushScroll('home', homeN, 103); // ~4.3s
  pushHold('home', homeN - 1, 12); // ~0.5s
  if (ficheN > 0) {
    pushScroll('fiche', ficheN, 101); // ~4.2s
  } else {
    pushScroll('home', homeN, 101);
  }
  if (pronoN > 0) {
    pushScroll('prono', pronoN, 91); // ~3.8s
  } else {
    pushScroll('home', homeN, 91);
  }
  pushHold(pronoN > 0 ? 'prono' : 'home', Math.max(0, (pronoN || homeN) - 1), 24); // 1.0s

  // Normalize length
  while (plan.length < TOTAL_FRAMES) plan.push(plan[plan.length - 1]);
  return plan.slice(0, TOTAL_FRAMES);
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

    await fn({ send, ws });
    ws.close();
  } finally {
    chrome.kill('SIGTERM');
    await sleep(200);
    try {
      chrome.kill('SIGKILL');
    } catch {}
  }
}

async function renderComposition({ name, query = '' }) {
  const framesDir = join(TMP, name);
  rmSync(framesDir, { recursive: true, force: true });
  mkdirSync(framesDir, { recursive: true });

  const htmlFile = join(ROOT, 'templates', 'person-using-pass50.html');
  const fileUrl = pathToFileURL(htmlFile).href + (query ? `?${query}` : '');
  console.log(`\n▶ Rendering ${name} (${DURATION_SEC}s mute)`);
  console.log('  ', fileUrl);

  let videoFrame = 0;
  let lastSeq = null;

  await withChrome(async ({ send }) => {
    await send('Page.navigate', { url: fileUrl });
    await sleep(1000);
    for (let i = 0; i < 50; i++) {
      const r = await send('Runtime.evaluate', {
        expression: `!!(window.__PASS50_PROMO__ && document.documentElement.dataset.ready === '1')`,
        returnByValue: true,
      });
      if (r.result?.value) break;
      await sleep(150);
    }
    const seqInfo = await send('Runtime.evaluate', {
      expression: `window.__PASS50_PROMO__.preload()`,
      awaitPromise: true,
      returnByValue: true,
    });
    const sequences = seqInfo.result?.value || [];
    const counts = Object.fromEntries(sequences.map((s) => [s.id, s.count || 0]));
    console.log('  sequences', counts);
    const plan = buildTimeline(counts);

    for (let i = 0; i < plan.length; i++) {
      const step = plan[i];
      if (step.seq !== lastSeq) {
        await send('Runtime.evaluate', {
          expression: `window.__PASS50_PROMO__.setSequence(${JSON.stringify(step.seq)})`,
        });
        lastSeq = step.seq;
      }
      await send('Runtime.evaluate', {
        expression: `window.__PASS50_PROMO__.setFrame(${step.frame})`,
      });
      if (i === 0 || step.seq !== plan[i - 1]?.seq) await sleep(30);
      const { data } = await send('Page.captureScreenshot', {
        format: 'jpeg',
        quality: 88,
        fromSurface: true,
        clip: { x: 0, y: 0, width: WIDTH, height: HEIGHT, scale: 1 },
      });
      writeFileSync(join(framesDir, `v-${String(videoFrame).padStart(5, '0')}.jpg`), Buffer.from(data, 'base64'));
      videoFrame += 1;
      if (i % 24 === 0) process.stdout.write(`\r  ${i}/${plan.length} (${(i / FPS).toFixed(1)}s)`);
    }
    process.stdout.write(`\r  ${plan.length}/${plan.length} (${DURATION_SEC}s)\n`);
  });

  const mp4 = join(OUT, `${name}.mp4`);
  // Vidéo muette volontairement — voix ajoutée à la publication
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
  console.log('  ✓', mp4);
  return mp4;
}

async function renderConcatReel() {
  // 15s fullscreen reel: home + fiche + prono stitched via ffmpeg (mute)
  const home = join(ROOT, 'captures', 'scroll-frames');
  const fiche = join(ROOT, 'captures', 'fiche-frames');
  const prono = join(ROOT, 'captures', 'prono-frames');
  const list = join(TMP, 'reel-list.txt');
  const parts = [];

  async function encodePart(name, pattern, frames, seconds) {
    if (!existsSync(pattern.replace('%03d', '000'))) return null;
    const out = join(TMP, `${name}.mp4`);
    // Map available frames across target duration
    await run('ffmpeg', [
      '-y',
      '-framerate',
      String(Math.max(1, frames / seconds)),
      '-i',
      pattern,
      '-an',
      '-vf',
      'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920',
      '-c:v',
      'libx264',
      '-pix_fmt',
      'yuv420p',
      '-crf',
      '18',
      '-t',
      String(seconds),
      '-r',
      String(FPS),
      out,
    ]);
    return out;
  }

  const { readdirSync } = await import('node:fs');
  const countFrames = (dir) => {
    try {
      return readdirSync(dir).filter((f) => f.endsWith('.jpg')).length;
    } catch {
      return 0;
    }
  };

  const homeN = countFrames(home);
  const ficheN = countFrames(fiche);
  const pronoN = countFrames(prono);

  // Budget: 5.5s home + 4.5s fiche + 5s prono = 15s
  if (homeN) parts.push(await encodePart('part-home', join(home, 'frame-%03d.jpg'), homeN, 5.5));
  if (ficheN) parts.push(await encodePart('part-fiche', join(fiche, 'frame-%03d.jpg'), ficheN, 4.5));
  if (pronoN) parts.push(await encodePart('part-prono', join(prono, 'frame-%03d.jpg'), pronoN, 5.0));
  // If fiche missing, extend home
  if (!ficheN && homeN) parts.push(await encodePart('part-home2', join(home, 'frame-%03d.jpg'), homeN, 4.5));

  const valid = parts.filter(Boolean);
  writeFileSync(list, valid.map((p) => `file '${p}'`).join('\n'));
  const mp4 = join(OUT, 'pass50-scroll-reel.mp4');
  await run('ffmpeg', [
    '-y',
    '-f',
    'concat',
    '-safe',
    '0',
    '-i',
    list,
    '-an',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-crf',
    '18',
    '-movflags',
    '+faststart',
    '-t',
    '15',
    mp4,
  ]);
  console.log('  ✓', mp4);
  return mp4;
}

async function main() {
  if (!existsSync(join(ROOT, 'captures', 'scroll-frames', 'frame-000.jpg'))) {
    throw new Error('Missing captures — run capture-pass50.mjs first');
  }

  const outputs = [];
  outputs.push(
    await renderComposition({
      name: 'pass50-personne-defilement',
      query: 'mode=person',
    })
  );
  outputs.push(
    await renderComposition({
      name: 'pass50-fullscreen-scroll',
      query: 'mode=fullscreen',
    })
  );
  outputs.push(await renderConcatReel());

  writeFileSync(
    join(OUT, 'manifest.json'),
    JSON.stringify(
      {
        generatedAt: new Date().toISOString(),
        source: 'https://pass50.store/',
        durationSec: 15,
        audio: false,
        videos: outputs.map((p) => p.replace(ROOT + '/', '')),
        scenes: ['classement', 'fiches influenceurs', 'pronostics'],
        note: 'Vidéos 15s muettes (sans voix). Contient classement, fiches et pronostics capturés sur le site réel.',
      },
      null,
      2
    )
  );
  console.log('\nDone:', outputs.length, 'videos × 15s mute');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
