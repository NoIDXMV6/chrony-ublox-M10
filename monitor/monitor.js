/**
 * NTP Monitor — monitor.js
 * Весь клиентский JavaScript. Данные: api.php. Действия: action.php.
 * index.html содержит только разметку.
 */

'use strict';

// ══════════════════════════════════════════════════════════════
// Константы и состояние
// ══════════════════════════════════════════════════════════════

const API    = 'api.php';
const ACTION = 'action.php';

const state = {
  refreshTimer : null,
  offsets      : [],          // история offset для графика
  leafletMap   : null,
  leafletMarker: null,
  theme        : 'dark',
  mode         : 'time',      // 'time' | 'ucenter'
  server       : { ip:'', hostname:'', ntp_port:123, ser2_port:2947 },
  lastSats     : [],          // последний список спутников (для перерисовки при смене темы)
  firstLoad    : true,
};

// ══════════════════════════════════════════════════════════════
// Вспомогательные функции
// ══════════════════════════════════════════════════════════════

const $ = id => document.getElementById(id);
const h = s  => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const statusClass = s => ({ good:'good', warning:'warn', error:'error' }[s] ?? '');

function reachBar(oct) {
  const dec  = parseInt(oct, 8) || 0;
  const bits = [];
  for (let i = 7; i >= 0; i--) bits.push((dec >> i) & 1);
  return '<div class="reach-bar">' +
    bits.map(b => `<div class="reach-bit ${b?'on':''}"></div>`).join('') +
    '</div>';
}

function srcStateEl(mode, state, nosel) {
  let cls, lbl;
  if (state === '*')                 { cls = 'src-selected'; lbl = '*'; }
  else if (state === '+')            { cls = 'src-combined'; lbl = '+'; }
  else if (['x','?'].includes(state)){ cls = nosel ? 'src-noselect' : 'src-error'; lbl = state; }
  else                               { cls = 'src-other';    lbl = state; }
  return `<span class="src-state ${cls}">${lbl}</span>`;
}

// ══════════════════════════════════════════════════════════════
// Тема оформления
// ══════════════════════════════════════════════════════════════

function initTheme() {
  const saved = localStorage.getItem('ntp_theme');
  if (saved) { applyTheme(saved); return; }
  const hr = new Date().getHours();
  applyTheme((hr >= 7 && hr < 20) ? 'light' : 'dark');
}

function applyTheme(t) {
  state.theme = t;
  document.documentElement.setAttribute('data-theme', t);
  const btn = $('theme-btn');
  if (btn) btn.textContent = (t === 'dark') ? '☀️' : '🌙';
  localStorage.setItem('ntp_theme', t);
  if (state.leafletMap) setTimeout(() => state.leafletMap.invalidateSize(), 100);
  // Перерисовать skyview с новыми цветами темы
  if (state.lastSats.length) renderSkyview(state.lastSats);
}

function toggleTheme() {
  applyTheme(state.theme === 'dark' ? 'light' : 'dark');
}

// ══════════════════════════════════════════════════════════════
// Часы
// ══════════════════════════════════════════════════════════════

function tickClock() {
  const n   = new Date();
  const p2  = v => String(v).padStart(2, '0');
  const p3  = v => String(v).padStart(3, '0');
  const ct  = $('clock-time');
  const cm  = $('clock-ms');
  const cd  = $('clock-date');
  if (ct) ct.textContent = `${p2(n.getHours())}:${p2(n.getMinutes())}:${p2(n.getSeconds())}`;
  if (cm) cm.textContent = `.${p3(n.getMilliseconds())}`;
  if (cd) {
    const days = ['Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'];
    const mons = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    const tz   = n.getTimezoneOffset();
    cd.textContent = `${days[n.getDay()]}, ${n.getDate()} ${mons[n.getMonth()]} ${n.getFullYear()} · UTC${tz<=0?'+':''}${-tz/60}`;
  }
}

// ══════════════════════════════════════════════════════════════
// Fetch helpers
// ══════════════════════════════════════════════════════════════

async function fetchJSON(url) {
  const r = await fetch(url + (url.includes('?') ? '&' : '?') + '_=' + Date.now());
  if (!r.ok) throw new Error(`HTTP ${r.status}`);
  return r.json();
}

async function postJSON(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });
  if (!r.ok) throw new Error(`HTTP ${r.status}`);
  return r.json();
}

// ══════════════════════════════════════════════════════════════
// Server info & Telegram status
// ══════════════════════════════════════════════════════════════

async function fetchServerInfo() {
  try {
    const d = await fetchJSON(`${ACTION}?action=server_info`);
    if (d.success) state.server = d;
  } catch(e) { console.warn('fetchServerInfo:', e); }
}

async function fetchTelegramStatus() {
  try {
    const d  = await fetchJSON(`${ACTION}?action=telegram_status`);
    const el = $('h-tg-status');
    if (!el) return;
    el.textContent = d.enabled ? '✓ TG' : '';
    el.title       = d.enabled ? `Telegram включён, proxy: ${d.proxy}` : 'Telegram отключён';
    el.style.color = d.enabled ? 'var(--green)' : 'var(--text3)';
  } catch(e) {}
}

// ══════════════════════════════════════════════════════════════
// Render: Header
// ══════════════════════════════════════════════════════════════

function renderHeader(d) {
  const sys = d.system || {};
  const hn  = $('h-hostname');
  const ht  = $('h-time');
  const hs  = $('h-services');
  if (hn) hn.textContent = sys.hostname || state.server.hostname || '—';
  if (ht) ht.textContent = new Date().toLocaleTimeString('ru-RU');
  if (hs) hs.innerHTML = [
    { l:'chrony', on: sys.chrony_active },
    { l:'gpsd',   on: sys.gpsd_active   },
    { l:'PPS',    on: sys.pps_device    },
  ].map(s =>
    `<div class="svc-pill ${s.on?'on':'off'}">` +
    `<span style="width:6px;height:6px;border-radius:50%;background:${s.on?'var(--green)':'var(--red)'};display:inline-block"></span>` +
    `${s.l}</div>`
  ).join('');
}

// ══════════════════════════════════════════════════════════════
// Render: Metric cards
// ══════════════════════════════════════════════════════════════

