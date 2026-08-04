'use strict';

(() => {
  const CONTRACT = 'PASS50-CLASSABILITY-SYNC-V1.2';
  const METRIC_SOURCE = /(?:^|\b)MR-V1\.0(?:\b|$)/i;
  const PUBLISHED_MR_STATUS = 'published_mr_v1';
  let syncing = false;

  function profiles() {
    try {
      return typeof db !== 'undefined' && Array.isArray(db?.profiles) ? db.profiles : [];
    } catch (_) {
      return [];
    }
  }

  function metricsControlsClassability(profileItem) {
    const engine = profileItem?.dataEngine || {};
    return METRIC_SOURCE.test(String(engine.algorithmVersion || ''))
      && String(engine.scoreStatus || '') === PUBLISHED_MR_STATUS;
  }

  function expectedLegacyClassability(profileItem) {
    return Boolean(profileItem?.eligible === true && profileItem?.alive !== false);
  }

  function authoritativeIsClassableProfile(profileItem) {
    if (!profileItem || profileItem.alive === false || profileItem.eligible !== true) return false;
    if (metricsControlsClassability(profileItem)) return profileItem.classable !== false;
    // Tant qu'aucune publication MR-V1.0 n'a réellement été appliquée à cette
    // fiche, la décision administrative Vivant + Éligible reste autoritaire.
    return true;
  }

  function installAuthoritativeRule() {
    try {
      // Affectation directe du binding global utilisé par ranking(),
      // completeRanking() et openTop50().
      if (typeof isClassableProfile === 'function') {
        isClassableProfile = authoritativeIsClassableProfile;
      }
      window.isClassableProfile = authoritativeIsClassableProfile;
    } catch (error) {
      console.warn('PASS50 classability rule', error);
    }
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

    // Seule une publication MR-V1.0 réellement appliquée garde sa propre
    // décision technique. Les marqueurs historiques ou expérimentaux sont ignorés.
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

  function diagnose() {
    const summary = {
      total: 0,
      eligible: 0,
      classable: 0,
      ineligible: 0,
      publishedMrClassable: 0,
      publishedMrExcluded: 0,
      legacyEligibleRepaired: 0,
    };

    profiles().forEach(profileItem => {
      summary.total += 1;
      const eligible = expectedLegacyClassability(profileItem);
      if (!eligible) {
        summary.ineligible += 1;
        return;
      }
      summary.eligible += 1;
      if (metricsControlsClassability(profileItem)) {
        if (profileItem.classable === false) summary.publishedMrExcluded += 1;
        else summary.publishedMrClassable += 1;
      } else if (profileItem.classable === true) {
        summary.legacyEligibleRepaired += 1;
      }
      if (authoritativeIsClassableProfile(profileItem)) summary.classable += 1;
    });

    return summary;
  }

  function renderOnly() {
    try {
      if (typeof render === 'function') render();
    } catch (error) {
      console.warn('PASS50 classability render', error);
    }
  }

  function persistAndRender() {
    try {
      if (typeof save === 'function') save();
    } catch (error) {
      console.warn('PASS50 classability save', error);
    }
    renderOnly();
  }

  function repairAll({ forceRender = false } = {}) {
    if (syncing) return 0;
    syncing = true;
    try {
      installAuthoritativeRule();
      let repaired = 0;
      profiles().forEach(profileItem => {
        if (repairProfile(profileItem)) repaired += 1;
      });
      if (repaired > 0) persistAndRender();
      else if (forceRender) renderOnly();
      window.PASS50_CLASSABILITY_DIAGNOSTIC = Object.freeze(diagnose());
      return repaired;
    } finally {
      syncing = false;
    }
  }

  function repairSavedProfile(profileId) {
    installAuthoritativeRule();
    const item = profiles().find(profileItem => String(profileItem?.id) === String(profileId));
    if (!item) return false;
    const changed = repairProfile(item, { adminSave: true });
    // La source et la date doivent être sauvegardées même si le booléen était
    // déjà cohérent : cela distingue désormais la validation administrative.
    if (changed || item.classabilitySource === 'admin_eligibility') {
      persistAndRender();
      window.PASS50_CLASSABILITY_DIAGNOSTIC = Object.freeze(diagnose());
      return true;
    }
    renderOnly();
    window.PASS50_CLASSABILITY_DIAGNOSTIC = Object.freeze(diagnose());
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
        // Le classement cloud vient d'être fusionné : réinstaller la règle puis
        // réparer et réafficher la liste sur l'état final, pas sur l'état local initial.
        setTimeout(() => repairAll({ forceRender: true }), 150);
      }
    }, 250);
  }

  function init() {
    installAuthoritativeRule();
    installProfileFormBridge();
    repairAll({ forceRender: true });
    installCloudRepairWindow();
    window.addEventListener('storage', () => setTimeout(() => repairAll({ forceRender: true }), 0));
    window.PASS50_CLASSABILITY_SYNC = Object.freeze({
      contract: CONTRACT,
      repairAll,
      repairProfile,
      diagnose,
      metricsControlsClassability,
      authoritativeIsClassableProfile,
      installAuthoritativeRule,
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();