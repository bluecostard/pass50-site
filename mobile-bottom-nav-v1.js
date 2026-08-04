'use strict';

(() => {
  const CONTRACT = 'PASS50-MOBILE-BOTTOM-NAV-V1.0';
  const isFeed = /(?:^|\/)mon-fil\.html$/i.test(location.pathname);

  function injectStyles() {
    if (document.getElementById('p50BottomNavStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50BottomNavStyles';
    style.textContent = `
      .p50-bottom-nav{display:none}
      @media(max-width:680px){
        body{padding-bottom:calc(82px + env(safe-area-inset-bottom))!important}
        body:not([data-pass50-page="feed"]) .app{padding-bottom:calc(98px + env(safe-area-inset-bottom))!important}
        body:not([data-pass50-page="feed"]) header>nav,body:not([data-pass50-page="feed"]) header>.actions{display:none!important}
        body:not([data-pass50-page="feed"]) header{justify-content:center!important}
        .p50-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:120;display:grid;grid-template-columns:repeat(4,1fr);padding:7px max(8px,env(safe-area-inset-right)) calc(7px + env(safe-area-inset-bottom)) max(8px,env(safe-area-inset-left));border-top:1px solid rgba(183,255,0,.19);background:rgba(6,9,6,.96);backdrop-filter:blur(18px);box-shadow:0 -12px 35px rgba(0,0,0,.45)}
        .p50-bottom-link{min-width:0;border:0;background:transparent;color:#9da79b;display:grid;justify-items:center;gap:3px;padding:5px 2px;border-radius:12px;font-size:9px;font-weight:950;letter-spacing:.1px;text-decoration:none}
        .p50-bottom-link .p50-bottom-icon{display:grid;place-items:center;width:26px;height:24px;font-size:18px;line-height:1}
        .p50-bottom-link.active{color:var(--lime,#b7ff00);background:rgba(183,255,0,.08)}
        .p50-bottom-link:active{transform:scale(.97)}
      }
    `;
    document.head.appendChild(style);
  }

  function navHtml() {
    return `<nav class="p50-bottom-nav" aria-label="Menu principal mobile" data-contract="${CONTRACT}">
      <a class="p50-bottom-link ${isFeed ? '' : 'active'}" href="./" data-p50-tab="ranking"><span class="p50-bottom-icon">▥</span><span>Classement</span></a>
      <a class="p50-bottom-link ${isFeed ? 'active' : ''}" href="./mon-fil.html" data-p50-tab="feed"><span class="p50-bottom-icon">≋</span><span>Mon fil</span></a>
      <a class="p50-bottom-link" href="./?open=live" data-p50-tab="live"><span class="p50-bottom-icon">●</span><span>En direct</span></a>
      <a class="p50-bottom-link" href="./?open=account" data-p50-tab="account"><span class="p50-bottom-icon">◉</span><span>Mon espace</span></a>
    </nav>`;
  }

  function injectNav() {
    if (document.querySelector('.p50-bottom-nav')) return;
    document.body.insertAdjacentHTML('beforeend', navHtml());
  }

  function injectUserEntry() {
    const body = document.getElementById('userBody');
    const grid = body?.querySelector('.user-grid');
    if (!grid) return;
    document.getElementById('p50FollowFeedModal')?.remove();
    grid.querySelector('#p50FollowFeedEntry')?.remove();
    if (grid.querySelector('#p50SeparateFeedEntry')) return;
    const section = document.createElement('section');
    section.className = 'user-section full';
    section.id = 'p50SeparateFeedEntry';
    section.innerHTML = `<div class="user-title"><span>≋ Mon fil</span><span>Page séparée</span></div><div class="pref"><div><strong>L’actualité de mes influenceurs suivis</strong><div class="muted">Une page limitée à vos suivis, distincte du classement et sans défilement infini.</div></div><a class="btn primary" href="./mon-fil.html">Ouvrir mon fil</a></div>`;
    grid.prepend(section);
  }

  function callWhenReady(name, callback, attempts = 100) {
    let count = 0;
    const timer = setInterval(() => {
      count += 1;
      if (typeof window[name] === 'function') {
        clearInterval(timer);
        callback(window[name]);
      } else if (count >= attempts) clearInterval(timer);
    }, 80);
  }

  function routeQuery() {
    if (isFeed) return;
    const params = new URLSearchParams(location.search);
    const action = params.get('open');
    const profileId = params.get('profile');
    if (action === 'live') callWhenReady('openLives', fn => fn());
    if (action === 'account') callWhenReady('currentUser', current => {
      if (current()) callWhenReady('openUser', fn => fn());
      else callWhenReady('openAuth', fn => fn('login'));
    });
    if (profileId) callWhenReady('openProfile', fn => fn(profileId));
  }

  function installEvents() {
    document.addEventListener('click', event => {
      const link = event.target.closest('.p50-bottom-link');
      if (!link || isFeed) return;
      const tab = link.dataset.p50Tab;
      if (tab === 'live' && typeof window.openLives === 'function') {
        event.preventDefault();
        window.openLives();
      }
      if (tab === 'account') {
        event.preventDefault();
        if (typeof window.currentUser === 'function' && window.currentUser()) {
          if (typeof window.openUser === 'function') window.openUser();
        } else if (typeof window.openAuth === 'function') window.openAuth('login');
      }
    });
  }

  function init() {
    injectStyles();
    injectNav();
    installEvents();
    const userBody = document.getElementById('userBody');
    if (userBody) {
      new MutationObserver(injectUserEntry).observe(userBody, { childList: true, subtree: true });
      injectUserEntry();
    }
    routeQuery();
    window.PASS50_MOBILE_BOTTOM_NAV = Object.freeze({ contract: CONTRACT });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
