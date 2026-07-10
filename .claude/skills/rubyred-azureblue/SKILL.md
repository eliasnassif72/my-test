---
name: rubyred-azureblue
description: >-
  Character bible and Kling generation kit for the two "Perfection Sun Oil Lady"
  sunscreen-bottle mascots, Ruby Red and Azure Blue. Use this whenever creating,
  animating, redrawing, or writing prompts for any image, video, or commercial
  featuring Ruby Red and/or Azure Blue, so their colors, bottle shapes, faces,
  labels, gloves and shoes stay perfectly consistent across shots. Triggers:
  "ruby red", "azure blue", "perfection sun oil", "sun oil lady", "bottle
  mascots", "sunscreen mascot", or any new scene/commercial for these two
  characters.
---

# Ruby Red & Azure Blue — Character Skill

Two friendly 3D cartoon mascots shaped like glossy translucent plastic
sunscreen spray bottles for the brand **"K Perfection — Sun Oil Lady (Ladies)"**.
They are always an **equal hero pair**: Ruby Red (warm/red) on the **left**,
Azure Blue (cool/blue) on the **right**, standing side by side facing the
viewer. Pixar-style premium render, family-friendly, cheerful.

The canonical reference is `reference/rubyred-azureblue-reference.png`. When any
detail is unclear, defer to that image — never invent new design elements.

---

## 1. Shared anatomy & material (identical for both)

- **Body:** a tall, slim spray-bottle silhouette in **glossy, translucent
  plastic** with soft internal light and a bright vertical highlight. Slightly
  rounded rectangular body, narrow neck, ribbed collar, and a **spray-pump cap**
  with a pump nozzle on top.
- **Face:** placed on the upper third of the bottle. **Large white cartoon eyes**
  with round irises and small catch-lights, thick expressive **eyebrows**, and a
  wide open happy **smile** showing a tongue. Friendly, welcoming expression.
- **Limbs:** thin tube arms and legs in the **same color as the body**, ending in
  classic **four-fingered white cartoon gloves** and chunky **white-soled
  sneakers** with the character's accent color and white laces.
- **Proportions:** the two bottles are the **same height and scale** and stand a
  fixed shoulder-to-shoulder distance apart. Never resize one relative to the
  other; never change the gap between them.

## 2. Ruby Red (left character)

| Element | Spec |
|---|---|
| Body color | Vivid warm **red / vermilion**, approx `#E11800` (translucent, glossy) |
| Cap & nozzle | Same red, approx `#E21801` |
| Eyes | **Warm brown / amber** irises (approx `#A4630B`) |
| Gloves | White cartoon gloves |
| Shoes | White sneakers with **red** accents + white laces |
| Label accents | **Orange** "LADIES" side-pill, orange UV-A/UV-B blocks, orange divider |

## 3. Azure Blue (right character)

| Element | Spec |
|---|---|
| Body color | Bright **azure / sky blue**, approx `#0E9BD6` (translucent, glossy) |
| Cap & nozzle | Same blue, approx `#018EDE` |
| Eyes | **Blue** irises (approx `#0A6C90`) |
| Gloves | White cartoon gloves |
| Shoes | White sneakers with **blue** accents + white laces |
| Label accents | **Purple / indigo** "LADIES" side-pill, purple UV-A/UV-B blocks, purple divider |

## 4. Label layout (identical structure, mirrored accent color)

Both bottles carry the **same front label**, top to bottom:

1. Silver foil bar with a small **"K"** monogram and **"PERFECTION"** wordmark.
2. Ribbon: **"SUN PROTECTION"** (red on Ruby, purple on Azure).
3. Arabic **`زيت حمام شمسي`** over English **"SUN OIL LADY"**.
4. Vertical rounded pill reading **"LADIES"** (orange on Ruby, purple on Azure).
5. Arabic ingredient line **`بخلاصة زيت اللوز وجوز الهند والجزر`**.
6. Two rounded blocks: **"UV-A 15"** and **"UV-B 60"**.
7. **"VITAMIN E"** tab.
8. Arabic footer **`مع الترطيب القوي`**.

> The label text is small; treat it as a **locked graphic**. In video, keep the
> camera slow and the labels flat and legible. Perfectly crisp small text is
> beyond any current video model — for a real commercial, composite the label as
> a tracked overlay in post rather than expecting the model to hold it.

---

## 5. Consistency locks

