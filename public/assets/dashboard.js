const base=(window.VIDEO_BASE||'').replace(/\/$/,'');
function api(path){return base+path;}
async function refresh(){
 try{
  const r=await fetch(api('/api/jobs'),{cache:'no-store'}); if(!r.ok)return;
  const {jobs}=await r.json();
  const counts={queued:0,processing:0,completed:0,failed:0};
  for(const j of jobs)counts[j.status]=(counts[j.status]||0)+1;
  for(const [key,n] of Object.entries(counts)){const el=document.getElementById('count-'+key);if(el)el.textContent=n;}
  const tbody=document.getElementById('jobs');tbody.replaceChildren();
  for(const j of jobs){
   const tr=document.createElement('tr');
   const a=document.createElement('td');a.textContent=j.title;const small=document.createElement('small');small.textContent=j.original_name;a.append(small);
   const b=document.createElement('td');b.textContent=j.status+' / '+j.stage;
   const c=document.createElement('td');c.textContent=j.progress.toFixed(1)+'%';const bar=document.createElement('div');bar.className='progress';const fill=document.createElement('span');fill.style.width=j.progress+'%';bar.append(fill);c.append(bar);
   const d=document.createElement('td');
   if(j.status==='completed'){const watch=document.createElement('a');watch.href=j.watch_url;watch.textContent='Watch';d.append(watch,' · ');const embed=document.createElement('a');embed.href=j.embed_url;embed.textContent='Embed';d.append(embed);}
   if(j.status==='failed'){const msg=document.createElement('small');msg.textContent=j.error||'Unknown error';d.append(msg);const retry=document.createElement('button');retry.textContent='Retry';retry.onclick=async()=>{await fetch(api('/api/jobs/'+j.id+'/retry'),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});refresh()};d.append(retry)}
   tr.append(a,b,c,d);tbody.append(tr);
  }
 }catch(e){console.warn('Could not refresh status',e)}
}
const form=document.getElementById('upload');form.addEventListener('submit',async e=>{
 e.preventDefault();const output=document.getElementById('upload-status');output.textContent='Uploading…';
 try{
  const r=await fetch(api('/api/uploads'),{method:'POST',body:new FormData(form)});
  const text=await r.text();
  let data;
  try{data=JSON.parse(text);}catch{throw new Error((text||'Upload failed').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,240));}
  if(!r.ok)throw new Error(data.error||'Upload failed');
  output.textContent='Queued '+data.id;form.reset();refresh();
 }catch(err){output.textContent=err.message;}
});
setInterval(refresh,5000);refresh();
