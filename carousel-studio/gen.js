#!/usr/bin/env node
/**
 * Carousel Studio — Slide Generator
 *
 * Usage:
 *   node gen.js topic.json
 *
 * Each slide is a self-contained 1080×1350 HTML file.
 * A ?render=1 query param freezes animation at a given frame
 * so headless Chrome can screenshot it deterministically.
 *
 * Slide types: cover | step | stat | cta
 */

const fs   = require('fs');
const path = require('path');

// ── Paths (relative to this script) ──────────────────────────────────────
const ROOT       = __dirname;
const SLIDES_DIR = path.join(ROOT, 'slides');
const ASSETS_DIR = path.join(ROOT, 'assets');

// ── Helpers ───────────────────────────────────────────────────────────────
function assetPath(file) {
  // Always use absolute file:// paths so headless Chrome can load them
  return `file://${path.join(ASSETS_DIR, file)}`;
}

function avatarTag(pose, style = '') {
  const p = path.join(ASSETS_DIR, `pose-${pose}-cutout.png`);
  const src = fs.existsSync(p)
    ? `file://${p}`
    : `file://${path.join(ASSETS_DIR, 'pose-placeholder.png')}`;
  return `<div class="avatar" style="${style}">
    <img src="${src}" alt="avatar ${pose}">
  </div>`;
}

function iconTag(name) {
  const iconPath = path.join(ROOT, 'icons', `${name}.png`);
  const src = fs.existsSync(iconPath)
    ? `file://${iconPath}`
    : `data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='4' fill='%233B82F6'/></svg>`;
  return `<div class="icon-tile"><img src="${src}" alt="${name}"></div>`;
}

// ── Shared HTML shell ──────────────────────────────────────────────────────
// The ?render=N param lets headless Chrome seek to frame N before screenshot.
function shell(content, gsapTimeline, durationMs = 3000) {
  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="${assetPath('base.css')}">
  <style>
    /* slide-level overrides go here */
    .slide-inner { position: relative; z-index: 10; width: 100%; height: 100%; }
  </style>
</head>
<body>
<div class="slide" id="slide">
  <div class="bg-radial"></div>
  <div class="bg-dots"></div>
  <div class="slide-inner">
    ${content}
  </div>
</div>

<script src="${assetPath('gsap.min.js')}"></script>
<script>
// Seek support: ?render=<ms> jumps the timeline to that offset
const params   = new URLSearchParams(location.search);
const seekMs   = parseFloat(params.get('render') || '0');
const DURATION = ${durationMs};

window.slideReady = false;   // render.sh polls this

(function() {
  const tl = gsap.timeline({ paused: true, defaults: { ease: 'power3.out' } });

  // ── Timeline ──────────────────────────────────────────────────────────
  ${gsapTimeline}
  // ─────────────────────────────────────────────────────────────────────

  tl.totalDuration(); // force calculation

  if (seekMs > 0) {
    // Deterministic render mode: jump to requested time, stay frozen
    tl.seek(seekMs / 1000, false);
  } else {
    tl.play();
  }

  window.slideReady = true;
  window.slideTimeline = tl;
  window.slideDuration = DURATION;
})();
</script>
</body>
</html>`;
}

// ═══════════════════════════════════════════════════════════════════════════
// SLIDE TEMPLATES
// ═══════════════════════════════════════════════════════════════════════════

/**
 * COVER — dark headline plate, avatar left, orbit icons around
 *
 * @param {object} s
 * @param {string} s.overline   small uppercase label e.g. "5 Tools"
 * @param {string} s.headline   big bold headline
 * @param {string} s.sub        subtitle / teaser
 * @param {string[]} s.icons    icon names (from icons/)
 * @param {string} s.pose       avatar pose name
 */
function cover(s) {
  const icons = (s.icons || []).slice(0, 6).map(i => iconTag(i)).join('\n    ');
  const orbitItems = (s.icons || []).slice(0, 6).map((_, i) => {
    const angle = (i / Math.max(s.icons.length, 1)) * 360;
    const rad   = angle * Math.PI / 180;
    const r     = 380; // orbit radius in px from plate center
    const cx = 50 + r * Math.cos(rad - Math.PI / 2) / 1080 * 100;
    const cy = 50 + r * Math.sin(rad - Math.PI / 2) / 1350 * 100;
    return `<div class="icon-tile" id="icon${i}" style="
      position:absolute;
      left:${cx.toFixed(1)}%;
      top:${cy.toFixed(1)}%;
      transform:translate(-50%,-50%);
      z-index:5;
    ">${iconTag((s.icons || [])[i])}</div>`;
  }).join('\n');

  const content = `
    ${orbitItems}
    <div style="
      position:absolute; left:50%; top:50%;
      transform:translate(-50%,-50%);
      width:780px;
      z-index:10;
    " id="plate">
      <div class="plate col" style="gap:24px; text-align:center; align-items:center;">
        <div class="pill t-overline" id="pill">${s.overline || ''}</div>
        <h1 class="t-display" id="hl" style="text-align:center;">${s.headline}</h1>
        <div class="accent-line" id="aline"></div>
        <p class="t-muted" id="sub" style="font-size:34px; color:#94A3B8; max-width:580px; text-align:center;">${s.sub || ''}</p>
      </div>
    </div>
    ${avatarTag(s.pose || 'casual', 'left: 30px; bottom: 0;')}
  `;

  const timeline = `
    gsap.set(['#plate','#pill','#hl','#aline','#sub'], { opacity:0 });
    ${(s.icons||[]).slice(0,6).map((_,i) => `gsap.set('#icon${i}', { opacity:0, scale:0.6 });`).join('\n    ')}
    gsap.set('.avatar img', { opacity:0, y:60 });

    tl
      .to('#plate',  { opacity:1, y:-20, duration:0 })
      .to('#plate',  { y:0, duration:.6 })
      .to('#pill',   { opacity:1, y:0, duration:.4 }, '-=.3')
      .to('#hl',     { opacity:1, y:0, duration:.5 }, '-=.2')
      .to('#aline',  { opacity:1, scaleX:1, duration:.4, transformOrigin:'left' }, '-=.1')
      .to('#sub',    { opacity:1, y:0, duration:.4 }, '-=.1')
      .to('.avatar img', { opacity:1, y:0, duration:.6, ease:'power2.out' }, '-=.4')
      ${(s.icons||[]).slice(0,6).map((_,i) => `.to('#icon${i}', { opacity:1, scale:1, duration:.35 }, '-=.2')`).join('\n      ')};
  `;

  return shell(content, timeline, 3000);
}

