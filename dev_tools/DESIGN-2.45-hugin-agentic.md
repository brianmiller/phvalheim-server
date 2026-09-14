# 2.45 — Hugin acts: agentic operations, BYO-LLM safety, and usage telemetry

The first half of 2.45 gave Hugin ten **read-only** tools. It could explain the server but
not touch it, so every answer ended with the operator doing the work by hand in another tab.

This second half lets Hugin *run* PhValheim. That is a different risk class, and the design
below is shaped almost entirely by one fact: **we do not control the model.**

---

## 1. The constraint that shapes everything

An operator brings their own LLM. It might be Claude Opus, or a 7B quant on a laptop.
There is no allowlist and there never will be — a model id is not a constant (see
`phvalheim_ai_provider_agnostic`). So the design cannot assume:

| assumption | reality |
|---|---|
| the model supports tool calling | many small/local models do not, or accept `tools` and never emit a call |
| tool arguments are well-formed | a weak model invents world names, mod ids, enum values |
| the model understands consequences | "clean up the old worlds" can mean `delete_world` to a model |
| the model won't loop | it will; 2.45 already bounds rounds for this reason |

**The safety of an action must not depend on the quality of the model.** Everything below
follows from that. A good model makes Hugin *pleasant*; the architecture makes it *safe*.

### Capability detection, not a capability table

Hugin must know whether the operator's model can call tools at all. We detect it the same
way we negotiate `max_tokens` (see `llm_capability_negotiation`): send the request, read
what the endpoint refused, adapt once.

Three outcomes, all recorded on the provider row:

- **`tools`** — the model emitted a well-formed tool call. Full agentic Hugin.
- **`text`** — the endpoint rejected the `tools` parameter outright. Hugin retries the turn
  with no tools and answers from the live-state block in the system prompt alone, with a
  visible banner: *"This model can't use tools, so Hugin is answering from general
  knowledge and the summary above — it can't inspect logs or change anything."*
- **`inert`** — tools were accepted but the model answered in prose when a tool was
  plainly required. Same degradation, different banner, because the cause is different and
  the operator's fix is different (a bigger model, not a different endpoint).

Never silent. A degraded Hugin that looks identical to a full one is how an operator comes
to trust an answer that was invented.

**Actions are only ever offered in `tools` mode.** In `text`/`inert` mode Hugin describes
what it *would* do and links to the admin page — useful, honest, harmless.

---

## 2. The safety model: propose → confirm → execute

Actions split into two tiers by **blast radius**, not by how hard they are to implement.

### Tier 1 — safe, executed immediately

Additive or trivially reversible. Hugin does it and reports it.

