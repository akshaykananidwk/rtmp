/* ---------------------------------------------------------------------------
 * AK COMPUTER – visual overlay editor
 *
 * A WYSIWYG canvas the size of the real output: elements are dragged, resized
 * and locked directly on the picture (optionally with the live stream behind
 * it), and every change is written back into the form as percentages so the
 * layout survives a change of output resolution.
 * ------------------------------------------------------------------------- */
(function () {
  'use strict';

  var root = document.getElementById('overlay-editor');
  if (!root) return;

  var stage = root.querySelector('[data-stage]');
  var frame = root.querySelector('[data-frame]');
  var list = document.getElementById('element-list');
  var tpl = document.getElementById('element-template');
  var addMenu = root.querySelector('[data-add-menu]');
  var hint = root.querySelector('[data-hint]');

  var OUT = { w: 1920, h: 1080 };
  var scale = 1;
  var nextIndex = 0;
  var selected = null;

  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
  var round1 = function (v) { return Math.round(v * 10) / 10; };

  /* ---------------------------------------------------------------- layout */

  function readOutputSize() {
    var res = (document.querySelector('[name="resolution"]') || {}).value || '1920x1080';
    var parts = res.split('x');
    OUT.w = parseInt(parts[0], 10) || 1920;
    OUT.h = parseInt(parts[1], 10) || 1080;
    frame.style.width = OUT.w + 'px';
    frame.style.height = OUT.h + 'px';
    fit();
  }

  function fit() {
    var available = stage.clientWidth;
    scale = available / OUT.w;
    frame.style.transform = 'scale(' + scale + ')';
    stage.style.height = (OUT.h * scale) + 'px';
  }

  window.addEventListener('resize', fit);

  /* --------------------------------------------------------------- helpers */

  function fieldsOf(row) {
    var f = {};
    row.querySelectorAll('[data-field]').forEach(function (input) { f[input.dataset.field] = input; });
    return f;
  }

  function valueOf(row, name, fallback) {
    var f = fieldsOf(row)[name];
    if (!f) return fallback;
    if (f.type === 'checkbox') return f.checked;
    return f.value === '' ? fallback : f.value;
  }

  function setValue(row, name, value) {
    var f = fieldsOf(row)[name];
    if (!f) return;
    if (f.type === 'checkbox') { f.checked = !!value; return; }
    f.value = value;
  }

  /* ------------------------------------------------------------ box on stage */

  function boxFor(row) {
    return frame.querySelector('[data-box="' + row.dataset.index + '"]');
  }

  function renderBox(row) {
    var index = row.dataset.index;
    var type = valueOf(row, 'type', 'text');
    var enabled = valueOf(row, 'enabled', true);
    var locked = valueOf(row, 'locked', false);
    var box = boxFor(row);

    if (!box) {
      box = document.createElement('div');
      box.className = 'ov-box';
      box.dataset.box = index;
      box.innerHTML = '<div class="ov-content"></div>' +
        ['nw', 'ne', 'sw', 'se'].map(function (h) { return '<i class="ov-handle ov-' + h + '" data-handle="' + h + '"></i>'; }).join('');
      frame.appendChild(box);
    }

    var x = parseFloat(valueOf(row, 'x_pct', 5));
    var y = parseFloat(valueOf(row, 'y_pct', 80));
    var wPct = parseFloat(valueOf(row, 'w_pct', type === 'box' ? 100 : 15));
    var hPct = parseFloat(valueOf(row, 'h_pct', 10));
    var sizePct = parseFloat(valueOf(row, 'size_pct', 5));
    var color = valueOf(row, 'color', '#ffffff');
    var opacity = parseFloat(valueOf(row, 'opacity', 1));

    box.style.left = (x / 100 * OUT.w) + 'px';
    box.style.top = (y / 100 * OUT.h) + 'px';
    box.style.opacity = enabled ? 1 : 0.3;
    box.classList.toggle('locked', !!locked);
    box.classList.toggle('selected', selected === index);
    box.dataset.type = type;

    var content = box.querySelector('.ov-content');
    var fontPx = sizePct / 100 * OUT.h;

    if (type === 'image') {
      var src = row.dataset.imagePreview || '';
      box.style.width = (wPct / 100 * OUT.w) + 'px';
      box.style.height = 'auto';
      content.innerHTML = src
        ? '<img src="' + esc(src) + '" style="width:100%;display:block;opacity:' + opacity + '">'
        : '<div class="ov-placeholder" style="height:' + (wPct / 100 * OUT.w * 0.4) + 'px">LOGO</div>';
    } else if (type === 'box') {
      box.style.width = (wPct / 100 * OUT.w) + 'px';
      box.style.height = (hPct / 100 * OUT.h) + 'px';
      content.innerHTML = '';
      content.style.cssText = 'width:100%;height:100%;background:' + esc(color) + ';opacity:' + opacity;
    } else {
      var text = type === 'clock'
        ? (valueOf(row, 'format', 'H:i:s') || 'H:i:s').replace('H:i:s', '14:05:09').replace('d-m-Y H:i', '18-09-2026 14:05').replace('H:i', '14:05').replace('d-m-Y', '18-09-2026').replace('h:i A', '02:05 PM').replace('D, d M Y', 'Fri, 18 Sep 2026')
        : (valueOf(row, 'text', '') || (type === 'ticker' ? 'Scrolling ticker text' : 'Your text'));
      var bg = valueOf(row, 'background', '');
      box.style.width = 'auto';
      box.style.height = 'auto';
      content.style.cssText = '';
      content.innerHTML = '<span style="font:700 ' + fontPx + 'px/1.15 Inter,DejaVu Sans,sans-serif;color:' + esc(color) +
        ';opacity:' + opacity + ';white-space:nowrap;display:inline-block;padding:' + (fontPx / 4) + 'px' +
        (bg ? ';background:' + esc(bg) : '') + '">' + esc(text) + '</span>';
      if (type === 'ticker') {
        box.style.left = '0px';
        box.style.width = OUT.w + 'px';
        content.firstChild.style.marginLeft = (x / 100 * OUT.w) + 'px';
      }
    }
  }

  function renderAll() {
    list.querySelectorAll('[data-element]').forEach(renderBox);
    // drop boxes whose row was removed
    frame.querySelectorAll('[data-box]').forEach(function (box) {
      if (!list.querySelector('[data-element][data-index="' + box.dataset.box + '"]')) box.remove();
    });
    if (hint) hint.style.display = list.querySelector('[data-element]') ? 'none' : '';
  }

  function select(index) {
    selected = index;
    list.querySelectorAll('[data-element]').forEach(function (row) {
      row.classList.toggle('active', row.dataset.index === index);
    });
    var row = list.querySelector('[data-element][data-index="' + index + '"]');
    if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    renderAll();
  }

  /* ------------------------------------------------------------ interaction */

  var drag = null;

  frame.addEventListener('pointerdown', function (e) {
    var box = e.target.closest('[data-box]');
    if (!box) { selected = null; renderAll(); return; }
    var row = list.querySelector('[data-element][data-index="' + box.dataset.box + '"]');
    if (!row) return;

    select(box.dataset.box);
    if (valueOf(row, 'locked', false)) return;

    var handle = e.target.closest('[data-handle]');
    var rect = box.getBoundingClientRect();
    drag = {
      row: row, box: box, mode: handle ? 'resize' : 'move', handle: handle && handle.dataset.handle,
      startX: e.clientX, startY: e.clientY,
      origX: parseFloat(valueOf(row, 'x_pct', 5)), origY: parseFloat(valueOf(row, 'y_pct', 80)),
      origW: parseFloat(valueOf(row, 'w_pct', 15)), origH: parseFloat(valueOf(row, 'h_pct', 10)),
      origSize: parseFloat(valueOf(row, 'size_pct', 5)),
      pxW: rect.width / scale, pxH: rect.height / scale,
      type: valueOf(row, 'type', 'text'),
    };
    frame.setPointerCapture(e.pointerId);
    e.preventDefault();
  });

  frame.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var dx = (e.clientX - drag.startX) / scale;
    var dy = (e.clientY - drag.startY) / scale;

    if (drag.mode === 'move') {
      var nx = drag.origX + dx / OUT.w * 100;
      var ny = drag.origY + dy / OUT.h * 100;
      if (!e.shiftKey) {                       // snap to edges and centre
        [0, 50 - (drag.pxW / OUT.w * 100) / 2, 100 - drag.pxW / OUT.w * 100].forEach(function (t) { if (Math.abs(nx - t) < 1.2) nx = t; });
        [0, 50 - (drag.pxH / OUT.h * 100) / 2, 100 - drag.pxH / OUT.h * 100].forEach(function (t) { if (Math.abs(ny - t) < 1.2) ny = t; });
      }
      setValue(drag.row, 'x_pct', round1(clamp(nx, -20, 110)));
      setValue(drag.row, 'y_pct', round1(clamp(ny, -20, 110)));
    } else {
      var grow = (drag.handle === 'se' || drag.handle === 'ne') ? dx : -dx;
      if (drag.type === 'image' || drag.type === 'box') {
        setValue(drag.row, 'w_pct', round1(clamp(drag.origW + grow / OUT.w * 100, 1, 200)));
        if (drag.type === 'box') {
          var growY = (drag.handle === 'se' || drag.handle === 'sw') ? dy : -dy;
          setValue(drag.row, 'h_pct', round1(clamp(drag.origH + growY / OUT.h * 100, 0.5, 100)));
        }
      } else {
        setValue(drag.row, 'size_pct', round1(clamp(drag.origSize + grow / OUT.w * 100 * 1.5, 0.8, 30)));
      }
    }

    renderBox(drag.row);
    e.preventDefault();
  });

  ['pointerup', 'pointercancel'].forEach(function (evt) {
    frame.addEventListener(evt, function () { drag = null; });
  });

  // Arrow keys nudge the selected element
  document.addEventListener('keydown', function (e) {
    if (!selected || ['INPUT', 'TEXTAREA', 'SELECT'].indexOf((document.activeElement || {}).tagName) !== -1) return;
    var step = e.shiftKey ? 2 : 0.2;
    var map = { ArrowLeft: ['x_pct', -step], ArrowRight: ['x_pct', step], ArrowUp: ['y_pct', -step], ArrowDown: ['y_pct', step] };
    if (!map[e.key]) return;
    var row = list.querySelector('[data-element][data-index="' + selected + '"]');
    if (!row || valueOf(row, 'locked', false)) return;
    setValue(row, map[e.key][0], round1(parseFloat(valueOf(row, map[e.key][0], 0)) + map[e.key][1]));
    renderBox(row);
    e.preventDefault();
  });

  /* ------------------------------------------------------------- form wiring */

  list.addEventListener('input', function (e) {
    var row = e.target.closest('[data-element]');
    if (row) renderBox(row);
  });
  list.addEventListener('change', function (e) {
    var row = e.target.closest('[data-element]');
    if (!row) return;
    if (e.target.dataset.field === 'type') applyTypeVisibility(row);
    if (e.target.dataset.field === 'image_file' && e.target.files && e.target.files[0]) {
      var reader = new FileReader();
      reader.onload = function (ev) { row.dataset.imagePreview = ev.target.result; renderBox(row); };
      reader.readAsDataURL(e.target.files[0]);
    }
    renderBox(row);
  });

  list.addEventListener('click', function (e) {
    var row = e.target.closest('[data-element]');
    if (!row) return;
    if (e.target.closest('[data-remove]')) { e.preventDefault(); row.remove(); renderAll(); return; }
    if (e.target.closest('[data-center-h]')) { e.preventDefault(); centre(row, 'x'); return; }
    if (e.target.closest('[data-center-v]')) { e.preventDefault(); centre(row, 'y'); return; }
    if (e.target.closest('[data-full-width]')) { e.preventDefault(); setValue(row, 'x_pct', 0); setValue(row, 'w_pct', 100); renderBox(row); return; }
    select(row.dataset.index);
  });

  function centre(row, axis) {
    var box = boxFor(row);
    var sizePct = box ? (axis === 'x' ? box.getBoundingClientRect().width / scale / OUT.w : box.getBoundingClientRect().height / scale / OUT.h) * 100 : 10;
    setValue(row, axis + '_pct', round1(50 - sizePct / 2));
    renderBox(row);
  }

  function applyTypeVisibility(row) {
    var type = valueOf(row, 'type', 'text');
    row.querySelectorAll('[data-for]').forEach(function (f) {
      f.style.display = f.dataset.for.split(',').indexOf(type) === -1 ? 'none' : '';
    });
    var label = row.querySelector('[data-type-label]');
    if (label) label.textContent = ({ text: '🅣 Text', ticker: '🄣 Ticker', clock: '🕐 Clock', image: '🖼 Logo', box: '▭ Bar' })[type] || type;
  }

  if (addMenu) {
    addMenu.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-add]');
      if (!btn) return;
      e.preventDefault();
      addElement(btn.dataset.add);
    });
  }

  function addElement(type) {
    var html = tpl.innerHTML.replace(/__INDEX__/g, String(nextIndex));
    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    var row = wrap.firstElementChild;
    row.dataset.index = String(nextIndex);
    list.appendChild(row);

    setValue(row, 'type', type);
    var defaults = {
      text: { x_pct: 4, y_pct: 82, size_pct: 4.5, color: '#ffffff', text: 'Your headline' },
      ticker: { x_pct: 0, y_pct: 92, size_pct: 2.8, color: '#a5f3fc', text: 'Scrolling ticker text — edit me', speed: 120 },
      clock: { x_pct: 80, y_pct: 4, size_pct: 3.2, color: '#ffffff', format: 'd-m-Y H:i' },
      image: { x_pct: 3, y_pct: 4, w_pct: 12 },
      box: { x_pct: 0, y_pct: 80, w_pct: 100, h_pct: 14, color: '#0b0f1a', opacity: 0.75 },
    }[type] || {};
    Object.keys(defaults).forEach(function (k) { setValue(row, k, defaults[k]); });

    applyTypeVisibility(row);
    nextIndex++;
    select(row.dataset.index);
    renderAll();
  }

  /* ------------------------------------------------------------------ start */

  list.querySelectorAll('[data-element]').forEach(function (row) {
    nextIndex = Math.max(nextIndex, parseInt(row.dataset.index, 10) + 1);
    applyTypeVisibility(row);
  });

  var resolution = document.querySelector('[name="resolution"]');
  if (resolution) resolution.addEventListener('change', function () { readOutputSize(); renderAll(); });

  readOutputSize();
  renderAll();
})();
