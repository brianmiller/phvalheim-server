# Player UI typography

Why the world cards look the way they do, and what not to undo.

## The problem

The player page asked for `"Lucida Console", "Courier New", monospace`. Lucida Console exists
only on Windows, so almost nobody got it:

| platform | what actually rendered |
|---|---|
| Linux | Liberation Mono (metric clone of Courier New) |
| macOS | Courier New |
| Windows | Lucida Console |

All three are Courier-lineage or bitmap-era faces: small x-height, wide sidebearings, thin
stems. Confirmed with Chrome DevTools `CSS.getPlatformFontsForNode` — the page reported
`Liberation Mono` for every card element.

On top of that the page set `line-height: normal`, which for these faces is roughly **1.17**.
Every readability guideline puts body text at 1.4–1.6. That combination — a thin, small-on-the-
body face packed at 1.17 — is what read as "tiny and squished". The declared sizes were not the
main problem; the face and the leading were.

## What changed

| | before | after | why |
|---|---|---|---|
| face | Lucida Console → Courier New | **JetBrains Mono**, self-hosted | tall x-height, open counters, designed for long reading at small sizes |
| body line-height | `normal` (~1.17) | **1.5**, unitless | standard body figure; unitless so it scales with each element |
| card info rows | 12px | **14px** | 12px is below the floor for secondary text; 14 × 1.5 = 21px exactly, so the rhythm lands on whole pixels |
| world name | 18px | **20px** | keeps the step above the body now that the body grew |
| label colour | same as value (`#d3d7cf`) | **`#8b93a7`** | hierarchy without a size change — the value is what the player came for |
| Seed / Server cells | `display:block` / `inline-block` | **`display:table-cell`** | they are `<td>`s; a non-table-cell clips its own content and breaks the rhythm |

Row pitch went **14px → 21px**. Uppercase badges keep their `0.04em` tracking — that is the one
place tracking helps. **Do not add letter-spacing to the monospace body text**: it is already
evenly spaced and tracking hurts scannability.

## Why the font is self-hosted

`css/fonts/*.woff2`, ~283 KB for regular, bold and italic. Not Google Fonts:

- a self-hosted game server may have no outbound internet, and the UI must not depend on one
- a webfont request would disclose every player's visit to a third party
- the rendering would otherwise differ per operating system, which is the bug being fixed

JetBrains Mono is **SIL OFL 1.1**. `css/fonts/OFL.txt` must ship alongside the font files —
that is a licence condition, not tidiness.

`nginx.conf` adds a `font/woff2` mime type; the Jammy `mime.types` predates woff2 and would
serve the files as `application/octet-stream`.

## Verifying a change

`dev_tools/test-public-card-render.js` measures the real page — label column width, colon gap,
row pitch, and that no value cell has been collapsed. It needs the dev container with the
Steam-auth bypass (`dev_tools/devUp.sh <steamid>`).

`dev_tools/test-world-card-layout.js` measures markup it builds itself. It agreed with four
consecutive wrong layouts. Do not trust it alone.

## Sources

- [Typography — U.S. Web Design System](https://designsystem.digital.gov/components/typography/)
- [Best UX practices for line spacing — Justinmind](https://www.justinmind.com/blog/best-ux-practices-for-line-spacing/)
- [Line Height & Letter Spacing: Readability Rules](https://madegooddesigns.com/line-height-letter-spacing/)
- [Best Fonts for Dashboards (Data-Legible UI)](https://madegooddesigns.com/best-fonts-for-dashboards/)
- [A Comprehensive List of Monospace Coding Fonts — hacking C++](https://hackingcpp.com/dev/coding_fonts)
- [The best monospace fonts for code — FontTest](https://fonttest.com/blog/best-monospace-fonts-for-code/)
