const CACHE='pass50-v23-step12-maj1';
const ASSETS=[
  './',
  './index.html',
  './v9-tools.css?v=22.4',
  './v9-tools.js?v=23-step12-maj1.0',
  './pass50_nouveaux_candidats_90_v19.json?v=22.6',
  './data-engine-ui.js?v=24.0',
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
  event.waitUntil(
    caches.open(CACHE).then(cache=>cache.addAll(ASSETS))
  );
});

self.addEventListener('activate',event=>{
  event.waitUntil(Promise.all([
    self.clients.claim(),
    caches.keys().then(keys=>Promise.all(
      keys.filter(key=>key!==CACHE).map(key=>caches.delete(key))
    ))
  ]));
});

self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET') return;

  if(event.request.mode==='navigate'){
    event.respondWith(
      fetch(event.request)
        .then(response=>{
          const copy=response.clone();
          caches.open(CACHE).then(cache=>cache.put('./index.html',copy));
          return response;
        })
        .catch(()=>caches.match('./index.html'))
    );
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then(response=>{
        const copy=response.clone();
        caches.open(CACHE).then(cache=>cache.put(event.request,copy));
        return response;
      })
      .catch(()=>caches.match(event.request))
  );
});
