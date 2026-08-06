(function(){
  'use strict';

  const PASS50_ADMIN_ALPHABETICAL='PASS50-ADMIN-ALPHABETICAL-V1.0';
  const collator=new Intl.Collator('fr',{sensitivity:'base',ignorePunctuation:true,numeric:true});
  let sorting=false;

  function clean(value=''){
    return String(value||'').replace(/\s+/g,' ').trim();
  }

  function compareNames(a,b){
    return collator.compare(clean(a),clean(b));
  }

  function profileNameById(id){
    const item=Array.isArray(window.db?.profiles)?window.db.profiles.find(profile=>String(profile.id)===String(id)):null;
    return clean(item?.name||'');
  }

  function sortChildren(container,nameOf){
    if(!container)return;
    const children=[...container.children];
    if(children.length<2)return;
    const sorted=[...children].sort((a,b)=>compareNames(nameOf(a),nameOf(b)));
    if(sorted.every((child,index)=>child===children[index]))return;
    const fragment=document.createDocumentFragment();
    sorted.forEach(child=>fragment.appendChild(child));
    container.appendChild(fragment);
  }

  function sortProfileSelect(select){
    if(!select||select.dataset.pass50Alphabetical==='1')return;
    const options=[...select.options];
    if(options.length<2)return;
    const profileOptions=options.filter(option=>profileNameById(option.value));
    if(profileOptions.length<2)return;
    const selected=select.value;
    const profileSet=new Set(profileOptions);
    const sortedProfiles=[...profileOptions].sort((a,b)=>compareNames(profileNameById(a.value)||a.textContent,profileNameById(b.value)||b.textContent));
    const fragment=document.createDocumentFragment();
    options.forEach(option=>{if(!profileSet.has(option))fragment.appendChild(option);});
    sortedProfiles.forEach(option=>fragment.appendChild(option));
    select.appendChild(fragment);
    select.value=selected;
    select.dataset.pass50Alphabetical='1';
  }

  function sortAdminLists(){
    if(sorting)return;
    const admin=document.getElementById('adminModal');
    if(!admin||!admin.classList.contains('show'))return;
    if(window.ui?.adminTab==='ranking')return;
    sorting=true;
    try{
      sortChildren(document.getElementById('profileAdminRows'),row=>row.querySelector('td')?.textContent||'');
      sortChildren(document.getElementById('linksCards'),card=>card.querySelector('.link-card-head strong')?.textContent||'');
      sortChildren(document.getElementById('hubRows'),row=>row.querySelector('td strong')?.textContent||row.querySelector('td')?.textContent||'');

      document.querySelectorAll('#adminModal .media-grid').forEach(grid=>{
        sortChildren(grid,item=>item.querySelector('.media-card h4')?.textContent||item.querySelector('h4')?.textContent||'');
      });

      document.querySelectorAll('#adminModal select').forEach(sortProfileSelect);

      document.querySelectorAll('#adminModal table.admin-table tbody').forEach(body=>{
        if(body.id==='profileAdminRows'||body.id==='hubRows')return;
        const firstHeader=clean(body.closest('table')?.querySelector('thead th')?.textContent).toLowerCase();
        if(!['influenceur','nom','profil'].includes(firstHeader))return;
        sortChildren(body,row=>row.querySelector('td strong')?.textContent||row.querySelector('td')?.textContent||'');
      });
    }finally{
      sorting=false;
    }
  }

  function scheduleSort(){
    requestAnimationFrame(()=>requestAnimationFrame(sortAdminLists));
  }

  const observer=new MutationObserver(scheduleSort);

  function boot(){
    const adminBody=document.getElementById('adminBody');
    if(adminBody)observer.observe(adminBody,{childList:true,subtree:true});
    document.addEventListener('click',event=>{
      if(event.target.closest('[data-admin-tab],#adminOpen,#adminHomeButton'))scheduleSort();
    });
    scheduleSort();
  }

  window.PASS50_ADMIN_ALPHABETICAL=PASS50_ADMIN_ALPHABETICAL;
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();
