# Hugin's Vibes short tour

Eight four-second chapters, 720×1280, 30 fps, H.264/AAC MP4 with fast-start. Text stays visible with music muted. The site also supplies a WebVTT caption track and a text transcript.

## Reproduce

Prerequisites: Node with Playwright and Chromium installed, Python with NumPy, and ffmpeg/ffprobe. These are production tools for the video, not dependencies of the docs site.

```bash
node website/media/render.cjs
```

If Playwright lives outside this checkout, set `NODE_PATH` to its `node_modules` directory. The renderer records `tour.html`, synthesizes the original instrumental in `music.py`, and writes the video and poster to `website/docs/assets/`. It uses temporary files beneath `/tmp`.

The supplied screenshots are real UI captures. CSS crops and captions direct attention; it does not simulate new AI output, a live build, or gameplay. A persistent “UI tour · condensed workflow” label makes that distinction visible in the video itself.

The score is generated from simple oscillators and seeded percussion noise. It contains no borrowed recording, commercial song, or sampled game audio.
