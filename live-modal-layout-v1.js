(function(){
'use strict';

if(document.getElementById('pass50LiveModalLayoutV1'))return;

const style=document.createElement('style');
style.id='pass50LiveModalLayoutV1';
style.textContent=`
/* PASS50 — mise en page stable de la fenêtre des directs. */
#liveBody .live-card{
  grid-template-columns:62px minmax(0,1fr) minmax(190px,auto);
  grid-auto-flow:row;
  align-items:center;
}
#liveBody .live-card > .avatar{
  grid-column:1;
  grid-row:1 / span 2;
}
#liveBody .live-card > .avatar + div{
  grid-column:2;
  grid-row:1 / span 2;
  min-width:0;
}
#liveBody .live-card > .avatar + div > strong{
  display:block;
  line-height:1.15;
  overflow-wrap:anywhere;
}
#liveBody .live-card .live-platform,
#liveBody .live-card .muted{
  line-height:1.35;
  overflow-wrap:anywhere;
}
#liveBody .live-card > .live-watch-link,
#liveBody .live-card > .btn.disabled,
#liveBody .live-card > .p50-share-live{
  grid-column:3;
  width:100%;
  max-width:270px;
  min-width:190px;
  min-height:46px;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:11px 14px;
  white-space:normal;
  text-align:center;
  line-height:1.15;
}
#liveBody .live-card > .live-watch-link,
#liveBody .live-card > .btn.disabled{
  grid-row:1;
}
#liveBody .live-card > .p50-share-live{
  grid-row:2;
  margin-top:8px;
}

@media(max-width:680px){
  #liveModal{
    padding:max(6px,env(safe-area-inset-top)) 6px max(6px,env(safe-area-inset-bottom));
  }
  #liveModal .modal-box{
    width:100%;
    max-height:calc(100dvh - 12px);
    border-radius:20px;
  }
  #liveModal .modal-head{
    padding:14px 15px;
  }
  #liveModal .modal-head strong{
    min-width:0;
    font-size:16px;
    line-height:1.1;
  }
  #liveModal .modal-body{
    padding:12px;
  }
  #liveBody .live-list{
    gap:12px;
  }
  #liveBody .live-card{
    grid-template-columns:64px minmax(0,1fr);
    grid-template-rows:auto auto auto;
    gap:12px;
    align-items:start;
    padding:14px;
  }
  #liveBody .live-card > .avatar{
    grid-column:1;
    grid-row:1;
    width:64px;
    height:64px;
    align-self:start;
  }
  #liveBody .live-card > .avatar + div{
    grid-column:2;
    grid-row:1;
    min-width:0;
    align-self:center;
  }
  #liveBody .live-card > .avatar + div > strong{
    font-size:18px;
  }
  #liveBody .live-card .live-platform{
    margin-top:4px;
    font-size:12px;
  }
  #liveBody .live-card > .live-watch-link,
  #liveBody .live-card > .btn.disabled,
  #liveBody .live-card > .p50-share-live{
    grid-column:1 / -1;
    width:100%;
    max-width:none;
    min-width:0;
    min-height:50px;
    margin:0;
    padding:12px 14px;
    border-radius:14px;
    font-size:13px;
  }
  #liveBody .live-card > .live-watch-link,
  #liveBody .live-card > .btn.disabled{
    grid-row:2;
  }
  #liveBody .live-card > .p50-share-live{
    grid-row:3;
  }
}
`;

document.head.appendChild(style);
})();
