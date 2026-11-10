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
function copyEmbedButton(url){
 const button=document.createElement('button');
 button.type='button';
 button.className='copy';
 button.dataset.copy=url;
 button.title='Copy embed link';
 button.setAttribute('aria-label','Copy embed link');
 button.innerHTML='<svg class="icon-copy" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M4 1.5A1.5 1.5 0 0 1 5.5 0h6A1.5 1.5 0 0 1 13 1.5v8A1.5 1.5 0 0 1 11.5 11h-6A1.5 1.5 0 0 1 4 9.5v-8zM5.5 1a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h6a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5h-6z"/><path fill="currentColor" d="M2 4.5A1.5 1.5 0 0 1 3.5 3H4v1h-.5a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h6a.5.5 0 0 0 .5-.5V12h1v.5A1.5 1.5 0 0 1 9.5 14h-6A1.5 1.5 0 0 1 2 12.5v-8z"/></svg><svg class="icon-check" viewBox="0 0 16 16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 8.5 6.2 11.5 13 4.5"/></svg>';
 return button;
}
function slotLines(td, job){
 for(const slot of job.placements||[]){
  const small=document.createElement('small');
  small.className='nowrap';
  const link=document.createElement('a');
  link.href=slot.watch_url;link.target='_blank';link.rel='noopener';
  link.textContent=slot.collection+' / '+slot.logical_key;
  small.append(link, copyEmbedButton(slot.embed_url));td.append(small);
 }
}
function suggest(input, source){
 const list=input.parentElement.querySelector('.suggestions');
 let items=[];let active=0;let timer=null;let request=0;let closeTimer=null;
 function close(){list.hidden=true;list.replaceChildren();items=[];}
 function paint(){
  list.replaceChildren();
  items.forEach((name,i)=>{
   const li=document.createElement('li');
   const button=document.createElement('button');
   button.type='button';button.textContent=name;
   button.setAttribute('aria-selected',i===active?'true':'false');
   button.addEventListener('mousedown',event=>{event.preventDefault();input.value=name;close();input.focus();});
   li.append(button);list.append(li);
  });
  list.hidden=items.length===0;
 }
 async function load(){
  const token=++request;
  const next=await source(input.value.trim());
  if(token!==request)return;
  items=next;active=0;paint();
 }
 input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(load,120);});
 input.addEventListener('focus',()=>{clearTimeout(closeTimer);load();});
 input.addEventListener('keydown',event=>{
  if(list.hidden||!items.length)return;
  if(event.key==='ArrowDown'){event.preventDefault();active=(active+1)%items.length;paint();}
  else if(event.key==='ArrowUp'){event.preventDefault();active=(active-1+items.length)%items.length;paint();}
  else if(event.key==='Tab'||event.key==='Enter'){
   event.preventDefault();
   input.value=items[active];
   close();
   if(event.key==='Tab') input.closest('label').nextElementSibling?.querySelector('input,textarea,select')?.focus();
  }else if(event.key==='Escape') close();
 });
 input.addEventListener('blur',()=>{closeTimer=setTimeout(close,150);});
}
suggest(document.querySelector('[name=collection]'),async q=>{
 const r=await fetch(api('/api/collections?q='+encodeURIComponent(q)),{cache:'no-store'});
 if(!r.ok)return [];
 const data=await r.json();
 return data.collections||[];
});
suggest(document.querySelector('[name=logical_key]'),async q=>{
 const collection=document.querySelector('[name=collection]').value.trim();
 if(!collection)return [];
 const r=await fetch(api('/api/keys?collection='+encodeURIComponent(collection)+'&q='+encodeURIComponent(q)),{cache:'no-store'});
 if(!r.ok)return [];
 const data=await r.json();
 return data.keys||[];
});
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
   const a=document.createElement('td');a.textContent=j.title;const small=document.createElement('small');small.textContent=j.original_name;a.append(small);const sha=document.createElement('small');sha.className='sha';sha.textContent=j.id;a.append(sha);slotLines(a,j);
   const b=document.createElement('td');b.textContent=j.status+' / '+j.stage;if(j.log_line){const line=document.createElement('small');line.className='log-line';line.textContent=j.log_line;b.append(line);}
   const c=document.createElement('td');c.textContent=j.progress.toFixed(1)+'%';const bar=document.createElement('div');bar.className='progress';const fill=document.createElement('span');fill.style.width=j.progress+'%';bar.append(fill);c.append(bar);
   const whenCell=datesCell(j);
   const d=document.createElement('td');
   if(j.status==='completed'){const watch=document.createElement('a');watch.href=j.watch_url;watch.target='_blank';watch.rel='noopener';watch.textContent='Watch';d.append(watch,' · ');const embed=document.createElement('a');embed.href=j.embed_url;embed.target='_blank';embed.rel='noopener';embed.textContent='Embed';const pair=document.createElement('span');pair.className='nowrap';pair.append(embed,copyEmbedButton(j.embed_url));d.append(pair,' · ');}
   if(j.status==='failed'){const msg=document.createElement('small');msg.textContent=j.error||'Unknown error';d.append(msg);const retry=document.createElement('button');retry.textContent='Retry';retry.onclick=async()=>{await fetch(api('/api/jobs/'+j.id+'/retry'),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});refresh()};d.append(retry,' ');}
   const log=document.createElement('a');log.href=api('/api/jobs/'+j.id+'/log');log.target='_blank';log.rel='noopener';log.textContent='Log';d.append(log);
   if(j.status!=='processing'){d.append(' · ');const del=document.createElement('button');del.textContent='Delete';del.dataset.delete=j.id;del.dataset.title=j.title;d.append(del);}
   tr.append(a,b,c,whenCell,d);tbody.append(tr);
  }
 }catch(e){console.warn('Could not refresh status',e)}
}
const form=document.getElementById('upload');
const fileInput=form.querySelector('input[type=file]');
const fileName=document.getElementById('file-name');
fileInput.addEventListener('change',()=>{fileName.textContent=fileInput.files[0]?.name||'No file chosen';});
form.addEventListener('submit',async e=>{
 e.preventDefault();const output=document.getElementById('upload-status');output.textContent='Uploading…';
 try{
  const r=await fetch(api('/api/uploads'),{method:'POST',body:new FormData(form)});
  const text=await r.text();
  let data;
  try{data=JSON.parse(text);}catch{throw new Error((text||'Upload failed').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,240));}
  if(!r.ok)throw new Error(data.error||'Upload failed');
  const slot=(data.placements||[])[0];
  const where=slot?slot.collection+'/'+slot.logical_key+' ':'';
  output.textContent=(data.status==='completed'?'Already available ':data.status==='queued'?'Queued ':'Saved ')+where+data.id;
  form.reset();fileName.textContent='No file chosen';refresh();
 }catch(err){output.textContent=err.message;}
});
document.getElementById('jobs').addEventListener('click', async (event) => {
  const copy = event.target.closest('button[data-copy]');
  if (copy) {
    try {
      await navigator.clipboard.writeText(copy.dataset.copy);
      copy.classList.add('copied');
      copy.title = 'Copied';
      copy.setAttribute('aria-label', 'Copied');
      setTimeout(() => {
        copy.classList.remove('copied');
        copy.title = 'Copy embed link';
        copy.setAttribute('aria-label', 'Copy embed link');
      }, 1200);
    } catch (err) {
      copy.title = 'Copy failed';
      setTimeout(() => { copy.title = 'Copy embed link'; }, 1200);
    }
    return;
  }
  const button = event.target.closest('button[data-delete]');
  if (!button) return;
  const title = button.dataset.title || 'this video';
  if (!confirm('Delete "' + title + '"? This removes the original, the published files, the job, and any collection key that points at it.')) return;
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
