#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════
# Carousel Studio — Renderer
#
# Workflow per slide:
#   1. Screenshot every frame at 30fps via headless Chrome
#   2. Retry any dropped frames once
#   3. ffmpeg → MP4 (H.264, 1080×1350, constant quality)
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

CHROME=${CHROME_PATH:-"/root/.cache/puppeteer/chrome/linux-150.0.7871.24/chrome-linux64/chrome"}
SLIDES_DIR="$(dirname "$0")/slides"
OUTPUT_DIR="$(dirname "$0")/output"
FRAMES_DIR="$(dirname "$0")/.frames"

FPS=30
DURATION_MS=3000                  # default slide duration; overridden per slide
PARALLELISM=2                     # Chrome instances at once (keep low, it's heavy)

mkdir -p "$OUTPUT_DIR" "$FRAMES_DIR"

# ── Find Chrome ────────────────────────────────────────────────────
if [ ! -f "$CHROME" ]; then
  for candidate in \
    /root/.cache/puppeteer/chrome/linux-*/chrome-linux64/chrome \
    /usr/bin/google-chrome \
    /usr/bin/chromium \
    /usr/bin/chromium-browser; do
    if [ -f "$candidate" ]; then CHROME="$candidate"; break; fi
  done
fi

if [ ! -f "$CHROME" ]; then
  echo "ERROR: Chrome not found. Set CHROME_PATH or run: npx puppeteer browsers install chrome"
  exit 1
fi

echo "Using Chrome: $CHROME"
echo "Slides dir:   $SLIDES_DIR"

# ── Screenshot one frame ────────────────────────────────────────────
# Args: <slide_html_path> <frame_ms> <out_png>
screenshot_frame() {
  local slide="$1"
  local ms="$2"
  local out="$3"

  "$CHROME" \
    --headless=new \
    --no-sandbox \
    --disable-setuid-sandbox \
    --disable-gpu \
    --disable-dev-shm-usage \
    --force-device-scale-factor=1 \
    --allow-file-access-from-files \
    --window-size=1080,1350 \
    --screenshot="$out" \
    --virtual-time-budget=5000 \
    "file://${slide}?render=${ms}" \
    2>/dev/null
}

# ── Render one slide to MP4 ─────────────────────────────────────────
render_slide() {
  local slide_html="$1"
  local slide_name
  slide_name="$(basename "$slide_html" .html)"
  local frames_sub="$FRAMES_DIR/$slide_name"
  local output_mp4="$OUTPUT_DIR/${slide_name}.mp4"

  mkdir -p "$frames_sub"
  echo ""
  echo "▶ Rendering $slide_name …"

  local total_frames=$(( DURATION_MS * FPS / 1000 ))
  local frame_step=$(( 1000 / FPS ))   # ms per frame

  # ── Pass 1: screenshot all frames ──────────────────────────────
  local pids=()
  local i=0

  while [ $i -lt $total_frames ]; do
    local ms=$(( i * frame_step ))
    local frame_num
    frame_num=$(printf "%04d" $i)
    local out_png="$frames_sub/frame-${frame_num}.png"

    if [ ! -f "$out_png" ] || [ ! -s "$out_png" ]; then
      screenshot_frame "$slide_html" "$ms" "$out_png" &
      pids+=($!)
    fi
    i=$(( i + 1 ))

    # Throttle parallelism
    if [ ${#pids[@]} -ge $PARALLELISM ]; then
      wait "${pids[@]}" 2>/dev/null || true
      pids=()
    fi
  done
  wait "${pids[@]}" 2>/dev/null || true

  # ── Pass 2: retry any missing/empty frames ──────────────────────
  echo "  Checking for dropped frames …"
  local retries=0
  i=0
  while [ $i -lt $total_frames ]; do
    local ms=$(( i * frame_step ))
    local frame_num
    frame_num=$(printf "%04d" $i)
    local out_png="$frames_sub/frame-${frame_num}.png"

    if [ ! -f "$out_png" ] || [ ! -s "$out_png" ]; then
      echo "  Retry frame $frame_num (${ms}ms)"
      screenshot_frame "$slide_html" "$ms" "$out_png" || true
      retries=$(( retries + 1 ))
    fi
    i=$(( i + 1 ))
  done
  echo "  Retried $retries frames."

  # ── ffmpeg: frames → MP4 ────────────────────────────────────────
  echo "  Encoding MP4 …"
  ffmpeg -y \
    -framerate $FPS \
    -i "$frames_sub/frame-%04d.png" \
    -vf "scale=1080:1350:flags=lanczos" \
    -c:v libx264 \
    -preset slow \
    -crf 18 \
    -pix_fmt yuv420p \
    -movflags +faststart \
    "$output_mp4" \
    2>&1 | grep -E "frame=|fps=|speed=|encoded|error" || true

  if [ -f "$output_mp4" ]; then
    local size
    size=$(du -sh "$output_mp4" | cut -f1)
    echo "  ✓ $output_mp4 ($size)"
  else
    echo "  ✗ FAILED: $output_mp4 not created"
  fi
}

# ── Main: render all slides ─────────────────────────────────────────
slides=("$SLIDES_DIR"/slide-*.html)
if [ ${#slides[@]} -eq 0 ] || [ ! -f "${slides[0]}" ]; then
  echo "No slides found in $SLIDES_DIR. Run: node gen.js topic.json"
  exit 1
fi

echo "Found ${#slides[@]} slide(s). Starting render …"
total_start=$SECONDS

for slide in "${slides[@]}"; do
  render_slide "$slide"
done

echo ""
echo "═══════════════════════════════════════════"
echo "Done in $(( SECONDS - total_start ))s"
echo "MP4s in: $OUTPUT_DIR"
ls -lh "$OUTPUT_DIR"/*.mp4 2>/dev/null || echo "(none produced)"
