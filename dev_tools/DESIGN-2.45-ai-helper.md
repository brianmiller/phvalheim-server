# DESIGN 2.45 — AI Helper overhaul

Supersedes the 2.31-era AI Helper. Closes issue #83.

## Why

Issue #83: `models/gemini-2.0-flash` is retired, and the AI Helper hardcoded it.
That is a symptom, not the bug. The bug is the design:

| Current | Consequence |
|---|---|
| `getAiProvidersJson()` hardcodes a model list per provider | Every frontier model release breaks us. Fixed once = broken again next quarter. |
| `aiHelperDispatch()` re-validates against the *same* hardcoded list and silently rewrites an unknown model to `$allowedModels[$provider][0]` | The operator picks a model, we quietly use a different one. |
| Four bespoke `aiHelper<Provider>()` functions | A fifth provider is a fifth copy of curl + auth + response parsing. |
| `ollamaUrl` has no API key field | vLLM/LM Studio/llama.cpp behind `--api-key` cannot be used at all. |
| Context = `tail -200` of one log file, jammed into the system prompt | The model cannot look anywhere else. It cannot follow a lead. |
| No streaming | A 30s wait behind a spinner. |
| No connection test | A bad key fails as a chat error, mid-conversation. |

**The fix is architectural: never hardcode a model, ever.** Every provider we care
about exposes live model discovery. Verified:

| Kind | Discovery endpoint | Auth |
|---|---|---|
| `openai` / any OpenAI-compatible | `GET {base}/models` | `Authorization: Bearer` |
| `anthropic` | `GET {base}/v1/models` | `x-api-key` + `anthropic-version` |
| `gemini` | `GET {base}/v1beta/models` | `?key=` or `x-goog-api-key` |
| `ollama` | `GET {base}/api/tags` | none (or Bearer) |

vLLM, LM Studio, llama.cpp-server, OpenRouter, Groq, Together, DeepSeek, Mistral
and xAI are all `openai_compatible` — one adapter covers all of them, plus any
future endpoint, with a user-supplied base URL **and** API key.

## Provider model

A provider is a **row**, not a hardcoded case. Operators can add N of them,
including several of the same kind (prod vLLM + lab vLLM, work key + personal key).

```
ai_providers
  id            INT PK
  kind          ENUM('openai_compatible','anthropic','gemini','ollama')
  label         VARCHAR(64)      -- operator's name: "Lab vLLM (A6000)"
  base_url      VARCHAR(512)     -- pre-filled per kind, always editable
  api_key       VARCHAR(1024)    -- optional for ollama/local
  model         VARCHAR(190)     -- chosen from live discovery; never defaulted from a constant
  enabled       TINYINT
  is_default    TINYINT
  extra_headers TEXT             -- JSON, for gateways needing e.g. HTTP-Referer
  sort_order    INT
```

`ai_model_cache(provider_id, models_json, fetched_at)` — discovery is cached 6h
and force-refreshable from the UI. A cache miss never blocks chat; it just means
the picker shows the pinned model only.

### Model validation

The old code validated against a constant. The new code validates against
**what the endpoint said it has**, and when discovery is unavailable it passes
the operator's string through untouched. A model we have never heard of is a
model the operator chose — we send it. This is the rule that makes #83
structurally impossible to recur.

## Context engine — tools, not a prompt dump

Two layers, following the AIOps split of deterministic orchestration + LLM
reasoning:

### 1. Deterministic scan (`aidiagnose.php`) — runs before the model

Pure PHP. Pattern-matches the logs for known PhValheim failure signatures and
cross-references the database. Emits structured findings:

- BepInEx plugin load failure / missing hard dependency
- Expected-mod-set (from `world_mods`) vs `[BepInEx] Loading` lines actually seen
- `UnauthorizedAccessException` (the 2.39 perms class)
- SteamCMD `Failed to install app` / retry storms
- UDP port bind conflicts
- Crash-loop detection (repeated start markers, no steady state)
- `permittedlist.txt` enforced-but-empty (the wide-open-server trap)
- Stale mod sync / failed `mod_sync_runs`
- Backups: last run age vs configured interval, retention overrun
- Disk/memory pressure

Each finding has `severity`, `title`, `evidence` (the actual log lines),
`world`, and `suggested_question`. These render as cards in the UI **with no
LLM call at all** — the helper is useful and instant even with no provider
configured, and even a small local model gets grounded evidence instead of 200
raw lines.

### 2. Tool calling (`aicontext.php`) — the model pulls what it needs

Read-only tools, each a whitelisted PHP function:

