const CACHE='pass50-admin-social-v3-20260722';
const ASSETS=[
  './',
  './index.html',
  './manifest.webmanifest',
  './icon.svg',
  './assets/pass50-wordmark.png',
  './assets/pass50-logo-email.png',
  './v9-tools.css',
  './v9-tools.js',
  './data-engine-ui.js',
  './assets/hero-media-1.svg',
  './assets/hero-media-2.svg',
  './assets/hero-media-3.svg',
  './assets/hero-media-4.svg',
  './data-engine-ui.css'
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