function renderCards(d) {
  const tr  = d.tracking || {};
  const act = d.activity  || {};
  const gps = d.gpsd      || {};
  const sky = gps.sky     || {};

  const fmt = k => { const f = tr[k+'_fmt']; return f ? { v:f.value, u:f.unit } : { v:'—', u:'' }; };
  const so  = fmt('system_time');
  const ro  = fmt('rms_offset');
  const sv  = Math.abs(parseFloat(tr.system_time || 0));
  const sc  = sv > 0.5 ? 'error' : sv > 0.1 ? 'warn' : 'good';

  const baud = gps.uart_baud || (gps.module && gps.module.bps) || null;

  const cards = [
    { l:'Stratum',    v: tr.stratum ?? '—',      u:'',    s: tr.reference_id ?? '',    c: tr.stratum==1?'good':'' },
    { l:'Смещение',   v: so.v,                   u: so.u, s: 'от NTP времени',         c: sc },
    { l:'RMS offset', v: ro.v,                   u: ro.u, s: 'скользящее среднее',     c: '' },
    { l:'Частота',    v: parseFloat(tr.frequency||0).toFixed(3), u:'ppm', s:'дрейф',  c: '' },
    { l:'Спутников',  v: sky.used!=null ? `${sky.used}/${sky.total}` : '—', u:'', s:'исп./видимых', c: sky.used>4?'good':sky.used>0?'warn':'' },
    { l:'Онлайн',     v: act.online ?? '—',      u:'',    s: `оффлайн: ${act.offline??'—'}`, c: act.online>0?'good':'error' },
    { l:'UART',       v: baud ?? '—',             u: baud?'bps':'', s:'/dev/ttyAMA0',  c: baud?'good':'' },
    { l:'Leap',       v: tr.leap_status ?? '—',  u:'',    s:'',                        c: tr.leap_status==='Normal'?'good':'warn' },
  ];

  const el = $('cards-row');
  if (el) el.innerHTML = cards.map(c =>
    `<div class="metric-card ${c.c}">` +
    `<div class="metric-label">${h(c.l)}</div>` +
    `<div class="metric-value">${h(c.v)}<span class="unit">${h(c.u)}</span></div>` +
    (c.s ? `<div class="metric-sub">${h(c.s)}</div>` : '') +
    `</div>`
  ).join('');
}

// ══════════════════════════════════════════════════════════════
// Render: Sources table
// ══════════════════════════════════════════════════════════════

function renderSources(d) {
  const src  = d.sources || {};
  const list = src.list  || [];
  const cls  = statusClass(src.status);

  const dot = $('sd-sources');
  if (dot) dot.className = 'status-dot ' + (cls==='good'?'dot-good':cls==='warn'?'dot-warn':'dot-error');

  const bb = $('bb-sources');
  if (bb) { bb.textContent = src.status==='good'?'OK':src.status==='warning'?'WARN':'ERR'; bb.className = 'block-badge '+cls; }

  const body = $('body-sources');
  if (!body) return;

  if (!list.length) { body.innerHTML = '<div class="err-msg">Нет источников</div>'; return; }

  body.innerHTML =
    `<table class="tbl"><thead><tr>` +
    `<th></th><th>Источник</th><th>Str</th><th>Poll</th><th>Reach</th><th>Last</th><th>Смещение</th>` +
    `</tr></thead><tbody>` +
    list.map(s =>
      `<tr>` +
      `<td>${srcStateEl(s.mode, s.state, s.is_noselect)}</td>` +
      `<td style="color:var(--text);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${h(s.name)}">${h(s.name)}</td>` +
      `<td>${s.stratum||'—'}</td><td>${s.poll}</td>` +
      `<td>${reachBar(s.reach)}</td><td>${h(s.last_rx)}</td>` +
      `<td style="color:${s.state==='*'?'var(--green)':'var(--text2)'};white-space:nowrap">${h(s.offset)}</td>` +
      `</tr>`
    ).join('') +
    `</tbody></table>`;
}

// ══════════════════════════════════════════════════════════════
// Render: GPS info panel
// ══════════════════════════════════════════════════════════════

function renderGps(d) {
  const gps  = d.gpsd || {};
  const bb   = $('bb-gps');
  const dot  = $('sd-gps');
  const body = $('body-gps');

  if (!gps.available) {
    if (dot)  dot.className = 'status-dot dot-off';
    if (bb)   { bb.textContent = 'N/A'; bb.className = 'block-badge'; }
    if (body) body.innerHTML = '<div class="err-msg">gpsd недоступен</div>';
    state.lastSats = [];
    renderSkyview([]); renderSnrHistogram([]); renderMap(null, null);
    return;
  }

  const fix  = gps.fix || {};
  const sky  = gps.sky || {};
  const mode = fix.mode || 0;
  const sats = sky.satellites || [];
  const baud = gps.uart_baud || (gps.module && gps.module.bps) || null;

  state.lastSats = sats;

  if (dot)  dot.className  = 'status-dot ' + (mode===3?'dot-good':mode===2?'dot-warn':'dot-error');
  if (bb)   { bb.textContent = fix.mode_label || '—'; bb.className = 'block-badge '+(mode===3?'good':mode===2?'warn':'error'); }

  const used = sats.filter(s => s.used).length;
  if (body) body.innerHTML =
    `<div class="kv-list">` +
    `<div class="kv-row"><span class="kv-key">Фикс</span><span class="kv-val ${mode===3?'good':mode===2?'warn':'error'}">${h(fix.mode_label)}</span></div>` +
    (fix.lat!=null ? `<div class="kv-row"><span class="kv-key">Широта</span><span class="kv-val accent">${fix.lat}°</span></div>` : '') +
    (fix.lon!=null ? `<div class="kv-row"><span class="kv-key">Долгота</span><span class="kv-val accent">${fix.lon}°</span></div>` : '') +
    (fix.alt!=null ? `<div class="kv-row"><span class="kv-key">Высота</span><span class="kv-val">${fix.alt} м</span></div>` : '') +
    `<div class="kv-row"><span class="kv-key">UART</span><span class="kv-val ${baud?'good':''}">${baud??'—'}${baud?' bps':''}</span></div>` +
    `<div class="kv-row"><span class="kv-key">PPS</span><span class="kv-val ${gps.pps?'good':'error'}">${gps.pps?'✓ /dev/pps0':'✗ нет'}</span></div>` +
    (sky.hdop!=null ? `<div class="kv-row"><span class="kv-key">HDOP</span><span class="kv-val">${sky.hdop}</span></div>` : '') +
    (sky.pdop!=null ? `<div class="kv-row"><span class="kv-key">PDOP</span><span class="kv-val">${sky.pdop}</span></div>` : '') +
    `<div class="kv-row"><span class="kv-key">Спутников</span><span class="kv-val">${used} / ${sats.length}</span></div>` +
    `</div>`;

  renderSkyview(sats);
  renderSnrHistogram(sats);
  renderMap(fix.lat, fix.lon);
}

