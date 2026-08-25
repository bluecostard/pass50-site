(() => {
  'use strict';

  const STORAGE_KEY = 'pass50_onboarding_seen_v1';
  const ROOT_ID = 'pass50-onboarding-root';

  const slides = [
    {
      step: 'Bienvenue',
      stepHint: 'Découvre l’app',
      eyebrow: 'PASS50 🇨🇮',
      title: 'L’actualité des influenceurs ivoiriens, autrement.',
      titleHtml: 'L’actualité des influenceurs ivoiriens, <span class="p50-ob-accent">autrement.</span>',
      body: 'Classement actualisé toutes les 2h - 24h - 48h',
      primary: 'Commencer',
      type: 'welcome'
    },
    {
      step: 'Le classement',
      stepHint: 'Vois qui domine',
      eyebrow: 'LE CLASSEMENT',
      title: 'Le classement. Vois qui domine et grimpe dans le classement.',
      body: 'Le classement évolue naturellement selon l’actualité.',
      note: 'Aucun classement forcé.',
      primary: 'Suivant',
      type: 'ranking'
    },
    {
      step: 'Parie',
      stepHint: 'Sur l’actualité',
      eyebrow: 'PARIE SUR L’ACTUALITÉ',
      title: 'Parie sur l’actualité',
      body: 'Pronostique l’évolution des influenceurs et confronte ton intuition à celle de la communauté.',
      chips: [
        {icon: '⚡', label: 'Parie', detail: 'Ça va faire le buzz', tone: 'buzz'},
        {icon: '↗', label: 'Progression', detail: 'Ça va monter', tone: 'up'},
        {icon: '↘', label: 'Chute', detail: 'Ça va chuter', tone: 'down'},
        {icon: '🏆', label: 'Top classement', detail: 'Ça va intégrer le top', tone: 'top'}
      ],
      primary: 'Je tente ma chance',
      action: 'bet',
      type: 'bet'
    },
    {
      step: 'Les coulés',
      stepHint: 'Qui mousse plus ? Ça va se savoir! 🌊',
      eyebrow: 'LES COULÉS',
      title: 'Les coulés. Qui mousse plus ? Ça va se savoir! 🌊',
      body: 'Retrouve les personnalités dont la dynamique est en baisse et découvre ce qui fait réagir la communauté.',
      primary: 'Voir qui est coulé',
      action: 'coules',
      type: 'coules'
    },
    {
      step: 'À toi de jouer',
      stepHint: 'C’est parti !',
      eyebrow: 'À TOI DE JOUER',
      title: 'À toi de jouer. C’est parti !',
      body: 'Observe. Vote. Pronostique. Réagis.',
      noteHtml: 'Et reviens régulièrement : le classement peut <span class="p50-ob-accent">changer</span> à tout moment.',
      note: 'Et reviens régulièrement : le classement peut changer à tout moment.',
      primary: 'Découvrir PASS50',
      type: 'final'
    }
  ];

  let current = 0;
  let touchStartX = 0;
  let previousFocus = null;

  const css = `
    #${ROOT_ID}{position:fixed;inset:0;z-index:2147483000;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#f6f8f4}
    #${ROOT_ID}[hidden]{display:none!important}
    .p50-ob-backdrop{position:absolute;inset:0;background:rgba(2,4,2,.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
    .p50-ob-shell{position:relative;min-height:100%;display:grid;place-items:center;padding:22px}
    .p50-ob-card{position:relative;width:min(100%,520px);min-height:min(720px,calc(100vh - 44px));overflow:hidden;border:1px solid rgba(183,255,0,.34);border-radius:30px;background:radial-gradient(circle at 50% 22%,rgba(183,255,0,.08),transparent 28%),linear-gradient(180deg,#090d09 0%,#050705 65%,#030503 100%);box-shadow:0 0 0 1px rgba(183,255,0,.05),0 28px 90px rgba(0,0,0,.64),0 0 42px rgba(183,255,0,.08);display:flex;flex-direction:column}
    .p50-ob-card:after{content:"";position:absolute;inset:auto -80px -100px auto;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(183,255,0,.09),transparent 68%);pointer-events:none}
    .p50-ob-progress{display:flex;gap:5px;padding:14px 18px 0;position:relative;z-index:3}
    .p50-ob-progress span{flex:1;height:3px;border-radius:999px;background:#2a3229}
    .p50-ob-progress span.is-done,.p50-ob-progress span.is-active{background:#b7ff00;box-shadow:0 0 10px rgba(183,255,0,.45)}
    .p50-ob-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:12px 19px 0;position:relative;z-index:3}
    .p50-ob-step-meta{min-width:0}.p50-ob-step-meta strong{display:block;font-size:13px;font-weight:1000}.p50-ob-step-meta span{display:block;font-size:11px;color:#aeb7ab;margin-top:2px;line-height:1.35}
    .p50-ob-skip{appearance:none;border:1px solid rgba(183,255,0,.16);background:#090d09;color:#cbd3c8;font-weight:850;font-size:13px;border-radius:999px;padding:9px 13px;cursor:pointer;flex:0 0 auto}
    .p50-ob-content{display:flex;flex:1;flex-direction:column;justify-content:center;padding:18px 32px 14px;text-align:left;position:relative;z-index:1}
    .p50-ob-accent{color:#b7ff00}
    .p50-ob-eyebrow{font-size:11px;letter-spacing:.16em;font-weight:1000;color:#b7ff00;margin:0 0 11px;text-transform:uppercase}
    .p50-ob-title{font-size:clamp(28px,7.2vw,42px);line-height:1.05;letter-spacing:-.045em;margin:0;max-width:440px;font-weight:1000}
    .p50-ob-body{font-size:16px;line-height:1.52;color:#b8c1b5;margin:18px 0 0;max-width:430px}
    .p50-ob-note{margin:14px 0 0;font-weight:850;color:#dce5d9;line-height:1.45}
    .p50-ob-footer{padding:13px 26px 26px;position:relative;z-index:3}
    .p50-ob-dots{display:flex;justify-content:center;gap:7px;margin-bottom:17px}
    .p50-ob-dot{width:22px;height:5px;border-radius:999px;background:#323a31;transition:background .2s ease,box-shadow .2s ease}
    .p50-ob-dot.is-active{background:#b7ff00;box-shadow:0 0 16px rgba(183,255,0,.5)}
    .p50-ob-actions{display:grid;gap:9px}
    .p50-ob-btn{appearance:none;width:100%;border-radius:999px;padding:15px 18px;font-size:15px;font-weight:1000;cursor:pointer}
    .p50-ob-primary{border:1px solid #b7ff00;background:linear-gradient(135deg,#c8ff1a,#8ddd00);color:#050705;box-shadow:0 10px 30px rgba(183,255,0,.17)}
    .p50-ob-btn:focus-visible,.p50-ob-skip:focus-visible{outline:3px solid rgba(183,255,0,.62);outline-offset:3px}
    .p50-ob-brand{font-size:52px;font-weight:1000;letter-spacing:-4px;line-height:1;margin-bottom:18px}.p50-ob-brand .pass{color:#fff}.p50-ob-brand .fifty{color:#b7ff00}
    .p50-ob-flag{width:76px;height:48px;border-radius:8px;overflow:hidden;display:grid;grid-template-columns:repeat(3,1fr);box-shadow:0 8px 24px rgba(0,0,0,.38);margin-bottom:24px}.p50-ob-flag span:nth-child(1){background:#f77f00}.p50-ob-flag span:nth-child(2){background:#fff}.p50-ob-flag span:nth-child(3){background:#009e60}
    .p50-ob-crowd{height:74px;margin:0 -32px 22px;background:linear-gradient(180deg,transparent,rgba(183,255,0,.04)),repeating-radial-gradient(ellipse at 50% 100%,rgba(183,255,0,.12) 0 3px,transparent 4px 15px);mask-image:linear-gradient(to top,#000,transparent);-webkit-mask-image:linear-gradient(to top,#000,transparent)}
    .p50-ob-podium{display:grid;grid-template-columns:1fr 1.18fr 1fr;align-items:end;gap:9px;margin:25px 0 4px}
    .p50-ob-rank{border:1px solid #334032;background:linear-gradient(180deg,#111711,#080b08);border-radius:16px;padding:12px 8px;text-align:center}.p50-ob-rank.first{border-color:#b7ff00;box-shadow:0 0 24px rgba(183,255,0,.13);padding-top:16px;padding-bottom:17px}
    .p50-ob-rank-pos{font-size:12px;color:#98a395;font-weight:950}.p50-ob-rank-avatar{width:46px;height:46px;margin:7px auto;border-radius:50%;display:grid;place-items:center;background:#1b241a;border:1px solid rgba(183,255,0,.28);font-size:20px;overflow:hidden}.p50-ob-rank.first .p50-ob-rank-avatar{width:56px;height:56px;border-color:#b7ff00}
    .p50-ob-rank-avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .p50-ob-rank-name{font-size:11px;text-transform:uppercase;font-weight:1000}.p50-ob-rank-score{font-size:27px;color:#b7ff00;font-weight:1000;line-height:1.1;margin-top:5px}.p50-ob-rank-score small{display:block;color:#9ca699;font-size:9px;font-weight:800}
    .p50-ob-score-ring{width:105px;height:105px;margin:18px auto 0;border-radius:50%;display:grid;place-items:center;border:9px solid rgba(183,255,0,.18);box-shadow:inset 0 0 0 3px #b7ff00,0 0 26px rgba(183,255,0,.15);color:#b7ff00;font-size:34px;font-weight:1000}.p50-ob-score-ring small{display:block;font-size:10px;color:#c9d2c6;text-align:center;margin-top:-6px}
    .p50-ob-chips{display:grid;gap:9px;margin-top:20px}.p50-ob-chip{display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:13px;background:#0a0e0a;border:1px solid rgba(183,255,0,.36);font-size:14px;font-weight:900}.p50-ob-chip-icon{width:28px;text-align:center;flex:0 0 auto}.p50-ob-chip-copy{min-width:0}.p50-ob-chip-copy strong{display:block}.p50-ob-chip-copy span{display:block;font-size:12px;font-weight:700;color:#aeb7ab;margin-top:2px}.p50-ob-chip.tone-up{border-color:#1ee5ff;color:#9ef4ff}.p50-ob-chip.tone-down{border-color:#ff4b4b;color:#ff9d9d}.p50-ob-chip.tone-top{border-color:#a66cff;color:#d9c1ff}.p50-ob-chip.tone-buzz{border-color:#b7ff00;color:#e7ff9d}
    .p50-ob-downchart,.p50-approved-boat{height:125px;margin:22px 0 5px;border:1px solid #432222;border-radius:16px;background:linear-gradient(180deg,rgba(255,75,75,.06),rgba(5,7,5,.2));position:relative;overflow:hidden}.p50-ob-downchart:before{content:"";position:absolute;inset:16px;background:linear-gradient(145deg,transparent 0 18%,#ff4b4b 19% 21%,transparent 22% 38%,#ff4b4b 39% 41%,transparent 42% 59%,#ff4b4b 60% 62%,transparent 63%)}.p50-ob-downchart:after{content:"↘  DYNAMIQUE EN BAISSE";position:absolute;left:14px;bottom:11px;color:#ff6565;font-size:11px;font-weight:1000;letter-spacing:.06em}
    .p50-ob-final-list{display:grid;gap:13px;margin-top:25px}.p50-ob-final-item{display:flex;align-items:center;gap:13px;font-size:20px;font-weight:1000}.p50-ob-final-icon{width:42px;height:42px;border:1px solid #b7ff00;border-radius:50%;display:grid;place-items:center;color:#b7ff00}.p50-ob-final-item:nth-child(2){color:#ff9d1d}.p50-ob-final-item:nth-child(2) .p50-ob-final-icon{border-color:#ff9d1d;color:#ff9d1d}.p50-ob-final-item:nth-child(3){color:#a66cff}.p50-ob-final-item:nth-child(3) .p50-ob-final-icon{border-color:#a66cff;color:#a66cff}.p50-ob-final-item:nth-child(4){color:#1ee5ff}.p50-ob-final-item:nth-child(4) .p50-ob-final-icon{border-color:#1ee5ff;color:#1ee5ff}
    .p50-ob-hit{position:absolute;top:78px;bottom:118px;width:28%;z-index:2;background:transparent;border:0;padding:0;cursor:pointer;-webkit-tap-highlight-color:transparent}
    .p50-ob-hit-left{left:0}.p50-ob-hit-right{right:0}
    .p50-ob-card.is-entering .p50-ob-content>*{animation:p50ObIn .3s ease both}.p50-ob-card.is-entering .p50-ob-content>*:nth-child(2){animation-delay:.03s}.p50-ob-card.is-entering .p50-ob-content>*:nth-child(3){animation-delay:.06s}.p50-ob-card.is-entering .p50-ob-content>*:nth-child(4){animation-delay:.09s}
    @keyframes p50ObIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
    @media(max-width:600px){.p50-ob-shell{padding:0}.p50-ob-card{width:100%;min-height:100dvh;border:0;border-radius:0}.p50-ob-content{padding:14px 24px 10px}.p50-ob-footer{padding:12px 21px max(21px,env(safe-area-inset-bottom))}.p50-ob-top,.p50-ob-progress{padding-left:max(16px,env(safe-area-inset-left));padding-right:max(16px,env(safe-area-inset-right))}.p50-ob-progress{padding-top:max(12px,env(safe-area-inset-top))}.p50-ob-crowd{margin-left:-24px;margin-right:-24px}.p50-ob-brand{font-size:48px}.p50-ob-hit{top:96px;bottom:128px;width:30%}}
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
    const patterns = key === 'bet' ? [/pari/i, /pronostic/i] : [/coul[ée]s?/i, /qui.*mousse/i];
    const nodes = [...document.querySelectorAll('a,button,[role="button"],[data-tab],[data-section]')].filter(node => !node.closest(`#${ROOT_ID}`));
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

  function visualFor(slide) {
    if (slide.type === 'welcome') return `
      <div class="p50-ob-brand" aria-label="PASS50"><span class="pass">PASS</span><span class="fifty">50</span></div>
      <div class="p50-ob-flag" role="img" aria-label="Drapeau de la Côte d’Ivoire"><span></span><span></span><span></span></div>
      <div class="p50-ob-crowd" aria-hidden="true"></div>`;
    if (slide.type === 'ranking') return `
      <div class="p50-ob-podium" aria-label="Exemple de classement">
        <div class="p50-ob-rank"><div class="p50-ob-rank-pos">2</div><div class="p50-ob-rank-avatar">B</div><div class="p50-ob-rank-name">Blue</div><div class="p50-ob-rank-score">72<small>/100</small></div></div>
        <div class="p50-ob-rank first"><div class="p50-ob-rank-pos">👑 1</div><div class="p50-ob-rank-avatar">C</div><div class="p50-ob-rank-name">Costard</div><div class="p50-ob-rank-score">87<small>/100</small></div></div>
        <div class="p50-ob-rank"><div class="p50-ob-rank-pos">3</div><div class="p50-ob-rank-avatar">C</div><div class="p50-ob-rank-name">Compagnie</div><div class="p50-ob-rank-score">65<small>/100</small></div></div>
      </div><div class="p50-ob-score-ring">87<small>/100</small></div>`;
    if (slide.type === 'coules') return `<div class="p50-ob-downchart" aria-hidden="true"></div>`;
    if (slide.type === 'final') return `<div class="p50-ob-final-list"><div class="p50-ob-final-item"><span class="p50-ob-final-icon" aria-hidden="true">◉</span>Observe.</div><div class="p50-ob-final-item"><span class="p50-ob-final-icon" aria-hidden="true">✓</span>Vote.</div><div class="p50-ob-final-item"><span class="p50-ob-final-icon" aria-hidden="true">◈</span>Pronostique.</div><div class="p50-ob-final-item"><span class="p50-ob-final-icon" aria-hidden="true">●</span>Réagis.</div></div>`;
    return '';
  }

  function chipsHtml(slide) {
    if (!slide.chips) return '';
    return `<div class="p50-ob-chips">${slide.chips.map(chip => `
      <div class="p50-ob-chip tone-${escapeHtml(chip.tone)}">
        <span class="p50-ob-chip-icon" aria-hidden="true">${escapeHtml(chip.icon)}</span>
        <span class="p50-ob-chip-copy"><strong>${escapeHtml(chip.label)}</strong><span>${escapeHtml(chip.detail)}</span></span>
      </div>`).join('')}</div>`;
  }

  function render() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    const slide = slides[current];
    const card = root.querySelector('.p50-ob-card');
    const progress = root.querySelector('.p50-ob-progress');
    const stepMeta = root.querySelector('.p50-ob-step-meta');
    const content = root.querySelector('.p50-ob-content');
    const footer = root.querySelector('.p50-ob-footer');

    progress.innerHTML = slides.map((_, index) => `<span class="${index < current ? 'is-done' : ''}${index === current ? ' is-active' : ''}" aria-hidden="true"></span>`).join('');
    stepMeta.innerHTML = `<strong>${escapeHtml(slide.step)}</strong><span>${escapeHtml(slide.stepHint)}</span>`;

    content.innerHTML = `
      <p class="p50-ob-eyebrow">${escapeHtml(slide.eyebrow)}</p>
      ${visualFor(slide)}
      <h2 class="p50-ob-title" id="p50-ob-title">${slide.titleHtml || escapeHtml(slide.title)}</h2>
      ${slide.type === 'final' ? '' : `<p class="p50-ob-body">${escapeHtml(slide.body)}</p>`}
      ${slide.noteHtml ? `<p class="p50-ob-note">${slide.noteHtml}</p>` : (slide.note ? `<p class="p50-ob-note">${escapeHtml(slide.note)}</p>` : '')}
      ${chipsHtml(slide)}
    `;

    footer.innerHTML = `
      <div class="p50-ob-dots" aria-label="Étape ${current + 1} sur ${slides.length}">${slides.map((_, index) => `<span class="p50-ob-dot${index === current ? ' is-active' : ''}" aria-hidden="true"></span>`).join('')}</div>
      <div class="p50-ob-actions">
        <button class="p50-ob-btn p50-ob-primary" type="button" data-ob-primary>${escapeHtml(slide.primary)} ›</button>
      </div>`;

    card.setAttribute('aria-labelledby', 'p50-ob-title');
    card.classList.remove('is-entering');
    void card.offsetWidth;
    card.classList.add('is-entering');

    footer.querySelector('[data-ob-primary]').addEventListener('click', () => {
      if (slide.action && findAndActivate(slide.action)) return;
      if (current === slides.length - 1) close(true);
      else goTo(current + 1);
    });
  }

  function goTo(index) {
    current = Math.max(0, Math.min(slides.length - 1, index));
    render();
  }

  function advance() {
    if (current >= slides.length - 1) close(true);
    else goTo(current + 1);
  }

  function retreat() {
    if (current > 0) goTo(current - 1);
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
    const host = candidates.sort((a, b) => (a.textContent || '').length - (b.textContent || '').length)[0];
    if (!host || host.querySelector('[data-pass50-replay-onboarding]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.pass50ReplayOnboarding = 'true';
    button.textContent = 'Revoir le tutoriel';
    button.style.cssText = 'display:block;width:100%;margin-top:8px;padding:12px 14px;border-radius:12px;border:1px solid rgba(183,255,0,.25);background:#090d09;color:inherit;font:inherit;font-weight:800;cursor:pointer;text-align:left;';
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
    root.innerHTML = `<div class="p50-ob-backdrop" aria-hidden="true"></div><div class="p50-ob-shell"><section class="p50-ob-card" role="dialog" aria-modal="true" aria-label="Tutoriel PASS50"><div class="p50-ob-progress" aria-hidden="true"></div><div class="p50-ob-top"><div class="p50-ob-step-meta"></div><button class="p50-ob-skip" type="button">Passer</button></div><button class="p50-ob-hit p50-ob-hit-left" type="button" aria-label="Avancer"></button><button class="p50-ob-hit p50-ob-hit-right" type="button" aria-label="Avancer"></button><div class="p50-ob-content"></div><div class="p50-ob-footer"></div></section></div>`;
    document.body.appendChild(root);

    root.querySelector('.p50-ob-skip').addEventListener('click', () => close(true));
    root.querySelector('.p50-ob-hit-left').addEventListener('click', advance);
    root.querySelector('.p50-ob-hit-right').addEventListener('click', advance);
    root.addEventListener('touchstart', event => { touchStartX = event.changedTouches[0].clientX; }, {passive: true});
    root.addEventListener('touchend', event => {
      const delta = event.changedTouches[0].clientX - touchStartX;
      if (Math.abs(delta) < 48) return;
      if (delta < 0) advance();
      if (delta > 0) retreat();
    }, {passive: true});
    root.addEventListener('keydown', event => {
      if (event.key === 'ArrowRight') advance();
      if (event.key === 'ArrowLeft') retreat();
      if (event.key === 'Escape') close(true);
      if (event.key === 'Tab') {
        const focusables = [...root.querySelectorAll('button:not([disabled])')].filter(el => el.offsetParent !== null && !el.classList.contains('p50-ob-hit'));
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
    observer.observe(document.body, {childList: true, subtree: true});
    setTimeout(() => open(false), 180);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once: true});
  else mount();
})();
