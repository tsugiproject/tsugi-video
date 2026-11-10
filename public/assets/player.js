(()=>{
 const v=document.getElementById('video');if(!v)return;
 const url=v.dataset.hls;
 if(v.canPlayType('application/vnd.apple.mpegurl')){v.src=url;return;}
 if(window.Hls && Hls.isSupported()){
  const hls=new Hls({enableWorker:true});hls.loadSource(url);hls.attachMedia(v);
  hls.on(Hls.Events.ERROR,(_,data)=>{if(data.fatal)document.getElementById('play-error').textContent='Playback error: '+data.details;});
  return;
 }
 document.getElementById('play-error').textContent='HLS is unsupported in this browser. Install the local hls.js bundle using bin/install-hlsjs.sh.';
})();