**Always keep identical to the reference:** bottle shapes & proportions, the
glossy translucent plastic material, caps and pump nozzles, eye shape, eyebrow
shape, smiles, four-fingered gloves, sneakers, full label layout, the two exact
body colors, and the left-Ruby / right-Azure arrangement.

**Never:** redesign the characters, alter packaging details, change their
relative size or spacing, add fingers, crop a character (they must stay fully
visible from cap to shoes unless a shot is deliberately framed otherwise), or
let one character's color bleed into the other.

---

## 6. Canonical description block (paste into any prompt)

> Two glossy translucent plastic sunscreen-bottle mascots standing side by side,
> facing the camera. Left = **Ruby Red**, a vivid red bottle with a red pump
> cap, warm brown eyes, thick eyebrows, a wide happy smile, white four-fingered
> gloves and white sneakers with red accents. Right = **Azure Blue**, a bright
> sky-blue bottle with a blue pump cap, blue eyes, the same cheerful face, white
> gloves and white sneakers with blue accents. Both carry the "K Perfection —
> Sun Oil Lady" front label (silver bar, SUN PROTECTION ribbon, Arabic and
> English product text, a LADIES pill, UV-A 15 / UV-B 60 blocks, VITAMIN E).
> They are the same height, equal heroes, premium Pixar-style 3D render.

---

## 7. Kling generation kit

### Recommended setup
- **Image → Video (character consistency):** upload the reference image as the
  **first frame**, model **Kling 2.5 Turbo** (`kling-video-v2_5`) or **3.0
  Turbo** for single-image, **1080p**, 5s or 10s. This is the safest path for
  identity — the characters are copied from the real image, not re-imagined.
- **New still poses:** Kling **Image o1** (`kling-image-o1`, highest feature
  consistency) via image-to-image on the reference, editing **only** the pose.
- Because Kling 2.x/3.x image-to-video with frames has **no separate negative
  prompt field**, fold "avoid" notes into the positive prompt as desired states
  (see below).

### Motion prompt template (image-to-video)
```
Both bottle mascots [DESCRIBE THE SMALL ACTION] toward the camera — small,
controlled motion only, bodies otherwise still, upright, stable and cheerful,
feet planted on the sand. [CAMERA: e.g. a slow gentle cinematic push-in]. The
sand, palm trees, props and shadows stay completely stable; only [the intended
motion] moves, with very slight ocean movement far in the background. No
walking, no jumping, no extra motion.
```

### Turn "don't" into "do" (Kling responds to stated concepts, not negations)
| Instead of "don't…" | Write the positive state |
|---|---|
| don't warp the text | label text stays sharp, flat and legible |
| don't drift the props | props stay firmly anchored and completely still |
| don't deform hands | clean glove silhouettes, four fingers, connected to the arms |
| don't cut scenes | single continuous shot |
| don't shake camera | smooth, stable, centered camera |

### Environment upload caveat (this workspace)
Direct upload to `kling.ai` / `higgsfield` is **blocked by egress policy (403)**
in the Claude Code web environment, so image-to-image / image-to-video that
needs the reference uploaded **cannot run from here** — run those on kling.ai
directly. Text-to-image/video MCP calls that need no upload still work.

---

## 8. Ready-made shot templates

**A. Closing waving shot (proven).** Both mascots gently wave their raised
gloved hands to the camera, small side-to-side wrist waving, slow push-in,
stable beach, no other motion. → clean, welcoming end card.

**B. Product hero.** Static locked camera, both bottles front and centered,
soft sun flare, gentle glossy light sweep across the plastic, subtle palm-leaf
sway only. Great for a title/logo overlay.

**C. Cheerful greeting.** Both give a single friendly two-hand hello, tiny happy
bob, then settle — for an opening beat.

Default environment (unless a brief says otherwise): **sunny tropical beach** —
golden sand, turquoise ocean, blue sky with soft clouds, palm trees framing the
sides, and only the props already in the reference (beach chair, umbrella,
striped towel, sunglasses, sandcastle, seashell, starfish). Add no new props.

---

## 9. Quick recipes
- *"Make a closing shot"* → reference as first frame + template **A** + Kling
  2.5 Turbo, 1080p, 5s, audio off, on kling.ai.
- *"New pose / still"* → Kling Image o1 image-to-image on the reference, edit
  only the pose, keep §5 locks.
- *"Write me a prompt for scene X"* → open with the §6 canonical block, then add
  the scene, then convert every "don't" via the §7 table.
