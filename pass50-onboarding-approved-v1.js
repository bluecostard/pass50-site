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
    const imgs = [...document.querySelectorAll('.rank-card img,.mini img,[data-profile] img')]
      .filter(img => img.src && !img.closest(ROOT));
    return [imgs[0]?.src || '', imgs[1]?.src || '', imgs[2]?.src || ''];
  }

  function style() {
    if (document.getElementById('p50ApprovedOnboardingStyles')) return;
    const el = document.createElement('style');
    el.id = 'p50ApprovedOnboardingStyles';
    el.textContent = `
      ${ROOT} .p50-ob-eyebrow{font-size:15px!important;letter-spacing:.11em!important}
      ${ROOT} .p50-ob-title{font-size:clamp(36px,10vw,52px)!important;line-height:.98!important;letter-spacing:-.045em!important}
      ${ROOT} .p50-ob-rank-avatar{overflow:hidden!important;border-radius:13px!important}
      ${ROOT} .p50-ob-rank-avatar img{display:block;width:100%;height:100%;object-fit:cover;object-position:center 18%}
      ${ROOT} .p50-approved-boat{height:230px;margin:18px 0 6px;border:1px solid #432222;border-radius:16px;overflow:hidden;background:#060909;box-shadow:inset 0 0 35px rgba(0,0,0,.75)}
      ${ROOT} .p50-approved-boat svg{width:100%;height:100%;display:block}
      @media(max-width:600px){${ROOT} .p50-ob-title{font-size:clamp(38px,12vw,50px)!important}${ROOT} .p50-approved-boat{height:220px}}
    `;
    document.head.appendChild(el);
  }

  function setTextIfChanged(el, value) {
    if (el && el.textContent !== value) el.textContent = value;
  }

  function applyApprovedScreen() {
    const root = document.querySelector(ROOT);
    if (!root || root.hidden) return;
    style();

    const eyebrow = (root.querySelector('.p50-ob-eyebrow')?.textContent || '').trim().toUpperCase();
    const title = root.querySelector('.p50-ob-title');
    const body = root.querySelector('.p50-ob-body');

    if (eyebrow.includes('PASS50') || eyebrow.includes('BIENVENUE')) {
      setTextIfChanged(body, 'Classement actualisé toutes les 2h - 24h - 48h');
      return;
    }

    if (eyebrow.includes('CLASSEMENT')) {
      setTextIfChanged(title, 'Le classement');
      const names = [...root.querySelectorAll('.p50-ob-rank-name')];
      const approvedNames = ['Blue', 'Costard', 'Compagnie'];
      names.slice(0, 3).forEach((el, i) => setTextIfChanged(el, approvedNames[i]));

      const photos = getRankingPhotos();
      const avatars = [...root.querySelectorAll('.p50-ob-rank-avatar')];
      avatars.slice(0, 3).forEach((avatar, i) => {
        if (!photos[i]) return;
        const existing = avatar.querySelector('img');
        if (existing?.src === photos[i]) return;
        avatar.replaceChildren();
        const img = document.createElement('img');
        img.src = photos[i];
        img.alt = '';
        avatar.appendChild(img);
      });
      return;
    }

    if (eyebrow.includes('PARIE')) {
      setTextIfChanged(title, 'Parie sur l’actualité');
      return;
    }

    if (eyebrow.includes('COUL')) {
      setTextIfChanged(title, 'Les Coulés');
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
