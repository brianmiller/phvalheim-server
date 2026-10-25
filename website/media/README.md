# PhValheim product tour

Twelve five-second chapters cover the full product: Docker setup, world management, mod selection, configuration, payload distribution, client syncing, backups/access/logs, Ask Hugin and Hugin's Vibes. Vertical 720×1280, 30 fps, H.264/AAC MP4 with fast-start. Text remains readable with music muted; WebVTT captions and a linked transcript accompany the video.

## Reproduce

Requires Node with Playwright/Chromium and ffmpeg/ffprobe. No NumPy, synthesis tool or external service is required. These are video production tools, not dependencies of the docs site.

```bash
node website/media/render.cjs
```

Set `NODE_PATH` if Playwright is outside the checkout. The renderer reads `scenes.json`, composes `tour.html`, checks chapter content stays inside the safe area, records it, and combines it with `audio/viking-intro-loop.mp3`. It writes `phvalheim-tour.mp4`, captions, sharing credits and a poster to `website/docs/assets/`. Temporary preview frames and recordings live under `/tmp/phv-tour-*`.

## Captures and presentation

World, creation form, mod picker, config and backup captures came from the development server through read-only UI navigation. No world was started, updated, configured or backed up to produce these shots. The Vibes captures show the real workbench and Package tab. Crops focus attention on specific controls; animated distribution/client diagrams explain the sequence rather than pretending to show a real sync or game launch. The persistent “UI tour · condensed workflow” label makes the presentation clear.

## Music

**Viking Intro (Loop)** by **Alexander Nakarada**, used under **CC BY 4.0**. See [the full source and attribution record](audio/ATTRIBUTION.md). This replaces the first tour's synthesized electronic score. The recording retains its own license, independently of the repository's software license.

The credit appears on the public tour page, in the MP4 metadata and in the downloadable sharing text. Preserve it when distributing or reposting the video. The edit uses the opening 60 seconds with normalization and a two-second ending fade.
