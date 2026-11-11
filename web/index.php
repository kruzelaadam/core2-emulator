<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>S1C31W74</title>
<style>
:root {
  --bg: #101114;
  --panel: #181b21;
  --line: #2a2f38;
  --text: #e7ecf3;
  --dim: #8b95a7;
  --accent: #8dffb0;
  --hot: #ff5d5d;
  --warn: #ffcc66;
  --mono: ui-monospace, "Cascadia Code", Consolas, monospace;
}
* { box-sizing: border-box; }
html, body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.4 system-ui, sans-serif; }
header, main { padding: 12px 16px; }
header { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: rgba(16,17,20,.94); z-index: 2; }
h1 { font-size: 15px; margin: 0; letter-spacing: .04em; }
h1 span { color: var(--dim); font-weight: 500; }
.badge { font: 12px var(--mono); padding: 2px 8px; border-radius: 99px; border: 1px solid var(--line); }
.badge.on { color: var(--accent); border-color: #24663a; }
.badge.off { color: var(--hot); border-color: #663030; }
button, input, select { background: #22262e; color: var(--text); border: 1px solid var(--line); border-radius: 6px; padding: 6px 10px; font: 13px var(--mono); }
button { cursor: pointer; }
button:hover { border-color: #4b5568; }
button.primary { background: #1c3d2c; border-color: #2f6b49; }
.grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 12px; }
@media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
section { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
h2 { margin: 0 0 8px; font-size: 12px; color: var(--dim); text-transform: uppercase; letter-spacing: .08em; }
.regs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 4px 10px; }
.reg { display: flex; justify-content: space-between; gap: 8px; font: 12px var(--mono); padding: 2px 0; border-bottom: 1px solid #22262e; }
.reg b { color: var(--dim); font-weight: 500; }
.pins { display: flex; gap: 6px; flex-wrap: wrap; align-items: end; }
.pin { width: 28px; text-align: center; font: 10px var(--mono); color: var(--dim); }
.dot { width: 18px; height: 18px; margin: 0 auto 4px; border-radius: 50%; border: 2px solid #3a4150; background: transparent; }
.dot.hi { background: var(--accent); border-color: var(--accent); box-shadow: 0 0 10px #8dffb088; }
.dot.lo { background: #2a241c; border-color: #6a5a3a; }
.stats { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 8px; font: 12px var(--mono); color: var(--dim); }
.stats strong { color: var(--text); font-weight: 500; }
#err { color: var(--hot); font: 12px var(--mono); white-space: pre-wrap; min-height: 1.2em; }
pre { margin: 0; font: 12px var(--mono); white-space: pre-wrap; word-break: break-all; }
.row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
label { color: var(--dim); font-size: 12px; }
input[type=file] { padding: 4px; }
details { color: var(--dim); }
table { width: 100%; border-collapse: collapse; font: 12px var(--mono); }
td { padding: 2px 6px 2px 0; border-bottom: 1px solid #22262e; }
td:first-child { color: var(--dim); }
#dump { margin-top: 8px; }
.lcd { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start; }
#glass { width: 380px; flex: none; user-select: none; }
#glass .band { fill: #0b0c0d; }
#glass .px { fill: #1e2023; cursor: pointer; }
#glass .px.on { fill: #d3d7d3; }
#glass .px.sel { fill: var(--hot); }
#glass .tick { fill: #0b0c0d; cursor: pointer; }
#glass .tick.on { fill: #d3d7d3; }
#glass .tick.sel { fill: var(--hot); }
#glass .ic { color: #2d3034; cursor: pointer; font-weight: 700; font-family: system-ui, sans-serif; }
#glass .ic.on { color: #d3d7d3; }
#glass .ic.sel { color: var(--hot); }
#glass .print { fill: #d8dbe0; font-weight: 700; font-family: system-ui, sans-serif; }
#glass .btn .cap { fill: #1c1e20; stroke: #3a3d41; }
#glass .btn .rib { fill: #2e3135; }
#glass .btn.down .cap { fill: #1c3d2c; stroke: var(--accent); }
#glass .btn.down .rib { fill: #2f6b49; }
#glass .hit { fill: transparent; cursor: pointer; touch-action: none; }
#glass .hit:hover { fill: #8dffb014; }
.keys { color: var(--dim); font: 12px var(--mono); margin: 4px 0 0; }
.keys kbd { color: var(--text); border: 1px solid var(--line); border-radius: 4px; padding: 0 4px; }
.lcdside { flex: 1; min-width: 300px; }
.mx { overflow-x: auto; }
#lcdMatrix { display: block; cursor: crosshair; }
#lcdStatus, #lcdInfo { font: 12px var(--mono); color: var(--dim); min-height: 1.4em; }
#lcdStatus { color: var(--text); }
#lcdWiring { width: 100%; height: 180px; margin: 6px 0; background: #22262e; color: var(--text); border: 1px solid var(--line); border-radius: 6px; font: 11px var(--mono); }
.hint { color: var(--dim); font-size: 12px; margin: 8px 0 0; }
</style>
</head>
<body>
<header>
  <h1>S1C31W74 <span>C31W74201</span></h1>
  <span id="run" class="badge off">HALTED</span>
  <span id="fw" class="badge"></span>
  <span id="vtime" class="badge"></span>
  <button class="primary" id="bRun" type="button">Run</button>
  <button id="bPause" type="button">Pause</button>
  <button id="bStep" type="button">Step</button>
  <input id="steps" value="1" size="4" title="instruction count">
  <button id="bFor" type="button">Run for</button>
  <input id="ms" value="100" size="4" title="milliseconds">
  <span style="color:var(--dim);font-size:12px">ms</span>
  <button id="bReset" type="button">Reset</button>
  <button id="bRestart" type="button">Restart Renode</button>
  <label style="font-size:12px;color:var(--dim)" title="pace virtual time to the host clock; off = run as fast as the host allows">
    <input type="checkbox" id="realtime" checked> real-time
  </label>
</header>
<main>
  <div id="err"></div>
  <section>
    <h2>LCD32B · Suunto Core 2 glass</h2>
    <div class="lcd">
      <div style="flex:none;width:380px">
        <svg id="glass" viewBox="-20 -20 400 400" role="img" aria-label="LCD glass"></svg>
        <p class="keys">buttons: click the case, or <kbd>↑</kbd> up · <kbd>Enter</kbd>/<kbd>→</kbd> next · <kbd>↓</kbd> down · <kbd>Backspace</kbd>/<kbd>←</kbd> back · <kbd>L</kbd> lock</p>
      </div>
      <div class="lcdside">
        <div id="lcdStatus">—</div>
        <div class="row" style="margin:6px 0">
          <label>matrix <select id="lcdArea"><option value="shown">shown area</option><option value="0">area 0</option><option value="1">area 1</option></select></label>
        </div>
        <div class="mx"><canvas id="lcdMatrix" width="734" height="78"></canvas></div>
        <div id="lcdInfo">hover a glass segment or a matrix cell</div>
        <details style="margin-top:8px">
          <summary>glass wiring, segment → [SEG, COM]</summary>
          <textarea id="lcdWiring" spellcheck="false"></textarea>
          <div class="row"><button id="lcdApply" type="button">Apply</button><button id="lcdDefault" type="button">Placeholder</button></div>
        </details>
        <p class="hint">Matrix is the display RAM as LCD32B scans it (duty, DSPAR, SEGREV/COMREV); glass is the pin state (DSPC, DSPREV, COMxDEN). Green = bit set and on the glass map, amber = set but unmapped. Text bands are the app's own table: pixel (x, y) is SEG x, COM y, 49×7 / 49×14 / 49×7 on SEG 1–49 at COM 2–8 / 9–22 / 24–30. The row under ALTI BARO COMP DIVE is the same 49 pixels at COM 23 (alti 1–8, baro 11–19, comp 23–31, dive 35–42, tool 47–49). SEG 46 COM 23 is the back arrow. Those four words and the vertical divider are paint, not segments. Battery is two glyphs (empty shell, full fill), the seven marks after it are the 3 h + 3 h baro trend (up, down, level, dot, up, down, level), waves under the battery are the snorkel mark. Bluetooth and the corner arrows are wired; the other status glyphs sit on SEG 200 until their pin is known. The 120 dots inside the bezel are the compass bearing and the seconds tick (one segment each, firmware walk order). Click a segment, then the matrix cell it should be. Saved in this browser. A matrix click with nothing selected flips that RAM bit.</p>
      </div>
    </div>
  </section>
  <div class="grid">
    <div>
      <section>
        <h2>P0 / LED</h2>
        <div class="pins" id="pins"></div>
        <div class="stats">
          <div>P0DAT <strong id="p0dat">—</strong></div>
          <div>P0IOEN <strong id="p0ioen">—</strong></div>
          <div>toggles <strong id="toggles">—</strong></div>
          <div>magic <strong id="magic">—</strong></div>
          <div>boots <strong id="boots">—</strong></div>
        </div>
      </section>
      <section>
        <h2>CPU</h2>
        <div class="regs" id="cpu"></div>
        <div class="stats">
          <div>vector SP <strong id="vsp">—</strong></div>
          <div>reset <strong id="vreset">—</strong></div>
        </div>
      </section>
    </div>
    <div>
      <section>
        <h2>Peripherals</h2>
        <table id="mmio"></table>
      </section>
      <section>
        <h2>Firmware</h2>
        <div class="row" id="examples"></div>
        <form id="up" class="row" style="margin-top:8px">
          <input type="file" name="fw[]" accept=".elf,.bin,.hex" multiple required>
          <label>bin load <input name="addr" value="0x00000000" size="12"></label>
          <select name="boot" title="Suunto images: boot0 stub target"><option value="app">boot App</option><option value="chain">boot BL1 → App</option></select>
          <label><input type="checkbox" name="run" checked> start</label>
          <button type="submit">Upload</button>
        </form>
        <p style="color:var(--dim);font-size:12px;margin:8px 0 0">Default image is <code>fw/core_demo.elf</code> — Core 2 style clock face on LCD32B at 1/32 duty, double-buffered via DSPAR, P0.0 toggles every second, frame counter at SRAM 0x20000008. <code>blink.elf</code> only toggles P0.0. <code>happy.elf</code> brings up IOSC, kicks WDT2, fills LCD RAM, then spins. <code>wdt_reset.elf</code> lets WDT2 reset the core once and counts boots in SRAM. Suunto <code>.bin</code>s (Bootloader1/2, Application) carry a <code>DA11C10C</code> header with their load address: pick several at once, or one with load 0, and they are placed at 0x2000 / 0xA000 / 0x12000 in a 0xFF-filled 512 KiB flash with <code>fw/boot0.elf</code> as stage 0 (first valid header wins, sets VTOR and MSP).</p>
      </section>
      <section>
        <h2>Memory</h2>
        <form id="peek" class="row">
          <input name="addr" value="0x20000000" size="12">
          <input name="len" value="64" size="4">
          <button type="submit">Peek</button>
        </form>
        <form id="poke" class="row" style="margin-top:8px">
          <input name="addr" value="0x20000000" size="12">
          <input name="value" value="0" size="12">
          <select name="width"><option value="4">word</option><option value="2">half</option><option value="1">byte</option></select>
          <button type="submit">Poke</button>
        </form>
        <pre id="dump"></pre>
      </section>
      <section>
        <h2>Monitor</h2>
        <form id="mon" class="row">
          <input name="cmd" placeholder="sysbus ReadWord 0x40000200" style="flex:1;min-width:220px">
          <button type="submit">Send</button>
        </form>
        <pre id="monout" style="margin-top:8px"></pre>
      </section>
      <section>
        <details>
          <summary>Map</summary>
          <pre>00000000  flash 512 KiB
00080000  QSPI XIP 1 MiB
20000000  SRAM 128 KiB
20000000  magic   20000004 boots   20000008 toggles
20200000  display RAM 704 B (window 1 KiB)
          area 0 +000, area 1 +200, u32 per SEG, bit n = COMn
20400000  USB buffer 1 KiB
40000000  16-bit peripheral file, 12 KiB
  0000 SYSPROT  key 0x0096
  0040 CLG     00A0 WDT2   0200 P0   0800 LCD32B
E000E000  NVIC / SysTick
WDT2 expiry is NMI or CPU reset. SRAM is kept.</pre>
        </details>
      </section>
    </div>
  </div>
</main>
<script src="core_glass.js?v=20"></script>
<script>
const cpuOrder = ['R0','R1','R2','R3','R4','R5','R6','R7','R8','R9','R10','R11','R12','SP','LR','PC','CPSR','XPSR','CONTROL','PRIMASK'];
const mmioOrder = ['SYSPROT','PWGACTL','CLGSCLK','CLGOSC','CLGIOSC','CLGOSC1','CLGOSC3','CLGINTF','CLGINTE','WDT2CLK','WDT2CTL','WDT2CMP','FLASHC','P0DAT','P0IOEN','LCDCLK','LCDCTL','LCDTIM1','LCDTIM2','LCDPWR','LCDDSP','LCDCOMC0','LCDCOMC1'];
let busy = false;
let inflight = false;
let timer = null;

function hexDec(s) {
  if (!s) return 0;
  return parseInt(s, 16) >>> 0;
}

async function api(action, opt) {
  opt = opt || {};
  const url = 'api.php?action=' + encodeURIComponent(action) + (opt.query || '');
  const res = await fetch(url, { method: opt.method || 'GET', body: opt.body || null, cache: 'no-store' });
  const data = await res.json();
  if (!res.ok || data.error) throw new Error(data.error || res.statusText);
  return data;
}

function setErr(e) {
  document.getElementById('err').textContent = e ? String(e.message || e) : '';
}

function render(s) {
  const halted = s.HALTED !== '0';
  const run = document.getElementById('run');
  run.textContent = halted ? 'HALTED' : 'RUNNING';
  run.className = 'badge ' + (halted ? 'off' : 'on');
  document.getElementById('fw').textContent = s.FIRMWARE || 'no firmware';
  document.getElementById('vtime').textContent = s.VTIME || '';
  if (s.REALTIME !== undefined) {
    document.getElementById('realtime').checked = s.REALTIME === '1';
  }
  document.getElementById('p0dat').textContent = s.P0DAT || '—';
  document.getElementById('p0ioen').textContent = s.P0IOEN || '—';
  document.getElementById('toggles').textContent = s.TOGGLES ? String(hexDec(s.TOGGLES)) : '—';
  document.getElementById('magic').textContent = s.MAGIC || '—';
  document.getElementById('boots').textContent = s.BOOTS ? String(hexDec(s.BOOTS)) : '—';
  document.getElementById('vsp').textContent = s.VSP || '—';
  document.getElementById('vreset').textContent = s.VRESET || '—';
  const dat = hexDec(s.P0DAT);
  const en = hexDec(s.P0IOEN);
  const pins = document.getElementById('pins');
  pins.innerHTML = '';
  for (let i = 0; i < 16; i++) {
    const el = document.createElement('div');
    el.className = 'pin';
    const dot = document.createElement('div');
    const out = (en >> i) & 1;
    const hi = (dat >> i) & 1;
    dot.className = 'dot' + (out ? (hi ? ' hi' : ' lo') : '');
    el.appendChild(dot);
    el.appendChild(document.createTextNode('P0.' + i));
    pins.appendChild(el);
  }
  const cpu = document.getElementById('cpu');
  cpu.innerHTML = '';
  for (const name of cpuOrder) {
    if (s[name] == null) continue;
    const d = document.createElement('div');
    d.className = 'reg';
    d.innerHTML = '<b>' + name + '</b><span>' + s[name] + '</span>';
    cpu.appendChild(d);
  }
  const mmio = document.getElementById('mmio');
  mmio.innerHTML = '';
  for (const name of mmioOrder) {
    if (s[name] == null) continue;
    const tr = document.createElement('tr');
    tr.innerHTML = '<td>' + name + '</td><td>' + s[name] + '</td>';
    mmio.appendChild(tr);
  }
  CoreLCD.render(s);
}

async function refresh() {
  if (busy || inflight) return;
  inflight = true;
  try {
    render(await api('snapshot'));
    setErr(null);
  } catch (e) {
    setErr(e);
  } finally {
    inflight = false;
  }
}

async function control(op) {
  busy = true;
  try {
    const body = new URLSearchParams();
    body.set('op', op);
    body.set('ms', document.getElementById('ms').value || '100');
    body.set('steps', document.getElementById('steps').value || '1');
    render(await api('control', { method: 'POST', body }));
    setErr(null);
  } catch (e) {
    setErr(e);
  } finally {
    busy = false;
  }
}

document.getElementById('bRun').onclick = () => control('start');
document.getElementById('bPause').onclick = () => control('pause');
document.getElementById('bStep').onclick = () => control('step');
document.getElementById('bFor').onclick = () => control('runfor');
document.getElementById('bReset').onclick = () => control('reset');
document.getElementById('bRestart').onclick = () => control('restart');
document.getElementById('realtime').onchange = async (ev) => {
  busy = true;
  try {
    const body = new URLSearchParams();
    body.set('on', ev.target.checked ? '1' : '0');
    render(await api('realtime', { method: 'POST', body }));
    setErr(null);
  } catch (e) {
    setErr(e);
  } finally {
    busy = false;
  }
};

document.getElementById('up').onsubmit = async (ev) => {
  ev.preventDefault();
  busy = true;
  try {
    const fd = new FormData(ev.target);
    if (!fd.get('run')) fd.set('run', '');
    else fd.set('run', '1');
    render(await api('upload', { method: 'POST', body: fd }));
    setErr(null);
  } catch (e) {
    setErr(e);
  } finally {
    busy = false;
  }
};

document.getElementById('peek').onsubmit = async (ev) => {
  ev.preventDefault();
  const q = new FormData(ev.target);
  try {
    const data = await api('peek', { query: '&addr=' + encodeURIComponent(q.get('addr')) + '&len=' + encodeURIComponent(q.get('len')) });
    document.getElementById('dump').textContent = data.addr + '  ' + data.hex;
    setErr(null);
  } catch (e) { setErr(e); }
};

document.getElementById('poke').onsubmit = async (ev) => {
  ev.preventDefault();
  busy = true;
  try {
    const q = new FormData(ev.target);
    const body = new URLSearchParams();
    body.set('addr', q.get('addr'));
    body.set('value', q.get('value'));
    body.set('width', q.get('width'));
    const data = await api('poke', { method: 'POST', body });
    document.getElementById('dump').textContent = data.addr + '  ' + data.hex;
    setErr(null);
    await refresh();
  } catch (e) { setErr(e); }
  finally { busy = false; }
};

document.getElementById('mon').onsubmit = async (ev) => {
  ev.preventDefault();
  busy = true;
  try {
    const body = new URLSearchParams();
    body.set('cmd', new FormData(ev.target).get('cmd'));
    const data = await api('monitor', { method: 'POST', body });
    document.getElementById('monout').textContent = data.text || '(empty)';
    setErr(null);
    await refresh();
  } catch (e) { setErr(e); }
  finally { busy = false; }
};

async function loadExamples() {
  try {
    const meta = await api('meta');
    const box = document.getElementById('examples');
    box.innerHTML = '';
    for (const ex of meta.examples) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = ex.label;
      b.disabled = !ex.available;
      b.title = ex.available ? 'load and start' : 'not built';
      b.onclick = async () => {
        busy = true;
        try {
          const body = new URLSearchParams();
          body.set('id', ex.id);
          body.set('run', '1');
          render(await api('example', { method: 'POST', body }));
          setErr(null);
        } catch (e) { setErr(e); }
        finally { busy = false; }
      };
      box.appendChild(b);
    }
  } catch (e) { setErr(e); }
}

// Press and release must reach the model in order; each call sends the
// whole pressed mask, so a dropped one is healed by the next.
let buttonChain = Promise.resolve();
CoreLCD.init({
  error: setErr,
  buttons: (mask) => {
    buttonChain = buttonChain.then(async () => {
      try {
        const body = new URLSearchParams();
        body.set('pressed', String(mask));
        await api('buttons', { method: 'POST', body });
        setErr(null);
      } catch (e) { setErr(e); }
    });
  },
  poke: async (addr, value) => {
    busy = true;
    try {
      const body = new URLSearchParams();
      body.set('addr', '0x' + addr.toString(16));
      body.set('value', '0x' + value.toString(16));
      body.set('width', '1');
      await api('poke', { method: 'POST', body });
      setErr(null);
    } catch (e) { setErr(e); }
    finally { busy = false; }
    await refresh();
  },
});
loadExamples().then(refresh);
timer = setInterval(refresh, 250);
</script>
</body>
</html>
