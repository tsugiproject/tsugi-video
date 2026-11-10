(() => {
  const dataEl = document.getElementById('playlist-data');
  const frame = document.getElementById('playlist-player');
  const title = document.getElementById('playlist-title');
  const key = document.getElementById('playlist-key');
  const description = document.getElementById('playlist-description');
  const list = document.getElementById('playlist');
  if (!dataEl || !frame || !title || !key || !list) return;

  const data = JSON.parse(dataEl.textContent);
  const buttons = [...list.querySelectorAll('button')];
  if (buttons.length === 0) return;

  function show(index, mode) {
    const video = data.videos[index];
    if (!video) return;
    if (frame.getAttribute('src') !== video.embed_path) frame.src = video.embed_path;
    frame.title = video.title;
    title.textContent = video.title;
    key.textContent = video.logical_key;
    if (description) description.textContent = video.description || '';
    document.title = video.title + ' · ' + data.collection;
    buttons.forEach((button, i) => {
      const on = i === index;
      if (on) button.setAttribute('aria-current', 'true');
      else button.removeAttribute('aria-current');
      if (!on) return;
      const top = button.offsetTop - list.offsetTop;
      const above = top < list.scrollTop;
      const below = top + button.offsetHeight > list.scrollTop + list.clientHeight;
      if (above || below) list.scrollTop = top - (list.clientHeight - button.offsetHeight) / 2;
    });
    const url = new URL(location.href);
    url.searchParams.set('v', video.logical_key);
    const state = { index };
    if (mode === 'push') history.pushState(state, '', url);
    else if (mode === 'replace') history.replaceState(state, '', url);
  }

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const index = Number(button.dataset.index);
      if (button.getAttribute('aria-current') === 'true') return;
      show(index, 'push');
    });
  });

  window.addEventListener('popstate', (event) => {
    const index = event.state && Number.isInteger(event.state.index) ? event.state.index : 0;
    show(index, 'none');
  });

  const initial = buttons.findIndex((button) => button.getAttribute('aria-current') === 'true');
  show(initial >= 0 ? initial : 0, 'replace');
})();
