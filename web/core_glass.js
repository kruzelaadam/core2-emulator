// LCD32B view: decodes display RAM exactly as the S1C31W74 drives the pins
// (LDUTY, DSPAR, SEGREV, COMREV, DSPC, DSPREV, COMxDEN), then draws it as a
// raw COM x SEG matrix and as a Suunto Core 2 face: negative glass, three
// dot-matrix bands and fixed icons.
//
// Dot bands follow the Suunto app's layout table (0x57874: {x, y, w, h} =
// {1,2,49,7}, {1,9,49,14}, {1,24,49,7}; pixel (x, y) is SEG x, COM y).
// The mode underline is the same kind of row, from the frame panels
// (0x575c0): alti x=1 w=8, baro x=11 w=9, comp x=23 w=9, dive x=35 w=8,
// tool x=46 w=4, all y=23 h=1. ALTI/BARO/COMP/DIVE and the status divider
// are paint on the glass, not segments. Each dot band maps affinely,
// SEG = seg + sx*x + sy*y and COM = com + cx*x + cy*y; icons map to one
// [SEG, COM] each. The 120-tick compass/seconds ring is the walk at
// file 0x42054 (count byte 0x78 at 0x575e8): index order is clockwise
// around the glass, placed by the segment's own (SEG, COM). Icons whose
// pin is not known yet sit at SEG 200 (outside the 72-SEG scan) so a
// stray RAM bit cannot light them. Overrides live in localStorage.
(function () {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';
  const STORE = 'coreGlassWiring7';
  const RAM_BASE = 0x20200000;
  const CELL = 8;
  const ML = 30;
  const MT = 14;
  const CX = 180;
  const CY = 180;
  const R = 142;

  // Glass geometry in viewBox units, w x h dots at pitch p. Band sizes from
  // the firmware; pitches and positions from a straight-on product photo
  // (the big band's dots are 1.26x the time dots).
  const FIELDS = {
    top: { w: 49, h: 7, x: 92.4, y: 97.3, p: 3.58 },
    big: { w: 49, h: 14, x: 77.4, y: 140, p: 4.165 },
    bottom: { w: 49, h: 7, x: 92.4, y: 242, p: 3.58 },
  };

  // Case buttons by compass angle -> P2 bit (active low). next and back are
  // confirmed against the setup wizard, lock passes the app's button-lock
  // filter (only P2.3/P2.4 get through), up/down are P2.0/P2.2.
  const BUTTONS = [
    { name: 'up', deg: 55, bit: 0, keys: ['ArrowUp'] },
    { name: 'next', deg: 90, bit: 1, keys: ['Enter', 'ArrowRight'] },
    { name: 'down', deg: 125, bit: 2, keys: ['ArrowDown'] },
    { name: 'back', deg: 235, bit: 4, keys: ['Backspace', 'ArrowLeft'] },
    { name: 'lock', deg: 305, bit: 3, keys: ['l', 'L'] },
  ];
  const btnEls = {};

  const fieldEls = {};
  const iconEls = {};
  const ringEls = [];
  // 120 (SEG, COM) pairs, app image VA 0x42054. Walks the matrix perimeter
  // clockwise from just right of 12 o'clock: top, right edge, bottom, left edge.
  const RING = (function () {
    const h = '20002100220023002400250026002700280029002a002b002c002d002e002f003000310032023203320532063207320a320b320c320d320e320f321032113213321432153216321732183219321a321b321c321d321e301f2f1f2e1f2d1f2c1f2b1f2a1f291f271f251f231f211f1f1f1d1f1b1f191f171f151f131f121f111f101f0f1f0e1f0d1f0c1f0b1f0a1f091f081f071f061f051f041f031f021f001e001d001c001b001a001900180017001600150014000f000e000d000c000b000a0009000800070006000500040002020103010401050113001400150016001700180019001a001b001c001d001e001f00';
    const out = [];
    for (let i = 0; i < h.length; i += 4) out.push([parseInt(h.substr(i, 2), 16), parseInt(h.substr(i + 2, 2), 16)]);
    return out;
  })();
  let wiring = null;
  let reverse = {};
  let st = null;
  let last = null;
  let selected = null;
  let hover = null;
  let hooks = {};

  function placeholder() {
    const id = { sx: 1, sy: 0, cx: 0, cy: 1 };
    const icons = {};
    // SEG 200 is outside the scan, so these stay dark until the pin is known.
    // Full battery is the three lit cells SEG 10-12, COM 1. Empty is the
    // other glyph and is still unmapped.
    ['weather', 'sun', 'divider', 'lock', 'alarm', 'menu', 'mark',
      'alti', 'baro', 'comp', 'dive', 'alti_u', 'baro_u', 'comp_u', 'dive_u'].forEach((n, k) => { icons[n] = [200, k]; });
    // Full battery is SEG 10-12 COM 1. Bluetooth, up, next and the back
    // arrow (SEG 46 COM 23) are the pins already confirmed on the watch.
    icons.battery = [[10, 1], [11, 1], [12, 1]];
    Object.assign(icons, { bluetooth: [42, 1], up: [50, 4], next: [50, 18], back: [46, 23] });
    return {
      fields: {
        top: Object.assign({ seg: 1, com: 2 }, id),
        big: Object.assign({ seg: 1, com: 9 }, id),
        bottom: Object.assign({ seg: 1, com: 24 }, id),
      },
      icons,
    };
  }

  function validate(w) {
    if (!w || typeof w !== 'object' || !w.fields || !w.icons) throw new Error('want {"fields": {...}, "icons": {...}}');
    for (const f in FIELDS) {
      const m = w.fields[f];
      if (!m) throw new Error('fields.' + f + ' missing');
      for (const k of ['seg', 'com', 'sx', 'sy', 'cx', 'cy']) {
        if (!Number.isInteger(m[k])) throw new Error('fields.' + f + '.' + k + ' must be an integer');
      }
    }
    for (const n in w.icons) {
      const v = w.icons[n];
      const rows = v && Array.isArray(v[0]) ? v : [v];
      if (!rows.length || rows.some((p) => !Array.isArray(p) || p.length !== 2 || !Number.isInteger(p[0]) || !Number.isInteger(p[1]))) {
        throw new Error('icons.' + n + ': want [seg, com] or [[seg, com], ...]');
      }
    }
    return w;
  }

  function loadWiring() {
    wiring = placeholder();
    try {
      const saved = JSON.parse(localStorage.getItem(STORE) || 'null');
      if (saved) wiring = validate(saved);
    } catch (e) { /* stale or bad map in storage: keep the placeholder */ }
    // Older maps put the back arrow on SEG 0. The lit menu cell is SEG 46.
    const b = wiring.icons.back;
    if (!b || (b[0] === 0 && b[1] === 0)) wiring.icons.back = [46, 23];
    const bat = wiring.icons.battery;
    if (!bat || bat[0] === 200) wiring.icons.battery = [[10, 1], [11, 1], [12, 1]];
    indexWiring();
  }

  function saveWiring() {
    localStorage.setItem(STORE, JSON.stringify(wiring));
    indexWiring();
  }

  function fieldMap(f, x, y) {
    const m = wiring.fields[f];
    return [m.seg + m.sx * x + m.sy * y, m.com + m.cx * x + m.cy * y];
  }

  function pins(v) {
    if (!v) return [];
    return Array.isArray(v[0]) ? v : [v];
  }

  function wired(id) {
    if (id.startsWith('icon:')) return wiring.icons[id.slice(5)] || null;
    if (id.startsWith('ring:')) return RING[Number(id.slice(5))];
    const [f, x, y] = id.split(':');
    return fieldMap(f, Number(x), Number(y));
  }

  function indexWiring() {
    reverse = {};
    const add = (sc, id) => {
      const k = sc[0] + ':' + sc[1];
      (reverse[k] = reverse[k] || []).push(id);
    };
    for (const f in FIELDS) {
      for (let y = 0; y < FIELDS[f].h; y++) {
        for (let x = 0; x < FIELDS[f].w; x++) {
          if (f === 'rule' && x === 45) continue;
          add(fieldMap(f, x, y), f + ':' + x + ':' + y);
        }
      }
    }
    for (const n in wiring.icons) pins(wiring.icons[n]).forEach((sc) => add(sc, 'icon:' + n));
    for (let i = 0; i < RING.length; i++) add(RING[i], 'ring:' + i);
  }

  function hex(s) {
    return s ? parseInt(s, 16) >>> 0 : 0;
  }

  function decode(s) {
    const ram = new Uint8Array(1024);
    const text = s.LCDRAM || '';
    for (let i = 0; i < 1024 && i * 2 + 2 <= text.length; i++) {
      ram[i] = parseInt(text.substr(i * 2, 2), 16);
    }
    const tim1 = s.LCDTIM1 ? hex(s.LCDTIM1) : 0x011f;
    const dsp = s.LCDDSP ? hex(s.LCDDSP) : 0x70;
    const ncom = (tim1 & 0x1f) + 1;
    const shown = (dsp >> 2) & 1;
    const pick = document.getElementById('lcdArea').value;
    return {
      ram,
      ncom,
      nseg: ncom <= 16 ? 88 : ncom <= 24 ? 80 : 72,
      shown,
      area: pick === 'shown' ? shown : Number(pick),
      segrev: (dsp >> 6) & 1,
      comrev: (dsp >> 5) & 1,
      dsprev: (dsp >> 4) & 1,
      dspc: dsp & 3,
      moden: hex(s.LCDCTL) & 1,
      comen: s.LCDCOMC0 ? ((hex(s.LCDCOMC0) | (hex(s.LCDCOMC1) << 16)) >>> 0) : 0xffffffff,
    };
  }

  // Figures 20.6.3.1-4: one 32-bit word per SEG, area n at +0x200*n.
  function locate(seg, com, area) {
    const idx = st.segrev ? seg : st.nseg - 1 - seg;
    const bit = st.comrev ? com : st.ncom - 1 - com;
    return { off: area * 0x200 + idx * 4 + (bit >> 3), bit: bit & 7 };
  }

  function inRange(seg, com) {
    return seg >= 0 && com >= 0 && seg < st.nseg && com < st.ncom;
  }

  function rawBit(seg, com, area) {
    if (!inRange(seg, com)) return 0;
    const l = locate(seg, com, area);
    return (st.ram[l.off] >> l.bit) & 1;
  }

  function driven(seg, com) {
    if (!inRange(seg, com)) return 0;
    if (!st.moden || st.dspc === 0 || st.dspc === 3) return 0;
    if (!((st.comen >>> com) & 1)) return 0;
    if (st.dspc === 2) return 1;
    return rawBit(seg, com, st.shown) ^ (st.dsprev ? 0 : 1);
  }

  function el(tag, attrs, parent) {
    const e = document.createElementNS(NS, tag);
    for (const k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  }

  function polar(deg, r) {
    const a = (deg - 90) * Math.PI / 180;
    return [CX + r * Math.cos(a), CY + r * Math.sin(a)];
  }

  // One cell is one LCD dot. Strings are rows, '#' is lit.
  function block(g, rows) {
    const s = 2.55;
    const h = rows.length;
    const w = rows[0].length;
    const ox = -w * s / 2;
    const oy = -h * s / 2;
    const d = (s * 0.82).toFixed(2);
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (rows[y][x] === '#') {
          el('rect', {
            x: (ox + x * s).toFixed(2), y: (oy + y * s).toFixed(2), width: d, height: d, fill: 'currentColor',
          }, g);
        }
      }
    }
  }

  // Arrow whose tail is the local origin and whose head points along +x,
  // then rotated. Trend tails sit on the hub so the six of them read as
  // one cluster: diagonals out, level arrows through the dot.
  function arrow(g, deg, len, head) {
    const gg = el('g', { transform: 'rotate(' + deg + ')' }, g);
    head = head || 3.2;
    const neck = (len - 0.2).toFixed(2);
    const tip = (len + head * 0.85).toFixed(2);
    el('path', {
      d: 'M0 -1.45H' + neck + 'V' + (-head).toFixed(2) + 'L' + tip + ' 0L' + neck + ' ' + head.toFixed(2) + 'V1.45H0Z',
      fill: 'currentColor',
    }, gg);
  }

  const ART = {
    battery: (g) => {
      el('path', { d: 'M-9 -4.5h16v9h-16z M-7.6 -3.1v6.2h13.2v-6.2z', 'fill-rule': 'evenodd', fill: 'currentColor' }, g);
      el('rect', { x: 7, y: -2, width: 1.8, height: 4, fill: 'currentColor' }, g);
      el('rect', { x: -6.4, y: -1.9, width: 3.6, height: 3.8, fill: 'currentColor' }, g);
    },
    weather: (g) => {
      el('path', { d: 'M-10 5V1Q-7.5 -3 -5 1T0 1T5 1T10 1V5Z', fill: 'currentColor' }, g);
    },
    sun: (g) => {
      el('circle', { r: 2.6, fill: 'currentColor' }, g);
      el('path', { d: 'M-3 -3L-6 -6M-3 3L-6 6M3 3L6 6M3 -3L6 -6M-4 0H-9M4 0H10M7 -3L10 0L7 3', stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
    },
    divider: (g) => {
      el('rect', { x: -0.8, y: -7, width: 1.6, height: 14, fill: 'currentColor' }, g);
    },
    lock: (g) => {
      el('path', { d: 'M-3 -1V-3.5A3 3 0 0 1 3 -3.5V-1', stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
      el('rect', { x: -5, y: -1, width: 10, height: 7.5, rx: 1, fill: 'currentColor' }, g);
      el('rect', { x: -0.6, y: 1, width: 1.2, height: 3, fill: '#0b0c0d' }, g);
    },
    alarm: (g) => {
      el('circle', { cy: 0.5, r: 4.6, stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
      el('circle', { cx: -4.4, cy: -4.2, r: 1.9, fill: 'currentColor' }, g);
      el('circle', { cx: 4.4, cy: -4.2, r: 1.9, fill: 'currentColor' }, g);
      el('path', { d: 'M0 -2V0.8H2.2M-3.4 4.6L-5 6.6M3.4 4.6L5 6.6', stroke: 'currentColor', 'stroke-width': 1.2, fill: 'none' }, g);
    },
    bluetooth: (g) => {
      el('ellipse', { rx: 4.6, ry: 7, fill: 'currentColor' }, g);
      el('path', { d: 'M-2.2 -2.6L2.2 1.6L0 3.8V-3.8L2.2 -1.6L-2.2 2.6', stroke: '#0b0c0d', 'stroke-width': 1, fill: 'none' }, g);
    },
    menu: (g) => {
      for (const y of [-4, -0.9, 2.2]) el('rect', { x: -5, y, width: 10, height: 1.8, fill: 'currentColor' }, g);
    },
    up: (g) => {
      el('path', { d: 'M-4 4L4 -4M-1 -4H4V1', stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
    },
    next: (g) => {
      el('path', { d: 'M-5 0H5M1 -4L5 0L1 4', stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
    },
    down: (g) => {
      el('path', { d: 'M-4 -4L4 4M4 -1V4H-1', stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
    },
    back: (g) => {
      el('path', { d: 'M4.5 4A5 5 0 1 0 -3.5 -2.5M-3.5 -2.5V2M-3.5 -2.5H1', stroke: 'currentColor', 'stroke-width': 1.6, fill: 'none' }, g);
    },
    mark: (g) => {
      el('path', { d: 'M0 -3L3.4 3H-3.4Z', fill: 'currentColor' }, g);
    },
  };

  function word(g, text, size) {
    const t = el('text', { 'text-anchor': 'middle', 'font-size': size, y: size * 0.36, fill: 'currentColor' }, g);
    t.textContent = text;
  }

  function iconAt(parent, name, x, y, draw, hit) {
    const g = el('g', { class: 'ic', 'data-id': 'icon:' + name, transform: 'translate(' + x + ' ' + y + ')' }, parent);
    const h = hit || [-8, -7, 16, 14];
    el('rect', { x: h[0], y: h[1], width: h[2], height: h[3], fill: 'transparent' }, g);
    draw(g);
    iconEls[name] = g;
  }

  function buildCase(svg) {
    const defs = el('defs', {}, svg);
    const cg = el('radialGradient', { id: 'caseG', cx: '45%', cy: '35%', r: '70%' }, defs);
    el('stop', { offset: '0%', 'stop-color': '#34373b' }, cg);
    el('stop', { offset: '100%', 'stop-color': '#0f1011' }, cg);

    // Three buttons right, two left.
    for (const b of BUTTONS) {
      const g = el('g', { class: 'btn', transform: 'rotate(' + (b.deg - 90) + ' ' + CX + ' ' + CY + ')' }, svg);
      el('rect', { x: CX + 176, y: CY - 11, width: 18, height: 22, rx: 5, class: 'cap' }, g);
      for (let k = 0; k < 4; k++) el('rect', { x: CX + 182 + k * 3, y: CY - 9, width: 1.2, height: 18, class: 'rib' }, g);
      btnEls[b.name] = g;
    }
    el('circle', { cx: CX, cy: CY, r: 184, fill: 'url(#caseG)', stroke: '#2b2e32', 'stroke-width': 2 }, svg);

    // Bezel: grips outside, compass scale inside.
    el('circle', { cx: CX, cy: CY, r: 176, fill: '#141517', stroke: '#26282b' }, svg);
    for (let k = 0; k < 40; k++) {
      const g = el('g', { transform: 'rotate(' + (k * 9 + 4.5) + ' ' + CX + ' ' + CY + ')' }, svg);
      el('rect', { x: CX - 2.6, y: CY - 176, width: 5.2, height: 9, rx: 2.6, fill: '#1d1f22', stroke: '#2c2f33', 'stroke-width': 0.8 }, g);
    }
    el('circle', { cx: CX, cy: CY, r: 166, fill: '#0e0f10' }, svg);
    for (let deg = 0; deg < 360; deg += 10) {
      const g = el('g', { transform: 'rotate(' + deg + ' ' + CX + ' ' + CY + ')' }, svg);
      if (deg === 0) {
        el('rect', { x: CX - 4.2, y: CY - 162, width: 2.6, height: 9, rx: 1.3, fill: '#9dff6a' }, g);
        el('rect', { x: CX + 1.6, y: CY - 162, width: 2.6, height: 9, rx: 1.3, fill: '#9dff6a' }, g);
      } else if (deg % 30 === 0) {
        const t = el('text', { x: CX, y: CY - 154, 'text-anchor': 'middle', class: 'print', 'font-size': 8.5 }, g);
        t.textContent = String(deg);
      } else {
        el('circle', { cx: CX, cy: CY - 160, r: 0.9, fill: '#c9ccd1' }, g);
        el('circle', { cx: CX, cy: CY - 156, r: 0.9, fill: '#c9ccd1' }, g);
      }
    }

    // Glass: metal ring, black LCD, printed ticks, north triangle, logo.
    el('circle', { cx: CX, cy: CY, r: R + 3, fill: '#2a2d31' }, svg);
    el('circle', { cx: CX, cy: CY, r: R, fill: '#0b0c0d' }, svg);
    for (let deg = 30; deg < 360; deg += 30) {
      const a = polar(deg, R - 7);
      const b = polar(deg, R - 1);
      el('path', { d: 'M' + a[0] + ' ' + a[1] + 'L' + b[0] + ' ' + b[1], stroke: '#d8dbe0', 'stroke-width': 2, 'stroke-linecap': 'round' }, svg);
    }
    el('path', { d: 'M' + CX + ' ' + (CY - R + 3) + 'l4 7h-8z', fill: '#d8dbe0' }, svg);
    const logo = el('text', { x: CX, y: 293, 'text-anchor': 'middle', class: 'print', 'font-size': 10, 'letter-spacing': '0.08em' }, svg);
    logo.textContent = 'SUUNTO';

    // Hit areas on top of the bezel: the caps only stick out 10 units.
    for (const b of BUTTONS) {
      const g = el('g', { transform: 'rotate(' + (b.deg - 90) + ' ' + CX + ' ' + CY + ')' }, svg);
      const hit = el('rect', { x: CX + 164, y: CY - 17, width: 34, height: 34, rx: 8, class: 'hit' }, g);
      el('title', {}, hit).textContent = b.name + ' · P2.' + b.bit + ' · key ' + b.keys.join(' / ');
      hit.addEventListener('pointerdown', (ev) => {
        if (ev.button !== 0) return;
        ev.preventDefault();
        hit.setPointerCapture(ev.pointerId);
        press(b, true);
      });
      const up = () => press(b, false);
      hit.addEventListener('pointerup', up);
      hit.addEventListener('pointercancel', up);
      hit.addEventListener('lostpointercapture', up);
    }
  }

  // The app samples P2 on a 10 Hz tick after the edge IRQ; a click shorter
  // than one tick can fall between samples, so hold at least that long.
  const MIN_HOLD_MS = 160;
  let pressed = 0;
  const pressedAt = {};
  const releaseTimer = {};

  function press(b, down) {
    const bit = 1 << b.bit;
    if (down) {
      clearTimeout(releaseTimer[b.name]);
      releaseTimer[b.name] = null;
      if (pressed & bit) return;
      pressed |= bit;
      pressedAt[b.name] = performance.now();
      btnEls[b.name].classList.add('down');
      if (hooks.buttons) hooks.buttons(pressed);
      return;
    }
    if (!(pressed & bit) || releaseTimer[b.name]) return;
    const release = () => {
      releaseTimer[b.name] = null;
      pressed &= ~bit;
      btnEls[b.name].classList.remove('down');
      if (hooks.buttons) hooks.buttons(pressed);
    };
    const wait = MIN_HOLD_MS - (performance.now() - pressedAt[b.name]);
    if (wait > 0) releaseTimer[b.name] = setTimeout(release, wait);
    else release();
  }

  function keyButton(ev) {
    const t = ev.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return null;
    if (ev.ctrlKey || ev.metaKey || ev.altKey) return null;
    return BUTTONS.find((b) => b.keys.includes(ev.key)) || null;
  }

  function buildGlass(svg) {
    buildCase(svg);
    const g = el('g', { id: 'glassSegs' }, svg);

    for (const f in FIELDS) {
      const F = FIELDS[f];
      if (F.h > 1) {
        const pad = F.p;
        el('rect', { x: F.x - pad, y: F.y - pad, width: F.w * F.p + 2 * pad, height: F.h * F.p + 2 * pad, rx: 1, class: 'band' }, g);
      } else {
        el('rect', {
          x: F.x - 1.5, y: F.y - 1.2, width: F.w * F.p + 3, height: F.p + 2.4, class: 'band',
        }, g);
      }
      fieldEls[f] = new Array(F.w * F.h);
      const s = (F.p * 0.84).toFixed(2);
      for (let y = 0; y < F.h; y++) {
        for (let x = 0; x < F.w; x++) {
          // Column 45 of the mode row is the back arrow, not an underline dot.
          if (f === 'rule' && x === 45) continue;
          fieldEls[f][y * F.w + x] = el('rect', {
            x: (F.x + x * F.p).toFixed(2), y: (F.y + y * F.p).toFixed(2), width: s, height: s,
            class: 'px', 'data-id': f + ':' + x + ':' + y,
          }, g);
        }
      }
    }

    // 120 compass/seconds pixels. (SEG, COM) projects onto the circle the
    // same way the matrix perimeter sits on the round glass: 12 o'clock is
    // the middle of COM 0, 3 o'clock is SEG 50.
    const RING_R = 136;
    for (let i = 0; i < RING.length; i++) {
      const sc = RING[i];
      let deg = Math.atan2(sc[0] - 25, -(sc[1] - 15.5)) * 180 / Math.PI;
      if (deg < 0) deg += 360;
      const p = polar(deg, RING_R);
      ringEls.push(el('rect', {
        x: (p[0] - 1.2).toFixed(2), y: (p[1] - 1.2).toFixed(2), width: 2.4, height: 2.4,
        class: 'tick', 'data-id': 'ring:' + i,
      }, g));
    }
    // North triangle is enamel and sits on top of the 12-o'clock ticks.
    el('path', { d: 'M' + CX + ' ' + (CY - R + 3) + 'l4 7h-8z', fill: '#d8dbe0' }, g);

    [['battery', 133, 79], ['weather', 122, 89], ['sun', 161, 80], ['divider', 181, 80], ['lock', 197, 80], ['alarm', 216, 80], ['bluetooth', 237, 80]]
      .forEach(([n, x, y]) => iconAt(g, n, x, y, ART[n]));
    [['alti', 'ALTI', 94, 26], ['baro', 'BARO', 137, 34], ['comp', 'COMP', 188, 38], ['dive', 'DIVE', 235, 30]].forEach(([n, text, x, w]) => {
      iconAt(g, n, x, 222, (k) => word(k, text, 12));
      iconAt(g, n + '_u', x, 231.5, (k) => el('rect', { x: -w / 2, y: -0.9, width: w, height: 1.8, fill: 'currentColor' }, k));
    });
    iconAt(g, 'menu', 270, 222, ART.menu);
    [['up', 294, 113], ['next', 296, 180], ['down', 293, 253], ['back', 71, 252]].forEach(([n, x, y]) => iconAt(g, n, x, y, ART[n]));
    iconAt(g, 'mark', CX, 277, ART.mark);

    const pick = (ev) => {
      const t = ev.target.closest('[data-id]');
      return t ? t.getAttribute('data-id') : null;
    };
    g.addEventListener('click', (ev) => {
      const id = pick(ev);
      if (!id) return;
      selected = selected === id ? null : id;
      paint();
      describe(id);
    });
    g.addEventListener('mousemove', (ev) => {
      const id = pick(ev);
      if (id) describe(id);
    });
  }

  function addr(sc) {
    const l = locate(sc[0], sc[1], st.area);
    return '0x' + (RAM_BASE + l.off).toString(16).toUpperCase() + ' bit ' + l.bit;
  }

  function label(id) {
    if (id.startsWith('icon:')) return id.slice(5);
    if (id.startsWith('ring:')) return 'ring ' + id.slice(5);
    const [f, x, y] = id.split(':');
    return f + '(' + x + ',' + y + ')';
  }

  function describe(id) {
    const info = document.getElementById('lcdInfo');
    const scs = pins(wired(id));
    if (!st || !scs.length) {
      info.textContent = label(id) + ' → unmapped';
      return;
    }
    const where = scs.map((sc) => 'SEG ' + sc[0] + ' COM ' + sc[1] + (inRange(sc[0], sc[1]) ? ' = ' + addr(sc) + ' · pin ' + driven(sc[0], sc[1]) : ' (outside this duty)')).join(', ');
    info.textContent = label(id) + ' → ' + where +
      (selected === id ? ' · selected: click the matrix cell it should be, Esc to cancel' : '');
  }

  function setOn(e, on, sel) {
    if (e.__on !== on) {
      e.classList.toggle('on', on);
      e.__on = on;
    }
    if (e.__sel !== sel) {
      e.classList.toggle('sel', sel);
      e.__sel = sel;
    }
  }

  function paintGlass() {
    for (const f in FIELDS) {
      const F = FIELDS[f];
      const m = wiring.fields[f];
      const els = fieldEls[f];
      for (let y = 0; y < F.h; y++) {
        for (let x = 0; x < F.w; x++) {
          const px = els[y * F.w + x];
          if (!px) continue;
          const on = !!driven(m.seg + m.sx * x + m.sy * y, m.com + m.cx * x + m.cy * y);
          setOn(px, on, selected === f + ':' + x + ':' + y);
        }
      }
    }
    for (const n in iconEls) {
      const on = pins(wiring.icons[n]).some((sc) => driven(sc[0], sc[1]));
      setOn(iconEls[n], on, selected === 'icon:' + n);
    }
    for (let i = 0; i < ringEls.length; i++) {
      const sc = RING[i];
      setOn(ringEls[i], !!driven(sc[0], sc[1]), selected === 'ring:' + i);
    }
  }

  function paintMatrix() {
    const cv = document.getElementById('lcdMatrix');
    const w = ML + st.nseg * CELL;
    const h = MT + st.ncom * CELL;
    if (cv.width !== w) cv.width = w;
    if (cv.height !== h) cv.height = h;
    const g = cv.getContext('2d');
    g.clearRect(0, 0, w, h);
    g.font = '8px ui-monospace, Consolas, monospace';
    g.fillStyle = '#8b95a7';
    for (let s = 0; s < st.nseg; s += 8) g.fillText(String(s), ML + s * CELL, 9);
    let stray = 0;
    for (let c = 0; c < st.ncom; c++) {
      g.fillStyle = (st.comen >>> c) & 1 ? '#8b95a7' : '#663030';
      g.fillText('C' + c, 2, MT + c * CELL + 7);
      for (let s = 0; s < st.nseg; s++) {
        const v = rawBit(s, c, st.area);
        const mapped = !!reverse[s + ':' + c];
        if (v && !mapped) stray++;
        g.fillStyle = v ? (mapped ? '#8dffb0' : '#ffcc66') : (mapped ? '#25392d' : '#22262e');
        g.fillRect(ML + s * CELL, MT + c * CELL, CELL - 1, CELL - 1);
      }
    }
    const mark = (sc, color) => {
      if (!sc || !inRange(sc[0], sc[1])) return;
      g.strokeStyle = color;
      g.lineWidth = 1.5;
      g.strokeRect(ML + sc[0] * CELL - 0.5, MT + sc[1] * CELL - 0.5, CELL, CELL);
    };
    if (selected) pins(wired(selected)).forEach((sc) => mark(sc, '#ff5d5d'));
    if (hover) mark(hover, '#e7ecf3');
    return stray;
  }

  function paintStatus(stray) {
    const mode = !st.moden ? 'MODEN=0' : ['display off', 'normal', 'all on', 'all off'][st.dspc];
    const duty = st.ncom === 1 ? 'static' : '1/' + st.ncom + ' duty';
    const off = [];
    for (let c = 0; c < st.ncom; c++) if (!((st.comen >>> c) & 1)) off.push(c);
    document.getElementById('lcdStatus').textContent = [
      mode + (st.moden && !st.dsprev ? ' inverted' : ''),
      duty + ', ' + st.nseg + ' SEG × ' + st.ncom + ' COM',
      'area ' + st.shown + ' shown' + (st.area !== st.shown ? ', viewing ' + st.area : ''),
      (st.segrev ? '' : 'SEGREV=0 ') + (st.comrev ? '' : 'COMREV=0 ') + (off.length ? 'COM off: ' + off.join(',') : ''),
      stray ? stray + ' lit bits not on the glass map' : '',
    ].filter((x) => x.trim()).join(' · ');
  }

  function paint() {
    if (!st) return;
    paintGlass();
    paintStatus(paintMatrix());
  }

  function cellAt(ev) {
    const cv = ev.target;
    const r = cv.getBoundingClientRect();
    const x = (ev.clientX - r.left) * (cv.width / r.width);
    const y = (ev.clientY - r.top) * (cv.height / r.height);
    const seg = Math.floor((x - ML) / CELL);
    const com = Math.floor((y - MT) / CELL);
    if (!st || !inRange(seg, com)) return null;
    return [seg, com];
  }

  function wiringText() {
    const f = Object.keys(wiring.fields).map((k) => '    ' + JSON.stringify(k) + ': ' + JSON.stringify(wiring.fields[k]).replace(/,/g, ', '));
    const i = Object.keys(wiring.icons).map((k) => {
      const ps = pins(wiring.icons[k]);
      const body = ps.length === 1 ? '[' + ps[0].join(', ') + ']' : '[' + ps.map((p) => '[' + p.join(', ') + ']').join(', ') + ']';
      return '    ' + JSON.stringify(k) + ': ' + body;
    });
    return '{\n  "fields": {\n' + f.join(',\n') + '\n  },\n  "icons": {\n' + i.join(',\n') + '\n  }\n}';
  }

  // Wire the selected element to matrix cell sc. A dot moves its whole band:
  // the band keeps its orientation and shifts so that dot lands on sc.
  function rewire(id, sc) {
    if (id.startsWith('ring:')) return;
    if (id.startsWith('icon:')) {
      wiring.icons[id.slice(5)] = sc;
    } else {
      const [f, xs, ys] = id.split(':');
      const m = wiring.fields[f];
      const x = Number(xs);
      const y = Number(ys);
      m.seg = sc[0] - m.sx * x - m.sy * y;
      m.com = sc[1] - m.cx * x - m.cy * y;
    }
    saveWiring();
  }

  function init(h) {
    hooks = h || {};
    for (const old of ['coreGlassWiring', 'coreGlassWiring2', 'coreGlassWiring3', 'coreGlassWiring4']) localStorage.removeItem(old);
    loadWiring();
    buildGlass(document.getElementById('glass'));
    const cv = document.getElementById('lcdMatrix');
    cv.addEventListener('mousemove', (ev) => {
      const c = cellAt(ev);
      hover = c;
      if (!c) return;
      const ids = reverse[c[0] + ':' + c[1]];
      document.getElementById('lcdInfo').textContent = 'SEG ' + c[0] + ' COM ' + c[1] + ' = ' + addr(c) +
        ' · ram ' + rawBit(c[0], c[1], st.area) + ' · ' + (ids ? ids.map(label).join(', ') : 'unmapped') +
        (selected ? ' · click to wire ' + label(selected) + ' here' : ' · click to toggle');
      paintMatrix();
    });
    cv.addEventListener('mouseleave', () => {
      hover = null;
      if (st) paintMatrix();
    });
    cv.addEventListener('click', async (ev) => {
      const c = cellAt(ev);
      if (!c) return;
      if (selected) {
        const id = selected;
        rewire(id, c);
        selected = null;
        document.getElementById('lcdWiring').value = wiringText();
        paint();
        describe(id);
        return;
      }
      const l = locate(c[0], c[1], st.area);
      if (hooks.poke) await hooks.poke(RAM_BASE + l.off, st.ram[l.off] ^ (1 << l.bit));
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && selected) {
        selected = null;
        paint();
        return;
      }
      const b = keyButton(ev);
      if (!b) return;
      ev.preventDefault();
      if (!ev.repeat) press(b, true);
    });
    document.addEventListener('keyup', (ev) => {
      const b = keyButton(ev);
      if (!b) return;
      ev.preventDefault();
      press(b, false);
    });
    window.addEventListener('blur', () => {
      for (const b of BUTTONS) press(b, false);
    });
    document.getElementById('lcdArea').onchange = () => {
      if (last) render(last);
    };
    const box = document.getElementById('lcdWiring');
    box.value = wiringText();
    document.getElementById('lcdApply').onclick = () => {
      try {
        wiring = validate(JSON.parse(box.value));
        saveWiring();
        box.value = wiringText();
        paint();
        if (hooks.error) hooks.error(null);
      } catch (e) {
        if (hooks.error) hooks.error(e);
      }
    };
    document.getElementById('lcdDefault').onclick = () => {
      localStorage.removeItem(STORE);
      loadWiring();
      box.value = wiringText();
      paint();
    };
  }

  function render(s) {
    if (s.LCDRAM === undefined) return;
    last = s;
    st = decode(s);
    paint();
  }

  window.CoreLCD = { init, render };
})();