// ══════════════════════════════════════════════════════════════
// Render: GNSS module info
// ══════════════════════════════════════════════════════════════

function renderGnss(d) {
  const gps  = d.gpsd  || {};
  const mod  = gps.module || {};
  const body = $('body-gnss');
  if (!body) return;

  if (!mod.driver) { body.innerHTML = '<div class="info-msg">Нет данных</div>'; return; }

  const names = { GPS:'GPS', GLO:'GLONASS', GAL:'Galileo', BDS:'BeiDou', SBAS:'SBAS', QZSS:'QZSS' };
  const cls   = { GPS:'gps', GLO:'glo', GAL:'gal', BDS:'bds', SBAS:'sbas', QZSS:'sbas' };
  const chips = (mod.gnss_systems||[]).map(s=>`<span class="gnss-chip ${cls[s]||''}">${names[s]||s}</span>`).join('');
  const aug   = (mod.augmentation||[]).map(s=>`<span class="gnss-chip sbas">${s}</span>`).join('');

  body.innerHTML =
    `<div class="kv-list">` +
    `<div class="kv-row"><span class="kv-key">Драйвер</span><span class="kv-val accent">${h(mod.driver)}</span></div>` +
    (mod.firmware ? `<div class="kv-row"><span class="kv-key">Прошивка</span><span class="kv-val">${h(mod.firmware)}</span></div>` : '') +
    (mod.fwver    ? `<div class="kv-row"><span class="kv-key">FW версия</span><span class="kv-val">${h(mod.fwver)}</span></div>` : '') +
    (mod.protver  ? `<div class="kv-row"><span class="kv-key">Протокол</span><span class="kv-val">${h(mod.protver)}</span></div>` : '') +
    (mod.hardware ? `<div class="kv-row"><span class="kv-key">HW версия</span><span class="kv-val">${h(mod.hardware)}</span></div>` : '') +
    `<div class="kv-row"><span class="kv-key">Baudrate</span><span class="kv-val">${mod.bps||'—'} bps</span></div>` +
    `<div class="kv-row"><span class="kv-key">Режим</span><span class="kv-val">${mod.native?'u-blox UBX':'NMEA'}</span></div>` +
    `</div>` +
    (chips ? `<div class="gnss-chips">${chips}${aug}</div>` : '');
}

// ══════════════════════════════════════════════════════════════
// Render: SNR Histogram
// ══════════════════════════════════════════════════════════════

function renderSnrHistogram(sats) {
  const el   = $('body-snr');
  const bb   = $('bb-snr');
  if (!el) return;

  const filtered = (sats || []).filter(s => s.el != null);
  if (!filtered.length) {
    el.innerHTML = '<div class="info-msg">Нет данных о спутниках</div>';
    if (bb) { bb.textContent = '—'; bb.className = 'block-badge'; }
    return;
  }

  const sorted     = [...filtered].sort((a,b) => a.prn - b.prn);
  const usedCount  = sorted.filter(s => s.used).length;
  if (bb) { bb.textContent = `${usedCount}/${sats.length}`; bb.className = 'block-badge '+(usedCount>4?'good':usedCount>0?'warn':'error'); }

  const MAX_SNR = 50;
  const HIST_H  = 130;
  const BAR_W   = Math.max(10, Math.min(24, Math.floor((el.clientWidth - 50) / Math.max(sorted.length,1))));

  function barColor(sat) {
    const gid = sat.gnss, prn = sat.prn, used = sat.used, snr = sat.ss || 0;
    let hue;
    if      (gid===0||(gid==null&&prn<65))     hue = 190;
    else if (gid===6||(prn>=65&&prn<96))        hue = 0;
    else if (gid===2||(prn>=300&&prn<400))      hue = 140;
    else if (gid===3||prn>=400)                 hue = 45;
    else if (gid===1)                           hue = 270;
    else                                         hue = 210;

    const light   = used ? Math.round(30 + (snr / MAX_SNR) * 40) : (state.theme==='dark' ? 18 : 78);
    const sat_s   = used ? 80 : 30;
    const opacity = used ? 1.0 : 0.45;
    return `hsla(${hue},${sat_s}%,${light}%,${opacity})`;
  }

  function gnssLabel(sat) {
    const g = sat.gnss, p = sat.prn;
    if (g===0||(g==null&&p<65))   return 'G';
    if (g===6||(p>=65&&p<96))     return 'R';
    if (g===2||(p>=300&&p<400))   return 'E';
    if (g===3||p>=400)            return 'C';
    if (g===1)                    return 'S';
    return '?';
  }

  const bars = sorted.map(s => {
    const snr = s.ss || 0;
    const pct = Math.min(snr / MAX_SNR, 1);
    const bh  = Math.max(2, Math.round(pct * (HIST_H - 24)));
    const col = barColor(s);
    const gl  = gnssLabel(s);
    const tip = `${gl} PRN:${s.prn} ${snr}dBHz${s.used?' ✓':''}`;
    return `<div style="display:inline-flex;flex-direction:column;align-items:center;flex-shrink:0;width:${BAR_W}px;margin:0 1px" title="${tip}">` +
      `<div style="font-family:var(--mono);font-size:6px;color:var(--text3);margin-bottom:2px;min-height:8px">${snr>0?snr:''}</div>` +
      `<div style="width:${BAR_W-2}px;height:${bh}px;background:${col};border-radius:2px 2px 0 0;${s.used?'border:1px solid rgba(255,255,255,.15)':''}"></div>` +
      `<div style="font-family:var(--mono);font-size:5.5px;color:${s.used?'var(--text2)':'var(--text3)'};margin-top:2px;writing-mode:vertical-rl;height:20px;line-height:${BAR_W}px;text-align:center">${s.prn}</div>` +
      `</div>`;
  }).join('');

  // Reference lines positions
  const line30h = Math.round((30/MAX_SNR) * (HIST_H - 24));
  const line40h = Math.round((40/MAX_SNR) * (HIST_H - 24));

  el.innerHTML =
    `<div style="position:relative;display:flex;align-items:flex-end;height:${HIST_H}px;padding-bottom:22px;overflow-x:auto;overflow-y:hidden">` +
    // 30 dBHz line
    `<div style="position:absolute;left:0;right:0;bottom:${22+line30h}px;height:1px;background:rgba(245,200,66,.35);pointer-events:none;z-index:1">` +
    `<span style="position:absolute;right:4px;bottom:2px;font-family:var(--mono);font-size:7px;color:rgba(245,200,66,.7)">30</span></div>` +
    // 40 dBHz line
    `<div style="position:absolute;left:0;right:0;bottom:${22+line40h}px;height:1px;background:rgba(57,217,138,.35);pointer-events:none;z-index:1">` +
    `<span style="position:absolute;right:4px;bottom:2px;font-family:var(--mono);font-size:7px;color:rgba(57,217,138,.7)">40</span></div>` +
    bars +
    `</div>` +
    `<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:5px;font-family:var(--mono);font-size:.58rem;color:var(--text3)">` +
    `<span><span style="color:hsl(190,80%,55%)">■</span> GPS</span>` +
    `<span><span style="color:hsl(0,70%,55%)">■</span> ГЛОНАСС</span>` +
    `<span><span style="color:hsl(140,70%,45%)">■</span> Galileo</span>` +
    `<span><span style="color:hsl(45,80%,55%)">■</span> BeiDou</span>` +
    `<span><span style="color:hsl(270,70%,65%)">■</span> SBAS</span>` +
    `<span style="color:var(--text3)">Яркость = SNR · Тёмные = не используются</span>` +
    `</div>`;
}

