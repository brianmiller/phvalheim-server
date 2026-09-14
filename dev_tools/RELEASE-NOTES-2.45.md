**Meet Hugin.** The AI Helper has a name, a face, and — new in this release — hands. It still
reads your logs and tells you what is wrong; now it can also fix it, and it asks first.

```
docker pull theoriginalbrian/phvalheim-server:2.45
```

---

## Bring your own model — we no longer have opinions about which ones exist

Reported as *"Gemini models are retired/deprecated"* (#83): `gemini-2.0-flash` was gone and the
helper still asked for it. Bumping that string would have been a fix with a shelf life of weeks.
The real defect is that **a model id was a constant in our source at all**.

2.44 carried three hardcoded model tables, and one of them did this:

```php
if (!in_array($model, $allowedModels[$provider])) {
    $model = $allowedModels[$provider][0];   // you asked for X, you silently got Y
}
```

So the failure was not only "our default is stale" — a **correct** model you typed got replaced
by a stale one, with no error. Both tables are gone. Every provider publishes its catalogue over
HTTP, so PhValheim asks yours, caches for six hours, and sends your choice **verbatim**. A model
we have never heard of is used as entered, with a warning, never a substitution. When a vendor
retires a model, it simply stops appearing in the dropdown.

- **Any OpenAI-compatible endpoint** is now supported *with an API key* — vLLM, LM Studio,
  llama.cpp, OpenRouter, Groq, Together, DeepSeek, Mistral, xAI, plus Anthropic and Gemini.
  Eleven one-click presets, or type your own endpoint.
- **Ollama is a preset, not a provider kind.** It serves an OpenAI-compatible API at `/v1`, and
  the dedicated adapter was ~70 lines duplicating one we already had. Existing Ollama providers
  are converted automatically. Previously it had no field for a key at all, so a server behind
  `--api-key` could not be used.
- **As many providers as you like**, including several of the same type — a cloud key for hard
  questions and a local model for everyday ones. Switch from the panel header.
- An **Add AI provider wizard** tests endpoint, credential and model *separately*, so a failure
  names which of the three is wrong instead of surfacing later as a chat error.
- **Existing keys migrate automatically.** Their model deliberately does **not** carry over — it
  is re-resolved from your provider on first use, which is what fixes the retired-model problem
  for upgrades and not just fresh installs.

## Hugin can act — and the confirmation is the server's, not the model's

Ask Hugin to start a world, back one up, change world options, edit who may join, adjust a backup
schedule, change the mod list, rebuild a world or restore a backup, and it will carry it out.

**Nothing that matters happens without your say-so.** Anything that stops a service, changes
configuration or destroys data is shown first as a card listing every change, old value to new,
with **Apply** and **Dismiss**. Starting a world and taking a backup happen immediately, because
neither can lose anything.

The detail that matters: that card is built **on the server from the validated change**, not from
Hugin's description of it. If the model says one thing and the change is another, the card shows
the change. Each confirmation works once, expires after fifteen minutes, and is re-checked at the
moment you click — so if the world moved on in the meantime, it stops rather than acting on stale
information. Deleting a world and restoring a backup additionally require you to **type the
world's name**, and a restore is refused outright if the backup belongs to a different world.

Hugin **refuses changes that would quietly break something**: an access list that would be
enforced but empty (which opens a world to *everyone* rather than closing it), listing a vanilla
world with no password (Valheim will not start), and crossplay or a password on a modded world
(where they do nothing). It will not act on a world name it cannot find — it shows you the real
list instead.

A **"What can Hugin do for me?"** button lists everything it can inspect, everything it can do and
everything it will refuse. It is generated from the live capability list rather than written down
separately, so it cannot drift, and it works with no AI provider configured at all.

## It looks things up itself, and diagnoses without a model

- Hugin was previously handed the last 200 lines of one log and nothing else, so it could not
  follow a lead or check whether the thing it was blaming was even configured. It can now list and
  search any log in full, read a world's log from the most recent start only, and look up world
  settings, the resolved mod list, catalogue sync state, backups and host health. **Every reply
  shows which of these it actually looked at**, so you can check its work.
- A **health scan runs the moment you open the panel, with no AI provider needed**. Mod load
  failures, missing dependencies, mods configured but never loaded, permission errors, Steam
  download trouble, port conflicts, restart loops, overdue backups, failed catalogue syncs, low
  disk, stopped services — each with the log lines that triggered it and a one-click
  "Ask AI about this".
- It flags a world whose **permitted list is enforced but empty**. Valheim only enforces that list
  when it has entries, so an empty one means the world is open to everyone while the Access tab
  implies it is private.
- Replies **stream as they are written** and render as proper headings, lists and log excerpts.
- The AI button is **always visible**. It used to be hidden until an API key was set, which hid
  the health scan from the operator most likely to need it.
- **If your model cannot call tools** — some smaller self-hosted models cannot — Hugin answers
  anyway and says plainly that it could not inspect anything and cannot make changes. Previously
  such an endpoint returned an error on every single message.

## Fixes

- **A streamed reply could silently lose words** mid-sentence when it contained an em dash, an
  accented letter or non-Latin text and the provider split that character across two chunks.
  `json_encode()` returns `false` on invalid UTF-8, so the frame went out empty and the browser
  dropped it — text vanished with no error anywhere.
- **A modded world's log said crossplay was enabled.** It never was: crossplay applies to vanilla
  worlds only and modded worlds have always started without it. The line opened *"has crossplay
  set"*, and that is what an operator scanning a log takes away. It now leads with the effective
  setting — `crossplay is OFF` — then explains the stored one and what to do about it.

## Analytics

If you leave anonymous analytics on, they now include **counts** of assistant use: conversations,
which tools get used, changes proposed versus applied, error categories, and whether endpoints
support tool calling. **No prompts, replies, world names, mod names, model names, endpoints or
keys are ever sent.**

---

Full detail, including the traps found along the way, is in
[CHANGELOG.md](https://github.com/brianmiller/phvalheim-server/blob/master/CHANGELOG.md).
