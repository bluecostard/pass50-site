// Hotfix V81 : désactivation complète du service worker PASS50.
// Le site repasse temporairement en accès réseau direct afin d'éliminer
// les boucles de mise à jour, les caches obsolètes et les préchargements massifs.
const PASS50_SW_DISABLED_VERSION='pass50-v81-service-worker-disabled';

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
