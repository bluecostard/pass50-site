/* PASS50 V26 — correction réelle du recensement 127 -> 133 */
(function () {
  'use strict';

  const RELEASE = '26.1';
  const EXPECTED_IDS = [
    'census-african-ryou',
    'census-samuella-kouassi',
    'census-nadiani',
    'census-investisseur-africain',
    'census-laura-ziehi',
    'census-aya-robert'
  ];
  const CANDIDATES = [{"id":"census-african-ryou","name":"African Ryou","known_alias":"@african_ryou","entity_type":"Personne","zone":"CI","category":"Humour / Culture ivoirienne / Lifestyle / Fitness","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","eligible":false,"classable":false,"platforms_detected":["Instagram","TikTok","YouTube","Snapchat"],"official_socials":{},"source":{"publisher":"Brut Afrique","date":"2025-10-13","url":"https://www.brut.media/afrique/videos/afrique/societe/lecon-de-nouchi-avec-ryou"},"additional_sources":[{"publisher":"AfrokanLife","date":"2025-04-25","url":"https://www.afrokanlife.com/african-ryou-influenceur-authentique-ivoirien-coreen-reseaux-sociaux/"}],"notes":"Ajout approuvé par le propriétaire PASS50. Les comptes repérés restent à confirmer manuellement avant affichage public.","priority_wave":"V26 — intégration immédiate","research_queries":["\"African Ryou\" créateur de contenu Côte d'Ivoire","\"@african_ryou\" Instagram","\"@african_ryou_officiel\" TikTok"]},{"id":"census-samuella-kouassi","name":"Samuella Kouassi","known_alias":"@samuellakouassiofficiel","entity_type":"Personne","zone":"CI","category":"Lifestyle / Mode / Divertissement","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","eligible":false,"classable":false,"platforms_detected":["Instagram","TikTok","YouTube","Facebook"],"official_socials":{},"source":{"publisher":"SEN Influenceurs","date":"2026-07-24","url":"https://seninfluenceurs.com/service/view/188"},"additional_sources":[{"publisher":"Hafi","date":"2026-07-18","url":"https://hafi.pro/top/most-followed-instagram/ivory-coast"}],"notes":"Ajout approuvé par le propriétaire PASS50. Aucun score ni compte officiel n'est publié automatiquement.","priority_wave":"V26 — intégration immédiate","research_queries":["\"Samuella Kouassi\" influenceuse ivoirienne","\"@samuellakouassiofficiel\" Instagram TikTok"]},{"id":"census-nadiani","name":"Nadiani","known_alias":"Imane Nadiani Touré / @officialnad_","entity_type":"Personne","zone":"BOTH","category":"Mode / Beauté / Lifestyle / Entrepreneuriat","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","eligible":false,"classable":false,"platforms_detected":["Instagram","TikTok","YouTube"],"official_socials":{},"source":{"publisher":"Portfolio public Nadiani","date":"2026-07-24","url":"https://nadiani.my.canva.site/"},"additional_sources":[{"publisher":"Social Blade","date":"2026-07-20","url":"https://socialblade.com/instagram/user/officialnad_"}],"notes":"Nom public : Nadiani. Imane Nadiani Touré est conservé comme alias. Réseaux à confirmer dans Administration.","priority_wave":"V26 — intégration immédiate","research_queries":["\"Nadiani\" \"officialnad_\"","\"Imane Nadiani Touré\" créatrice de contenu"]},{"id":"census-investisseur-africain","name":"L’Investisseur Africain","known_alias":"Jean-Yves Bragbo / Jean Yves Bragbo / @sbragbo","entity_type":"Personne","zone":"BOTH","category":"Business / Investissement / Entrepreneuriat / Diaspora","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","eligible":false,"classable":false,"platforms_detected":["YouTube","Facebook","LinkedIn","Instagram"],"official_socials":{},"source":{"publisher":"L’Investisseur Africain — site public","date":"2026-07-24","url":"https://www.investisseur-africain.com/cgv"},"additional_sources":[{"publisher":"KALIMANJARO — Le Podcast des ambitieux","date":"2024-03-08","url":"https://podcasts.apple.com/us/podcast/227-jean-yves-bragbo-linvestisseur-africain-lever-3/id1532619060?i=1000648578388"}],"notes":"Nom public : L’Investisseur Africain. Jean-Yves Bragbo est conservé comme identité/alias.","priority_wave":"V26 — intégration immédiate","research_queries":["\"Jean Yves Bragbo\" \"L’Investisseur Africain\"","\"L’Investisseur Africain\" YouTube"]},{"id":"census-laura-ziehi","name":"Laura Ziehi","known_alias":"@laura.ziehi","entity_type":"Personne","zone":"BOTH","category":"Lifestyle / Mode / Divertissement","census_status":"Recensé confirmé — réseaux à compléter","verification_priority":"P1","eligible":false,"classable":false,"platforms_detected":["Instagram","TikTok","Snapchat"],"official_socials":{},"source":{"publisher":"Pannelle Talents","date":"2026-07-24","url":"https://pannelle.com/reseaupannelle/talents/laura-ziehi/"},"additional_sources":[{"publisher":"Pulse Côte d’Ivoire","date":"2025-08-27","url":"https://www.pulse.ci/article/laura-ziehi-repond-a-sindika-si-je-ne-dis-rien-de-vous-cest-parce-que-je-ne-suis-pas-comme-ca-2025082709190794271"}],"notes":"Ajout approuvé au statut « à recenser ». Aucun réseau n'est publié avant revalidation manuelle du compte exact.","priority_wave":"V26 — à recenser","research_queries":["\"Laura Ziehi\" influenceuse ivoirienne","\"@laura.ziehi\" Instagram"]},{"id":"census-aya-robert","name":"Aya Robert","known_alias":"Aya Robert","entity_type":"Personne","zone":"CI","category":"TikTok / Lives / Débats / Divertissement","census_status":"À vérifier — compte officiel actuel","verification_priority":"P1","eligible":false,"classable":false,"platforms_detected":["TikTok","Facebook"],"official_socials":{},"source":{"publisher":"Digital Mag Côte d’Ivoire","date":"2024-09-20","url":"https://digitalmag.ci/reseaux-sociaux-portrait-robot-de-tiktok-en-cote-divoire/"},"additional_sources":[{"publisher":"AfrikMag","date":"2021-06-08","url":"https://www.afrikmag.com/tina-glamour-attaque-aya-robert-espece-de-salete-que-tu-sois/"}],"notes":"Ajout approuvé au statut « à recenser ». Aucun compte officiel ni score n'est publié à ce stade.","priority_wave":"V26 — à recenser","research_queries":["\"Aya Robert\" influenceuse ivoirienne TikTok","\"Aya Robert\" compte TikTok officiel"]}];
  let cloudPersistInProgress = false;
  let cloudPersisted = false;

  function normalize(value = '') {
    return String(value)
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[’'`´]/g, '')
      .replace(/[^a-z0-9]+/g, '')
      .trim();
  }

  function aliasParts(value = '') {
    return String(value).split(/[\/·,;|]/).map(normalize).filter(Boolean);
  }

  function handleFor(candidate) {
    const match = String(candidate.known_alias || '').match(/@[A-Za-z0-9._-]+/);
    if (match) return match[0];
    return '@' + (normalize(candidate.name).slice(0, 28) || 'profil');
  }

  function initialsFor(name = '') {
    const words = String(name).replace(/[’']/g, ' ').split(/\s+/).filter(Boolean);
    return (words.slice(0, 2).map(word => word[0]).join('') || 'P').toUpperCase();
  }

  function findExisting(candidate) {
    const wantedId = String(candidate.id || '').toLowerCase();
    const wantedName = normalize(candidate.name);
    const wantedHandle = normalize(handleFor(candidate));
    const wantedAliases = aliasParts(candidate.known_alias);

    return (db.profiles || []).find(item => {
      const itemId = String(item.id || '').toLowerCase();
      const itemName = normalize(item.name);
      const itemHandle = normalize(item.handle);
      const itemAliases = aliasParts(item.knownAlias || item.known_alias || '');
      return (wantedId && itemId === wantedId)
        || (wantedName && itemName === wantedName)
        || (wantedHandle && itemHandle === wantedHandle)
        || wantedAliases.includes(itemName)
        || wantedAliases.includes(itemHandle)
        || itemAliases.includes(wantedName)
        || itemAliases.some(alias => wantedAliases.includes(alias));
    }) || null;
  }

  function buildProfile(candidate) {
    const detected = Array.isArray(candidate.platforms_detected)
      ? candidate.platforms_detected.filter(Boolean)
      : [];
    const activePeriods = Array.isArray(periods) && periods.length
      ? periods
      : ['2H', '24H', '48H', '7J', '15J'];

    return {
      id: String(candidate.id),
      name: String(candidate.name),
      handle: handleFor(candidate),
      initials: initialsFor(candidate.name),
      region: ['CI', 'DIASPORA', 'BOTH'].includes(candidate.zone) ? candidate.zone : 'BOTH',
      category: String(candidate.category || 'À catégoriser'),
      platforms: detected,
      scores: Object.fromEntries(activePeriods.map(period => [period, 0])),
      delta: 0,
      decline: 0,
      alive: true,
      eligible: false,
      classable: false,
      censusStatus: String(candidate.census_status || 'À vérifier'),
      verificationPriority: String(candidate.verification_priority || 'P1'),
      entityType: String(candidate.entity_type || 'Personne'),
      knownAlias: String(candidate.known_alias || ''),
      censusSource: candidate.source || {},
      additionalSources: Array.isArray(candidate.additional_sources) ? candidate.additional_sources : [],
      censusNotes: String(candidate.notes || ''),
      priorityWave: String(candidate.priority_wave || 'V26'),
      researchQueries: Array.isArray(candidate.research_queries) ? candidate.research_queries : [],
      detectedPlatforms: detected,
      censusImportedAt: new Date().toISOString(),
      ageStatus: 'unconfirmed',
      birthDate: null,
      birthYear: null,
      agePublic: true,
      photoUrl: '',
      photoCandidateUrl: '',
      photoStatus: 'missing',
      photoSource: '',
      photoNote: 'Profil recensé : identité visuelle à vérifier.',
      photoPosition: '50% 50%',
      badges: [],
      links: {},
      linkChecks: Object.fromEntries(detected.map(platform => [
        platform,
        {
          status: 'search_not_official',
          checkedAt: null,
          message: 'Compte repéré, validation manuelle obligatoire avant publication.'
        }
      ]))
    };
  }

  function enrichExisting(current, candidate) {
    current.knownAlias = current.knownAlias || String(candidate.known_alias || '');
    current.censusStatus = String(candidate.census_status || current.censusStatus || 'Recensé confirmé');
    current.verificationPriority = String(candidate.verification_priority || current.verificationPriority || 'P1');
    current.entityType = current.entityType || String(candidate.entity_type || 'Personne');
    current.censusSource = candidate.source || current.censusSource || {};
    current.additionalSources = [
      ...(Array.isArray(current.additionalSources) ? current.additionalSources : []),
      ...(Array.isArray(candidate.additional_sources) ? candidate.additional_sources : [])
    ];
    current.censusNotes = String(candidate.notes || current.censusNotes || '');
    current.priorityWave = String(candidate.priority_wave || current.priorityWave || 'V26');
    current.researchQueries = [
      ...new Set([
        ...(Array.isArray(current.researchQueries) ? current.researchQueries : []),
        ...(Array.isArray(candidate.research_queries) ? candidate.research_queries : [])
      ])
    ];
    current.detectedPlatforms = [
      ...new Set([
        ...(Array.isArray(current.detectedPlatforms) ? current.detectedPlatforms : []),
        ...(Array.isArray(candidate.platforms_detected) ? candidate.platforms_detected : [])
      ])
    ];
    if (current.eligible !== true) current.eligible = false;
    if (current.classable !== true) current.classable = false;
    current.alive = current.alive !== false;
    current.links = current.links || {};
    current.linkChecks = current.linkChecks || {};
    return current;
  }

  function presentCount() {
    if (typeof db === 'undefined' || !db || !Array.isArray(db.profiles)) return 0;
    return EXPECTED_IDS.filter(id => db.profiles.some(item => String(item.id) === id)).length;
  }

  function mergeSix() {
    if (typeof db === 'undefined' || !db) return null;
    db.profiles = Array.isArray(db.profiles) ? db.profiles : [];
    let added = 0;
    let updated = 0;

    for (const candidate of CANDIDATES) {
      const current = findExisting(candidate);
      if (current) {
        enrichExisting(current, candidate);
        updated++;
      } else {
        db.profiles.push(buildProfile(candidate));
        added++;
      }
    }

    db.censusVersion = '96-v26';
    db.censusImportedAt = new Date().toISOString();
    db.v26ProfileImport = {
      release: RELEASE,
      present: presentCount(),
      expected: EXPECTED_IDS.length,
      importedAt: new Date().toISOString()
    };
    db.version = Math.max(Number(db.version || 0), 26);
    return { added, updated, total: db.profiles.length, present: presentCount() };
  }

  function refreshUi() {
    if (typeof save === 'function') save();
    else if (typeof APP_KEY !== 'undefined') {
      try { localStorage.setItem(APP_KEY, JSON.stringify(db)); } catch {}
    }
    if (typeof render === 'function') render();
    if (document.querySelector('#adminModal.show') && typeof renderAdminPane === 'function') {
      renderAdminPane();
    }
  }

  async function persistOwnerState() {
    if (cloudPersistInProgress || cloudPersisted) return false;
    if (!window.__pass50CloudReady) return false;
    if (typeof currentUser !== 'function' || typeof syncCloudState !== 'function') return false;

    const user = currentUser();
    if (!user || !['owner', 'admin'].includes(user.role)) return false;

    cloudPersistInProgress = true;
    try {
      await syncCloudState();
      if (typeof apiFetch === 'function') {
        try {
          await apiFetch('data-hub.php', {
            method: 'POST',
            body: { action: 'sync' }
          });
        } catch (registryError) {
          console.warn('[PASS50 V26] Registre Data Hub à resynchroniser manuellement', registryError);
        }
      }
      cloudPersisted = true;
      db.v26CloudPersistedAt = new Date().toISOString();
      try { localStorage.setItem(APP_KEY, JSON.stringify(db)); } catch {}
      console.info('[PASS50 V26] État MySQL mis à jour', {
        total: db.profiles.length,
        present: presentCount()
      });
      return true;
    } catch (error) {
      console.warn('[PASS50 V26] Sauvegarde MySQL différée', error);
      return false;
    } finally {
      cloudPersistInProgress = false;
    }
  }

  async function importSix(showMessage = false) {
    const result = mergeSix();
    if (!result) return null;
    refreshUi();

    if (showMessage && typeof toast === 'function') {
      const suffix = result.present === 6 ? '6/6 présents' : `${result.present}/6 présents`;
      toast(`${result.total} profils recensés · ${suffix}`);
    }

    await persistOwnerState();
    console.info('[PASS50 V26] Import des six profils', result);
    return result;
  }

  window.p50ImportSixV26 = importSix;
  window.PASS50_V26_CANDIDATES = CANDIDATES;

  importSix(false);
  setTimeout(() => importSix(false), 1200);
  setTimeout(() => importSix(false), 4500);

  const cloudTimer = setInterval(() => {
    if (window.__pass50CloudReady) {
      importSix(true);
      if (cloudPersisted) clearInterval(cloudTimer);
    }
  }, 1000);
  setTimeout(() => clearInterval(cloudTimer), 5 * 60 * 1000);

  if (typeof renderAdminPane === 'function') {
    const previousRenderAdminPaneV26 = renderAdminPane;
    renderAdminPane = function () {
      previousRenderAdminPaneV26();
      if (typeof ui === 'undefined' || ui.adminTab !== 'profiles') return;
      const pane = document.querySelector('#adminPane');
      const toolbar = pane?.querySelector('.admin-toolbar');
      if (!toolbar || pane.querySelector('.v26-six-status')) return;

      const count = presentCount();
      const status = document.createElement('div');
      status.className = 'note v26-six-status';
      status.style.marginBottom = '12px';
      status.innerHTML = `<strong>V26 · ${count}/6 nouveaux profils présents</strong> · Total actuel : ${db.profiles.length}`;
      toolbar.insertAdjacentElement('afterend', status);

      const button = document.createElement('button');
      button.className = 'btn small';
      button.id = 'p50ImportSixV26Btn';
      button.textContent = count === 6 ? 'Resynchroniser les 6 profils' : 'Ajouter les 6 profils';
      toolbar.appendChild(button);
    };
  }

  document.addEventListener('click', event => {
    if (event.target?.id === 'p50ImportSixV26Btn') importSix(true);
  });

  try {
    if ('caches' in window && localStorage.getItem('pass50.v26.cache-cleared') !== RELEASE) {
      caches.keys().then(keys => Promise.all(
        keys.filter(key => /pass50/i.test(key)).map(key => caches.delete(key))
      )).finally(() => localStorage.setItem('pass50.v26.cache-cleared', RELEASE));
    }
    navigator.serviceWorker?.getRegistrations?.().then(registrations => {
      registrations.forEach(registration => registration.update().catch(() => null));
    });
  } catch {}

  console.info('[PASS50 V26] Module chargé', {
    release: RELEASE,
    expectedProfiles: EXPECTED_IDS
  });
})();
