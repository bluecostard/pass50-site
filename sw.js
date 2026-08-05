// Connecteurs privés repliables, isolés du classement public.
// Jalon de compatibilité protégé : pass50-v73-keep-official-links.
// Jalon de compatibilité protégé : pass50-v75-duel-audio-identity.
// Jalon de compatibilité protégé : pass50-v76-mobile-modal-video-progress.
// Jalon de compatibilité protégé : pass50-v77-context-share.
const CACHE='pass50-v80-site-recovery';
const ASSETS=[
  './','./index.html','./mon-fil.html','./offline.html','./app-config.js','./content-intelligence.js?v=1.2','./mon-fil.js?v=2.2','./duel-audio-feed-v1.js?v=1.1','./mobile-modal-video-progress-v1.js?v=1.0','./context-share-v1.js?v=1.0','./context-share-v2.js?v=2.0','./classability-sync-v1.js?v=1.4','./mobile-bottom-nav-v1.js?v=1.1','./mobile-bottom-nav-v1.js?v=1.2','./share-center-v1.js?v=1.0','./coules-share-simple-v1.js?v=1.0','./fi-navigation-v3.js?v=1.2','./fi-engagement-v3.js?v=1.3','./live-modal-layout-v1.js?v=1.0',
  './profile-ennemi-des-djandjou.js?v=1.0','./profile-kawaii-nanami.js?v=1.0','./profile-melanie-tms.js?v=1.0','./profile-ivorian-kid.js?v=1.0','./profile-obre-marie-pascale.js?v=1.0','./profile-oustaz-diane.js?v=1.0','./profile-ismael-aka.js?v=1.0','./profile-general-camille-makosso.js?v=1.0','./profile-lolo-beaute.js?v=1.0','./profile-kim-makosso.js?v=1.0','./profile-dez-cocrane225.js?v=1.0','./profile-atoule.js?v=1.0','./profile-lionel-pcs.js?v=1.0','./profile-yasmine-fofana.js?v=1.0',
  './live-radar-v3.js?v=1.7','./live-trust-gate-v1.js?v=1.2','./live-experience-v4-1.js?v=1.4','./live-dismiss-ui-v1.js?v=1.0','./official-links-persistence-v3.js?v=3.4','./public-copy-fixes.js?v=1.1','./connector-sections-v1.js?v=1.1','./youtube-analytics-ui-v1.js?v=1.0','./meta-oauth-ui-v1.js?v=1.5','./tiktok-oauth-ui-v1.js?v=1.0','./admin-fictive-ranking-v1.js?v=1.0','./classement-fictif.html',
  './v9-tools.css?v=22.4','./v9-tools.js?v=15.6','./pass50_nouveaux_candidats_90_v19.json?v=22.8','./data-engine-ui.js?v=18.7','./manifest.webmanifest?v=22.4','./icon.svg?v=22.4','./favicon-32.png?v=22.4','./apple-touch-icon.png?v=22.4','./assets/hero-media-1.jpg','./assets/hero-media-2.jpg','./assets/hero-media-3.jpg','./assets/hero-media-4.jpg','./data-engine-ui.css?v=27.1'
];

async function cacheAsset(cache,asset){
  try{
    const response=await fetch(asset,{cache:'reload'});
    if(response&&response.ok)await cache.put(asset,response.clone());
  }catch{}
}

self.addEventListener('install',event=>{
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE).then(cache=>Promise.allSettled(ASSETS.map(asset=>cacheAsset(cache,asset)))));
});

self.addEventListener('activate',event=>{
  event.waitUntil(Promise.all([
    self.clients.claim(),
    caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('pass50-')&&key!==CACHE).map(key=>caches.delete(key))))
  ]));
});

self.addEventListener('message',event=>{
  const type=event.data&&event.data.type;
  if(type==='SKIP_WAITING')self.skipWaiting();
  if(type==='PASS50_CLEAR_OLD_CACHES')event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('pass50-')&&key!==CACHE).map(key=>caches.delete(key)))));
});

async function navigationResponse(request){
  const url=new URL(request.url);
  const feedPath=url.pathname.endsWith('/mon-fil.html');
  const fallback=feedPath?'./mon-fil.html':'./index.html';
  try{
    const response=await fetch(request,{cache:'no-store'});
    if(response&&response.ok){
      const cache=await caches.open(CACHE);
      await cache.put(fallback,response.clone());
    }
    return response;
  }catch{
    return (await caches.match(fallback))||(await caches.match('./offline.html'))||new Response('PASS50 est momentanément indisponible. Réessayez avec une connexion active.',{status:503,headers:{'Content-Type':'text/plain; charset=utf-8','Cache-Control':'no-store'}});
  }
}

async function assetResponse(request){
  const url=new URL(request.url);
  const sameOrigin=url.origin===self.location.origin;
  const forceFresh=sameOrigin&&['.js','.css','.html','.php','.json'].some(extension=>url.pathname.endsWith(extension));
  try{
    const response=await fetch(request,forceFresh?{cache:'no-store'}:undefined);
    if(response&&response.ok&&sameOrigin){
      const cache=await caches.open(CACHE);
      await cache.put(request,response.clone());
    }
    return response;
  }catch{
    return (await caches.match(request))||new Response('',{status:503,statusText:'Offline'});
  }
}

self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET')return;
  if(event.request.mode==='navigate')event.respondWith(navigationResponse(event.request));
  else event.respondWith(assetResponse(event.request));
});
