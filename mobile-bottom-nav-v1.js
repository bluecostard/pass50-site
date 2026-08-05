'use strict';

(() => {
  const CONTRACT = 'PASS50-MOBILE-BOTTOM-NAV-V1.3';
  const LEGACY_CONTEXT_SHARE_ASSET = './context-share-v1.js?v=1.0';
  const path = location.pathname || '';
  const isFeed = /(?:^|\/)mon-fil\.html$/i.test(path);
  const isProno = /(?:^|\/)pronostics\.html$/i.test(path);
  const isHome = !isFeed && !isProno;

  function injectStyles() {
    if (document.getElementById('p50BottomNavStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50BottomNavStyles';
    style.textContent = `
      .p50-bottom-nav{display:none}
      @media(max-width:680px){
        body{padding-bottom:calc(110px + env(safe-area-inset-bottom))!important}
        body:not([data-pass50-page="feed"]) .app{padding-bottom:calc(118px + env(safe-area-inset-bottom))!important}
        body:not([data-pass50-page="feed"]) header>nav{display:none!important}
        .p50-bottom-nav{
          position:fixed;left:50%;right:auto;bottom:calc(10px + env(safe-area-inset-bottom));z-index:120;
          display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:end;
          width:min(400px,calc(100vw - 20px));min-height:70px;padding:8px 8px;
          border:1px solid rgba(183,255,0,.22);border-radius:24px;
          background:linear-gradient(180deg,rgba(16,21,16,.97),rgba(6,9,6,.98));
          -webkit-backdrop-filter:blur(18px);backdrop-filter:blur(18px);
          box-shadow:0 14px 42px rgba(0,0,0,.52),inset 0 1px 0 rgba(255,255,255,.035);
          transform:translateX(-50%);
        }
        .p50-bottom-link{
          min-width:0;min-height:52px;border:0;background:transparent;color:#98a295;
          display:grid;align-content:center;justify-items:center;gap:4px;padding:6px 2px;border-radius:16px;
          font-size:8.5px;font-weight:950;letter-spacing:.04px;line-height:1.1;text-decoration:none;
          transition:color .16s ease,background .16s ease,transform .16s ease,box-shadow .16s ease;
          -webkit-tap-highlight-color:transparent;
        }
        .p50-bottom-icon{display:grid;place-items:center;width:29px;height:27px}
        .p50-bottom-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
        .p50-bottom-link.active:not(.p50-bottom-link-ranking){color:var(--lime,#b7ff00);background:rgba(183,255,0,.075)}
        .p50-bottom-link-ranking{
          position:relative;z-index:2;min-height:78px;margin-top:-18px;padding:7px 6px 6px;
          border:1px solid rgba(183,255,0,.38);border-radius:21px;
          background:linear-gradient(180deg,#151d13,#090d09);
          color:#dce5d8;box-shadow:0 12px 28px rgba(0,0,0,.5),0 0 20px rgba(183,255,0,.1);
          transform:translateY(-3px);
        }
        .p50-bottom-link-ranking .p50-bottom-icon{
          width:44px;height:44px;border:1px solid rgba(183,255,0,.36);border-radius:15px;
          background:rgba(183,255,0,.08);color:var(--lime,#b7ff00);
        }
        .p50-bottom-link-ranking .p50-bottom-icon svg{width:25px;height:25px;stroke-width:2}
        .p50-bottom-link-ranking.active{
          color:#050705;border-color:var(--lime,#b7ff00);
          background:linear-gradient(145deg,var(--lime,#b7ff00),#7ee500);
          box-shadow:0 14px 31px rgba(0,0,0,.5),0 0 28px rgba(183,255,0,.3);
        }
        .p50-bottom-link-ranking.active .p50-bottom-icon{border-color:rgba(5,7,5,.2);background:rgba(5,7,5,.12);color:#050705}
        .p50-bottom-link:active{transform:scale(.96)}
        .p50-bottom-link-ranking:active{transform:translateY(-3px) scale(.96)}
        .p50-bottom-link:focus-visible{outline:2px solid var(--lime,#b7ff00);outline-offset:2px}
      }
      @media(max-width:360px){
        .p50-bottom-nav{width:calc(100vw - 14px);padding-inline:5px}
        .p50-bottom-link{font-size:8px}
      }
    `;
    document.head.appendChild(style);
  }

  function feedIcon() {
    return `<span class="p50-bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="2.5"></rect><path d="M8 9h8M8 13h5M8 16h7"></path><path d="M8 5V3.5M16 5V3.5"></path></svg></span>`;
  }

  function pronoIcon() {
    return `<span class="p50-bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3l2.2 4.6L19 9l-3.4 3.2.9 4.8L12 15.2 7.5 17l.9-4.8L5 9l4.8-1.4L12 3Z"></path><path d="M7 20h10"></path></svg></span>`;
  }

  function rankingIcon() {
    return `<span class="p50-bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 4h8v3c0 3-1.8 5-4 5S8 10 8 7V4Z"></path><path d="M8 6H5c0 2.8 1.2 4 3.5 4M16 6h3c0 2.8-1.2 4-3.5 4M12 12v4M9 20h6M10 16h4"></path></svg></span>`;
  }

  function accountIcon() {
    return `<span class="p50-bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.25"></circle><path d="M5.5 19c.8-3.6 3.1-5.3 6.5-5.3s5.7 1.7 6.5 5.3"></path></svg></span>`;
  }

  function navHtml() {
    return `<nav class="p50-bottom-nav" aria-label="Menu principal mobile" data-contract="${CONTRACT}">
      <a class="p50-bottom-link ${isFeed ? 'active' : ''}" href="./mon-fil.html" data-p50-tab="feed" ${isFeed ? 'aria-current="page"' : ''}>${feedIcon()}<span>Mon fil</span></a>
      <a class="p50-bottom-link ${isProno ? 'active' : ''}" href="./pronostics.html" data-p50-tab="prono" ${isProno ? 'aria-current="page"' : ''}>${pronoIcon()}<span>Pronos</span></a>
      <a class="p50-bottom-link p50-bottom-link-ranking ${isHome ? 'active' : ''}" href="./" data-p50-tab="ranking" ${isHome ? 'aria-current="page"' : ''}>${rankingIcon()}<span>Classement</span></a>
      <a class="p50-bottom-link" href="./?open=account" data-p50-tab="account">${accountIcon()}<span>Mon espace</span></a>
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
    grid.querySelector('#p50PronoEntry')?.remove();
    if (grid.querySelector('#p50SeparateFeedEntry')) return;
    const section = document.createElement('section');
    section.className = 'user-section full';
    section.id = 'p50SeparateFeedEntry';
    section.innerHTML = `<div class="user-title"><span>≋ Mon fil</span><span>Page séparée</span></div><div class="pref"><div><strong>L’actualité de mes influenceurs suivis</strong><div class="muted">Une page limitée à vos suivis, distincte du classement et sans défilement infini.</div></div><a class="btn primary" href="./mon-fil.html">Ouvrir mon fil</a></div>`;
    grid.prepend(section);
  }

  function loadContextShare() {
    void LEGACY_CONTEXT_SHARE_ASSET;
    if (window.PASS50_CONTEXT_SHARE_V2 || document.querySelector('script[data-pass50-context-share-v2]')) return;
    const script = document.createElement('script');
    script.src = './context-share-v2.js?v=2.3';
    script.async = false;
    script.dataset.pass50ContextShare = '2.0';
    script.dataset.pass50ContextShareV2 = '2.0';
    document.head.appendChild(script);
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
    if (isFeed || isProno) return;
    const params = new URLSearchParams(location.search);
    const action = params.get('open');
    const profileId = params.get('profile');
    if (action === 'account') callWhenReady('currentUser', current => {
      if (current()) callWhenReady('openUser', fn => fn());
      else callWhenReady('openAuth', fn => fn('login'));
    });
    if (profileId) callWhenReady('openProfile', fn => fn(profileId));
  }

  function installEvents() {
    document.addEventListener('click', event => {
      const link = event.target.closest('.p50-bottom-link');
      if (!link || isFeed || isProno || link.dataset.p50Tab !== 'account') return;
      event.preventDefault();
      if (typeof window.currentUser === 'function' && window.currentUser()) {
        if (typeof window.openUser === 'function') window.openUser();
      } else if (typeof window.openAuth === 'function') window.openAuth('login');
    });
  }

  function init() {
    injectStyles();
    injectNav();
    loadContextShare();
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
