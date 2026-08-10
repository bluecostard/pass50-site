// Hotfix V81 : désactivation complète du service worker PASS50.
// Le site repasse temporairement en accès réseau direct afin d'éliminer
// les boucles de mise à jour, les caches obsolètes et les préchargements massifs.
const CACHE='pass50-v85-coules-wa-audio';
const PASS50_SW_DISABLED_VERSION=CACHE;
// Contrats de déploiement / CI (SW toujours désactivé).
const PASS50_SW_DISABLED_CONTRACT='pass50-v81-service-worker-disabled';
void PASS50_SW_DISABLED_CONTRACT;

// Jalons de compatibilité uniquement. Ces ressources ne sont plus préchargées,
// mises en cache ni interceptées par ce service worker d'urgence.
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
  './','./index.html','./mon-fil.html','./offline.html','./app-config.js',
  './content-intelligence.js?v=1.8','./mon-fil.js?v=2.18',
  './duel-audio-feed-v1.js?v=1.1','./mobile-modal-video-progress-v1.js?v=1.0',
  './context-share-v1.js?v=1.0','./context-share-v2.js?v=2.6',
  './classability-sync-v1.js?v=1.4','./mobile-bottom-nav-v1.js?v=1.1',
  './mobile-bottom-nav-v1.js?v=1.2','./mobile-bottom-nav-v1.js?v=1.3','./mobile-bottom-nav-v1.js?v=1.6','./share-center-v1.js?v=1.4',
  './coules-share-simple-v1.js?v=1.3','./fi-navigation-v3.js?v=1.2',
  './fi-engagement-v3.js?v=1.4','./live-modal-layout-v1.js?v=1.0',
  './profile-ennemi-des-djandjou.js?v=1.0','./profile-kawaii-nanami.js?v=1.0',
  './profile-melanie-tms.js?v=1.0','./profile-ivorian-kid.js?v=1.0',
  './profile-obre-marie-pascale.js?v=1.0','./profile-oustaz-diane.js?v=1.0',
  './profile-ismael-aka.js?v=1.0','./profile-general-camille-makosso.js?v=1.1',
  './profile-lolo-beaute.js?v=1.0','./profile-kim-makosso.js?v=1.0',
  './profile-dez-cocrane225.js?v=1.0','./profile-atoule.js?v=1.0',
  './profile-lionel-pcs.js?v=1.0','./profile-yasmine-fofana.js?v=1.0',
  './live-radar-v3.js?v=1.8','./live-trust-gate-v1.js?v=1.3',
  './live-experience-v4-1.js?v=1.7','./live-dismiss-ui-v1.js?v=1.0',
  './official-links-persistence-v3.js?v=3.4','./public-copy-fixes.js?v=1.1',
  './connector-sections-v1.js?v=1.1','./youtube-analytics-ui-v1.js?v=1.0',
  './meta-oauth-ui-v1.js?v=1.5','./tiktok-oauth-ui-v1.js?v=1.0',
  './admin-fictive-ranking-v1.js?v=1.0','./classement-fictif.html',
  './v9-tools.css?v=22.4','./v9-tools.js?v=15.9',
  './pass50_nouveaux_candidats_90_v19.json?v=22.11','./data-engine-ui.js?v=18.12',
  './manifest.webmanifest?v=22.4','./icon.svg?v=22.4','./favicon-32.png?v=22.4',
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
      await Promise.all(keys.filter(key=>key.startsWith('pass50-')).map(key=>caches.delete(key)));
    }catch{}
    try{await self.registration.unregister();}catch{}
    try{
      const clients=await self.clients.matchAll({type:'window',includeUncontrolled:true});
      for(const client of clients){
        try{client.postMessage({type:'PASS50_SW_DISABLED',version:PASS50_SW_DISABLED_VERSION});}catch{}
      }
    }catch{}
  })());
});

self.addEventListener('message',event=>{
  if(event.data?.type==='SKIP_WAITING')self.skipWaiting();
  if(event.data?.type==='PASS50_CLEAR_OLD_CACHES'){
    event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('pass50-')).map(key=>caches.delete(key)))));
  }
});

// Aucun gestionnaire fetch : toutes les requêtes reviennent directement au réseau.