// ══════════════════════════════════════════════════════════════
// Render: Skyview SVG
// ══════════════════════════════════════════════════════════════

function renderSkyview(sats) {
  const bb   = $('bb-skyview');
  const body = $('body-skyview');
  if (!body) return;

  const SZ   = 248, CX = SZ/2, CY = SZ/2, R = SZ * .5 * .88;
  const dark = (state.theme !== 'light');

  const C = {
    bgOuter  : dark ? '#0f141c' : '#e8edf3',
    bgInner  : dark ? '#0a0e14' : '#f0f4f8',
    ring     : dark ? '#1e2a3a' : '#cbd5e1',
    gridText : dark ? '#4a6a88' : '#94a3b8',
    compass  : dark ? '#7a9ab8' : '#475569',
  };

  const azel2xy = (az, el) => {
    const r = R * (1 - el / 90);
    const a = (az - 90) * Math.PI / 180;
    return [+(CX + r*Math.cos(a)).toFixed(1), +(CY + r*Math.sin(a)).toFixed(1)];
  };

  function satColor(sat) {
    const gid = sat.gnss, prn = sat.prn, used = sat.used, snr = sat.ss || 0;
    let hue;
    if      (gid===0||(gid==null&&prn<65))     hue = 190;
    else if (gid===6||(prn>=65&&prn<96))        hue = 0;
    else if (gid===2||(prn>=300&&prn<400))      hue = 140;
    else if (gid===3||prn>=400)                 hue = 45;
    else if (gid===1)                           hue = 270;
    else                                         hue = 210;

    const light   = used ? Math.round(35 + (snr/50)*35) : (dark ? 20 : 75);
    const sat_s   = used ? 80 : 30;
    const opacity = used ? 1 : 0.5;
    return {
      fill:   `hsla(${hue},${sat_s}%,${light}%,${opacity})`,
      stroke: used ? `hsl(${hue},80%,${Math.min(light+15,90)}%)` : C.ring,
      sw:     used ? 0.5 : 1,
    };
  }

  const shape = s => {
    const g = s.gnss, p = s.prn;
    if (g===6||(p>=65&&p<96))     return 'sq';
    if (g===3||p>=400)             return 'td';
    if (g===2||(p>=300&&p<400))   return 'tu';
    if (g===1)                     return 'di';
    return 'ci';
  };

  const satEl = s => {
    if (s.el == null || s.az == null) return '';
    const [x,y]           = azel2xy(s.az, s.el);
    const S                = 5.5;
    const { fill, stroke, sw } = satColor(s);
    const sh               = shape(s);
    let el = '';
    if      (sh==='ci') el = `<circle cx="${x}" cy="${y}" r="${S}" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"/>`;
    else if (sh==='sq') el = `<rect x="${x-S}" y="${y-S}" width="${S*2}" height="${S*2}" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"/>`;
    else if (sh==='tu') { const p=`${x},${y-S} ${x-S},${y+S} ${x+S},${y+S}`; el=`<polygon points="${p}" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"/>`; }
    else if (sh==='td') { const p=`${x},${y+S} ${x-S},${y-S} ${x+S},${y-S}`; el=`<polygon points="${p}" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"/>`; }
    else if (sh==='di') { const p=`${x},${y-S} ${x+S},${y} ${x},${y+S} ${x-S},${y}`; el=`<polygon points="${p}" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"/>`; }
    const lbl = `<text x="${x+S+2}" y="${y+3}" font-family="JetBrains Mono,monospace" font-size="6.5" fill="${C.gridText}">${s.prn}</text>`;
    return `<g><title>PRN:${s.prn} El:${s.el}° Az:${s.az}° SNR:${s.ss||0}dBHz${s.used?' ✓':''}</title>${el}${lbl}</g>`;
  };

  let rings = '', rads = '';
  for (let e = 0; e <= 75; e += 15) {
    const r = R * (1 - e/90);
    rings += `<circle cx="${CX}" cy="${CY}" r="${r.toFixed(1)}" fill="none" stroke="${C.ring}" stroke-width=".5" ${e>0?'stroke-dasharray="3,4"':''}/>`;
    if (e > 0) rings += `<text x="${CX+4}" y="${(CY-r+8).toFixed(1)}" font-family="JetBrains Mono,monospace" font-size="6.5" fill="${C.gridText}">${e}°</text>`;
  }
  for (let a = 0; a < 180; a += 30) {
    const [x0,y0] = azel2xy(a,0), [x1,y1] = azel2xy(a+180,0);
    rads += `<line x1="${x0}" y1="${y0}" x2="${x1}" y2="${y1}" stroke="${C.ring}" stroke-width=".5"/>`;
  }
  const comp = [
    {l:'N',az:0,ox:0,oy:-4},{l:'E',az:90,ox:8,oy:4},
    {l:'S',az:180,ox:0,oy:11},{l:'W',az:270,ox:-10,oy:4},
  ].map(({l,az,ox,oy}) => {
    const [x,y] = azel2xy(az,0);
    return `<text x="${x+ox}" y="${y+oy}" font-family="JetBrains Mono,monospace" font-size="9" font-weight="700" fill="${C.compass}" text-anchor="middle">${l}</text>`;
  }).join('');

  if (!sats || !sats.length) {
    body.innerHTML = '<div class="err-msg">Нет данных</div>';
    if (bb) { bb.textContent = '—'; bb.className = 'block-badge'; }
    return;
  }

  const uc = sats.filter(s => s.used).length;
  if (bb) { bb.textContent = `${uc}/${sats.length}`; bb.className = 'block-badge '+(uc>4?'good':uc>0?'warn':'error'); }

  body.innerHTML =
    `<svg viewBox="0 0 ${SZ} ${SZ}" width="${SZ}" height="${SZ}" xmlns="http://www.w3.org/2000/svg">` +
    `<rect width="${SZ}" height="${SZ}" fill="${C.bgOuter}"/>` +
    `<circle cx="${CX}" cy="${CY}" r="${R.toFixed(1)}" fill="${C.bgInner}" stroke="${C.ring}" stroke-width="1"/>` +
    rings + rads + comp + sats.map(satEl).join('') +
    `</svg>` +
    `<div class="sky-legend">` +
    `<div class="sky-legend-item"><svg class="sky-legend-shape" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="hsl(190,80%,55%)"/></svg>GPS</div>` +
    `<div class="sky-legend-item"><svg class="sky-legend-shape" viewBox="0 0 10 10"><rect x="1" y="1" width="8" height="8" fill="hsl(0,70%,55%)"/></svg>ГЛОНАСС</div>` +
    `<div class="sky-legend-item"><svg class="sky-legend-shape" viewBox="0 0 10 10"><polygon points="5,1 9,9 1,9" fill="hsl(140,70%,45%)"/></svg>Galileo▲</div>` +
    `<div class="sky-legend-item"><svg class="sky-legend-shape" viewBox="0 0 10 10"><polygon points="5,9 9,1 1,1" fill="hsl(45,80%,55%)"/></svg>BeiDou▼</div>` +
    `<div class="sky-legend-item"><svg class="sky-legend-shape" viewBox="0 0 10 10"><polygon points="5,1 9,5 5,9 1,5" fill="hsl(270,70%,65%)"/></svg>SBAS◇</div>` +
    `<div class="sky-legend-item" style="color:var(--text3)">Яркость=SNR</div>` +
    `</div>`;
}

