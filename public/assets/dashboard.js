const base=(window.VIDEO_BASE||'').replace(/\/$/,'');
function api(path){return base+path;}
function when(value){
 if(!value) return '—';
 const d=new Date(String(value).replace(' ','T')+'Z');
 if(Number.isNaN(d.getTime())) return value;
 const p=n=>String(n).padStart(2,'0');
 return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
}
function datesCell(j){
 const td=document.createElement('td');td.className='dates';
 for(const [label,value] of [['Uploaded',j.created_at],['Started',j.started_at],['Ended',j.finished_at]]){
  const line=document.createElement('small');
  line.append(label+' ', document.createTextNode(when(value)));
  td.append(line);
 }
 return td;
}
document.querySelectorAll('time[data-utc]').forEach(el=>{el.textContent=when(el.dataset.utc);});
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
   const b=document.createElement('td');b.textContent=j.status+' / '+j.stage;if(j.log_line){const line=document.createElement('small');line.className='log-line';line.textContent=j.log_line;b.append(line);}
   const c=document.createElement('td');c.textContent=j.progress.toFixed(1)+'%';const bar=document.createElement('div');bar.className='progress';const fill=document.createElement('span');fill.style.width=j.progress+'%';bar.append(fill);c.append(bar);
   const whenCell=datesCell(j);
   const d=document.createElement('td');
   if(j.status==='completed'){const watch=document.createElement('a');watch.href=j.watch_url;watch.target='_blank';watch.rel='noopener';watch.textContent='Watch';d.append(watch,' · ');const embed=document.createElement('a');embed.href=j.embed_url;embed.target='_blank';embed.rel='noopener';embed.textContent='Embed';d.append(embed,' · ');}
   if(j.status==='failed'){const msg=document.createElement('small');msg.textContent=j.error||'Unknown error';d.append(msg);const retry=document.createElement('button');retry.textContent='Retry';retry.onclick=async()=>{await fetch(api('/api/jobs/'+j.id+'/retry'),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});refresh()};d.append(retry,' ');}
   const log=document.createElement('a');log.href=api('/api/jobs/'+j.id+'/log');log.target='_blank';log.rel='noopener';log.textContent='Log';d.append(log);
   if(j.status!=='processing'){d.append(' · ');const del=document.createElement('button');del.textContent='Delete';del.dataset.delete=j.id;del.dataset.title=j.title;d.append(del);}
   tr.append(a,b,c,whenCell,d);tbody.append(tr);
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
document.getElementById('jobs').addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-delete]');
  if (!button) return;
  const title = button.dataset.title || 'this video';
  if (!confirm('Delete "' + title + '"? This removes the original, the published files, and the job.')) return;
  button.disabled = true;
  try {
    const r = await fetch(api('/api/jobs/' + button.dataset.delete), {method:'DELETE', headers:{'Content-Type':'application/json'}});
    const text = await r.text();
    let data = {};
    try { data = JSON.parse(text); } catch { throw new Error((text || 'Delete failed').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240)); }
    if (!r.ok) throw new Error(data.error || 'Delete failed');
    refresh();
  } catch (err) {
    button.disabled = false;
    alert(err.message);
  }
});
setInterval(refresh,5000);refresh();
