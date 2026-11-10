const base=(window.VIDEO_BASE||'').replace(/\/$/,'');
function api(path){return base+path;}
function when(value){
 if(!value) return '—';
 const d=new Date(String(value).replace(' ','T')+'Z');
 if(Number.isNaN(d.getTime())) return value;
 const p=n=>String(n).padStart(2,'0');
 return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
}
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
const libraryPageSize=20;
let libraryPage=0;
const libraryCollection=document.getElementById('library-collection');
const librarySearch=document.getElementById('library-search');
const librarySort=document.getElementById('library-sort');
let libraryNames='';
async function loadCollections(){
 const r=await fetch(api('/api/collections'),{cache:'no-store'});
 if(!r.ok)return;
 const data=await r.json();
 const names=(data.collections||[]).join('\n');
 if(names===libraryNames)return;
 libraryNames=names;
 const selected=libraryCollection.value;
 libraryCollection.replaceChildren(new Option('All videos',''));
 for(const name of data.collections||[]) libraryCollection.append(new Option(name,name));
 if([...(libraryCollection.options)].some(option=>option.value===selected)) libraryCollection.value=selected;
}
async function loadLibrary(){
 try{
  await loadCollections();
  const [sort,dir]=(librarySort.value||'title:asc').split(':');
  const params=new URLSearchParams({limit:String(libraryPageSize),offset:String(libraryPage*libraryPageSize),sort,dir,q:librarySearch.value.trim(),collection:libraryCollection.value});
  const r=await fetch(api('/api/jobs?'+params),{cache:'no-store'});
  if(!r.ok)return;
  const data=await r.json();
  const jobs=data.jobs||[];
  const total=data.total||0;
  const tbody=document.getElementById('library');
  tbody.replaceChildren();
  if(!jobs.length && libraryPage>0 && total>0){libraryPage=Math.max(0,Math.ceil(total/libraryPageSize)-1);return loadLibrary();}
  if(!jobs.length){
   const tr=document.createElement('tr');
   const td=document.createElement('td');
   td.colSpan=5;td.className='muted';td.textContent='No videos';
   tr.append(td);tbody.append(tr);
  }
  for(const j of jobs){
   const tr=document.createElement('tr');
   const title=document.createElement('td');
   title.textContent=j.display_title||j.title;
   if((j.display_title||j.title)!==j.original_name){const small=document.createElement('small');small.textContent=j.original_name;title.append(small);}
   const sha=document.createElement('small');sha.className='sha';sha.textContent=j.id;title.append(sha);
   const where=document.createElement('td');
   if(!(j.placements||[]).length) where.textContent='—';
   else slotLines(where,j);
   const status=document.createElement('td');status.textContent=j.status;if(j.status==='failed')status.className='status-failed';
   const completed=document.createElement('td');
   completed.className='nowrap';
   completed.textContent=j.status==='completed'?when(j.finished_at):'—';
   const actions=document.createElement('td');
   if(j.status==='completed'){
    const watch=document.createElement('a');watch.href=j.watch_url;watch.target='_blank';watch.rel='noopener';watch.textContent='Watch';
    const embed=document.createElement('a');embed.href=j.embed_url;embed.target='_blank';embed.rel='noopener';embed.textContent='Embed';
    const pair=document.createElement('span');pair.className='nowrap';pair.append(embed,copyEmbedButton(j.embed_url));
    const caps=document.createElement('a');caps.href=api('/captions.php')+'?id='+encodeURIComponent(j.id);caps.textContent='Captions';
    actions.append(watch,' · ',pair,' · ',caps,' · ');
   }
   const del=document.createElement('button');del.textContent='Delete';del.dataset.delete=j.id;del.dataset.title=j.display_title||j.title;
   if(j.status==='processing') del.disabled=true;
   actions.append(del);
   tr.append(title,where,status,completed,actions);tbody.append(tr);
  }
  const start=total?libraryPage*libraryPageSize+1:0;
  document.getElementById('library-page').textContent=total?start+'–'+Math.min(total,start+jobs.length-1)+' of '+total:'0 videos';
  document.getElementById('library-prev').disabled=libraryPage<=0;
  document.getElementById('library-next').disabled=(libraryPage+1)*libraryPageSize>=total;
 }catch(e){console.warn('Could not load library',e)}
}
let libraryTimer=null;
function libraryChanged(){libraryPage=0;loadLibrary();}
libraryCollection.addEventListener('change',libraryChanged);
librarySort.addEventListener('change',libraryChanged);
librarySearch.addEventListener('input',()=>{clearTimeout(libraryTimer);libraryTimer=setTimeout(libraryChanged,200);});
document.getElementById('library-prev').addEventListener('click',()=>{if(libraryPage>0){libraryPage-=1;loadLibrary();}});
document.getElementById('library-next').addEventListener('click',()=>{libraryPage+=1;loadLibrary();});
document.querySelector('main').addEventListener('click', async (event) => {
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
    loadLibrary();
  } catch (err) {
    button.disabled = false;
    alert(err.message);
  }
});
loadLibrary();
