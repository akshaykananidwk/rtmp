/* AK COMPUTER – ONE LIVE EVERYWHERE : vanilla JS helpers (no build step) */
(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';

  // Mobile menus
  $$('[data-toggle]').forEach(btn => btn.addEventListener('click', () => { const t = $(btn.dataset.toggle); t && t.classList.toggle('open'); }));

  // Copy buttons: data-copy="#selector" or data-copy-text="..."
  async function copyText(text) {
    try { await navigator.clipboard.writeText(text); return true; }
    catch (e) { const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); const ok = document.execCommand('copy'); ta.remove(); return ok; }
  }
  document.addEventListener('click', async ev => {
    const btn = ev.target.closest('[data-copy],[data-copy-text],[data-copy-reveal]');
    if (!btn) return;
    ev.preventDefault();
    let text = btn.dataset.copyText;
    if (btn.dataset.copy) { const el = $(btn.dataset.copy); text = el ? (el.value !== undefined ? el.value : el.textContent) : ''; }
    if (btn.dataset.copyReveal) {
      btn.disabled = true;
      try {
        const res = await fetch(btn.dataset.copyReveal, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } });
        const j = await res.json(); text = j.key || '';
      } catch (e) { text = ''; } finally { btn.disabled = false; }
    }
    if (!text) return;
    const ok = await copyText(text.trim());
    const old = btn.innerHTML; btn.innerHTML = ok ? '✓ Copied' : 'Copy failed'; setTimeout(() => btn.innerHTML = old, 1600);
  });

  // Reveal secret key (fetches plaintext once, shows for 20s)
  document.addEventListener('click', async ev => {
    const btn = ev.target.closest('[data-reveal]');
    if (!btn) return;
    ev.preventDefault();
    const target = $(btn.dataset.target);
    if (!target) return;
    if (target.dataset.revealed === '1') { target.value = target.dataset.masked; target.dataset.revealed = '0'; btn.textContent = 'Show'; return; }
    btn.disabled = true;
    try {
      const res = await fetch(btn.dataset.reveal, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } });
      const j = await res.json();
      if (j.key) { target.dataset.masked = target.value; target.value = j.key; target.dataset.revealed = '1'; btn.textContent = 'Hide'; setTimeout(() => { if (target.dataset.revealed === '1') { target.value = target.dataset.masked; target.dataset.revealed = '0'; btn.textContent = 'Show'; } }, 20000); }
    } catch (e) { alert('Could not reveal key'); } finally { btn.disabled = false; }
  });

  // Confirm dangerous forms: <form data-confirm="message">
  document.addEventListener('submit', ev => {
    const f = ev.target;
    if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) ev.preventDefault();
    if (f.dataset.confirmWord) {
      const w = window.prompt(f.dataset.confirm || ('Type ' + f.dataset.confirmWord + ' to continue'));
      if (w !== f.dataset.confirmWord) { ev.preventDefault(); return; }
      let inp = f.querySelector('input[name="confirm"]');
      if (!inp) { inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'confirm'; f.appendChild(inp); }
      inp.value = w;
    }
  });

  // Live status polling
  const fmtDuration = s => { s = Math.max(0, s | 0); const h = String(Math.floor(s / 3600)).padStart(2, '0'), m = String(Math.floor(s % 3600 / 60)).padStart(2, '0'), x = String(s % 60).padStart(2, '0'); return `${h}:${m}:${x}`; };
  const badge = st => `<span class="badge badge-${st}">${st === 'live' ? '🟢 ' : (['connecting', 'reconnecting', 'pending', 'preparing'].includes(st) ? '🟡 ' : (st === 'failed' ? '🔴 ' : '⚪ '))}${st.replace('_', ' ')}</span>`;
  window.AK = { badge, fmtDuration, copyText };

  const status = $('[data-status-url]');
  if (status) {
    const url = status.dataset.statusUrl;
    let startedAt = null;
    const render = p => {
      const live = p.live;
      $$('[data-live-indicator]').forEach(el => { el.className = 'live-indicator ' + (live ? 'on' : 'off'); el.innerHTML = live ? '<span class="dot pulse"></span> LIVE' : '<span class="dot"></span> OFFLINE'; });
      const s = p.session || {};
      startedAt = s.started_at ? new Date(s.started_at) : null;
      const set = (k, v) => $$(`[data-field="${k}"]`).forEach(el => el.textContent = (v === null || v === undefined || v === '') ? '—' : v);
      set('title', s.title); set('endpoint', s.endpoint); set('resolution', s.resolution); set('fps', s.fps); set('codec', s.video_codec ? `${s.video_codec}/${s.audio_codec || '?'}` : null);
      set('incoming', s.incoming_bitrate != null ? s.incoming_bitrate + ' kbps' : null); set('outgoing', s.outgoing_bitrate != null ? s.outgoing_bitrate + ' kbps' : null);
      set('recording', s.recording === undefined ? null : (s.recording ? 'Enabled' : 'Disabled')); set('started', startedAt ? startedAt.toLocaleTimeString() : null);
      set('viewers', p.viewers); set('dest_total', p.summary.total); set('dest_live', p.summary.live); set('dest_failed', p.summary.failed); set('dest_connecting', p.summary.connecting);
      const list = $('[data-destinations]');
      if (list) {
        list.innerHTML = p.destinations.length ? p.destinations.map(d => `<div class="dest-row"><div><div class="name">${esc(d.name || '')} <span class="pill">${esc(d.platform || '')}</span></div><div class="meta">${d.last_error ? '⚠ ' + esc(d.last_error) + (d.last_error_at ? ' · ' + esc(d.last_error_at) : '') : (d.last_success_at ? 'OK ' + esc(d.last_success_at) : '')}${d.retry_count ? ' · retries: ' + d.retry_count : ''}${d.bitrate ? ' · ' + d.bitrate + ' kbps' : ''}${d.watch_url ? ' · <a href="' + esc(d.watch_url) + '" target="_blank" rel="noopener">watch</a>' : ''}</div></div><div style="display:flex;gap:6px;align-items:center">${badge(d.status)}${list.dataset.canControl === '1' ? `<form method="post" action="${list.dataset.restartUrl.replace('__ID__', d.id)}"><input type="hidden" name="_token" value="${csrf()}"><button class="btn btn-sm btn-outline" title="Restart">↻</button></form><form method="post" action="${list.dataset.stopUrl.replace('__ID__', d.id)}" data-confirm="Stop this destination?"><input type="hidden" name="_token" value="${csrf()}"><button class="btn btn-sm btn-outline" title="Stop">■</button></form>` : ''}</div></div>`).join('') : '<div class="empty">No destinations active</div>';
      }
    };
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const poll = async () => { try { const r = await fetch(url, { headers: { 'Accept': 'application/json' } }); if (r.ok) render(await r.json()); } catch (e) { } };
    setInterval(() => { if (startedAt) $$('[data-field="duration"]').forEach(el => el.textContent = fmtDuration((Date.now() - startedAt.getTime()) / 1000)); }, 1000);
    if (status.dataset.initial) { try { render(JSON.parse(status.dataset.initial)); } catch (e) { } }
    setInterval(poll, parseInt(status.dataset.interval || '5000', 10));
  }

  // Log polling
  const logBox = $('[data-logs-url]');
  if (logBox) {
    let after = parseInt(logBox.dataset.after || '0', 10);
    const esc = s => String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    const tick = async () => {
      try {
        const r = await fetch(logBox.dataset.logsUrl + '?after=' + after, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) return; const j = await r.json();
        j.logs.forEach(l => { after = Math.max(after, l.id); const d = document.createElement('div'); d.className = 'log-line log-' + l.level; d.innerHTML = `<span class="log-time">${l.time}</span><span>${esc(l.message)}</span>`; logBox.appendChild(d); });
        if (j.logs.length) logBox.scrollTop = logBox.scrollHeight;
        while (logBox.children.length > 400) logBox.removeChild(logBox.firstChild);
      } catch (e) { }
    };
    setInterval(tick, 3000); logBox.scrollTop = logBox.scrollHeight;
  }

  // Update progress polling
  const upd = $('[data-update-status-url]');
  if (upd) {
    const box = $('#update-logs'); const esc = s => String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    const tick = async () => {
      try {
        const r = await fetch(upd.dataset.updateStatusUrl, { headers: { 'Accept': 'application/json' } }); if (!r.ok) return; const j = await r.json();
        $$('[data-update-state]').forEach(el => { el.textContent = j.status; el.className = 'badge badge-' + j.state; });
        if (box) { box.innerHTML = j.logs.map(l => `<div class="log-line log-${l.level}"><span class="log-time">${l.time}</span><span>[${esc(l.step || '')}] ${esc(l.message)}</span></div>`).join(''); box.scrollTop = box.scrollHeight; }
        if (j.finished) { clearInterval(timer); setTimeout(() => location.reload(), 1500); }
      } catch (e) { }
    };
    const timer = setInterval(tick, 2500); tick();
  }

  // Platform field switching in destination form
  const platformSel = $('#platform-select');
  if (platformSel) {
    const apply = () => {
      const p = platformSel.value;
      $$('[data-platform-fields]').forEach(el => {
        const on = el.dataset.platformFields === p;
        el.style.display = on ? '' : 'none';
        // Hiding a field does not stop the browser submitting it. Disable the platforms the
        // operator did not choose so their boxes (and any key typed before switching) stay out
        // of the request entirely.
        el.querySelectorAll('input, select, textarea').forEach(i => { i.disabled = !on; });
      });
      $$('[data-platform-info]').forEach(el => el.style.display = el.dataset.platformInfo === p ? '' : 'none');
    };
    platformSel.addEventListener('change', apply); apply();
  }

  // Installer DB test
  const dbTest = $('#db-test');
  if (dbTest) dbTest.addEventListener('click', async () => {
    const form = dbTest.closest('form'); const out = $('#db-test-result'); out.textContent = 'Testing…'; out.className = 'alert alert-info';
    const fd = new FormData(form);
    try { const r = await fetch(dbTest.dataset.url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() } }); const j = await r.json(); out.textContent = j.message || (j.errors ? Object.values(j.errors).flat().join(' ') : 'Error'); out.className = 'alert ' + (j.ok ? 'alert-success' : 'alert-error'); $('#db-continue').disabled = !j.ok; }
    catch (e) { out.textContent = 'Request failed'; out.className = 'alert alert-error'; }
  });

  // Auto-submit installer run
  const runForm = $('#install-run-form');
  if (runForm && runForm.dataset.auto === '1') setTimeout(() => runForm.submit(), 600);
})();

