(() => {
  'use strict';
  if (window.__pass50ApprovedOnboardingV1) return;
  window.__pass50ApprovedOnboardingV1 = true;

  const ROOT = '#pass50-onboarding-root';

  const boatSvg = `
    <svg viewBox="0 0 720 420" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Bateau qui coule dans une mer agitée">
      <defs>
        <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#12191a"/><stop offset="1" stop-color="#020404"/></linearGradient>
        <linearGradient id="sea" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#10272b"/><stop offset="1" stop-color="#030809"/></linearGradient>
        <filter id="glow"><feGaussianBlur stdDeviation="5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
      </defs>
      <rect width="720" height="420" fill="url(#sky)"/>
      <path d="M0 275 C90 240 150 320 240 275 S390 250 480 292 S620 245 720 282 V420 H0Z" fill="url(#sea)"/>
      <g opacity=".7" stroke="#83979a" fill="none" stroke-width="3">
        <path d="M0 302 C90 275 160 332 250 300 S410 277 500 315 S640 282 720 310"/>
        <path d="M0 340 C110 310 175 365 285 330 S450 318 555 352 S650 326 720 348"/>
      </g>
      <g transform="translate(370 230) rotate(22)">
        <path d="M-180 15 H175 L125 88 H-145Z" fill="#111719" stroke="#a6b2b3" stroke-width="4"/>
        <path d="M-105 -72 H80 V18 H-105Z" fill="#1a2224" stroke="#95a3a4" stroke-width="3"/>
        <rect x="-78" y="-50" width="28" height="20" rx="3" fill="#c7d2d2"/>
        <rect x="-34" y="-50" width="28" height="20" rx="3" fill="#c7d2d2"/>
        <rect x="10" y="-50" width="28" height="20" rx="3" fill="#c7d2d2"/>
        <path d="M72 -72 V-155" stroke="#b4c0c0" stroke-width="5"/>
        <path d="M75 -150 L130 -70" stroke="#707d7e" stroke-width="3"/>
        <path d="M-25 -75 V-132" stroke="#b4c0c0" stroke-width="5"/>
        <ellipse cx="-6" cy="-145" rx="18" ry="32" fill="#262b2b" opacity=".8"/>
        <ellipse cx="4" cy="-180" rx="27" ry="45" fill="#1d2222" opacity=".55"/>
      </g>
      <g filter="url(#glow)">
        <path d="M515 250 q42 18 70 3 q-12 26 -42 33 q-27 4 -50 -8" fill="none" stroke="#b7ff00" stroke-width="4" opacity=".35"/>
      </g>
    </svg>`;

  function getRankingPhotos() {
    return [
      'data:image/jpeg;base64,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',
      'data:image/jpeg;base64,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',
      'data:image/jpeg;base64,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'
    ];
  }

  function style() {
    if (document.getElementById('p50ApprovedOnboardingStyles')) return;
    const el = document.createElement('style');
    el.id = 'p50ApprovedOnboardingStyles';
    el.textContent = `
      ${ROOT} .p50-ob-rank-avatar{overflow:hidden!important;border-radius:13px!important}
      ${ROOT} .p50-ob-rank-avatar img{display:block;width:100%;height:100%;object-fit:cover;object-position:center 18%}
      ${ROOT} .p50-approved-boat{height:230px;margin:18px 0 6px;border:1px solid #432222;border-radius:16px;overflow:hidden;background:#060909;box-shadow:inset 0 0 35px rgba(0,0,0,.75)}
      ${ROOT} .p50-approved-boat svg{width:100%;height:100%;display:block}
      @media(max-width:600px){${ROOT} .p50-approved-boat{height:220px}}
    `;
    document.head.appendChild(el);
  }

  function applyApprovedScreen() {
    const root = document.querySelector(ROOT);
    if (!root || root.hidden) return;
    style();

    const eyebrow = (root.querySelector('.p50-ob-eyebrow')?.textContent || '').trim().toUpperCase();

    if (eyebrow.includes('CLASSEMENT')) {
      const names = [...root.querySelectorAll('.p50-ob-rank-name')];
      const approvedNames = ['Blue', 'Costard', 'Compagnie'];
      names.slice(0, 3).forEach((el, i) => {
        if (el.textContent !== approvedNames[i]) el.textContent = approvedNames[i];
      });

      const photos = getRankingPhotos();
      const avatars = [...root.querySelectorAll('.p50-ob-rank-avatar')];
      // Podium order in DOM: 2nd, 1st, 3rd → map photos accordingly when available.
      const photoOrder = [photos[1] || photos[0], photos[0], photos[2] || photos[1]];
      avatars.slice(0, 3).forEach((avatar, i) => {
        if (!photoOrder[i]) return;
        const existing = avatar.querySelector('img');
        if (existing?.src === photoOrder[i]) return;
        avatar.replaceChildren();
        const img = document.createElement('img');
        img.src = photoOrder[i];
        img.alt = '';
        avatar.appendChild(img);
      });
      return;
    }

    if (eyebrow.includes('COUL')) {
      const old = root.querySelector('.p50-ob-downchart');
      if (old && !root.querySelector('.p50-approved-boat')) {
        const boat = document.createElement('div');
        boat.className = 'p50-approved-boat';
        boat.innerHTML = boatSvg;
        old.replaceWith(boat);
      }
    }
  }

  function boot() {
    let rootObserver = null;
    let lastRoot = null;
    let queued = false;

    const run = () => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(() => {
        queued = false;
        const root = document.querySelector(ROOT);
        if (root && root !== lastRoot) {
          if (rootObserver) rootObserver.disconnect();
          rootObserver = new MutationObserver(run);
          rootObserver.observe(root, { childList: true, subtree: true });
          lastRoot = root;
        }
        applyApprovedScreen();
      });
    };

    const mountObserver = new MutationObserver(run);
    mountObserver.observe(document.body, { childList: true, subtree: true });
    run();
    setTimeout(() => mountObserver.disconnect(), 5000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
