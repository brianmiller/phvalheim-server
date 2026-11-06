# Public theme artwork

Classic Reforged, The Gate and Ember share `bosses.png`, a 4-column, 2-row transparent sprite sheet. Read left to right: Eikthyr, The Elder, Bonemass, Moder; Yagluth, The Queen, Fader, empty. The CSS selects cells without modifying the image. Classic retains its original artwork and layout.

`hugin.svg` is copied unchanged from `website/docs/assets/hugin.svg`, the landing-page Hugin.

The raster assets were produced with the built-in imagegen tool in edit mode from the approved concepts under `website/docs/assets/ui-concepts/2.62/`. No raster postprocessing was used.

Prompts:

- Bosses: isolate the seven Reforged heads, retain their individual appearance, arrange in four equal columns and two equal rows, last cell empty, 10% padding, no UI or labels, transparent background. Follow-up edit: remove all white/off-white/grid background; retain exact positions and dimensions, opaque heads and transparent space.
- Gate: recreate only the moonlit stone ring at right, moon through the portal, lake reflection, mountains and pines, blue mist, dark negative space on the left for real HTML; remove all UI, lettering and heads.
- Ember: recreate only the warm longhouse landscape at right, orange glow, lake sunset, mountains and forest; dark negative space on the left for HTML; remove all UI, lettering and heads.
