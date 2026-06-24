#!/usr/bin/env node
/**
 * Carousel Studio — QA Previewer
 *
 * Screenshots every slide at frame ~1500ms (mid-animation)
 * to a PNG for fast visual review before the full render.
 *
 * Usage: node qa.js [--frame 1500]
 */

const { execSync, spawn } = require('child_process');
const fs   = require('fs');
const path = require('path');

const ROOT      = __dirname;
const SLIDES    = path.join(ROOT, 'slides');
const QA_DIR    = path.join(ROOT, 'output', 'qa');
const FRAME_MS  = process.argv.includes('--frame')
  ? parseInt(process.argv[process.argv.indexOf('--frame') + 1], 10)
  : 1500;

const CHROME_CANDIDATES = [
  '/root/.cache/puppeteer/chrome/linux-150.0.7871.24/chrome-linux64/chrome',
  // glob fallback handled below
];

function findChrome() {
  for (const c of CHROME_CANDIDATES) {
    if (fs.existsSync(c)) return c;
  }
  // Try globbing for any puppeteer chrome
  try {
    const found = execSync(
      'find /root/.cache/puppeteer -name "chrome" -type f 2>/dev/null | head -1'
    ).toString().trim();
    if (found) return found;
  } catch {}
  throw new Error('Chrome not found. Run: npx puppeteer browsers install chrome');
}

async function screenshot(chrome, slidePath, outPng) {
  return new Promise((resolve, reject) => {
    const args = [
      '--headless=new',
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-gpu',
      '--disable-dev-shm-usage',
      '--force-device-scale-factor=1',
      '--allow-file-access-from-files',
      '--window-size=1080,1350',
      `--screenshot=${outPng}`,
      '--virtual-time-budget=5000',
      `file://${slidePath}?render=${FRAME_MS}`,
    ];
    const proc = spawn(chrome, args, { stdio: 'pipe' });
    proc.on('close', code => code === 0 ? resolve() : reject(new Error(`Chrome exit ${code}`)));
    proc.on('error', reject);
  });
}

async function main() {
  const chrome = findChrome();
  const slides = fs.readdirSync(SLIDES)
    .filter(f => f.endsWith('.html'))
    .sort()
    .map(f => path.join(SLIDES, f));

  if (slides.length === 0) {
    console.error('No slides found. Run: node gen.js topic.json');
    process.exit(1);
  }

  fs.mkdirSync(QA_DIR, { recursive: true });
  console.log(`QA: ${slides.length} slides at ${FRAME_MS}ms → ${QA_DIR}\n`);

  for (const slide of slides) {
    const name   = path.basename(slide, '.html');
    const outPng = path.join(QA_DIR, `${name}.png`);
    process.stdout.write(`  ${name} … `);
    try {
      await screenshot(chrome, slide, outPng);
      const kb = Math.round(fs.statSync(outPng).size / 1024);
      console.log(`✓ ${kb}KB`);
    } catch (e) {
      console.log(`✗ ${e.message}`);
    }
  }

  console.log(`\nDone. Open PNGs in: ${QA_DIR}`);
}

main().catch(e => { console.error(e); process.exit(1); });
