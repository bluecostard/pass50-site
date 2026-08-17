'use strict';
(function () {
  const MQ = '(max-width:680px)';
  const STORAGE_KEY = 'pass50_account_mobile_tab_v2';
  const FOLDERS = [
    { id: 'profil', label: 'Profil', hint: 'Identité' },
    { id: 'listes', label: 'Suivis', hint: 'Favoris' },
    { id: 'alertes', label: 'Alertes', hint: 'Notifs' },
    { id: 'mes-comptes', label: 'Mes comptes', hint: 'OAuth' },
    { id: 'legal', label: 'Légal', hint: '7 pages' },
    { id: 'compte', label: 'Compte', hint: 'Session' },
  ];

  let activeTab = readTab();
  let scheduled = false;
  let tabsNode = null;

  function readTab() {
    try {
      const value = localStorage.getItem(STORAGE_KEY);
      return FOLDERS.some((item) => item.id === value) ? value : 'profil';
    } catch (_) {
      return 'profil';
    }
  }

  function writeTab(id) {
    activeTab = id;
    try {
      localStorage.setItem(STORAGE_KEY, id);
    } catch (_) {}
  }

  function isMobile() {
    return window.matchMedia(MQ).matches;
  }

  function mesComptesPanel() {
    return document.getElementById('p50MesComptesPanel');
  }

  window.p50MesComptesMount = function mountMesComptes(node) {
    if (!node) return null;
    const panel = mesComptesPanel();
    if (panel) {
      panel.appendChild(node);
      return panel;
    }
    const grid = document.querySelector('#userBody .user-grid');
    const anchor = grid?.querySelector('[data-user-fold="legal"], [data-user-fold="account"]');
    if (grid && anchor) grid.insertBefore(node, anchor);
    else if (grid) grid.appendChild(node);
    return grid;
  };

  function injectStyles() {
    if (document.getElementById('p50AccountMobileNavStyles')) return;
    const style = document.createElement('style');
    style.id = 'p50AccountMobileNavStyles';
    style.textContent = `
      #userModal .modal-body{padding:16px 14px 20px}
      @media (max-width:680px){
        #userModal .user-grid.p50-account-mobile-ready{display:grid;gap:16px}
        #userModal .user-section{padding:16px;border-radius:18px}
        #userModal .p50-account-mobile-tabs{display:flex;gap:8px;overflow-x:auto;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity;position:sticky;top:0;z-index:3;padding:4px 2px 12px;margin:0 -2px 4px;background:linear-gradient(180deg,#090c09 86%,rgba(9,12,9,0));scrollbar-width:none}
        #userModal .p50-account-mobile-tabs::-webkit-scrollbar{display:none}
        #userModal .p50-account-mobile-tab{flex:0 0 auto;min-width:92px;scroll-snap-align:start;border:1px solid var(--line);border-radius:14px;background:#101510;color:#c8d0c5;padding:10px 12px;font:inherit;font-size:11px;font-weight:950;line-height:1.2;cursor:pointer;min-height:48px}
        #userModal .p50-account-mobile-tab.is-active{border-color:rgba(183,255,0,.55);color:var(--lime);background:rgba(183,255,0,.08);box-shadow:inset 0 0 0 1px rgba(183,255,0,.12)}
        #userModal .p50-account-mobile-tab small{display:block;margin-top:4px;font-size:9px;font-weight:800;color:var(--muted)}
        #userModal .user-grid.p50-account-mobile-ready .user-section[data-p50-account-hidden="1"]{display:none!important}
        #userModal .p50-account-mobile-empty{border:1px dashed var(--line);border-radius:16px;padding:22px 16px;color:var(--muted);font-size:12px;line-height:1.5;text-align:center}
        #userModal .p50-mes-comptes-panel{gap:12px}
      }
      @media (min-width:681px){
        #userModal .p50-account-mobile-tabs,#userModal .p50-account-mobile-empty{display:none!important}
      }
    `;
    document.head.appendChild(style);
  }

  function classifySection(section) {
    if (section.dataset.p50AccountFolder) return section.dataset.p50AccountFolder;
    if (section.matches('[data-user-fold="profile"]')) return 'profil';
    if (section.matches('[data-user-fold="favorites"],[data-user-fold="following"],#p50SeparateFeedEntry,#p50FollowFeedEntry')) return 'listes';
    if (section.matches('[data-user-fold="notifications"]')) return 'alertes';
    if (section.matches('#p50MesComptesHub,[data-user-fold="mes-comptes"],#p50YoutubeOauthSection,#p50MetaOauthSection,#p50TiktokOauthSection,.p50-connector-section')) return 'mes-comptes';
    if (section.matches('[data-user-fold="legal"]')) return 'legal';
    if (section.matches('[data-user-fold="account"]')) return 'compte';
    const text = String(section.textContent || '').toLowerCase();
    if (text.includes('youtube') || text.includes('meta') || text.includes('tiktok') || text.includes('connecter ma')) return 'mes-comptes';
    return 'profil';
  }

  function collectSections(grid) {
    const top = [...grid.querySelectorAll(':scope > .user-section, :scope > .p50-connector-section')];
    const nested = [...(mesComptesPanel()?.querySelectorAll('.user-section, .p50-connector-section') || [])];
    return [...top, ...nested.filter((node) => !top.includes(node))];
  }

  function ensureTabs(grid) {
    if (tabsNode && grid.contains(tabsNode)) return tabsNode;
    tabsNode = document.createElement('div');
    tabsNode.className = 'p50-account-mobile-tabs';
    tabsNode.setAttribute('role', 'tablist');
    tabsNode.setAttribute('aria-label', 'Sections Mon espace');
    tabsNode.innerHTML = FOLDERS.map((folder) => `<button type="button" class="p50-account-mobile-tab" role="tab" data-p50-account-tab="${folder.id}" aria-selected="false"><span>${folder.label}</span><small>${folder.hint}</small></button>`).join('');
    tabsNode.addEventListener('click', (event) => {
      const tab = event.target.closest('[data-p50-account-tab]');
      if (!tab) return;
      event.preventDefault();
      writeTab(tab.dataset.p50AccountTab || 'profil');
      applyView(grid);
    });
    grid.insertBefore(tabsNode, grid.firstChild);
    return tabsNode;
  }

  function ensureEmptyState(grid) {
    let empty = grid.querySelector('.p50-account-mobile-empty');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'p50-account-mobile-empty';
      empty.textContent = 'Aucun contenu dans cette section pour le moment.';
      empty.hidden = true;
      grid.appendChild(empty);
    }
    return empty;
  }

  function applyView(grid) {
    injectStyles();
    const sections = collectSections(grid);
    if (!sections.length) return;

    if (!isMobile()) {
      grid.classList.remove('p50-account-mobile-ready');
      tabsNode?.remove();
      tabsNode = null;
      grid.querySelector('.p50-account-mobile-empty')?.remove();
      sections.forEach((section) => section.removeAttribute('data-p50-account-hidden'));
      return;
    }

    ensureTabs(grid);
    grid.classList.add('p50-account-mobile-ready');
    sections.forEach((section) => {
      section.dataset.p50AccountFolder = classifySection(section);
    });

    let visible = 0;
    sections.forEach((section) => {
      const show = section.dataset.p50AccountFolder === activeTab;
      section.toggleAttribute('data-p50-account-hidden', !show);
      if (show) visible += 1;
    });

    if (tabsNode) {
      tabsNode.querySelectorAll('[data-p50-account-tab]').forEach((tab) => {
        const active = tab.dataset.p50AccountTab === activeTab;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });
    }

    const empty = ensureEmptyState(grid);
    empty.hidden = visible > 0;
  }

  function reorganize() {
    scheduled = false;
    const body = document.getElementById('userBody');
    const grid = body?.querySelector('.user-grid');
    if (!grid || !body.innerHTML.trim()) return;
    applyView(grid);
  }

  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(reorganize);
  }

  function install() {
    injectStyles();
    window.addEventListener('resize', schedule, { passive: true });
    const body = document.getElementById('userBody');
    if (body) new MutationObserver(schedule).observe(body, { childList: true, subtree: true });
    const modal = document.getElementById('userModal');
    if (modal) {
      new MutationObserver(() => {
        if (modal.classList.contains('show')) schedule();
      }).observe(modal, { attributes: true, attributeFilter: ['class'] });
    }
    schedule();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
  else install();
}());
