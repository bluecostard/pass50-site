'use strict';

(() => {
  const CONTRACT = 'PASS50-CLASSABILITY-SYNC-V1.8';
  const METRIC_SOURCE = /(?:^|\b)MR-V1\.\d+(?:\b|$)/i;
  const PUBLISHED_MR_STATUS = 'published_mr_v1';
  const VERIFIED_LINK_STATUSES = new Set(['owner_verified', 'manual_verified', 'ok', 'verified']);
  const MIN_VERIFIED_LINKS = 1;
  const PERIODS = ['2H', '24H', '48H', '7J', '15J'];
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

  function isDirectOfficialUrl(platform, url) {
    try {
      if (typeof p50v9IsDirectPlatformLink === 'function') return p50v9IsDirectPlatformLink(platform, url);
    } catch (_) {}
    const value = String(url || '');
    if (!/^https?:\/\//i.test(value)) return false;
    if (/results\?|search\/top|\/search\?/i.test(value)) return false;
    return true;
  }

  function verifiedOfficialLinkCount(profileItem) {
    const links = profileItem?.links && typeof profileItem.links === 'object' ? profileItem.links : {};
    const checks = profileItem?.linkChecks && typeof profileItem.linkChecks === 'object' ? profileItem.linkChecks : {};
    let count = 0;
    Object.entries(links).forEach(([platform, url]) => {
      if (!isDirectOfficialUrl(platform, url)) return;
      const status = String(checks[platform]?.status || '');
      if (VERIFIED_LINK_STATUSES.has(status)) count += 1;
    });
    return count;
  }

  function selectedPeriod() {
    try {
      if (typeof ui !== 'undefined' && ui && typeof ui.period === 'string' && ui.period) return ui.period;
    } catch (_) {}
    return '24H';
  }

  function periodScoreValue(profileItem, period) {
    const scores = profileItem?.scores;
    if (!scores || typeof scores !== 'object') return 0;
    const value = Number(scores[period]);
    return Number.isFinite(value) ? value : 0;
  }

  function hasSelectedPeriodScore(profileItem, period = selectedPeriod()) {
    try {
      if (typeof hasPeriodScore === 'function' && hasPeriodScore !== hasSelectedPeriodScore) {
        return Boolean(hasPeriodScore(profileItem, period));
      }
    } catch (_) {}
    const scores = profileItem?.scores;
    if (!scores || typeof scores !== 'object') return false;
    if (!Object.prototype.hasOwnProperty.call(scores, period)) return false;
    return periodScoreValue(profileItem, period) > 0;
  }

  function hasAnyPublishedScore(profileItem) {
    const scores = profileItem?.scores;
    if (!scores || typeof scores !== 'object') return false;
    if (PERIODS.some(period => periodScoreValue(profileItem, period) > 0)) return true;
    return Object.values(scores).some(value => Number(value) > 0);
  }

  function restorePublishedScoreClassability(profileItem) {
    if (!profileItem || typeof profileItem !== 'object' || profileItem.alive === false) return false;
    if (!hasAnyPublishedScore(profileItem)) return false;
    let changed = false;
    if (profileItem.eligible !== true) {
      profileItem.eligible = true;
      changed = true;
    }
    if (profileItem.classable !== true) {
      profileItem.classable = true;
      changed = true;
    }
    if (changed) {
      profileItem.classabilitySource = 'published_period_score';
      profileItem.classabilityUpdatedAt = new Date().toISOString();
    }
    return changed;
  }

  function promoteFromVerifiedLinks(profileItem) {
    if (!profileItem || typeof profileItem !== 'object' || profileItem.alive === false) return false;
    // Ne pas écraser une exclusion MR sans score. Un score déjà publié n'est pas un recensement.
    if (metricsControlsClassability(profileItem) && profileItem.classable === false && !hasAnyPublishedScore(profileItem)) return false;
    if (verifiedOfficialLinkCount(profileItem) < MIN_VERIFIED_LINKS) return false;
    let changed = false;
    if (profileItem.eligible !== true) {
      profileItem.eligible = true;
      changed = true;
    }
    if (!metricsControlsClassability(profileItem) && profileItem.classable !== true) {
      profileItem.classable = true;
      changed = true;
    }
    if (changed) {
      profileItem.eligibilitySource = 'verified_official_links';
      profileItem.classabilitySource = 'verified_official_links';
      profileItem.classabilityUpdatedAt = new Date().toISOString();
    }
    return changed;
  }

  function expectedLegacyClassability(profileItem) {
    return Boolean(profileItem?.eligible === true && profileItem?.alive !== false);
  }

  function authoritativeIsClassableProfile(profileItem) {
    if (!profileItem || profileItem.alive === false) return false;
    if (typeof p50IsDeletedProfileId === 'function' && p50IsDeletedProfileId(profileItem.id)) return false;
    // Un score publié pour la période affichée doit apparaître dans le classement,
    // même si un overlay « à recenser » a forcé classable=false.
    if (hasSelectedPeriodScore(profileItem)) return true;
    if (profileItem.eligible !== true) return false;
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

    let changed = restorePublishedScoreClassability(profileItem);
    changed = promoteFromVerifiedLinks(profileItem) || changed;

    const metricsControlled = metricsControlsClassability(profileItem);
    const editorialEligible = Boolean(profileItem.eligible === true && profileItem.alive !== false);

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
      if ((changed || adminSave) && profileItem.classabilitySource !== 'verified_official_links') {
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
      verifiedLinkPromoted: 0,
      verifiedLinkReadyButBlocked: 0,
    };

    profiles().forEach(profileItem => {
      summary.total += 1;
      const verifiedReady = verifiedOfficialLinkCount(profileItem) >= MIN_VERIFIED_LINKS;
      if (profileItem?.eligibilitySource === 'verified_official_links' || profileItem?.classabilitySource === 'verified_official_links') {
        summary.verifiedLinkPromoted += 1;
      }
      const eligible = expectedLegacyClassability(profileItem);
      if (!eligible) {
        summary.ineligible += 1;
        if (verifiedReady) summary.verifiedLinkReadyButBlocked += 1;
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

  function isBootSettling() {
    try {
      return typeof ui !== 'undefined' && ui && ui.bootSettling === true;
    } catch (_) {
      return false;
    }
  }

  function renderOnly({ force = false } = {}) {
    if (isBootSettling() && !force) return;
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

  function schedulePostCloudRepair() {
    const attempt = () => {
      if (isBootSettling()) {
        setTimeout(attempt, 120);
        return;
      }
      repairAll({ forceRender: true });
    };
    setTimeout(attempt, 150);
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
      else if (forceRender) renderOnly({ force: true });
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
    if (changed || item.classabilitySource === 'admin_eligibility' || item.classabilitySource === 'verified_official_links') {
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

  function installLinkSaveBridge() {
    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      if (!target.closest('.save-links, .check-links, #recoverProfileLinks, #recoverAllLinks')) return;
      // Ne pas forceRender ici : cela réaffichait le panneau Liens pendant
      // Enregistrer/Vérifier et annulait la validation en cours.
      const run = () => {
        if (window.PASS50_LINK_SAVE_RUNNING) {
          setTimeout(run, 200);
          return;
        }
        repairAll();
      };
      setTimeout(run, 500);
    });
  }

  function installCloudRepairWindow() {
    let attempts = 0;
    const timer = setInterval(() => {
      attempts += 1;
      repairAll();
      if (attempts >= 80 || (typeof window.__pass50CloudReady !== 'undefined' && window.__pass50CloudReady)) {
        clearInterval(timer);
        // Le classement cloud vient d'être fusionné : attendre la fin du boot
        // avant de réafficher, pour éviter un 3e passage Top 3 instable.
        schedulePostCloudRepair();
      }
    }, 250);
  }

  function init() {
    installAuthoritativeRule();
    installProfileFormBridge();
    installLinkSaveBridge();
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
      verifiedOfficialLinkCount,
      promoteFromVerifiedLinks,
      hasAnyPublishedScore,
      installAuthoritativeRule,
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