// ══════════════════════════════════════════════════════════════
// Render: Map (Leaflet / OpenStreetMap)
// ══════════════════════════════════════════════════════════════

function renderMap(lat, lon) {
  const container = $('map-container');
  const bb        = $('bb-map');
  if (!container) return;

  if (lat == null || lon == null) {
    if (state.leafletMap) { state.leafletMap.remove(); state.leafletMap = null; }
    container.innerHTML = '<div class="info-msg" style="line-height:230px">Нет GPS фикса</div>';
    if (bb) { bb.textContent = 'Нет фикса'; bb.className = 'block-badge'; }
    return;
  }

  if (bb) { bb.textContent = `${lat.toFixed(4)}°, ${lon.toFixed(4)}°`; bb.className = 'block-badge good'; }

  if (!state.leafletMap) {
    container.innerHTML = '';
    state.leafletMap = L.map(container, { zoomControl:true }).setView([lat, lon], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution:'© OpenStreetMap', maxZoom:19 }).addTo(state.leafletMap);
    const icon = L.divIcon({
      html: '<div style="width:14px;height:14px;background:#00d4ff;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px #00d4ff"></div>',
      iconSize:[14,14], iconAnchor:[7,7], className:'',
    });
    state.leafletMarker = L.marker([lat, lon], { icon }).addTo(state.leafletMap);
    state.leafletMarker.bindPopup(`<b>NTP Сервер</b><br>${lat.toFixed(6)}°, ${lon.toFixed(6)}°`);
  } else {
    state.leafletMap.setView([lat, lon]);
    if (state.leafletMarker) state.leafletMarker.setLatLng([lat, lon]);
  }
}

// ══════════════════════════════════════════════════════════════
// Render: Tracking
// ══════════════════════════════════════════════════════════════

function renderTracking(d) {
  const tr   = d.tracking || {};
  const body = $('body-tracking');
  if (!body) return;

  const kv = (l, v, e='') => {
    if (!v && v !== 0) return '';
    const val = (typeof v === 'object' && v.value !== undefined)
      ? `${h(v.value)}<span style="color:var(--text3);font-size:.6rem;margin-left:2px">${h(v.unit)}</span>`
      : h(v);
    return `<div class="kv-row"><span class="kv-key">${l}</span><span class="kv-val ${e}">${val}</span></div>`;
  };

  const sv   = Math.abs(parseFloat(tr.system_time || 0));
  const scls = sv > 0.5 ? 'error' : sv > 0.1 ? 'warn' : 'good';

  body.innerHTML =
    `<div class="kv-list">` +
    kv('Reference ID', tr.reference_id, 'accent') +
    kv('Stratum', tr.stratum) +
    kv('Ref time', tr.ref_time ? tr.ref_time.substring(0,19) : '—') +
    kv('Смещение', tr.system_time_fmt, scls) +
    kv('Last offset', tr.last_offset_fmt) +
    kv('RMS offset', tr.rms_offset_fmt) +
    kv('Частота', tr.frequency ? tr.frequency+' ppm' : '—') +
    kv('Skew', tr.skew ? tr.skew+' ppm' : '—') +
    kv('Root delay', tr.root_delay_fmt) +
    kv('Leap', tr.leap_status, tr.leap_status==='Normal'?'good':'warn') +
    `</div>`;
}

// ══════════════════════════════════════════════════════════════
// Render: Clients
// ══════════════════════════════════════════════════════════════