/**
 * STEP — icon tile + step number + heading + one line of copy
 *
 * @param {object} s
 * @param {number} s.step       step number
 * @param {number} s.total      total steps
 * @param {string} s.icon       icon name
 * @param {string} s.heading    bold heading
 * @param {string} s.body       one line of body copy
 * @param {string} s.pose       avatar pose
 */
function step(s) {
  const content = `
    <div style="
      position:absolute; inset:0;
      display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      padding:80px;
      gap:0;
    ">
      <!-- Top: step counter pill -->
      <div class="pill t-overline" id="counter" style="margin-bottom:48px;">
        Step ${s.step} of ${s.total || '?'}
      </div>

      <!-- Main card -->
      <div class="card w-full" id="card" style="max-width:900px;">
        <div class="row gap-md" style="margin-bottom:36px;">
          <div id="badge"><div class="step-badge">${s.step}</div></div>
          <div id="icon">${iconTag(s.icon || '')}</div>
        </div>
        <h2 class="t-headline" id="heading" style="color:#0F172A; margin-bottom:20px;">${s.heading}</h2>
        <div class="accent-line" id="aline" style="background:var(--accent); margin-bottom:24px;"></div>
        <p class="t-body" id="body">${s.body}</p>
      </div>
    </div>
    ${avatarTag(s.pose || 'pointing', 'right: 0; bottom: 0; z-index: 20;')}
  `;

  const timeline = `
    gsap.set(['#counter','#card','#badge','#icon','#heading','#aline','#body','.avatar img'],
      { opacity:0, y:30 });

    tl
      .to('#counter',     { opacity:1, y:0, duration:.4 })
      .to('#card',        { opacity:1, y:0, duration:.5 }, '-=.2')
      .to('#badge',       { opacity:1, y:0, duration:.3 }, '-=.3')
      .to('#icon',        { opacity:1, y:0, duration:.3 }, '-=.2')
      .to('#heading',     { opacity:1, y:0, duration:.4 }, '-=.2')
      .to('#aline',       { opacity:1, scaleX:1, duration:.35, transformOrigin:'left' }, '-=.1')
      .to('#body',        { opacity:1, y:0, duration:.4 }, '-=.1')
      .to('.avatar img',  { opacity:1, y:0, duration:.5, ease:'power2.out' }, '-=.3');
  `;

  return shell(content, timeline, 3000);
}

/**
 * STAT — one giant number, context label, avatar
 *
 * @param {object} s
 * @param {string} s.stat       the number e.g. "10x"
 * @param {string} s.unit       unit or suffix e.g. "faster"
 * @param {string} s.label      what this stat means
 * @param {string} s.source     attribution (small, muted)
 * @param {string} s.pose       avatar pose
 */
function stat(s) {
  const content = `
    <div style="
      position:absolute; inset:0;
      display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      padding:80px; text-align:center;
    ">
      <div class="plate col" id="plate" style="
        gap:16px; align-items:center;
        width:860px; padding:80px 72px;
      ">
        <div class="t-overline" style="margin-bottom:8px;" id="over">${s.label || ''}</div>
        <div style="display:flex; align-items:flex-end; gap:16px; line-height:1;">
          <span class="t-stat" id="num">${s.stat}</span>
          <span class="t-stat-unit" id="unit" style="margin-bottom:18px;">${s.unit || ''}</span>
        </div>
        <div class="accent-line" id="aline" style="width:120px; margin:16px auto;"></div>
        <p class="t-muted" id="source" style="font-size:26px;">${s.source || ''}</p>
      </div>
    </div>
    ${avatarTag(s.pose || 'victory', 'right: 40px; bottom: 0;')}
  `;

  const timeline = `
    gsap.set(['#plate','#over','#num','#unit','#aline','#source','.avatar img'], { opacity:0 });
    gsap.set('#num', { scale:0.7 });

    tl
      .to('#plate',     { opacity:1, duration:.4 })
      .to('#over',      { opacity:1, y:0, duration:.35 }, '-=.2')
      .to('#num',       { opacity:1, scale:1, duration:.5, ease:'back.out(1.4)' }, '-=.1')
      .to('#unit',      { opacity:1, duration:.3 }, '-=.2')
      .to('#aline',     { opacity:1, duration:.3 }, '-=.1')
      .to('#source',    { opacity:1, duration:.3 }, '-=.1')
      .to('.avatar img',{ opacity:1, y:0, duration:.5, ease:'power2.out' }, '-=.3');
  `;

  return shell(content, timeline, 3000);
}

