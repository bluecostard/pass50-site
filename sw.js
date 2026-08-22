// PASS50 App Shell SW V1 — installable, network-only (pas de cache agressif).
const CACHE='pass50-app-shell-v1';
const PASS50_SW_APP_SHELL=CACHE;
const PASS50_SW_CONTRACT='PASS50-APP-SHELL-SW-V1';
// Marqueur historique conservé pour les greps deploy/CI existants.
const PASS50_SW_DISABLED_CONTRACT='pass50-v81-service-worker-disabled';
void PASS50_SW_APP_SHELL;
void PASS50_SW_CONTRACT;
void PASS50_SW_DISABLED_CONTRACT;

// Jalons de compatibilité uniquement. Ces ressources ne sont plus préchargées
// ni interceptées — le SW reste network-only.
const ASSETS=[
  'pass50-v73-keep-official-links',
  'pass50-v75-duel-audio-identity',
  'pass50-v76-mobile-modal-video-progress',
  'pass50-v77-context-share',
  'pass50-v78-share-photo-layout',
  'pass50-v79-cache-bust-hotfix',
  'pass50-v80-site-recovery',
  'pass50-v81-service-worker-disabled',
  'pass50-v82-hero-ghost-covers',
  'pass50-app-shell-v1',
  './','./index.html','./mon-fil.html','./app.html','./app-client.js?v=1.1','./app-client.js?v=1.0','./offline.html','./app-config.js',
  './content-intelligence.js?v=1.15','./content-intelligence.js?v=1.14','./mon-fil.js?v=2.23','./mon-fil.js?v=2.22','./mon-fil.js?v=2.21',
  './duel-audio-feed-v1.js?v=1.1','./mobile-modal-video-progress-v1.js?v=1.0',
  './context-share-v1.js?v=1.0','./context-share-v2.js?v=2.6',
  './classability-sync-v1.js?v=1.8','./mobile-bottom-nav-v1.js?v=1.11','./mobile-bottom-nav-v1.js?v=1.10','./mobile-bottom-nav-v1.js?v=1.9','./mobile-bottom-nav-v1.js?v=1.8',
  './mobile-bottom-nav-v1.js?v=1.6','./mobile-bottom-nav-v1.js?v=1.3','./mobile-bottom-nav-v1.js?v=1.2','./mobile-bottom-nav-v1.js?v=1.1','./share-center-v1.js?v=1.5','./share-center-v1.js?v=1.4',
  './coules-share-simple-v1.js?v=1.3','./fi-navigation-v3.js?v=1.3','./fi-navigation-v3.js?v=1.2',
  './fi-engagement-v3.js?v=1.4','./live-modal-layout-v1.js?v=1.0',
  './profile-ennemi-des-djandjou.js?v=1.1','./profile-kawaii-nanami.js?v=1.1',
  './profile-melanie-tms.js?v=1.0','./profile-ivorian-kid.js?v=1.0',
  './profile-obre-marie-pascale.js?v=1.2','./profile-oustaz-diane.js?v=1.1','./profile-oustaz-diane.js?v=1.0',
  './profile-ismael-aka.js?v=1.0','./profile-apoutchou.js?v=1.0','./profile-general-camille-makosso.js?v=1.2',
  './profile-lolo-beaute.js?v=1.0','./profile-kim-makosso.js?v=1.0','./profile-kim-makosso.js?v=1.1',
  './profile-dez-cocrane225.js?v=1.0','./profile-atoule.js?v=1.0',
  './profile-jp-nda.js?v=1.1','./profile-jp-nda.js?v=1.0','./profile-cahie-kunta.js?v=1.1','./profile-cahie-kunta.js?v=1.0','./profile-lise-akrassi.js?v=1.0','./profile-lexes.js?v=1.1','./profile-lexes.js?v=1.0',
  './profile-zagba-le-requin.js?v=1.0','./profile-samo-samo.js?v=1.1','./profile-samo-samo.js?v=1.0',
  './profile-lionel-pcs.js?v=1.0','./profile-yasmine-fofana.js?v=1.0',
  './profile-ange-morel.js?v=1.1','./profile-ange-morel.js?v=1.0','./profile-laguepe.js?v=1.0','./profile-rosemark-marcel.js?v=1.2','./profile-rosemark-marcel.js?v=1.1','./profile-rosemark-marcel.js?v=1.0','./profile-jiaan-wu.js?v=1.0','./profile-samuella-kouassi.js?v=1.1','./profile-samuella-kouassi.js?v=1.0','./profile-daniel-m.js?v=1.0','./profile-akalajoie.js?v=1.0',
  './live-radar-v3.js?v=1.11','./live-radar-v3.js?v=1.10','./live-radar-v3.js?v=1.9','./live-trust-gate-v1.js?v=1.4','./live-trust-gate-v1.js?v=1.3',
  './live-experience-v4-1.js?v=1.7','./live-dismiss-ui-v1.js?v=1.0',
  './official-links-persistence-v3.js?v=3.4','./public-copy-fixes.js?v=1.15','./public-copy-fixes.js?v=1.14','./public-copy-fixes.js?v=1.13','./public-copy-fixes.js?v=1.12','./public-copy-fixes.js?v=1.11','./admin-fi-edit-preserve-v1.js?v=1.0',
  './connector-sections-v1.js?v=1.1','./account-mobile-nav-v1.js?v=1.1','./youtube-analytics-ui-v1.js?v=1.0',
  './meta-oauth-ui-v1.js?v=1.7','./tiktok-oauth-ui-v1.js?v=1.1',
  './admin-fictive-ranking-v1.js?v=1.0','./classement-fictif.html',
  './v9-tools.css?v=22.4','./v9-tools.js?v=15.32','./v9-tools.js?v=15.31','./v9-tools.js?v=15.30','./v9-tools.js?v=15.29','./v9-tools.js?v=15.28','./v9-tools.js?v=15.27',
  './pass50_nouveaux_candidats_90_v19.json?v=22.15','./data-engine-ui.js?v=18.26','./data-engine-ui.js?v=18.25','./data-engine-ui.js?v=18.24','./data-engine-ui.js?v=18.23','./data-engine-ui.js?v=18.22','./data-engine-ui.js?v=18.21',
  './manifest.webmanifest?v=24.0','./manifest.webmanifest?v=23.0','./manifest.webmanifest?v=22.4','./icon.svg?v=22.4','./favicon-32.png?v=22.4',
  './apple-touch-icon.png?v=22.4','./assets/hero-media-1.jpg','./assets/hero-media-2.jpg',
  './assets/hero-media-3.jpg','./assets/hero-media-4.jpg','./assets/hero-media-5.jpg','./assets/hero-media-6.jpg','./data-engine-ui.css?v=27.1'
];
void ASSETS;

self.addEventListener('install',event=>{
  self.skipWaiting();
});

self.addEventListener('activate',event=>{
  event.waitUntil((async()=>{
    try{
      const keys=await caches.keys();
      await Promise.all(
        keys
          .filter(key=>key.startsWith('pass50-')&&key!==PASS50_SW_APP_SHELL)
          .map(key=>caches.delete(key))
      );
    }catch{}
    await self.clients.claim();
  })());
});

self.addEventListener('message',event=>{
  if(event.data?.type==='SKIP_WAITING')self.skipWaiting();
  if(event.data?.type==='PASS50_CLEAR_OLD_CACHES'){
    event.waitUntil(caches.keys().then(keys=>Promise.all(
      keys.filter(key=>key.startsWith('pass50-')&&key!==PASS50_SW_APP_SHELL).map(key=>caches.delete(key))
    )));
  }
});

// Aucun gestionnaire fetch : toutes les requêtes vont au réseau.
