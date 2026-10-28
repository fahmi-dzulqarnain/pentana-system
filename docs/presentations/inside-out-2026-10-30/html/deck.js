// Inside Out browser deck. Renders window.DECK (from deck-data.js, written by deck_src.py).
// Audience: index.html (press F for full screen, P to open the presenter window).
// Presenter: index.html?presenter (opened by P; mirrors and drives the audience window).
// Tests/previews: index.html?static#<slide-id> renders one slide with no animation.
(() => {
  const SLIDES = window.DECK.slides;
  const params = new URLSearchParams(location.search);
  const STATIC = params.has('static');
  const PRESENTER = params.has('presenter');
  const BG = { navy: '../assets/bg-navy.png', glow: '../assets/bg-glow.png' };
  const FONTS = {
    'Manrope-ExtraBold': ["'Manrope', sans-serif", 800, 'display'],
    'Manrope-Bold': ["'Manrope', sans-serif", 700, 'display'],
    'HelveticaNeue': ["'Helvetica Neue', Helvetica, sans-serif", 400, 'body'],
    'HelveticaNeue-Medium': ["'Helvetica Neue', Helvetica, sans-serif", 500, 'body'],
    'HelveticaNeue-Bold': ["'Helvetica Neue', Helvetica, sans-serif", 700, 'body'],
  };
  const START = { h: 15, m: 30 };   // 3:30 PM MYT

  // ------------------------------------------------------------------ rendering
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  // Colour the n-th words orange; "words" are letter/number runs, matching Keynote's word count.
  function richText(text, accent = []) {
    if (!accent.length) return esc(text);
    let n = -1;
    return text.replace(/[\p{L}\p{N}'’]+|[^\p{L}\p{N}'’]+/gu, (tok) => {
      if (!/[\p{L}\p{N}]/u.test(tok)) return esc(tok);
      n += 1;
      return accent.includes(n) ? `<span class="accent">${esc(tok)}</span>` : esc(tok);
    });
  }

  // Every element gets a key; equal keys on consecutive slides glide (Magic Move-style).
  function elements(slide) {
    const out = [];
    for (const im of slide.images) {
      if (im.file.endsWith('live-dot.gif')) {
        out.push({ key: 'live-dot', kind: 'dot', x: im.x, y: im.y, w: im.w, h: im.h, rot: 0 });
      } else {
        out.push({ key: `img:${im.file}`, kind: 'img', file: im.file, x: im.x, y: im.y, w: im.w, h: im.h,
                   rot: im.rotation || 0, opacity: (im.opacity ?? 100) / 100 });
      }
    }
    for (const t of slide.texts) {
      out.push({ key: `txt:${t.text}`, kind: 'txt', t, x: t.x, y: t.y, w: t.w, rot: 0 });
    }
    return out;
  }

  function makeEl(e) {
    let node;
    if (e.kind === 'img') {
      node = document.createElement('img');
      node.src = `../${e.file}`;
      node.className = 'el img';
      node.style.height = `${e.h}px`;
      node.style.opacity = e.opacity;
    } else if (e.kind === 'dot') {
      node = document.createElement('div');
      node.className = 'el live-dot';
      node.innerHTML = '<i></i>';
      node.style.height = `${e.h}px`;
    } else {
      const [family, weight, cls] = FONTS[e.t.font] || FONTS.HelveticaNeue;
      node = document.createElement('div');
      node.className = `el txt ${cls}`;
      const style = e.t.style;
      const step = style && style.startsWith('step') && e.t.text.match(/^(\d+)\.?\s+([\s\S]*)$/);
      if (step) {
        // "1  Tap ..." -> orange badge + text; word 0 was the number, so shift accents down by one.
        const rest = (e.t.accentWords || []).filter((n) => n > 0).map((n) => n - 1);
        node.innerHTML = `<span class="badge">${step[1]}</span><span>${richText(step[2], rest)}</span>`;
      } else {
        node.innerHTML = richText(e.t.text, e.t.accentWords);
      }
      if (style) node.classList.add(`s-${style}`);
      Object.assign(node.style, { fontFamily: family, fontWeight: weight, fontSize: `${e.t.size}px`,
                                  color: e.t.color, textAlign: e.t.align || 'left' });
    }
    Object.assign(node.style, { left: `${e.x}px`, top: `${e.y}px`, width: `${e.w}px`, transform: `rotate(${e.rot}deg)` });
    node.dataset.key = e.key;
    return node;
  }

  function buildSlide(slide) {
    const layer = document.createElement('div');
    layer.className = 'slide';
    layer.dataset.id = slide.id;
    for (const e of elements(slide)) layer.appendChild(makeEl(e));
    if (slide.skipped) {
      const tag = document.createElement('div');
      tag.className = 'backup-tag';
      tag.textContent = 'BACKUP';
      layer.appendChild(tag);
    }
    return layer;
  }

  // Panels (cards, bubbles, steps) are taller than plain text: push any panel that would overlap the
  // panel above it in the same column down, keeping a 22px gap. Needs the layer to be in the DOM.
  function relayout(layer) {
    const panels = [...layer.querySelectorAll('.el.txt[class*=" s-"]')]
      .map((n) => ({ n, x: parseFloat(n.style.left), y: parseFloat(n.style.top) }))
      .sort((a, b) => a.y - b.y);
    panels.forEach((p, i) => {
      for (let j = i - 1; j >= 0; j--) {
        const q = panels[j];
        if (Math.abs(q.x - p.x) > 200) continue;
        const bottom = q.y + q.n.offsetHeight + 22;
        if (p.y < bottom) { p.y = bottom; p.n.style.top = `${bottom}px`; }
        break;
      }
    });
  }

  // A static, non-animated copy of a slide (presenter thumbnails).
  function miniSlide(slide) {
    const box = document.createElement('div');
    box.className = `mini look-${slide.look}`;
    if (BG[slide.look]) {
      const img = document.createElement('img');
      img.src = BG[slide.look];
      Object.assign(img.style, { position: 'absolute', inset: 0, width: '100%', height: '100%' });
      box.appendChild(img);
    }
    box.appendChild(buildSlide(slide));
    return box;
  }

  // ------------------------------------------------------------------ audience stage
  const stage = document.getElementById('stage');
  const bgLayer = document.getElementById('bg');
  let cur = -1;
  let currentLayer = null;
  let currentLook = null;

  function fit() {
    const s = Math.min(innerWidth / 1920, innerHeight / 1080);
    stage.style.transform = `translate(-50%, -50%) scale(${s})`;
  }

  function setBackground(look, animate) {
    if (look === currentLook) return;
    currentLook = look;
    const old = [...bgLayer.children];
    if (BG[look]) {
      const img = document.createElement('img');
      img.src = BG[look];
      img.style.opacity = animate ? 0 : 1;
      bgLayer.appendChild(img);
      if (animate) setTimeout(() => { img.style.opacity = 1; }, 20);   // not rAF: it stalls in background tabs
    }
    stage.className = `look-${look}`;
    old.forEach((o) => {
      if (!animate) return o.remove();
      o.style.opacity = 0;
      setTimeout(() => o.remove(), 500);
    });
  }

  function show(i, { animate = !STATIC } = {}) {
    if (i < 0 || i >= SLIDES.length) return;
    const slide = SLIDES[i];
    const prev = cur >= 0 ? SLIDES[cur] : null;
    const oldLayer = currentLayer;
    // Fast key presses: only the outgoing slide may stay while it fades; drop anything older.
    stage.querySelectorAll('.slide').forEach((l) => { if (l !== oldLayer) l.remove(); });
    const layer = buildSlide(slide);
    cur = i;
    setBackground(slide.look, animate && !!prev);
    stage.appendChild(layer);
    relayout(layer);
    currentLayer = layer;

    if (animate && prev && oldLayer) {
      const magic = slide.transition.effect === 'magic move';
      const dur = magic ? 900 : 650;
      const before = new Map(elements(prev).map((e) => [e.key, e]));
      let stagger = 0;
      for (const node of layer.querySelectorAll('.el')) {
        const was = before.get(node.dataset.key);
        const now = elements(slide).find((e) => e.key === node.dataset.key);
        if (was) {
          // Glide from the previous slide's place, size and angle.
          oldLayer.querySelector(`[data-key="${CSS.escape(node.dataset.key)}"]`)?.style.setProperty('visibility', 'hidden');
          const sx = was.w / now.w;
          const sy = (was.h && now.h) ? was.h / now.h : sx;
          node.animate([
            { transform: `translate(${was.x - now.x}px, ${was.y - now.y}px) rotate(${was.rot}deg) scale(${sx}, ${sy})`, transformOrigin: 'top left' },
            { transform: `translate(0, 0) rotate(${now.rot}deg) scale(1, 1)`, transformOrigin: 'top left' },
          ], { duration: dur, easing: 'cubic-bezier(.2, .8, .2, 1)' });
        } else {
          // Build in: fade up, one after another.
          node.animate([
            { opacity: 0, transform: `translateY(28px) rotate(${now.rot}deg)` },
            { opacity: node.style.opacity || 1, transform: `translateY(0) rotate(${now.rot}deg)` },
          ], { duration: 520, delay: 120 + stagger, easing: 'cubic-bezier(.2, .8, .2, 1)', fill: 'backwards' });
          stagger += 90;
        }
      }
      oldLayer.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 380, easing: 'ease-out', fill: 'forwards' });
      setTimeout(() => oldLayer.remove(), 420);   // timer, not .finished: hidden tabs never finish animations
    } else if (oldLayer) {
      oldLayer.remove();
    }

    if (animate && slide.audio) {
      const a = new Audio(`../${slide.audio}`);
      a.play().catch(() => {});
    }
    if (!STATIC) history.replaceState(null, '', `#${slide.id}`);
    broadcast();
  }

  // ------------------------------------------------------------------ navigation
  // Main path skips backup slides. From a demo slide, B enters its backups; Esc leaves them.
  const isBackup = (i) => SLIDES[i]?.skipped;
  function nextIndex(i) {
    if (isBackup(i) && isBackup(i + 1) && SLIDES[i + 1].backupFor === SLIDES[i].backupFor) return i + 1;
    let j = i + 1;
    while (j < SLIDES.length && isBackup(j)) j++;
    return j < SLIDES.length ? j : i;
  }
  function prevIndex(i) {
    if (isBackup(i) && isBackup(i - 1)) return i - 1;
    let j = i - 1;
    while (j >= 0 && isBackup(j)) j--;
    return j >= 0 ? j : i;
  }
  function demoOf(i) {   // the demo slide a backup belongs to
    let j = i;
    while (j > 0 && isBackup(j)) j--;
    return j;
  }

  let typed = '';
  const jumpBox = document.getElementById('jump');
  function command(key) {
    if (key === 'ArrowRight' || key === ' ' || key === 'PageDown' || key === 'Enter' && !typed) show(nextIndex(cur));
    else if (key === 'ArrowLeft' || key === 'PageUp') show(prevIndex(cur));
    else if (key === 'b' || key === 'B') { if (isBackup(cur + 1) && !isBackup(cur)) show(cur + 1); }
    else if (key === 'Escape') { if (isBackup(cur)) show(demoOf(cur)); }
    else if (/^[0-9]$/.test(key)) { typed += key; jumpBox.hidden = false; jumpBox.textContent = `Go to ${typed}`; }
    else if (key === 'Enter' && typed) { show(Number(typed) - 1); typed = ''; jumpBox.hidden = true; }
    else if (key === 'f' || key === 'F') document.documentElement.requestFullscreen?.();
    else if (key === 'p' || key === 'P') openPresenter();
    else if (key === 'r' || key === 'R') { t0 = Date.now(); broadcast(); }
  }

  // ------------------------------------------------------------------ presenter sync
  let presenterWin = null;
  let t0 = Date.now();
  function openPresenter() {
    presenterWin = window.open(`index.html?presenter${location.hash}`, 'insideout-presenter', 'width=1400,height=900');
    setTimeout(broadcast, 600);
  }
  function broadcast() {
    if (presenterWin && !presenterWin.closed) presenterWin.postMessage({ type: 'state', cur, t0 }, '*');
  }
  addEventListener('message', (ev) => {
    if (ev.data?.type === 'key') command(ev.data.key);
    if (ev.data?.type === 'hello') { presenterWin = ev.source; broadcast(); }
  });

  // ------------------------------------------------------------------ presenter window
  const plannedStart = (() => {   // minutes from 3:30 at which each slide is planned to start
    const out = []; let t = 0;
    SLIDES.forEach((s) => { out.push(t); if (!s.skipped) t += s.minutes; });
    return out;
  })();
  const clock = (mins) => {
    const total = START.h * 60 + START.m + Math.round(mins);
    const h = Math.floor(total / 60), m = total % 60;
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')}`;
  };

  function presenter() {
    document.getElementById('viewport').hidden = true;
    const root = document.getElementById('presenter');
    root.hidden = false;
    let state = { cur: 0, t0: Date.now() };
    const fitMini = (frame) => {
      const mini = frame.firstChild; if (mini) mini.style.transform = `scale(${frame.clientWidth / 1920})`;
    };
    function paint() {
      const i = state.cur, s = SLIDES[i];
      const now = document.getElementById('p-now'), next = document.getElementById('p-next');
      now.replaceChildren(miniSlide(s)); fitMini(now); relayout(now.firstChild);
      const n = nextIndex(i);
      next.replaceChildren(n !== i ? miniSlide(SLIDES[n]) : document.createTextNode('')); fitMini(next);
      if (n !== i) relayout(next.firstChild);
      document.getElementById('p-id').textContent = `${i + 1}. ${s.id}${s.skipped ? ' (backup)' : ''}`;
      document.getElementById('p-notes').innerHTML = esc(s.notes).replace(/\[([^\]]+)\]/g, '<span class="cue">[$1]</span>');
      document.getElementById('p-pos').textContent = `slide ${i + 1} / ${SLIDES.length}`;
    }
    function tick() {
      const el = (Date.now() - state.t0) / 1000;
      document.getElementById('p-clock').textContent = `${Math.floor(el / 60)}:${String(Math.floor(el % 60)).padStart(2, '0')}`;
      const plan = plannedStart[state.cur];
      const late = el / 60 - plan;
      const p = document.getElementById('p-plan');
      p.textContent = `planned ${clock(plan)} · ${late >= 0 ? '+' : ''}${late.toFixed(1)} min`;
      p.classList.toggle('late', late > 2);
    }
    addEventListener('message', (ev) => { if (ev.data?.type === 'state') { state = ev.data; paint(); } });
    addEventListener('keydown', (ev) => { window.opener?.postMessage({ type: 'key', key: ev.key }, '*'); ev.preventDefault(); });
    addEventListener('resize', () => document.querySelectorAll('.p-frame').forEach(fitMini));
    window.opener?.postMessage({ type: 'hello' }, '*');
    setInterval(tick, 500);
    paint();
  }

  // ------------------------------------------------------------------ boot
  if (PRESENTER) return presenter();
  fit();
  addEventListener('resize', fit);
  addEventListener('keydown', (ev) => { command(ev.key); if ([' ', 'ArrowRight', 'ArrowLeft'].includes(ev.key)) ev.preventDefault(); });
  stage.addEventListener('click', () => show(nextIndex(cur)));
  const start = SLIDES.findIndex((s) => s.id === decodeURIComponent(location.hash.slice(1)));
  show(start >= 0 ? start : 0, { animate: false });
})();
