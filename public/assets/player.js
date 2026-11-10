(() => {
  const embedCode = document.getElementById('embed-code');
  if (embedCode) embedCode.addEventListener('click', () => embedCode.select());

  const v = document.getElementById('video');
  if (!v) return;
  const url = v.dataset.hls;

  if (v.canPlayType('application/vnd.apple.mpegurl')) {
    v.src = url;
    return;
  }
  if (window.Hls && Hls.isSupported()) {
    const hls = new Hls({ enableWorker: true });
    hls.loadSource(url);
    hls.attachMedia(v);
    hls.on(Hls.Events.ERROR, (_, data) => {
      if (data.fatal) document.getElementById('play-error').textContent = 'Playback error: ' + data.details;
    });
    // Firefox will not paint a <track> on a Media Source video. Draw the cue instead.
    if (/Firefox\//.test(navigator.userAgent)) firefoxCaptions(v, hls);
    return;
  }
  document.getElementById('play-error').textContent = 'HLS is unsupported in this browser. Install the local hls.js bundle using bin/install-hlsjs.sh.';
})();

function firefoxCaptions(video, hls) {
  const trackEls = [...video.querySelectorAll('track')];
  if (!trackEls.length) return;
  video.classList.add('firefox-captions');

  const box = document.createElement('div');
  box.className = 'captions';
  video.parentElement.append(box);

  const cues = new Map();

  function showing() {
    return trackEls.find((el) => el.track && el.track.mode === 'showing') || null;
  }

  function render() {
    box.replaceChildren();
    const el = showing();
    if (!el) return;
    const time = video.currentTime || 0;
    for (const cue of cues.get(el) || []) {
      if (time < cue.start || time >= cue.end) continue;
      const line = document.createElement('span');
      line.textContent = cue.text;
      box.append(line);
    }
  }

  Promise.all(trackEls.map((el) => fetch(el.src).then((response) => response.text()).then((text) => {
    cues.set(el, parseVtt(text));
  }))).then(render).catch(() => {});

  // hls.js attaches after the track is parsed and can leave a default track disabled.
  function showDefault() {
    const preferred = trackEls.find((el) => el.default) || trackEls[0];
    const track = preferred.track;
    if (track && track.mode !== 'showing') track.mode = 'showing';
    render();
  }

  hls.on(Hls.Events.MANIFEST_PARSED, showDefault);
  video.addEventListener('timeupdate', render);
  video.addEventListener('seeked', render);
  video.textTracks.addEventListener('change', render);
}

function parseVtt(text) {
  const cues = [];
  for (const block of text.replace(/\r/g, '').split(/\n\n+/)) {
    const lines = block.split('\n').filter((line) => line.trim() !== '');
    const timingIndex = lines.findIndex((line) => line.includes('-->'));
    if (timingIndex < 0) continue;
    const [start, end] = lines[timingIndex].split('-->').map((part) => vttTime(part.trim().split(/\s+/)[0]));
    cues.push({ start, end, text: lines.slice(timingIndex + 1).join('\n').trim() });
  }
  return cues;
}

function vttTime(value) {
  const parts = value.split(':').map(Number);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  return parts[0] * 60 + parts[1];
}