/* ---------------------------------------------------------------------------
 * Live preview: plays the authenticated HLS proxy, switching between the
 * branded output and the raw OBS source.
 * ------------------------------------------------------------------------- */
(function () {
  'use strict';
  var box = document.querySelector('[data-preview]');
  if (!box) return;

  var video = box.querySelector('[data-preview-video]');
  var placeholder = box.querySelector('[data-preview-placeholder]');
  var message = box.querySelector('[data-preview-message]');
  var info = box.querySelector('[data-preview-info]');
  var rawToggle = box.querySelector('[data-preview-raw]');
  var statusUrl = box.dataset.preview;
  var hls = null, currentUrl = null;

  function show(on, text) {
    video.style.display = on ? '' : 'none';
    placeholder.style.display = on ? 'none' : 'flex';
    if (text) message.textContent = text;
  }

  function stop() {
    if (hls) { hls.destroy(); hls = null; }
    video.removeAttribute('src'); video.load();
    currentUrl = null;
  }

  function play(url) {
    if (url === currentUrl) return;
    stop();
    currentUrl = url;
    if (window.Hls && window.Hls.isSupported()) {
      hls = new window.Hls({ liveSyncDurationCount: 3, lowLatencyMode: false, manifestLoadingMaxRetry: 6 });
      hls.loadSource(url);
      hls.attachMedia(video);
      hls.on(window.Hls.Events.MANIFEST_PARSED, function () { show(true); video.play().catch(function () {}); });
      hls.on(window.Hls.Events.ERROR, function (_e, data) {
        if (!data.fatal) return;
        if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) { hls.startLoad(); }
        else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) { hls.recoverMediaError(); }
        else { stop(); show(false, 'Preview could not start – is the stream still running?'); }
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = url; show(true); video.play().catch(function () {});
    } else {
      show(false, 'This browser cannot play HLS previews.');
    }
  }

  function tick() {
    fetch(statusUrl, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        if (!j.live) { stop(); show(false, 'Waiting for a stream… start streaming in OBS.'); if (info) info.textContent = 'No incoming stream'; return; }
        var wantRaw = rawToggle && rawToggle.checked;
        var url = (wantRaw && j.raw_playlist) ? j.raw_playlist : j.playlist;
        if (info) {
          info.textContent = (j.branded ? 'Branded output' + (j.overlay ? ' · ' + j.overlay : '') : 'Direct from OBS')
            + (j.resolution ? ' · ' + j.resolution : '') + (j.fps ? ' @ ' + j.fps + ' fps' : '');
        }
        if (url) play(url);
      })
      .catch(function () {});
  }

  if (rawToggle) rawToggle.addEventListener('change', function () { currentUrl = null; tick(); });
  var reload = box.querySelector('[data-preview-reload]');
  if (reload) reload.addEventListener('click', function () { stop(); tick(); });

  tick();
  setInterval(tick, 8000);
})();
