**Hugin was wrong about which worlds were running.** It would tell you a world you were
standing in was stopped, read its live log as ancient history, and refuse to stop or restart
anything. This release fixes that, and makes answers from smaller self-hosted models look
like answers instead of raw text.

```
docker pull theoriginalbrian/phvalheim-server:2.46
```

---

## "That world is stopped" — about a world with players on it

Reported from the panel. Hugin was reading the wrong database column for whether a world is
running: one that reads `Down` for **every** world on the server, including the ones actively
serving players.

That single wrong field had a much wider blast radius than the symptom:

- Hugin was told **0 worlds running**, along with a note that a fully stopped server is the
  normal resting state — so it confidently explained a live world as stopped, and treated its
  current log as history.
- **Stop and restart refused every world**, with "that world is already stopped". You could
  not turn anything off through Hugin at all.
- Start would happily have started a world that was already up.
- The health scan downgraded every finding to "historical" and **skipped the restart-loop and
  backup-freshness checks for exactly the worlds that needed them** — the ones with players
  on.

All of it now comes from the same source the admin world list uses, so Hugin and the UI agree.
The old column is still reported where it says something the new one cannot: a world whose
last start attempt failed is now described as "stopped (last start failed)" rather than just
"stopped".

## Answers from smaller models now look like answers

Measured against a real self-hosted model rather than guessed at — and most of the problem
turned out to be our renderer, not the model.

Smaller models lean hard on tables, section rules and quotes, and **none of them were being
drawn**. A side-by-side comparison of two worlds arrived as rows of literal `|` characters; a
section break as a line of dashes. Now rendered properly, along with column alignment,
blockquotes, nested sub-bullets, lists numbered from something other than 1, and code blocks
the model forgot to close (which happens on every truncated answer). Heading levels are
distinguishable too, so a long answer is no longer one flat wall of text.

Separately, smaller models **think out loud**, and that working-out was ending up at the top
of the answer — several paragraphs of *"let me check…"*, *"now I have enough…"* before the
first real sentence. Hugin now drops it: anything written before it looks something up is
removed, and the tools it used are still listed under the reply. Where there is any doubt the
text is left alone, so nothing is ever thrown away silently.

## The raven stays where you can see it

The thinking indicator sat at the top of the answer, so on anything longer than the panel it
scrolled away — you lost the raven, the phrase and the timer exactly when the wait was
longest, and had to scroll back up to check anything was still happening. It is now pinned to
the bottom of the conversation until the answer lands.

## Hugin and passwords

Two related fixes to what Hugin knows about how a world is protected:

- A world has a password, and a separate setting for whether that password is shown on the
  public world card. Hugin was being handed the second one **as if it were a second
  password** — and it reported it as "set" whichever way the setting was switched. It went on
  to describe this to operators as a separate password for the public view. No such password
  exists.
- **A password only applies to a vanilla world.** A modded world is started with no password
  at all — who may join is decided by its CITIZENS list — but the password you set is still
  stored and shown, so Hugin could tell you a modded world was password protected when
  nothing was checking it. It now reports both facts: whether a password is set, and whether
  it is actually in effect.

Asked about the server as a whole, Hugin's summary now includes whether each world has a
password. Previously that summary carried the who-may-join settings and nothing about
passwords, so a question about how your server is secured could only be answered from half
the picture.

## Smaller things

- You can **set which AI provider is the default** from Server Settings → AI Helper, with a
  "Make default" button on each provider. Previously the only way to change it was to re-run
  the whole Add-provider wizard over an existing entry.
- The Back and Next buttons in the Add/Edit AI provider dialog no longer sit jammed into the
  bottom corners of the dialog.

---

## Upgrading

No database changes in this release, so there is nothing to migrate — pull and restart.

```
docker pull theoriginalbrian/phvalheim-server:2.46
```

Rolling back to `theoriginalbrian/phvalheim-server:2.45` is safe for the same reason.

## For the curious

Four new oracle test scripts under `dev_tools/`, each mutation-checked against 2.45 so it
provably fails on the bug it names — `test-ai-world-state.sh` (13 of 15 assertions red
against 2.45), `test-ai-password-context.sh` (14 of 17), `test-ai-markdown.js` (20 of 29) and
`test-ai-narration.php`. `CHANGELOG.md` has the full engineering account, including why the
world-state test fixture has to be `status='Down'` **with** `mode='running'`: that combination
*is* the bug, and a fixture that set `status='Running'` would have passed against the broken
code — which is how this shipped in the first place.
