const base = (window.VIDEO_BASE || '').replace(/\/$/, '');
function api(path) { return base + path; }

function when(value) {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T') + 'Z');
  if (Number.isNaN(date.getTime())) return value;
  const pad = (n) => String(n).padStart(2, '0');
  return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
}
document.querySelectorAll('time[data-utc]').forEach((el) => { el.textContent = when(el.dataset.utc); });

document.querySelector('main').addEventListener('click', async (event) => {
  const copy = event.target.closest('button[data-copy]');
  if (copy) {
    try {
      await navigator.clipboard.writeText(copy.dataset.copy);
      copy.textContent = 'Copied';
      setTimeout(() => { copy.textContent = 'Copy embed'; }, 1200);
    } catch (err) {
      copy.textContent = 'Copy failed';
      setTimeout(() => { copy.textContent = 'Copy embed'; }, 1200);
    }
    return;
  }
  if (event.target.id === 'retry') {
    event.target.disabled = true;
    const response = await fetch(api('/api/jobs/' + window.JOB_ID + '/retry'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    if (!response.ok) {
      event.target.disabled = false;
      const data = await response.json().catch(() => ({}));
      alert(data.error || 'Retry failed');
      return;
    }
    location.reload();
    return;
  }
  if (event.target.id !== 'delete') return;
  const title = event.target.dataset.title || 'this video';
  if (!confirm('Delete "' + title + '"? This removes the original, the published files, the job, and any collection key that points at it.')) return;
  event.target.disabled = true;
  const response = await fetch(api('/api/jobs/' + window.JOB_ID), {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
  });
  if (!response.ok) {
    event.target.disabled = false;
    const data = await response.json().catch(() => ({}));
    alert(data.error || 'Delete failed');
    return;
  }
  location.href = api('/');
});
