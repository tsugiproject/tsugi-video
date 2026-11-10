const base = (window.VIDEO_BASE || '').replace(/\/$/, '');
function api(path) { return base + path; }

const form = document.getElementById('caption-upload');
const fileInput = form.querySelector('input[type=file]');
const fileName = document.getElementById('caption-file');
const status = document.getElementById('caption-status');
const names = window.CAPTION_NAMES || {};

fileInput.addEventListener('change', () => {
  fileName.textContent = fileInput.files[0] ? fileInput.files[0].name : 'No file chosen';
});

function labelFor(code) {
  return names[code] || code.toUpperCase();
}

function paint(tracks) {
  const tbody = document.getElementById('tracks');
  tbody.replaceChildren();
  if (!tracks.length) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 3;
    td.className = 'muted';
    td.textContent = 'No captions yet.';
    tr.append(td);
    tbody.append(tr);
    return;
  }
  for (const track of tracks) {
    const tr = document.createElement('tr');
    const language = document.createElement('td');
    language.append(labelFor(track.language), ' ');
    const code = document.createElement('small');
    code.textContent = track.language;
    language.append(code);
    const file = document.createElement('td');
    file.className = 'nowrap';
    file.append(track.file + ' ');
    const link = document.createElement('a');
    link.href = api('/media/' + window.CAPTION_JOB.slice(0, 2) + '/' + window.CAPTION_JOB.slice(2, 4) + '/' + window.CAPTION_JOB + '/' + track.file);
    link.download = track.file;
    link.textContent = 'Download';
    file.append(link);
    const action = document.createElement('td');
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.remove = track.language;
    button.dataset.label = labelFor(track.language);
    button.textContent = 'Remove';
    action.append(button);
    tr.append(language, file, action);
    tbody.append(tr);
  }
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  status.textContent = 'Uploading…';
  const body = new FormData();
  body.set('language', form.language.value.trim().toLowerCase());
  body.set('file', fileInput.files[0]);
  try {
    const response = await fetch(api('/api/jobs/' + window.CAPTION_JOB + '/captions'), { method: 'POST', body });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Upload failed');
    paint(data.tracks || []);
    form.reset();
    form.language.value = 'en';
    fileName.textContent = 'No file chosen';
    status.textContent = 'Saved ' + labelFor(data.track.language) + '.';
  } catch (error) {
    status.textContent = error.message;
  }
});

document.querySelector('main').addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-remove]');
  if (!button) return;
  const label = button.dataset.label || button.dataset.remove;
  if (!confirm('Remove the ' + label + ' captions?')) return;
  button.disabled = true;
  status.textContent = '';
  try {
    const response = await fetch(api('/api/jobs/' + window.CAPTION_JOB + '/captions/' + button.dataset.remove), {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Remove failed');
    paint(data.tracks || []);
    status.textContent = 'Removed ' + label + '.';
  } catch (error) {
    button.disabled = false;
    status.textContent = error.message;
  }
});
