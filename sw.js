const CACHE='pass50-v35-live-modal-mobile';
const ASSETS=[
  './',
  './index.html',
  './app-config.js',
  './fi-navigation-v3.js?v=1.0',
  './fi-engagement-v3.js?v=1.1',
  './live-modal-layout-v1.js?v=1.0',
  './profile-ennemi-des-djandjou.js?v=1.0',
  './profile-kawaii-nanami.js?v=1.0',
  './live-radar-v3.js?v=1.1',
  './official-links-persistence-v3.js?v=3.1',
  './public-copy-fixes.js?v=1.0',
  './v9-tools.css?v=22.4',
  './v9-tools.js?v=15.0',
  './pass50_nouveaux_candidats_90_v19.json?v=22.6',
  './data-engine-ui.js?v=15.0',
  './manifest.webmanifest?v=22.4',
  './icon.svg?v=22.4',
  './favicon-32.png?v=22.4',
  './apple-touch-icon.png?v=22.4',
  './assets/hero-media-1.jpg',
  './assets/hero-media-2.jpg',
  './assets/hero-media-3.jpg',
  './assets/hero-media-4.jpg',
  './data-engine-ui.css?v=24.0'
];

self.addEventListener('install',event=>{
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)));
});

self.addEventListener('activate',event=>{
  event.waitUntil(Promise.all([
    self.clients.claim(),
    caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key))))
  ]));
});

self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET')return;

  if(event.request.mode==='navigate'){
    event.respondWith(
      fetch(event.request,{cache:'no-store'})
        .then(response=>{
          const copy=response.clone();
          caches.open(CACHE).then(cache=>cache.put('./index.html',copy));
          return response;
        })
        .catch(()=>caches.match('./index.html'))
    );
    return;
  }

  const url=new URL(event.request.url);
  const forceFresh=['.js','.css','.html','.php'].some(extension=>url.pathname.endsWith(extension));
  event.respondWith(
    fetch(event.request,forceFresh?{cache:'no-store'}:undefined)
      .then(response=>{
        const copy=response.clone();
        caches.open(CACHE).then(cache=>cache.put(event.request,copy));
        return response;
      })
      .catch(()=>caches.match(event.request))
  );
});