function renderClients(d) {
  const list = (d.clients?.list || []).filter(c => c.hostname !== 'localhost');
  const bb   = $('bb-clients');
  const body = $('body-clients');
  if (!body) return;

  if (bb) { bb.textContent = list.length + ' клиент' + (list.length===1?'':'ов'); bb.className = 'block-badge '+(list.length>0?'good':''); }
  if (!list.length) { body.innerHTML = '<div class="info-msg">Нет активных клиентов</div>'; return; }

  body.innerHTML =
    `<table class="tbl"><thead><tr><th>Хост</th><th>NTP</th><th>Drop</th><th>Last</th></tr></thead><tbody>` +
    list.map(c =>
      `<tr>` +
      `<td style="color:var(--text);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${h(c.hostname)}">${h(c.hostname)}</td>` +
      `<td>${c.ntp}</td>` +
      `<td>${c.drop>0?`<span style="color:var(--yellow)">${c.drop}</span>`:'0'}</td>` +
      `<td>${h(c.last)}</td>` +
      `</tr>`
    ).join('') +
    `</tbody></table>`;
}

// ══════════════════════════════════════════════════════════════
// Render: System
// ══════════════════════════════════════════════════════════════

function renderSystem(d) {
  const sys  = d.system    || {};
  const ss   = d.serverstats || {};
  const mem  = sys.memory  || {};
  const load = sys.load    || [0,0,0];
  const body = $('body-system');
  if (!body) return;

  const t  = sys.temp;
  const tc = t > 75 ? 'error' : t > 60 ? 'warn' : 'good';
  const mp = mem.percent || 0;
  const mc = mp > 90 ? 'error' : mp > 75 ? 'warn' : 'good';

  body.innerHTML =
    `<div class="kv-list">` +
    `<div class="kv-row"><span class="kv-key">Uptime</span><span class="kv-val">${h(sys.uptime)}</span></div>` +
    (t!=null ? `<div class="kv-row"><span class="kv-key">Температура</span><span class="kv-val ${tc}">${t} °C</span></div><div class="progress-wrap"><div class="progress-bar ${t>75?'hot':t>60?'warm':''}" style="width:${Math.min(t,100)}%"></div></div>` : '') +
    (mem.percent!=null ? `<div class="kv-row" style="margin-top:4px"><span class="kv-key">RAM</span><span class="kv-val ${mc}">${mp}%</span></div><div class="progress-wrap"><div class="progress-bar ${mc}" style="width:${mp}%"></div></div>` : '') +
    `<div class="kv-row" style="margin-top:4px"><span class="kv-key">Load</span><span class="kv-val">${load.map(l=>l.toFixed(2)).join(' ')}</span></div>` +
    `<div class="kv-row"><span class="kv-key">chrony</span><span class="kv-val ${sys.chrony_active?'good':'error'}">${sys.chrony_active?'active':'stopped'}</span></div>` +
    `<div class="kv-row"><span class="kv-key">gpsd</span><span class="kv-val ${sys.gpsd_active?'good':'error'}">${sys.gpsd_active?'active':'stopped'}</span></div>` +
    `<div class="kv-row"><span class="kv-key">/dev/pps0</span><span class="kv-val ${sys.pps_device?'good':'error'}">${sys.pps_device?'✓':'✗'}</span></div>` +
    `<div class="kv-row"><span class="kv-key">/dev/ttyAMA0</span><span class="kv-val ${sys.uart_device?'good':'error'}">${sys.uart_device?'✓':'✗'}</span></div>` +
    (ss.packets_received!=null ? `<div class="kv-row"><span class="kv-key">NTP пакетов</span><span class="kv-val">${ss.packets_received}</span></div>` : '') +
    `</div>`;
}

// ══════════════════════════════════════════════════════════════
// Render: Sourcestats
// ══════════════════════════════════════════════════════════════

function renderSourcestats(d) {
  const list = (d.sourcestats || {}).list || [];
  const body = $('body-sourcestats');
  if (!body) return;

  if (!list.length) { body.innerHTML = '<div class="info-msg">Нет данных</div>'; return; }

  body.innerHTML =
    `<table class="tbl"><thead><tr>` +
    `<th>Источник</th><th>NP</th><th>NR</th><th>Span</th><th>Freq (ppm)</th><th>Freq skew</th><th>Offset</th><th>Std Dev</th>` +
    `</tr></thead><tbody>` +
    list.map(s =>
      `<tr><td style="color:var(--text)">${h(s.name)}</td><td>${h(s.np)}</td><td>${h(s.nr)}</td><td>${h(s.span)}</td><td>${h(s.frequency)}</td><td>${h(s.freq_skew)}</td><td>${h(s.offset)}</td><td>${h(s.std_dev)}</td></tr>`
    ).join('') +
    `</tbody></table>`;
}

// ══════════════════════════════════════════════════════════════
// Render: Offset chart (Canvas)
// ══════════════════════════════════════════════════════════════

function addOffsetPoint(tr) {
  const v = parseFloat(tr.system_time || 0);
  if (!isNaN(v)) {
    state.offsets.push({ t: Date.now(), v: v * 1e9 });
    if (state.offsets.length > 300) state.offsets.shift();
  }
}