/**
 * CTA — comment-keyword call to action
 *
 * @param {object} s
 * @param {string} s.keyword    the comment keyword e.g. "TOOLS"
 * @param {string} s.hook       hook line e.g. "Want the full list?"
 * @param {string} s.cta        action line e.g. "Comment TOOLS below"
 * @param {string} s.sub        sub-note e.g. "I'll DM you the free PDF"
 * @param {string} s.handle     your @ handle
 * @param {string} s.pose       avatar pose
 */
function cta(s) {
  const content = `
    <div style="
      position:absolute; inset:0;
      display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      padding:80px 100px;
      text-align:center;
    ">
      <div class="col gap-md" style="align-items:center; width:100%; max-width:860px;">
        <div class="pill t-overline" id="badge" style="margin-bottom:8px;">Free for you</div>
        <h2 class="t-headline" id="hook" style="text-align:center;">${s.hook}</h2>
        <div class="accent-line" id="aline" style="width:100px; margin:8px auto;"></div>
        <p class="t-body" id="cta" style="font-size:44px; font-weight:700; color:#0F172A;">
          Comment <span class="keyword" id="kw">&nbsp;${s.keyword}&nbsp;</span> below
        </p>
        <p class="t-muted" id="sub" style="font-size:34px;">${s.sub || ''}</p>
        <div id="handle" style="
          margin-top:24px; font-size:32px; font-weight:700;
          color:var(--accent); letter-spacing:0.02em;
        ">${s.handle || ''}</div>
      </div>
    </div>
    ${avatarTag(s.pose || 'arms-crossed', 'right: 30px; bottom: 0;')}

    <!-- dark bg so white card floats -->
    <style>
      body { background: var(--bg-plate); }
      .bg-radial { background:
        radial-gradient(ellipse 800px 600px at 50% 20%,
          color-mix(in srgb, var(--accent) 22%, transparent) 0%, transparent 70%),
        var(--bg-plate);
      }
    </style>
  `;

  const timeline = `
    gsap.set(['#badge','#hook','#aline','#cta','#kw','#sub','#handle','.avatar img'],
      { opacity:0, y:24 });

    tl
      .to('#badge',    { opacity:1, y:0, duration:.4 })
      .to('#hook',     { opacity:1, y:0, duration:.5 }, '-=.2')
      .to('#aline',    { opacity:1, scaleX:1, duration:.35, transformOrigin:'center' }, '-=.1')
      .to('#cta',      { opacity:1, y:0, duration:.4 }, '-=.1')
      .to('#kw',       { scale:1.06, duration:.25, ease:'back.out(2)', yoyo:true, repeat:1 }, '-=.1')
      .to('#sub',      { opacity:1, y:0, duration:.4 }, '-=.1')
      .to('#handle',   { opacity:1, y:0, duration:.3 }, '-=.1')
      .to('.avatar img',{ opacity:1, y:0, duration:.5, ease:'power2.out' }, '-=.3');
  `;

  return shell(content, timeline, 3000);
}

// ═══════════════════════════════════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════════════════════════════════
function run() {
  const topicFile = process.argv[2];
  if (!topicFile) {
    console.error('Usage: node gen.js topic.json');
    process.exit(1);
  }

  const topic = JSON.parse(fs.readFileSync(topicFile, 'utf8'));

  if (!fs.existsSync(SLIDES_DIR)) fs.mkdirSync(SLIDES_DIR, { recursive: true });

  topic.slides.forEach((s, i) => {
    const num  = String(i + 1).padStart(2, '0');
    const file = path.join(SLIDES_DIR, `slide-${num}.html`);
    let html;

    switch (s.type) {
      case 'cover': html = cover(s); break;
      case 'step':  html = step(s);  break;
      case 'stat':  html = stat(s);  break;
      case 'cta':   html = cta(s);   break;
      default:
        console.warn(`Unknown slide type "${s.type}" at index ${i}, skipping.`);
        return;
    }

    fs.writeFileSync(file, html, 'utf8');
    console.log(`  Wrote ${file}`);
  });

  console.log(`\nDone. ${topic.slides.length} slides in ${SLIDES_DIR}`);
  console.log('Run QA:    node qa.js');
  console.log('Render:    bash render.sh');
}

run();
