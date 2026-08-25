(() => {
  'use strict';

  const STORAGE_KEY = 'pass50_onboarding_seen_v1';
  const ROOT_ID = 'pass50-onboarding-root';

  const slides = [
    {
      eyebrow: 'PASS50 🇨🇮',
      title: 'L’actualité des influenceurs ivoiriens, autrement.',
      body: 'Découvre qui fait parler, qui monte… et qui coule.',
      primary: 'Commencer',
      accent: '50'
    },
    {
      eyebrow: 'LE CLASSEMENT',
      title: 'Qui domine le moment ?',
      body: 'PASS50 analyse l’activité et les tendances autour des influenceurs pour établir un score sur 100. Le classement évolue naturellement selon l’actualité.',
      note: 'Aucun classement forcé.',
      primary: 'Suivant',
      accent: '87/100'
    },
    {
      eyebrow: 'PARIE SUR L’ACTUALITÉ',
      title: 'Tu penses savoir ce qui va se passer ?',
      body: 'Pronostique l’évolution de l’actualité des influenceurs et confronte ton intuition à celle de la communauté.',
      chips: ['🔥 Buzz', '📈 Progression', '📉 Chute', '👑 Top classement'],
      primary: 'Suivant',
      secondary: 'Je tente ma chance',
      action: 'bet',
      accent: '🎯'
    },
    {
      eyebrow: 'LES COULÉS',
      title: 'Qui est coulé, qui mousse plus ?',
      body: 'Ça va se savoir 👀 Retrouve les personnalités dont la dynamique est en baisse et découvre ce qui fait réagir la communauté.',
      primary: 'Suivant',
      secondary: 'Voir qui est coulé',
      action: 'coules',
      accent: '📉'
    },
    {
      eyebrow: 'À TOI DE JOUER',
      title: 'Bienvenue dans PASS50.',
      body: 'Observe. Vote. Pronostique. Réagis.',
      note: 'Et reviens régulièrement : le classement peut changer à tout moment.',
      primary: 'Découvrir PASS50',
      accent: '🚀'
    }
  ];

  let current = 0;
  let touchStartX = 0;
  let previousFocus = null;

  const css = `
    #${ROOT_ID}{position:fixed;inset:0;z-index:2147483000;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#fff}
    #${ROOT_ID}[hidden]{display:none!important}
    .p50-ob-backdrop{position:absolute;inset:0;background:rgba(7,8,15,.82);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
    .p50-ob-shell{position:relative;min-height:100%;display:grid;place-items:center;padding:22px}
    .p50-ob-card{position:relative;width:min(100%,520px);min-height:min(700px,calc(100vh - 44px));overflow:hidden;border:1px solid rgba(255,255,255,.12);border-radius:30px;background:radial-gradient(circle at 85% 12%,rgba(122,63,255,.34),transparent 32%),linear-gradient(160deg,#171326 0%,#0c0b14 62%,#08080d 100%);box-shadow:0 28px 90px rgba(0,0,0,.48);display:flex;flex-direction:column}
    .p50-ob-top{display:flex;justify-content:flex-end;padding:20px 20px 0}
    .p50-ob-skip{appearance:none;border:0;background:rgba(255,255,255,.08);color:rgba(255,255,255,.78);font-weight:750;font-size:14px;border-radius:999px;padding:10px 14px;cursor:pointer}
    .p50-ob-content{display:flex;flex:1;flex-direction:column;justify-content:center;padding:22px 34px 16px;text-align:left}
    .p50-ob-visual{width:104px;height:104px;border-radius:28px;display:grid;place-items:center;margin-bottom:30px;background:linear-gradient(145deg,#8b5cf6,#5b21b6);box-shadow:0 18px 45px rgba(109,40,217,.35);font-size:34px;font-weight:950;letter-spacing:-2px;transform:rotate(-3deg)}
    .p50-ob-eyebrow{font-size:12px;letter-spacing:.16em;font-weight:900;color:#b9a2ff;margin:0 0 12px;text-transform:uppercase}
    .p50-ob-title{font-size:clamp(30px,8vw,46px);line-height:1.02;letter-spacing:-.04em;margin:0;max-width:440px;font-weight:950}
    .p50-ob-body{font-size:17px;line-height:1.55;color:rgba(255,255,255,.72);margin:20px 0 0;max-width:430px}
    .p50-ob-note{margin:16px 0 0;font-weight:850;color:#fff}
    .p50-ob-chips{display:flex;flex-wrap:wrap;gap:9px;margin-top:22px}
    .p50-ob-chip{padding:9px 12px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);font-size:13px;font-weight:800}
    .p50-ob-footer{padding:16px 28px 28px}
    .p50-ob-dots{display:flex;justify-content:center;gap:8px;margin-bottom:18px}
    .p50-ob-dot{width:7px;height:7px;border-radius:999px;background:rgba(255,255,255,.22);transition:width .25s ease,background .25s ease}
    .p50-ob-dot.is-active{width:24px;background:#8b5cf6}
    .p50-ob-actions{display:grid;gap:10px}
    .p50-ob-btn{appearance:none;width:100%;border:0;border-radius:17px;padding:16px 18px;font-size:16px;font-weight:900;cursor:pointer}
    .p50-ob-primary{background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:white;box-shadow:0 13px 34px rgba(109,40,217,.3)}
    .p50-ob-secondary{background:rgba(255,255,255,.08);color:white;border:1px solid rgba(255,255,255,.1)}
    .p50-ob-btn:focus-visible,.p50-ob-skip:focus-visible{outline:3px solid rgba(196,181,253,.8);outline-offset:3px}
    .p50-ob-card.is-entering .p50-ob-content>*{animation:p50ObIn .32s ease both}
    .p50-ob-card.is-entering .p50-ob-content>*:nth-child(2){animation-delay:.03s}.p50-ob-card.is-entering .p50-ob-content>*:nth-child(3){animation-delay:.06s}.p50-ob-card.is-entering .p50-ob-content>*:nth-child(4){animation-delay:.09s}
    @keyframes p50ObIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
    @media(max-width:600px){.p50-ob-shell{padding:0}.p50-ob-card{width:100%;min-height:100dvh;border:0;border-radius:0}.p50-ob-content{padding:18px 25px 12px}.p50-ob-footer{padding:14px 22px max(22px,env(safe-area-inset-bottom))}.p50-ob-top{padding-top:max(16px,env(safe-area-inset-top))}.p50-ob-visual{width:88px;height:88px;border-radius:24px;margin-bottom:25px}}
    @media(prefers-reduced-motion:reduce){.p50-ob-card.is-entering .p50-ob-content>*{animation:none}.p50-ob-dot{transition:none}}
  `;

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  }

  function markSeen() {
    try { localStorage.setItem(STORAGE_KEY, '1'); } catch (_) {}
  }

  function hasSeen() {
    try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch (_) { return false; }
  }

  function findAndActivate(key) {
    const patterns = key === 'bet'
      ? [/pari/i, /pronostic/i, /actualité/i]
      : [/coul[ée]s?/i, /qui.*mousse/i];
    const nodes = [...document.querySelectorAll('a,button,[role="button"],[data-tab],[data-section]')]
      .filter(node => !node.closest(`#${ROOT_ID}`));
    const target = nodes.find(node => {
      const haystack = [node.textContent, node.getAttribute('href'), node.getAttribute('aria-label'), node.getAttribute('data-tab'), node.getAttribute('data-section')].filter(Boolean).join(' ');
      return patterns.some(pattern => pattern.test(haystack));
    });
    if (target) {
      close(true);
      requestAnimationFrame(() => target.click());
      return true;
    }
    return false;
  }

  function render() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    const slide = slides[current];
    const card = root.querySelector('.p50-ob-card');
    const content = root.querySelector('.p50-ob-content');
    const footer = root.querySelector('.p50-ob-footer');

    content.innerHTML = `
      <div class="p50-ob-visual" aria-hidden="true">${escapeHtml(slide.accent)}</div>
      <p class="p50-ob-eyebrow">${escapeHtml(slide.eyebrow)}</p>
      <h2 class="p50-ob-title" id="p50-ob-title">${escapeHtml(slide.title)}</h2>
      <p class="p50-ob-body">${escapeHtml(slide.body)}</p>
      ${slide.note ? `<p class="p50-ob-note">${escapeHtml(slide.note)}</p>` : ''}
      ${slide.chips ? `<div class="p50-ob-chips">${slide.chips.map(chip => `<span class="p50-ob-chip">${escapeHtml(chip)}</span>`).join('')}</div>` : ''}
    `;

    footer.innerHTML = `
      <div class="p50-ob-dots" aria-label="Étape ${current + 1} sur ${slides.length}">
        ${slides.map((_, index) => `<span class="p50-ob-dot${index === current ? ' is-active' : ''}" aria-hidden="true"></span>`).join('')}
      </div>
      <div class="p50-ob-actions">
        ${slide.secondary ? `<button class="p50-ob-btn p50-ob-secondary" type="button" data-ob-secondary>${escapeHtml(slide.secondary)}</button>` : ''}
        <button class="p50-ob-btn p50-ob-primary" type="button" data-ob-primary>${escapeHtml(slide.primary)}</button>
      </div>
    `;

    card.setAttribute('aria-labelledby', 'p50-ob-title');
    card.classList.remove('is-entering');
    void card.offsetWidth;
    card.classList.add('is-entering');

    footer.querySelector('[data-ob-primary]').addEventListener('click', () => {
      if (current === slides.length - 1) close(true);
      else goTo(current + 1);
    });

    const secondary = footer.querySelector('[data-ob-secondary]');
    if (secondary) secondary.addEventListener('click', () => {
      if (!findAndActivate(slide.action)) goTo(current + 1);
    });
  }

  function goTo(index) {
    current = Math.max(0, Math.min(slides.length - 1, index));
    render();
  }

  function open(force = false) {
    if (!force && hasSeen()) return;
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    previousFocus = document.activeElement;
    current = 0;
    root.hidden = false;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    render();
    requestAnimationFrame(() => root.querySelector('.p50-ob-skip')?.focus());
  }

  function close(save = true) {
    const root = document.getElementById(ROOT_ID);
    if (!root || root.hidden) return;
    if (save) markSeen();
    root.hidden = true;
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
  }

  function addReplayEntry() {
    const candidates = [...document.querySelectorAll('nav,aside,[role="menu"],section,div')].filter(el => {
      const txt = (el.textContent || '').trim();
      return txt.length < 1200 && /param[èe]tres|settings/i.test(txt);
    });
    const host = candidates.sort((a,b) => (a.textContent || '').length - (b.textContent || '').length)[0];
    if (!host || host.querySelector('[data-pass50-replay-onboarding]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.pass50ReplayOnboarding = 'true';
    button.textContent = 'Revoir le tutoriel';
    button.style.cssText = 'display:block;width:100%;margin-top:8px;padding:12px 14px;border-radius:12px;border:1px solid rgba(127,127,127,.25);background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer;text-align:left;';
    button.addEventListener('click', () => open(true));
    host.appendChild(button);
  }

  function mount() {
    if (document.getElementById(ROOT_ID)) return;
    const style = document.createElement('style');
    style.id = 'pass50-onboarding-styles';
    style.textContent = css;
    document.head.appendChild(style);

    const root = document.createElement('div');
    root.id = ROOT_ID;
    root.hidden = true;
    root.innerHTML = `
      <div class="p50-ob-backdrop" aria-hidden="true"></div>
      <div class="p50-ob-shell">
        <section class="p50-ob-card" role="dialog" aria-modal="true" aria-label="Tutoriel PASS50">
          <div class="p50-ob-top"><button class="p50-ob-skip" type="button">Passer</button></div>
          <div class="p50-ob-content"></div>
          <div class="p50-ob-footer"></div>
        </section>
      </div>`;
    document.body.appendChild(root);

    root.querySelector('.p50-ob-skip').addEventListener('click', () => close(true));
    root.addEventListener('touchstart', event => { touchStartX = event.changedTouches[0].clientX; }, {passive:true});
    root.addEventListener('touchend', event => {
      const delta = event.changedTouches[0].clientX - touchStartX;
      if (Math.abs(delta) < 48) return;
      if (delta < 0 && current < slides.length - 1) goTo(current + 1);
      if (delta > 0 && current > 0) goTo(current - 1);
    }, {passive:true});
    root.addEventListener('keydown', event => {
      if (event.key === 'ArrowRight' && current < slides.length - 1) goTo(current + 1);
      if (event.key === 'ArrowLeft' && current > 0) goTo(current - 1);
      if (event.key === 'Escape') close(true);
      if (event.key === 'Tab') {
        const focusables = [...root.querySelectorAll('button:not([disabled])')].filter(el => el.offsetParent !== null);
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });

    window.PASS50Onboarding = { open: () => open(true), close: () => close(false), reset: () => { try { localStorage.removeItem(STORAGE_KEY); } catch (_) {} } };

    addReplayEntry();
    const observer = new MutationObserver(() => addReplayEntry());
    observer.observe(document.body, {childList:true,subtree:true});

    setTimeout(() => open(false), 180);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true});
  else mount();
})();