`start_world` · `create_backup` · `sync_mod_catalogue` · `get_*` (all of 2.45's ten)

### Tier 2 — consequential, requires operator confirmation

Everything that stops a service, changes configuration, or destroys data. Hugin does **not**
execute these. It writes a **proposal** and the UI renders a confirm card.

The flow, and why each step exists:

```
model emits tool call  ──►  aiActionPropose() validates args against live state
                            and writes a row to ai_proposals
                                  │
                                  ▼
                       chat renders a diff card: old ──► new
                                  │
                        operator clicks Apply
                                  ▼
              POST applyAiProposal {token}  ──►  executor loads the row,
                                                 re-validates, runs the
                                                 EXISTING admin function,
                                                 marks the row consumed
```

Five properties make this safe regardless of model quality:

1. **The plan is server-authored.** The proposal row holds the exact validated parameters.
   Apply sends only an opaque token. A tampered client cannot change what executes, and the
   model never gets a second say.
2. **Single-use and expiring.** `consumed_at` plus a 15-minute TTL. A proposal cannot be
   replayed, and a card left open in a tab overnight is dead.
3. **Validated at propose time *and* apply time.** State moves between the two — a world
   that was stopped when proposed may be running when applied. Re-validation fails closed
   with a plain explanation rather than acting on a stale premise.
4. **Actions are never parsed from prose.** Only a genuine tool call creates a proposal.
   A model that writes "I'll delete the world now" in text does nothing at all.
5. **The diff is rendered from the proposal row, not from the model's summary.** The model
   describes what it *thinks* it is doing; the card shows what will *actually* happen. When
   those disagree, the operator sees it.

### The executor calls the admin functions, never new SQL

This is the single most important implementation rule.

Every action wraps the function the admin UI already calls — `stopWorld()`,
`saveWorldOptions()`, `saveCitizens()`, `createManualBackup()`. It must not write its own
SQL, because the existing functions carry guards that took incidents to learn:

> `saveCitizens` refuses an empty enforced list. Valheim enforces `permittedlist.txt`
> **only when it has entries**, so an enforced-but-empty list is a **wide open server**
> whose Access tab claims otherwise. Access lists are written only via `writeAccessList()`,
> never `file_put_contents()`, and the return value is never ignored.
> — `CLAUDE.md`, and `dev_tools/test-create-access-guards.sh`

A Hugin path with its own SQL would bypass every one of those and the UI would still look
right. Wrapping means a guard added anywhere protects Hugin for free.

**Delete is special.** `delete_world` and `delete_backup(s)` require the operator to type
the world name into the confirm card, exactly as the admin UI does. Not because the model
might be wrong — because the *operator* might be, and Hugin makes destructive intent one
sentence away from execution.

---

## 3. The action catalogue

Wrapping existing endpoints, tier in brackets.

**World lifecycle**
| tool | tier | wraps |
|---|---|---|
| `start_world` | safe | `startWorld()` |
| `stop_world` | confirm | `stopWorld()` — kicks connected players |
| `restart_world` | confirm | stop + start |
| `update_world` | confirm | `updateWorld()` — rebuild, world is down for minutes |
| `create_world` | confirm | `createWorld()` — demands a first player ID |
| `delete_world` | confirm + typed name | `deleteWorld()` |

**World configuration**
| tool | tier | wraps |
|---|---|---|
| `set_world_options` | confirm | `saveWorldOptions()` — crossplay, listed, password, public, launch params |
| `set_world_mods` | confirm | `saveWorldMods()` — add/remove/pin; **requires a rebuild to take effect** |
| `set_world_access` | confirm | `saveCitizens()` / `saveAdmins()` / `saveBanned()` |
| `set_world_backup_policy` | confirm | `saveWorldBackupSettings()` |

**Backups**
| tool | tier | wraps |
|---|---|---|
| `create_backup` | safe | `createManualBackup()` |
| `restore_backup` | confirm + typed name | `restoreBackup()` — overwrites the live save |
| `delete_backups` | confirm + typed name | `deleteBackups()` |
| `reconcile_backups` | confirm | `reconcileBackups()` |

**System**
| tool | tier | wraps |
|---|---|---|
| `set_server_settings` | confirm | `saveServerSettings()` |
| `sync_mod_catalogue` | safe | forces a catalogue sync |

Each definition's `description` states the consequence in the words the operator will see
on the card — the model writes better proposals when the schema is honest about cost, and
that text is the fallback if the model summarises badly.

---

## 4. Curated playbooks — teaching Hugin the job

Tools without procedure produce a model that pokes at things. The system prompt gains an
OPERATING PROCEDURES section: the handful of rules that separate someone who knows
PhValheim from someone reading the schema.

- **Diagnose before acting.** `get_diagnostics` is cheap and deterministic. Never propose a
  restart for an unexamined symptom — 2.45 already learned that *a stopped world is not a
  broken world*.
- **A mod change is not live until the world is rebuilt.** `set_world_mods` edits the plan;
  `update_world` applies it. Proposing the first without mentioning the second leaves the
  operator believing a change landed when it did not.
- **Never add an access-list entry the operator did not supply.** Not a Steam ID, not a
  placeholder, not an example. If a citizens list would end up empty and enforced, say the
  server would be wide open and stop.
- **Vanilla and modded worlds are configured differently.** Modded worlds gate on CITIZENS
  with `-public 0`; vanilla worlds have real `password`/`crossplay`/`listed` columns. A
  listed vanilla world *requires* a password or Valheim refuses to start.
- **`worlds.public` is the CITIZENS flag, not Valheim's `-public`.** That is `listed`.
  Conflating them is a one-word mistake that opens a server to the browser.
- **Stopping a world disconnects players.** Say so in the proposal. Check
  `last_player_activity` first and mention it if someone was on recently.
- **Prefer the narrow tool.** `search_log` over a 1200-line `read_log`; one world over all.

These are assertions about *this* system, not general LLM prompt-craft — which is why they
live beside the code they describe and are covered by a guard that fails if the section
goes missing.

---

## 5. Telemetry

`pushAnalytics.sh` already sends `ai_enabled` and the set of provider **kinds** — never a
label, endpoint or key, because a self-hoster's base URL is an internal hostname and none of
our business. this release keeps that bar and adds **counters only**.

New `ai_usage` table, incremented at the existing choke points (`aiChat`, the tool
dispatcher, the executor), reported as a rolling 24h window:

```
ai_chats                 conversations started
ai_tool_calls            total tool invocations
ai_tools_used            {"get_diagnostics": 41, "read_log": 12, ...}
ai_actions_proposed      tier-2 proposals written
ai_actions_applied       proposals the operator confirmed
ai_actions_expired       proposals that timed out unapplied
ai_capability            {"tools": 2, "text": 1, "inert": 0}   per provider row
ai_errors                {"http_400": 3, "timeout": 1, ...}    classes, never messages
ai_rounds_p50            median tool rounds per conversation
```

**Never sent:** prompt or reply text, world names, mod names, model ids, endpoints, keys,
proposal parameters. The applied/proposed ratio tells us whether operators trust the
proposals; `ai_tools_used` tells us which tools earn their place; `ai_capability` directly
measures how much of the BYO-LLM field can actually drive tools — the number that decides
how much further this feature should go.

Model ids are deliberately excluded even though they would be the most *useful* field,
because on a self-hosted endpoint a model id is often an internal deployment name. The
capability record answers the same question without naming anything private.

`ai_usage` is a handful of scalars plus three small JSON objects — but it still goes into
the payload file via `--slurpfile`, never `--argjson`, because Linux caps a single argv
entry at 128 KiB and that is exactly how analytics pushes died silently once before.

The `phvalheim-analytics` service needs matching columns and a dashboard panel; that repo
ships separately and its ingest must tolerate an older server omitting these fields.

---

## 6. Verification

Every guard mutation-proven — a test that passes whether or not the bug is present is worse
than no test (`feedback_non_oracle_tests`).

| check | mutation that must fail it |
|---|---|
| consequential action needs a token | executor accepts a direct call |
| token is single-use | replay the same token twice |
| token expires | backdate `created_at` past the TTL |
| prose is never an action | a reply saying "deleting now" with no tool call |
| re-validation at apply | flip world state between propose and apply |
| access guards survive | propose an empty enforced citizens list |
| degradation is visible | an endpoint that 400s on `tools` |
| telemetry leaks nothing | assert no world/mod/model string appears in the payload |
| playbooks present | strip the OPERATING PROCEDURES section |

Plus the existing chain, all of which must still pass: `test-ai-helper.sh`,
`test-ai-e2e.sh`, `test-ai-toolargs.sh`, `test-ai-max-tokens.sh`,
`test-ai-stopped-world.sh`, `test-create-access-guards.sh`.

---

## 7. This ships inside 2.45 — no version bump

The new tables are **appended to `dbUpdate_2.45.sh`**, not given a file of their own.

I originally created a `dbUpdate_2.46.sh` and bumped the Dockerfile, on the reasoning that
"each migration self-gates with `exit 2` once applied, so anything added to 2.45 would never
run on a server that already applied it". **That was wrong, and I never checked it.**
`dbUpdate_2.45.sh` has no top-level gate at all — it is object-by-object idempotent, and its
own header says so explicitly, *for exactly this reason*:

> Object-by-object idempotent rather than one top-level guard, for the same reason as 2.40
> and 2.43: this ships as an RC first, and later revisions of THIS script must run on
> servers that already ran an earlier revision.

`exit 2` gating is a **legacy** pattern (2.7 through 2.38). Every migration from 2.40 onward
is append-safe by construction. So new schema for an unreleased version belongs in that
version's existing migration, and a version bump is a release decision — not something to
reach for because a migration looked inconvenient.

`dev_tools/test-migration-append-safe.sh` now asserts this rather than leaving it to be
assumed: it fails if the current version's migration has a top-level gate, if a migration
exists for a version *newer* than the Dockerfile, or if a `CREATE TABLE` / `ADD COLUMN` in
it is not guarded.