function renderChart() {
  const cvs = $('offset-canvas');
  if (!cvs) return;

  if (state.offsets.length < 2) { cvs.style.display = 'none'; return; }
  cvs.style.display = 'block';

  const W = cvs.parentElement.clientWidth || 800, H = 200;
  cvs.width = W; cvs.height = H;

  const ctx  = cvs.getContext('2d');
  ctx.clearRect(0, 0, W, H);

  const vals = state.offsets.map(p => p.v);
  let mn = Math.min(...vals), mx = Math.max(...vals);
  const rng = mx - mn || 1; mn -= rng*.15; mx += rng*.15;

  const pad = { t:16, r:70, b:26, l:60 };
  const cw  = W - pad.l - pad.r;
  const ch  = H - pad.t - pad.b;
  const tx  = t => pad.l + ((t - state.offsets[0].t) / (state.offsets[state.offsets.length-1].t - state.offsets[0].t || 1)) * cw;
  const ty  = v => pad.t + (1 - (v - mn) / (mx - mn)) * ch;

  // Grid
  for (let i = 0; i <= 4; i++) {
    const y = pad.t + i*ch/4, val = mx - i*(mx-mn)/4;
    ctx.strokeStyle = '#1e2a3a'; ctx.lineWidth = .5;
    ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(W-pad.r, y); ctx.stroke();
    ctx.fillStyle = '#4a6a88'; ctx.font = '10px JetBrains Mono,monospace'; ctx.textAlign = 'right';
    ctx.fillText(val.toFixed(1)+' нс', pad.l-4, y+4);
  }

  // Zero line
  if (mn < 0 && mx > 0) {
    const y0 = ty(0);
    ctx.strokeStyle = '#243347'; ctx.lineWidth = 1; ctx.setLineDash([4,4]);
    ctx.beginPath(); ctx.moveTo(pad.l, y0); ctx.lineTo(W-pad.r, y0); ctx.stroke();
    ctx.setLineDash([]);
  }

  // Area fill
  ctx.beginPath(); ctx.moveTo(tx(state.offsets[0].t), ty(0));
  state.offsets.forEach(p => ctx.lineTo(tx(p.t), ty(p.v)));
  ctx.lineTo(tx(state.offsets[state.offsets.length-1].t), ty(0)); ctx.closePath();
  const g = ctx.createLinearGradient(0, pad.t, 0, H-pad.b);
  g.addColorStop(0, 'rgba(0,212,255,.18)'); g.addColorStop(1, 'rgba(0,212,255,.01)');
  ctx.fillStyle = g; ctx.fill();

  // Line
  ctx.beginPath(); ctx.strokeStyle = '#00d4ff'; ctx.lineWidth = 1.5; ctx.lineJoin = 'round';
  state.offsets.forEach((p,i) => { i===0 ? ctx.moveTo(tx(p.t), ty(p.v)) : ctx.lineTo(tx(p.t), ty(p.v)); });
  ctx.stroke();

  // Last point dot + label
  const lp = state.offsets[state.offsets.length-1];
  ctx.beginPath(); ctx.arc(tx(lp.t), ty(lp.v), 3, 0, Math.PI*2); ctx.fillStyle = '#00d4ff'; ctx.fill();
  ctx.fillStyle = '#00d4ff'; ctx.font = 'bold 10px JetBrains Mono,monospace'; ctx.textAlign = 'left';
  ctx.fillText(lp.v.toFixed(2)+' нс', W-pad.r+4, ty(lp.v)+4);

  // Time axis
  ctx.fillStyle = '#4a6a88'; ctx.font = '10px JetBrains Mono,monospace'; ctx.textAlign = 'center';
  [0,.25,.5,.75,1].forEach(f => {
    const p = state.offsets[Math.floor(f*(state.offsets.length-1))]; if (!p) return;
    const dt = new Date(p.t);
    ctx.fillText(
      `${String(dt.getHours()).padStart(2,'0')}:${String(dt.getMinutes()).padStart(2,'0')}:${String(dt.getSeconds()).padStart(2,'0')}`,
      tx(p.t), H-pad.b+14
    );
  });

  const bb = $('bb-chart');
  if (bb) bb.textContent = lp.v.toFixed(2)+' нс';
}

// ══════════════════════════════════════════════════════════════
// Popup: Connection instructions
// ══════════════════════════════════════════════════════════════

async function showConnPopup() {
  const mode = (state.mode === 'ucenter') ? 'ucenter' : 'ntp';
  let data = null;
  try { data = await fetchJSON(`${ACTION}?action=get_instructions&mode=${mode}`); } catch(e) {}

  const ip     = data?.ip       || state.server.ip       || '192.168.x.x';
  const host   = data?.hostname || state.server.hostname  || 'ntp.local';
  const ntpP   = data?.ntp_port || state.server.ntp_port  || 123;
  const s2P    = data?.ser2_port|| state.server.ser2_port || 2947;
  const instrs = data?.instructions || {};

  $('popup-title').textContent = mode === 'ucenter'
    ? `u-center (ser2net) · tcp://${ip}:${s2P}`
    : `NTP сервер · ${ip} (${host}) · UDP ${ntpP}`;

  const tabs = Object.keys(instrs);
  if (!tabs.length) {
    $('popup-body').innerHTML = '<div class="info-msg">Инструкции не настроены в config.json</div>';
    $('conn-popup').classList.add('open');
    return;
  }

  const tabHtml = tabs.map((t,i) => `<button class="popup-tab ${i===0?'active':''}" onclick="switchPopupTab(this,'ptab-${i}')">${t}</button>`).join('');
  const secHtml = tabs.map((t,i) => {
    const header = mode==='ucenter' ? `<h4>tcp://${ip}:${s2P}</h4><p style="font-size:.68rem;color:var(--text3);margin-bottom:6px">Переключи режим кнопкой "u-center" выше</p>` : `<h4>${ip} (${host}) · UDP ${ntpP}</h4>`;
    return `<div id="ptab-${i}" class="popup-section ${i===0?'active':''}">${header}<pre>${h(instrs[t]||'')}</pre></div>`;
  }).join('');

  $('popup-body').innerHTML = `<div class="popup-tabs">${tabHtml}</div>${secHtml}`;
  $('conn-popup').classList.add('open');
}

