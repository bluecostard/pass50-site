'use strict';

(() => {
  const CONTRACT = 'PASS50-CLASSABILITY-SYNC-V1.0';
  const METRIC_SOURCE = /(?:^|\b)MR-V1\.0(?:\b|$)/i;
  let syncing = false;

  function profiles() {
    try {
      return typeof db !== 'undefined' && Array.isArray(db?.profiles) ? db.profiles : [];
    } catch (_) {
      return [];
    }
  }

  function metricsControlsClassability(profileItem) {
    const sources = [
      profileItem?.classabilitySource,
      profileItem?.publicRankingSource,
      profileItem?.rankingAlgorithmVersion,
      profileItem?.algorithmVersion,
      profileItem?.rankingPublication?.algorithmVersion,
    ];
    return sources.some(value => METRIC_SOURCE.test(String(value || '')));
  }

  function expectedLegacyClassability(profileItem) {
    return Boolean(profileItem?.eligible === true && profileItem?.alive !== false);
  }

  function repairProfile(profileItem, { adminSave = false } = {}) {
    if (!profileItem || typeof profileItem !== 'object') return false;

    const metricsControlled = metricsControlsClassability(profileItem);
    const editorialEligible = Boolean(profileItem.eligible === true && profileItem.alive !== false);
    let changed = false;

    if (profileItem.editorialEligible !== editorialEligible) {
      profileItem.editorialEligible = editorialEligible;
      changed = true;
    }

    // MR-V1.0 garde sa propre décision technique. Cette réparation ne concerne
    // que l'ancien classement public piloté manuellement par la case Éligible.
    if (!metricsControlled) {
      const expected = expectedLegacyClassability(profileItem);
      if (profileItem.classable !== expected) {
        profileItem.classable = expected;
        changed = true;
      }
      if (changed || adminSave) {
        profileItem.classabilitySource = 'admin_eligibility';
        profileItem.classabilityUpdatedAt = new Date().toISOString();
      }
    }

    return changed;
  }

  function persistAndRender() {
    try {
      if (typeof save === 'function') save();
    } catch (error) {
      console.warn('PASS50 classability save', error);
    }
    try {
      if (typeof render === 'function') render();
    } catch (error) {
      console.warn('PASS50 classability render', error);
    }
  }

  function repairAll() {
    if (syncing) return 0;
    syncing = true;
    try {
      let repaired = 0;
      profiles().forEach(profileItem => {
        if (repairProfile(profileItem)) repaired += 1;
      });
      if (repaired > 0) persistAndRender();
      return repaired;
    } finally {
      syncing = false;
    }
  }

  function repairSavedProfile(profileId) {
    const item = profiles().find(profileItem => String(profileItem?.id) === String(profileId));
    if (!item) return false;
    const changed = repairProfile(item, { adminSave: true });
    // La source et la date doivent être sauvegardées même si le booléen était
    // déjà cohérent : cela distingue désormais la validation administrative.
    if (changed || item.classabilitySource === 'admin_eligibility') {
      persistAndRender();
      return true;
    }
    return false;
  }

  function installProfileFormBridge() {
    document.addEventListener('submit', event => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || form.id !== 'profileForm') return;
      const profileId = form.dataset.id || '';
      setTimeout(() => repairSavedProfile(profileId), 0);
    });
  }

  function installCloudRepairWindow() {
    let attempts = 0;
    const timer = setInterval(() => {
      attempts += 1;
      repairAll();
      if (attempts >= 80 || (typeof window.__pass50CloudReady !== 'undefined' && window.__pass50CloudReady)) {
        clearInterval(timer);
        // La fusion cloud peut se terminer dans la même boucle d'événement.
        setTimeout(repairAll, 150);
      }
    }, 250);
  }

  function init() {
    installProfileFormBridge();
    repairAll();
    installCloudRepairWindow();
    window.addEventListener('storage', () => setTimeout(repairAll, 0));
    window.PASS50_CLASSABILITY_SYNC = Object.freeze({
      contract: CONTRACT,
      repairAll,
      repairProfile,
      metricsControlsClassability,
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
