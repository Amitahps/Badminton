(function () {
  var file = document.getElementById('payment_screenshot');
  var b64 = document.getElementById('payment_screenshot_b64');
  var overlay = document.getElementById('crop-overlay');
  var canvas = document.getElementById('crop-canvas');
  var kbEl = document.getElementById('crop-kb');
  var useBtn = document.getElementById('crop-use');
  var cancelBtn = document.getElementById('crop-cancel');
  if (!file || !canvas || !overlay) return;
  var ctx = canvas.getContext('2d');
  var img = new Image();
  var natW = 0, natH = 0, viewW = 0, viewH = 0;
  var crop = { x: 0, y: 0, w: 0, h: 0 };
  var drag = null;

  function fit() {
    var max = Math.min(520, window.innerWidth - 48);
    var scale = Math.min(max / natW, 420 / natH, 1);
    viewW = Math.max(1, Math.round(natW * scale));
    viewH = Math.max(1, Math.round(natH * scale));
    canvas.width = viewW;
    canvas.height = viewH;
    crop = { x: viewW * 0.08, y: viewH * 0.08, w: viewW * 0.84, h: viewH * 0.84 };
    draw();
  }
  function draw() {
    ctx.clearRect(0, 0, viewW, viewH);
    ctx.drawImage(img, 0, 0, viewW, viewH);
    ctx.fillStyle = 'rgba(0,0,0,0.45)';
    ctx.fillRect(0, 0, viewW, viewH);
    ctx.clearRect(crop.x, crop.y, crop.w, crop.h);
    ctx.drawImage(img, crop.x * natW / viewW, crop.y * natH / viewH, crop.w * natW / viewW, crop.h * natH / viewH, crop.x, crop.y, crop.w, crop.h);
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 2;
    ctx.strokeRect(crop.x, crop.y, crop.w, crop.h);
  }
  function inside(x, y) {
    return x >= crop.x && y >= crop.y && x <= crop.x + crop.w && y <= crop.y + crop.h;
  }
  function pos(e) {
    var r = canvas.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }
  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('touchstart', start, { passive: false });
  function start(e) {
    e.preventDefault();
    var p = pos(e);
    drag = inside(p.x, p.y)
      ? { mode: 'move', dx: p.x - crop.x, dy: p.y - crop.y }
      : { mode: 'new', x: p.x, y: p.y };
  }
  window.addEventListener('mousemove', move);
  window.addEventListener('touchmove', move, { passive: false });
  function move(e) {
    if (!drag) return;
    e.preventDefault();
    var p = pos(e);
    if (drag.mode === 'move') {
      crop.x = Math.min(Math.max(0, p.x - drag.dx), viewW - crop.w);
      crop.y = Math.min(Math.max(0, p.y - drag.dy), viewH - crop.h);
    } else {
      crop.x = Math.max(0, Math.min(drag.x, p.x));
      crop.y = Math.max(0, Math.min(drag.y, p.y));
      crop.w = Math.min(viewW - crop.x, Math.abs(p.x - drag.x));
      crop.h = Math.min(viewH - crop.y, Math.abs(p.y - drag.y));
    }
    draw();
  }
  window.addEventListener('mouseup', function () { drag = null; });
  window.addEventListener('touchend', function () { drag = null; });

  function compress(dataUrl, cb) {
    var work = document.createElement('canvas');
    var im = new Image();
    im.onload = function () {
      var max = 1400;
      var w = im.width, h = im.height;
      if (w > max || h > max) {
        var s = max / Math.max(w, h);
        w = Math.round(w * s); h = Math.round(h * s);
      }
      work.width = w; work.height = h;
      work.getContext('2d').drawImage(im, 0, 0, w, h);
      var q = 0.78;
      var out = work.toDataURL('image/jpeg', q);
      while (out.length > 240000 && q > 0.45) {
        q -= 0.08;
        out = work.toDataURL('image/jpeg', q);
      }
      var kb = Math.round(out.length * 0.75 / 1024);
      cb(out, kb);
    };
    im.src = dataUrl;
  }

  file.addEventListener('change', function () {
    var f = file.files && file.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function () {
      img.onload = function () {
        natW = img.naturalWidth; natH = img.naturalHeight;
        overlay.classList.remove('is-hidden');
        fit();
      };
      img.src = String(reader.result || '');
    };
    reader.readAsDataURL(f);
  });

  if (useBtn) useBtn.addEventListener('click', function () {
    var sx = crop.x * natW / viewW, sy = crop.y * natH / viewH;
    var sw = crop.w * natW / viewW, sh = crop.h * natH / viewH;
    var cut = document.createElement('canvas');
    cut.width = Math.max(1, Math.round(sw));
    cut.height = Math.max(1, Math.round(sh));
    cut.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, cut.width, cut.height);
    compress(cut.toDataURL('image/jpeg', 0.85), function (data, kb) {
      if (b64) b64.value = data;
      if (kbEl) kbEl.textContent = 'Saved at about ' + kb + ' KB — still clear to read.';
      file.required = false;
      overlay.classList.add('is-hidden');
    });
  });
  if (cancelBtn) cancelBtn.addEventListener('click', function () {
    overlay.classList.add('is-hidden');
    file.value = '';
    if (b64) b64.value = '';
    if (kbEl) kbEl.textContent = '';
  });
  var form = file.form;
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!overlay.classList.contains('is-hidden')) {
        e.preventDefault();
        return;
      }
      if (file.files && file.files[0] && b64 && b64.value === '') {
        e.preventDefault();
        overlay.classList.remove('is-hidden');
      }
    });
  }
})();
