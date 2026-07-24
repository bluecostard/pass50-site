/* BEGIN PASS50 V27 REAL FIX
   Correctifs :
   - recensement 127 -> 133 ;
   - URL vidéo cliquable lorsque le lien original a été validé ;
   - sauvegardes Réseaux + Actualité sérialisées ;
   - expiration des LIVE anciens, y compris sans endsAt.
*/
(function(){
  'use strict';

  const P50V27_VERSION='27.0';
  const P50V27_CANDIDATES=[{"id":"census-african-ryou","name":"African Ryou","known_alias":"@african_ryou","entity_type":"Personne","zone":"CI","category":"Humour / Culture ivoirienne / Lifestyle / Fitness","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","official_socials":{},"platforms_detected":["Instagram","TikTok","YouTube","Snapchat"],"source":{"publisher":"Brut Afrique","date":"2025-10-13","url":"https://www.brut.media/afrique/videos/afrique/societe/lecon-de-nouchi-avec-ryou"},"notes":"Ajout approuvé par le propriétaire PASS50. Comptes officiels à valider manuellement avant publication.","priority_wave":"V27 — intégration immédiate"},{"id":"census-samuella-kouassi","name":"Samuella Kouassi","known_alias":"@samuellakouassiofficiel","entity_type":"Personne","zone":"CI","category":"Lifestyle / Mode / Divertissement","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","official_socials":{},"platforms_detected":["Instagram","TikTok","YouTube","Facebook"],"source":{"publisher":"SEN Influenceurs","date":"2026-07-24","url":"https://seninfluenceurs.com/service/view/188"},"notes":"Ajout approuvé par le propriétaire PASS50. Aucun score fictif.","priority_wave":"V27 — intégration immédiate"},{"id":"census-nadiani","name":"Nadiani","known_alias":"Imane Nadiani Touré / @officialnad_","entity_type":"Personne","zone":"BOTH","category":"Mode / Beauté / Lifestyle / Entrepreneuriat","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","official_socials":{},"platforms_detected":["Instagram","TikTok","YouTube"],"source":{"publisher":"Portfolio public Nadiani","date":"2026-07-24","url":"https://nadiani.my.canva.site/"},"notes":"Nom public : Nadiani. Imane Nadiani Touré est conservé comme alias.","priority_wave":"V27 — intégration immédiate"},{"id":"census-investisseur-africain","name":"L’Investisseur Africain","known_alias":"Jean-Yves Bragbo / Jean Yves Bragbo / @sbragbo","entity_type":"Personne","zone":"BOTH","category":"Business / Investissement / Entrepreneuriat / Diaspora","census_status":"Recensé confirmé — intégration prioritaire","verification_priority":"P0","official_socials":{},"platforms_detected":["YouTube","Facebook","LinkedIn","Instagram"],"source":{"publisher":"L’Investisseur Africain — site public","date":"2026-07-24","url":"https://www.investisseur-africain.com/cgv"},"notes":"Nom public : L’Investisseur Africain. Jean-Yves Bragbo est conservé comme identité/alias.","priority_wave":"V27 — intégration immédiate"},{"id":"census-laura-ziehi","name":"Laura Ziehi","known_alias":"@laura.ziehi","entity_type":"Personne","zone":"BOTH","category":"Lifestyle / Mode / Divertissement","census_status":"Recensé confirmé — réseaux à compléter","verification_priority":"P1","official_socials":{},"platforms_detected":["Instagram","TikTok","Snapchat"],"source":{"publisher":"Pannelle Talents","date":"2026-07-24","url":"https://pannelle.com/reseaupannelle/talents/laura-ziehi/"},"notes":"Ajout approuvé au statut « à recenser ». Réseaux à revalider.","priority_wave":"V27 — à recenser"},{"id":"census-aya-robert","name":"Aya Robert","known_alias":"Aya Robert","entity_type":"Personne","zone":"CI","category":"TikTok / Lives / Débats / Divertissement","census_status":"À vérifier — compte officiel actuel","verification_priority":"P1","official_socials":{},"platforms_detected":["TikTok","Facebook"],"source":{"publisher":"Digital Mag Côte d’Ivoire","date":"2024-09-20","url":"https://digitalmag.ci/reseaux-sociaux-portrait-robot-de-tiktok-en-cote-divoire/"},"notes":"Ajout approuvé au statut « à recenser ». Aucun compte officiel affiché avant revalidation.","priority_wave":"V27 — à recenser"}];
  const P50V27_IDS=P50V27_CANDIDATES.map(item=>item.id);
  const P50V27_LIVE_FIRST_SEEN_KEY='pass50.v27.live.firstSeen';
  const P50V27_LIVE_MAX_AUTO_MS=8*60*60*1000;
  const P50V27_LIVE_MAX_MANUAL_MS=8*60*60*1000;
  const P50V27_LIVE_MAX_UNDATED_MS=10*60*1000;

  function p50v27Normalize(value=''){
    return String(value)
      .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
      .toLowerCase()
      .replace(/[’'`´]/g,'')
      .replace(/[^a-z0-9]+/g,'')
      .trim();
  }

  function p50v27Aliases(value=''){
    return String(value).split(/[\/·,;|]/).map(p50v27Normalize).filter(Boolean);
  }

  function p50v27Handle(candidate){
    const match=String(candidate?.known_alias||'').match(/@[A-Za-z0-9._-]+/);
    if(match)return match[0];
    return '@'+(p50v27Normalize(candidate?.name||'profil').slice(0,28)||'profil');
  }

  function p50v27Initials(name=''){
    const words=String(name).replace(/[’']/g,' ').split(/\s+/).filter(Boolean);
    return (words.slice(0,2).map(word=>word[0]).join('')||'P').toUpperCase();
  }

  function p50v27FindProfile(candidate){
    const wantedId=String(candidate.id||'').toLowerCase();
    const wantedName=p50v27Normalize(candidate.name);
    const wantedHandle=p50v27Normalize(p50v27Handle(candidate));
    const wantedAliases=p50v27Aliases(candidate.known_alias);
    return (db.profiles||[]).find(item=>{
      const itemId=String(item.id||'').toLowerCase();
      const itemName=p50v27Normalize(item.name);
      const itemHandle=p50v27Normalize(item.handle);
      const itemAliases=p50v27Aliases(item.knownAlias||item.known_alias||'');
      return (wantedId&&itemId===wantedId)
        || (wantedName&&itemName===wantedName)
        || (wantedHandle&&itemHandle===wantedHandle)
        || wantedAliases.includes(itemName)
        || wantedAliases.includes(itemHandle)
        || itemAliases.includes(wantedName)
        || itemAliases.some(alias=>wantedAliases.includes(alias));
    })||null;
  }

  function p50v27Profile(candidate){
    const detected=Array.isArray(candidate.platforms_detected)
      ? candidate.platforms_detected.filter(Boolean):[];
    const activePeriods=Array.isArray(periods)&&periods.length
      ? periods:['2H','24H','48H','7J','15J'];
    return {
      id:String(candidate.id),
      name:String(candidate.name),
      handle:p50v27Handle(candidate),
      initials:p50v27Initials(candidate.name),
      region:['CI','DIASPORA','BOTH'].includes(candidate.zone)?candidate.zone:'BOTH',
      category:String(candidate.category||'À catégoriser'),
      platforms:detected,
      scores:Object.fromEntries(activePeriods.map(period=>[period,0])),
      delta:0,
      decline:0,
      alive:true,
      eligible:false,
      classable:false,
      censusStatus:String(candidate.census_status||'À vérifier'),
      verificationPriority:String(candidate.verification_priority||'P1'),
      entityType:String(candidate.entity_type||'Personne'),
      knownAlias:String(candidate.known_alias||''),
      censusSource:candidate.source||{},
      censusNotes:String(candidate.notes||''),
      priorityWave:String(candidate.priority_wave||'V27'),
      detectedPlatforms:detected,
      censusImportedAt:new Date().toISOString(),
      ageStatus:'unconfirmed',
      birthDate:null,
      birthYear:null,
      agePublic:true,
      photoUrl:'',
      photoCandidateUrl:'',
      photoStatus:'missing',
      photoSource:'',
      photoNote:'Profil recensé : identité visuelle à vérifier.',
      photoPosition:'50% 50%',
      badges:[],
      links:{},
      linkChecks:Object.fromEntries(detected.map(platform=>[
        platform,
        {
          status:'search_not_official',
          checkedAt:null,
          message:'Compte repéré : validation manuelle obligatoire.'
        }
      ]))
    };
  }

  function p50v27MergeCandidates(){
    if(typeof db==='undefined'||!db)return {changed:false,added:0,total:0,present:0};
    db.profiles=Array.isArray(db.profiles)?db.profiles:[];
    let changed=false,added=0;

    P50V27_CANDIDATES.forEach(candidate=>{
      let item=p50v27FindProfile(candidate);
      if(!item){
        item=p50v27Profile(candidate);
        db.profiles.push(item);
        added++;
        changed=true;
        return;
      }

      const detected=Array.isArray(candidate.platforms_detected)?candidate.platforms_detected:[];
      const nextPlatforms=[...new Set([...(item.platforms||[]),...detected])];
      if(JSON.stringify(nextPlatforms)!==JSON.stringify(item.platforms||[])){
        item.platforms=nextPlatforms;changed=true;
      }
      const fill={
        knownAlias:String(candidate.known_alias||''),
        censusStatus:String(candidate.census_status||'Recensé confirmé'),
        verificationPriority:String(candidate.verification_priority||'P1'),
        entityType:String(candidate.entity_type||'Personne'),
        censusSource:candidate.source||{},
        censusNotes:String(candidate.notes||''),
        priorityWave:String(candidate.priority_wave||'V27'),
        detectedPlatforms:detected
      };
      Object.entries(fill).forEach(([key,value])=>{
        const empty=item[key]===undefined||item[key]===null||item[key]===''||
          (Array.isArray(item[key])&&item[key].length===0);
        if(empty){item[key]=value;changed=true;}
      });
      item.links=item.links||{};
      item.linkChecks=item.linkChecks||{};
      detected.forEach(platform=>{
        if(!item.linkChecks[platform]){
          item.linkChecks[platform]={
            status:'search_not_official',
            checkedAt:null,
            message:'Compte repéré : validation manuelle obligatoire.'
          };
          changed=true;
        }
      });
      if(item.eligible!==true&&item.eligible!==false){item.eligible=false;changed=true;}
      if(item.classable!==true&&item.classable!==false){item.classable=false;changed=true;}
      if(item.alive===undefined){item.alive=true;changed=true;}
    });

    const present=P50V27_IDS.filter(id=>db.profiles.some(item=>String(item.id)===id)).length;
    if(db.censusVersion!=='96-v27'){db.censusVersion='96-v27';changed=true;}
    db.v27Status={
      version:P50V27_VERSION,
      present,
      expected:6,
      total:db.profiles.length,
      checkedAt:new Date().toISOString()
    };
    return {changed,added,total:db.profiles.length,present};
  }

  /* -------------------------------------------------------------- */
  /* Liens vidéo exacts et cliquables                               */
  /* -------------------------------------------------------------- */

  function p50v27HttpUrl(value=''){
    try{
      const url=new URL(String(value||'').trim(),location.href);
      return /^https?:$/.test(url.protocol)?url:null;
    }catch{return null;}
  }

  function p50v27ExactContentLink(value=''){
    const url=p50v27HttpUrl(value);
    if(!url)return false;
    const host=url.hostname.toLowerCase().replace(/^www\./,'');
    const path=url.pathname.replace(/\/+/g,'/');
    const clean=path.replace(/\/+$/,'')||'/';

    if(host==='youtu.be')return /^\/[A-Za-z0-9_-]{6,}\/?$/.test(path);
    if(host.endsWith('youtube.com')||host.endsWith('youtube-nocookie.com')){
      if(clean==='/watch'&&/^[A-Za-z0-9_-]{6,}$/.test(url.searchParams.get('v')||''))return true;
      return /^\/(?:shorts|live|embed)\/[A-Za-z0-9_-]{6,}$/i.test(clean);
    }
    if(host.endsWith('tiktok.com'))return /^\/@[^/]+\/video\/\d+\/?$/i.test(path);
    if(host.endsWith('instagram.com'))return /^\/(?:p|reel|reels|tv)\/[^/?#]+\/?$/i.test(path);
    if(host==='fb.watch')return clean!=='/';
    if(host.endsWith('facebook.com')){
      if(['v','story_fbid','fbid'].some(key=>Boolean(url.searchParams.get(key))))return true;
      return /^\/(?:[^/]+\/)?videos\/[^/?#]+\/?$/i.test(path)
        || /^\/(?:reel|posts)\/[^/?#]+\/?$/i.test(path)
        || /^\/share\/(?:v|r|p)\/[^/?#]+\/?$/i.test(path)
        || /^\/watch\/[^/?#]+\/?$/i.test(path);
    }
    if(host==='x.com'||host==='twitter.com')return /^\/[^/]+\/status\/\d+\/?$/i.test(path);

    const segments=clean.split('/').filter(Boolean);
    if(!segments.length)return false;
    const first=segments[0].toLowerCase();
    if(['home','feed','watch','login','search','explore','results'].includes(first))return false;
    if(url.searchParams.has('search_query')||url.searchParams.has('q'))return false;
    return true;
  }

  function p50v27EventUrl(event){
    if(!event||event.originalLinkValidated!==true)return '';
    const candidates=[
      event.resolvedUrl,
      event.canonicalUrl,
      event.submittedUrl,
      event.url,
      event.originalUrl
    ];
    return candidates.map(value=>String(value||'').trim())
      .find(p50v27ExactContentLink)||'';
  }

  p50v9ExactContentLink=p50v27ExactContentLink;
  window.p50v27ExactContentLink=p50v27ExactContentLink;
  window.p50v27EventUrl=p50v27EventUrl;

  function p50v27RepairEventUrls(){
    let changed=false;
    (db.events||[]).forEach(event=>{
      const url=p50v27EventUrl(event);
      if(url&&event.url!==url){event.url=url;changed=true;}
      if(url&&!event.resolvedUrl){event.resolvedUrl=url;changed=true;}
      const content=(db.content||[]).find(item=>item.profileId===event.profileId);
      if(url&&content&&content.url!==url){content.url=url;changed=true;}
    });
    return changed;
  }

  eventHtml=function(profileItem){
    const event=primaryEvent(profileItem.id);
    if(!event){
      return '<div class="trigger-empty"><strong>Élément déclencheur non encore validé</strong><div style="margin-top:5px">Aucun lien original n’a encore été sélectionné dans Administration → Actualité.</div></div>';
    }
    const url=p50v27EventUrl(event);
    const thumb=typeof p50v20EventThumbHtml==='function'
      ?p50v20EventThumbHtml(profileItem,event):triggerThumbHtml(event);
    const link=url
      ?`<a class="btn small primary" href="${safeAttr(url)}" target="_blank" rel="noopener noreferrer">Voir l’élément original ↗</a>`
      :'<span class="muted">Lien original à valider dans Administration → Actualité</span>';
    return `<section class="trigger-card">
      <div class="trigger-head">
        <div class="trigger-kicker">⚡ POURQUOI DANS LE TOP 10 ?</div>
        <span class="trigger-type">${event.type||'Actualité'}</span>
      </div>
      <div class="trigger-main">${thumb}<div>
        <div class="trigger-title">${event.title||'Actualité validée'}</div>
        <div class="trigger-meta">${(event.platforms||[]).join(' · ')} · ${event.publishedLabel||''} · Confiance ${event.confidence||'à vérifier'}</div>
        <div class="trigger-reason">${event.reason||''}</div>
      </div></div>
      <div class="trigger-actions">
        <span class="badge hot">${event.metric||'Signal détecté'}</span>${link}
      </div>
    </section>`;
  };

  renderContent=function(){
    const grid=document.querySelector('#contentGrid');
    if(!grid)return;
    const items=[...(db.content||[])]
      .filter(item=>profile(item.profileId))
      .sort((a,b)=>score(profile(b.profileId))-score(profile(a.profileId)))
      .slice(0,5);

    grid.innerHTML=items.map((content,index)=>{
      const profileItem=profile(content.profileId);
      const event=primaryEvent(content.profileId);
      const url=p50v27EventUrl(event)||
        (p50v27ExactContentLink(content.url)?content.url:'');
      const detected=typeof p50v20DetectedCover==='function'?p50v20DetectedCover(event):'';
      const cover=typeof p50v20TrendCover==='function'
        ?p50v20TrendCover(profileItem,event):publicPhoto(profileItem);
      const fallback=Boolean(cover&&!detected);
      const body=`${cover?`<img class="cover-bg" src="${safeAttr(cover)}" alt="Visuel ${safeAttr(profileItem.name)}" referrerpolicy="no-referrer" onerror="this.style.display='none'">`:''}
        ${fallback?'<span class="content-cover-fallback">VISUEL DU PROFIL</span>':''}
        <div><strong>#${index+1} · ${profileItem.name}</strong><div style="margin-top:8px">${badgeHtml(content.badge||'HOT')}</div></div>
        <div class="play">▶</div>
        <div class="content-meta"><span>${content.platform||event?.platforms?.[0]||'Réseau social'}</span><span>${content.views||'Contenu validé'} · ${content.time||'Récent'}</span></div>`;
      return url
        ?`<a class="content-card ${cover?'has-cover':''}" href="${safeAttr(url)}" target="_blank" rel="noopener noreferrer" data-content="${safeAttr(content.id)}">${body}</a>`
        :`<article class="content-card ${cover?'has-cover':''}" data-content="${safeAttr(content.id)}">${body}<div class="platform-hidden-note">Lien original à valider</div></article>`;
    }).join('');
  };

  /* -------------------------------------------------------------- */
  /* LIVE : expiration obligatoire                                  */
  /* -------------------------------------------------------------- */

  function p50v27ReadMap(key){
    try{
      const value=JSON.parse(localStorage.getItem(key)||'{}');
      return value&&typeof value==='object'?value:{};
    }catch{return {};}
  }

  function p50v27WriteMap(key,value){
    try{localStorage.setItem(key,JSON.stringify(value));}catch{}
  }

  function p50v27Time(value){
    const time=value?new Date(value).getTime():NaN;
    return Number.isFinite(time)?time:0;
  }

  function p50v27LiveKey(live){
    return String(live?.id||live?.url||`${live?.profileId||'profil'}:${live?.platform||'live'}`);
  }

  function p50v27FreshLive(live,now=Date.now()){
    if(!live||!live.profileId||String(live.status||'').toLowerCase()!=='live')return false;

    const endsAt=p50v27Time(live.endsAt);
    if(endsAt&&endsAt<=now)return false;

    const startedAt=p50v27Time(
      live.startedAt||live.detectedAt||live.createdAt||live.publishedAt
    );
    if(startedAt>now+5*60*1000)return false;

    const source=String(live.source||'automatic').toLowerCase();
    const hardMax=source==='manual'?P50V27_LIVE_MAX_MANUAL_MS:P50V27_LIVE_MAX_AUTO_MS;
    if(startedAt&&now-startedAt>hardMax)return false;

    if(!startedAt){
      const map=p50v27ReadMap(P50V27_LIVE_FIRST_SEEN_KEY);
      const key=p50v27LiveKey(live);
      if(!map[key]){map[key]=now;p50v27WriteMap(P50V27_LIVE_FIRST_SEEN_KEY,map);}
      if(now-Number(map[key]||now)>P50V27_LIVE_MAX_UNDATED_MS)return false;
    }
    return true;
  }

  normalizeLiveStreams=function(){
    if(!Array.isArray(db.liveStreams))db.liveStreams=[];
    db.liveStreams=db.liveStreams.filter(item=>p50v27FreshLive(item));
    (db.profiles||[]).forEach(profileItem=>{
      profileItem.badges=(profileItem.badges||[]).filter(badge=>badge!=='LIVE');
      if(db.liveStreams.some(item=>item.profileId===profileItem.id&&item.status==='live')){
        profileItem.badges.unshift('LIVE');
      }
    });
  };

  refreshLiveStatus=async function(){
    try{
      const response=await fetch('./api/live-status.php?v=27.0',{cache:'no-store'});
      if(!response.ok)return null;
      const data=await response.json();
      window.PASS50_LIVE_RADAR=data.radar||{};
      if(Array.isArray(data.liveStreams)){
        db.liveStreams=data.liveStreams.filter(item=>p50v27FreshLive(item));
        normalizeLiveStreams();
        localStorage.setItem(APP_KEY,JSON.stringify(db));
        render();
      }
      return data;
    }catch(error){
      console.warn('Radar LIVE indisponible',error);
      return null;
    }
  };

  /* -------------------------------------------------------------- */
  /* Sauvegarde cloud sérialisée                                    */
  /* -------------------------------------------------------------- */

  const p50v27CloudQueue={
    timer:null,
    retryTimer:null,
    running:false,
    dirty:false,
    promise:null,
    revision:Number(db?.clientRevision||0)
  };

  function p50v27CanSync(){
    if(typeof CLOUD==='undefined'||!CLOUD?.enabled||!CLOUD?.ready||!CLOUD?.token)return false;
    const user=typeof currentUser==='function'?currentUser():null;
    return Boolean(user&&['owner','admin'].includes(user.role));
  }

  async function p50v27FlushCloud(){
    if(!p50v27CanSync())return null;
    if(p50v27CloudQueue.running){
      p50v27CloudQueue.dirty=true;
      return p50v27CloudQueue.promise;
    }

    p50v27CloudQueue.running=true;
    p50v27CloudQueue.promise=(async()=>{
      try{
        do{
          p50v27CloudQueue.dirty=false;
          const snapshot=cloudSafeState();
          snapshot.clientRevision=++p50v27CloudQueue.revision;
          snapshot.clientUpdatedAt=new Date().toISOString();
          snapshot.patchVersion=P50V27_VERSION;
          db.clientRevision=snapshot.clientRevision;
          db.clientUpdatedAt=snapshot.clientUpdatedAt;

          await apiFetch('state.php',{
            method:'POST',
            body:{
              data:snapshot,
              clientRevision:snapshot.clientRevision,
              clientUpdatedAt:snapshot.clientUpdatedAt
            }
          });

          try{
            if(typeof syncCloudPrefs==='function')await syncCloudPrefs();
          }catch(preferenceError){
            console.warn('[PASS50 V27] Préférences non synchronisées',preferenceError);
          }
        }while(p50v27CloudQueue.dirty);
        return true;
      }catch(error){
        console.warn('[PASS50 V27] Synchronisation différée',error);
        p50v27CloudQueue.dirty=true;
        clearTimeout(p50v27CloudQueue.retryTimer);
        p50v27CloudQueue.retryTimer=setTimeout(()=>p50v27FlushCloud(),2500);
        return false;
      }finally{
        p50v27CloudQueue.running=false;
        p50v27CloudQueue.promise=null;
        if(p50v27CloudQueue.dirty){
          clearTimeout(p50v27CloudQueue.timer);
          p50v27CloudQueue.timer=setTimeout(()=>p50v27FlushCloud(),80);
        }
      }
    })();
    return p50v27CloudQueue.promise;
  }

  scheduleCloudSync=function(){
    if(!p50v27CanSync())return;
    p50v27CloudQueue.dirty=true;
    clearTimeout(p50v27CloudQueue.timer);
    p50v27CloudQueue.timer=setTimeout(()=>p50v27FlushCloud(),650);
  };

  syncCloudState=async function(){
    p50v27CloudQueue.dirty=true;
    return p50v27FlushCloud();
  };

  /* -------------------------------------------------------------- */
  /* Réparation et persistance                                      */
  /* -------------------------------------------------------------- */

  function p50v27Repair(showMessage=false){
    const census=p50v27MergeCandidates();
    const eventChanged=p50v27RepairEventUrls();
    const beforeLives=Array.isArray(db.liveStreams)?db.liveStreams.length:0;
    normalizeLiveStreams();
    const liveChanged=beforeLives!==db.liveStreams.length;
    const changed=census.changed||eventChanged||liveChanged;

    if(changed){
      try{localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
      if(typeof save==='function')save();
    }
    if(typeof render==='function')render();

    const status={
      version:P50V27_VERSION,
      total:db.profiles.length,
      candidatesPresent:census.present,
      added:census.added,
      staleLivesRemoved:liveChanged
    };
    window.PASS50_V27_STATUS=status;

    if(showMessage&&typeof toast==='function'){
      toast(`${status.total} profils recensés · ${status.candidatesPresent}/6 nouveaux présents`);
    }
    return status;
  }

  window.p50V27Repair=p50v27Repair;
  window.p50V27FlushCloud=p50v27FlushCloud;

  p50v27Repair(false);
  setTimeout(()=>p50v27Repair(false),1200);
  setTimeout(()=>p50v27Repair(false),4500);

  const p50v27CloudTimer=setInterval(async()=>{
    if(window.__pass50CloudReady){
      const status=p50v27Repair(false);
      if(p50v27CanSync()){
        p50v27CloudQueue.dirty=true;
        await p50v27FlushCloud();
        if(typeof toast==='function'){
          toast(`${status.total} profils recensés · données V27 enregistrées`);
        }
        clearInterval(p50v27CloudTimer);
      }
    }
  },1000);
  setTimeout(()=>clearInterval(p50v27CloudTimer),5*60*1000);

  setInterval(()=>{
    const before=Array.isArray(db.liveStreams)?db.liveStreams.length:0;
    normalizeLiveStreams();
    if(before!==db.liveStreams.length){
      try{localStorage.setItem(APP_KEY,JSON.stringify(db));}catch{}
      if(typeof render==='function')render();
    }else if(typeof renderLiveHeader==='function'){
      renderLiveHeader();
    }
  },5000);

  console.info('[PASS50 V27] Correctif chargé',p50v27Repair(false));
})();
/* END PASS50 V27 REAL FIX */