function switchPopupTab(btn, id) {
  btn.parentElement.querySelectorAll('.popup-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  btn.closest('.popup-body').querySelectorAll('.popup-section').forEach(s => s.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
}

function closePopup(id) {
  const el = $(id);
  if (el) el.classList.remove('open');
}

// ══════════════════════════════════════════════════════════════
// Popup: Settings
// ══════════════════════════════════════════════════════════════

async function openSettings() {
  let configRaw = '{}';
  try {
    const d = await fetchJSON(`${ACTION}?action=config_read`);
    if (d.success) configRaw = d.raw || JSON.stringify(d.config, null, 2);
  } catch(e) {}

  $('settings-body').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:16px">
      <div>
        <div style="font-size:.63rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Тема</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="applyTheme('dark');localStorage.setItem('ntp_theme','dark')">🌙 Тёмная</button>
          <button class="btn btn-primary" onclick="applyTheme('light');localStorage.setItem('ntp_theme','light')">☀️ Светлая</button>
          <button class="btn btn-warn"    onclick="localStorage.removeItem('ntp_theme');applyTheme(new Date().getHours()>=7&&new Date().getHours()<20?'light':'dark')">🕐 Авто</button>
        </div>
      </div>
      <div>
        <div style="font-size:.63rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Интервал обновления</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          ${[10,15,30,60].map(s=>`<button class="btn btn-primary" onclick="setRefreshInterval(${s})">${s} с</button>`).join('')}
        </div>
      </div>
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
          <div style="font-size:.63rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.08em">config.json</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn btn-green"   onclick="saveConfig()">💾 Сохранить</button>
            <button class="btn btn-primary" onclick="testTelegram()">📨 Тест Telegram</button>
          </div>
        </div>
        <textarea id="config-editor" style="width:100%;height:300px;font-family:var(--mono);font-size:.65rem;background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:4px;padding:10px;resize:vertical;outline:none;tab-size:2">${h(configRaw)}</textarea>
        <div id="config-status" style="font-family:var(--mono);font-size:.63rem;margin-top:5px;min-height:16px"></div>
      </div>
      <div id="tg-test-result" style="display:none">
        <div style="font-size:.63rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px">Ответ Telegram</div>
        <div id="tg-test-output" class="repair-output"></div>
      </div>
    </div>`;

  $('settings-popup').classList.add('open');
}

async function saveConfig() {
  const raw    = $('config-editor')?.value;
  const status = $('config-status');
  if (!raw || !status) return;
  try { JSON.parse(raw); } catch(e) {
    status.style.color = 'var(--red)'; status.textContent = 'Ошибка JSON: ' + e.message; return;
  }
  try {
    const d = await postJSON(`${ACTION}?action=config_write`, raw);
    status.style.color  = d.success ? 'var(--green)' : 'var(--red)';
    status.textContent  = d.output || (d.success ? 'Сохранено' : 'Ошибка');
  } catch(e) { status.style.color = 'var(--red)'; status.textContent = 'Ошибка: ' + e.message; }
}

async function testTelegram() {
  const res = $('tg-test-result'), out = $('tg-test-output');
  if (!res || !out) return;
  res.style.display = 'block';
  out.className = 'repair-output'; out.innerHTML = '<span class="spinner"></span>Отправка...';
  try {
    const d = await fetchJSON(`${ACTION}?action=telegram_test`);
    out.textContent = d.output || d.error || '(нет ответа)';
    out.className   = 'repair-output' + (d.success ? '' : ' error-out');
  } catch(e) { out.textContent = 'Ошибка: ' + e.message; out.className = 'repair-output error-out'; }
}

function setRefreshInterval(s) {
  clearInterval(state.refreshTimer);
  state.refreshTimer = setInterval(refresh, s * 1000);
}

// ══════════════════════════════════════════════════════════════
// Mode switch (NTP ↔ u-center)
// ══════════════════════════════════════════════════════════════

async function switchMode(mode) {
  const out = $('repair-output');
  if (out) { out.className = 'repair-output'; out.innerHTML = '<span class="spinner"></span>Переключение...'; }
  document.querySelectorAll('.btn').forEach(b => b.disabled = true);

  try {
    const d = await fetchJSON(`${ACTION}?action=switch_mode&mode=${mode}`);
    if (out) { out.textContent = d.output || ''; out.className = 'repair-output' + (d.success?'':' error-out'); }
    if (d.success) {
      state.mode = d.mode || mode;
      updateModeUI(state.mode);
      // Показать popup с инструкцией по ser2net
      if (state.mode === 'ucenter') showConnPopup();
    }
  } catch(e) {
    if (out) { out.textContent = 'Ошибка: '+e.message; out.className = 'repair-output error-out'; }
  }

  document.querySelectorAll('.btn').forEach(b => b.disabled = false);
}

async function checkMode() {
  try {
    const d = await fetchJSON(`${ACTION}?action=current_mode`);
    if (d.success) { state.mode = d.mode || 'time'; updateModeUI(state.mode); }
  } catch(e) {}
}

function updateModeUI(mode) {
  const mb  = $('h-mode');
  const bN  = $('btn-mode-ntp');
  const bU  = $('btn-mode-ucenter');
  if (mode === 'ucenter') {
    if (mb) { mb.textContent = '📡 u-center'; mb.className = 'mode-badge ucenter'; }
    bN?.classList.remove('active'); bU?.classList.add('active');
  } else {
    if (mb) { mb.textContent = '⏱ NTP'; mb.className = 'mode-badge ntp'; }
    bN?.classList.add('active'); bU?.classList.remove('active');
  }
}

// ══════════════════════════════════════════════════════════════
// Repair panel actions
// ══════════════════════════════════════════════════════════════

async function doAction(action, outId) {
  const el = $(outId);
  if (!el) return;
  el.className = 'repair-output'; el.innerHTML = '<span class="spinner"></span>Выполняется...';
  document.querySelectorAll('.btn').forEach(b => b.disabled = true);
  try {
    const d = await fetchJSON(`${ACTION}?action=${action}`);
    el.textContent = d.output || d.error || '(нет вывода)';
    el.className   = 'repair-output' + (d.success===false ? ' error-out' : '');
  } catch(e) {
    el.textContent = 'Ошибка: ' + e.message;
    el.className   = 'repair-output error-out';
  }
  document.querySelectorAll('.btn').forEach(b => b.disabled = false);
}

// ══════════════════════════════════════════════════════════════
// Main render & data fetch
// ══════════════════════════════════════════════════════════════

function render(d) {
  renderHeader(d);
  renderCards(d);
  renderSources(d);
  renderGps(d);
  renderGnss(d);
  renderTracking(d);
  renderClients(d);
  renderSystem(d);
  renderSourcestats(d);
  addOffsetPoint(d.tracking || {});
  renderChart();
}

async function refresh() {
  try {
    const d = await fetchJSON(API);
    render(d);
    if (state.firstLoad) {
      state.firstLoad = false;
      const loader = $('loader');
      if (loader) { loader.style.opacity = '0'; setTimeout(() => loader.style.display='none', 400); }
      const app = $('app');
      if (app) app.style.opacity = '1';
    }
  } catch(e) {
    const ht = $('h-time');
    if (ht) ht.textContent = 'Ошибка соединения';
    console.warn('refresh error:', e);
  }
}

function startTimer() {
  clearInterval(state.refreshTimer);
  state.refreshTimer = setInterval(refresh, 15000);
}

// ══════════════════════════════════════════════════════════════
// Инициализация
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  // Закрытие попапов по клику на оверлей
  ['conn-popup','settings-popup'].forEach(id => {
    const el = $(id);
    if (el) el.addEventListener('click', e => { if (e.target === e.currentTarget) closePopup(id); });
  });

  setInterval(tickClock, 10);
  tickClock();
  initTheme();
  fetchServerInfo();
  checkMode();
  fetchTelegramStatus();
  setInterval(fetchTelegramStatus, 60000);
  refresh().then(startTimer);
});