| Tool | Purpose |
|---|---|
| `list_worlds` | names, state, mode, player counts, versions |
| `get_world` | full config: ports, crossplay/listed/public, backup policy, seed |
| `list_logs` | what logs exist + sizes + mtimes |
| `read_log` | tail/head/range of a *whitelisted* log path |
| `search_log` | regex/substring search with surrounding context lines |
| `get_world_mods` | the resolved install plan: source, owner, name, version, pin, deps |
| `get_mod_sync_status` | last run per catalogue, counts, errors |
| `get_backup_status` | per-world last backup, count, size, retention |
| `get_system_health` | CPU, memory, disk, supervisor process states |
| `get_diagnostics` | the deterministic findings above |

Path safety: `read_log`/`search_log` resolve against an allowlist built from
`/opt/stateful/logs` with `realpath()` containment — the model cannot read
`/etc/shadow` by asking nicely.

Providers that do not support tool calling fall back to the pre-baked context
injection (current behaviour), driven by a per-provider `supports_tools`
capability probe rather than a hardcoded assumption.

## Transport

One `aiChat()` entry point. Per-kind adapters handle only:
request shaping, auth headers, tool schema dialect, response/stream parsing.

- OpenAI-compatible: `/chat/completions`, `tools[]`, SSE `data:` deltas
- Anthropic: `/v1/messages`, `tools[]`, SSE `content_block_delta`
- Gemini: `:streamGenerateContent`, `functionDeclarations`, SSE JSON array
- Ollama: `/api/chat`, OpenAI-style `tools`, NDJSON

Streaming is served to the browser as SSE from `aiStream.php`, normalized to one
event shape regardless of provider: `{type: delta|tool|done|error, ...}`.

## UI/UX

Full rebuild of the side panel into a proper assistant surface.

- **Header** — provider+model selector grouped by provider label, live status dot,
  context chip ("World: Midgard"), refresh-models action.
- **Diagnostics strip** — severity-sorted finding cards from the deterministic
  scan. Each has "Ask AI" which seeds the conversation with that finding's
  evidence.
- **Chat** — streaming tokens, markdown + syntax-highlighted code, collapsible
  "used tool: read_log(valheimworld_Midgard.log)" trace rows so the operator can
  see what the model looked at.
- **Composer** — suggested prompts that change with context; Ctrl+Enter send.
- **Footer** — token counts, elapsed, model actually used.

### Wizard — "Add AI Provider"

Five named steps, matching the existing Build-a-game wizard idiom:

1. **Choose a kind** — cards: OpenAI · Anthropic · Google Gemini · Ollama ·
   Self-hosted / OpenAI-compatible (vLLM, LM Studio, llama.cpp, OpenRouter, …)
2. **Endpoint** — base URL pre-filled per kind, always editable
3. **Credentials** — API key (optional for Ollama/local), extra headers
4. **Test** — live round trip: discovery + a 1-token completion. Pass/fail with
   the actual error text, not "failed".
5. **Model** — picker populated from discovery, searchable, with context window
   and capability badges. Save.

Settings modal keeps a compact provider list (reorder, edit, test, delete) and
opens the wizard for adds.

## Migration

`dbUpdate_2.45.sh` creates the tables and migrates the four legacy columns into
rows:

| Legacy column | Becomes |
|---|---|
| `openaiApiKey` | kind `openai_compatible`, base `https://api.openai.com/v1` |
| `claudeApiKey` | kind `anthropic`, base `https://api.anthropic.com` |
| `geminiApiKey` | kind `gemini`, base `https://generativelanguage.googleapis.com` |
| `ollamaUrl` | kind `ollama`, base = the URL |

`model` is left **empty** on migration — it is resolved from live discovery on
first use. That is the #83 fix applied retroactively: an upgraded install with a
Gemini key stops pointing at a retired model the moment it upgrades.

Legacy columns are kept (not dropped) as a rollback record, per the 2.43
precedent. Nothing reads them after migration except the migration itself.

## Files

| File | Status |
|---|---|
| `includes/aiproviders.php` | new — registry, discovery, transport, adapters |
| `includes/aicontext.php` | new — tool definitions + dispatch |
| `includes/aidiagnose.php` | new — deterministic log/DB scan |
| `admin/aiStream.php` | new — SSE endpoint |
| `admin/adminAPI.php` | AI section replaced; provider CRUD + test + discovery actions |
| `admin/index.php` | AI panel rebuilt; settings section replaced; wizard added |
| `admin/setup.php` | AI step simplified — defer to the wizard |
| `includes/config_env_puller.php` | `$aiKeys` replaced by provider rows |
| `engine/dbUpdates/dbUpdate_2.45.sh` | new |
| `admin/readLog.php` | "Analyze with AI" now passes a finding, not a canned prompt |
