# Ask Hugin for help

Hugin is optional. Use him to investigate your server and propose changes; use [Hugin’s Vibes](../vibes/first-mod.md) to write mods.

Add one or more providers under **Server Settings → Hugin Setup**. The provider wizard tests the
endpoint, the credential and the model separately, so a failure tells you which of the three
is wrong instead of surfacing later as a chat error.

| Provider type | What you need |
|---|---|
| **OpenAI-compatible** | Endpoint URL + API key. One-click presets for OpenAI, Ollama, vLLM, LM Studio, llama.cpp, OpenRouter, Groq, Together, DeepSeek, Mistral and xAI — or type any endpoint of your own. |
| **Anthropic** | API key |
| **Google Gemini** | API key |

The model picker asks your configured endpoint for available models instead of relying on a fixed built-in list. Opening the Model step fetches a fresh list; **Refresh models** fetches again without changing your selection. Provider cards also have a refresh button. The last successful fetch time is shown. A failed refresh retains the previous list and labels the error; a selected model missing from the list is flagged, never silently replaced. Ordinary background discovery uses a six-hour cache. You can also enter a model name directly. Configure multiple providers and switch between them from the panel header.

## What Hugin can do

Hugin reads logs, world settings, the resolved mod list, catalogue sync state, backups and host
health on its own, and shows which of them it consulted in every reply. It can also **act**:
start, stop, restart, update or delete a world, take or restore a backup, and change world
options, access lists, backup policy, mods or server settings.

Starting a stopped world and taking a backup can run immediately. Actions that stop a service,
change configuration or destroy data require a confirmation card with **Apply** and
**Dismiss**. The card describes the validated action, including setting changes where
applicable. That card is built on the server from the validated change — not
from Hugin's description of it — works once, expires after fifteen minutes, and is re-checked at
the moment you click. Deleting a world and restoring a backup additionally require you to type
the world's name.

The
**"What can Hugin do for me?"** button lists every capability and every refusal, generated from
the live capability list, and works with no provider configured.

If your model cannot call tools — some smaller self-hosted models cannot — Hugin answers anyway
and tells you it could not inspect anything and cannot make changes.

## Provider limits (2.62 development preview)

In **Hugin Setup**, edit a provider and open **Limits**. These controls apply to both Hugin
chat and Hugin’s Vibes. Vibes retains its own project context, response-time, turn-time and
round limits; the lower applicable shared/project limit wins. Saved credentials are retained
when the key field is blank. Saving limits does not require recreating the container;
new turns read the saved configuration. In-flight requests finish under their original limits.

| Limit | Default | Range |
|---|---:|---:|
| Context budget, tokens | 60,000 | 4,000–2,000,000 |
| Maximum response, tokens | 4,096 | 256–128,000; at most half the context budget |
| Compaction threshold | 65% | 25–90% |
| Model response timeout | 120 seconds | 5–7,200 seconds |
| Connection timeout | 15 seconds | 1–120 seconds |
| Discovery timeout | 12 seconds | 1–300 seconds |
| Concurrent model requests per provider | 2 | 1–16 |
| Queue timeout | 60 seconds | 1–600 seconds |
| Hugin chat tool rounds | 10 | 1–100 |
| Hugin chat turn time | 900 seconds | 5–7,200 seconds |

Context usage is an estimate, including the system prompt and tool definitions. A reported
model context limit caps the local budget; if the gateway supplies no limit, its capacity is
shown as unknown. A manual budget cannot increase the gateway's real capacity. Response
budgets can include reasoning tokens. Compaction summarizes older exchanges while preserving
the latest request and the last complete tool exchange, and reserves room for the response.
Oversized current requests are rejected with guidance instead of sending a known oversized
request. Summary input may be clipped at the middle to fit; important requirements should
remain in the current request or Vibes project notes. Hugin chat history lives in the open page and is cleared on reload; Vibes summaries persist with the project session.

The concurrency limit governs this PhValheim server's requests, not other clients of your
gateway. Waiting requests show queue status, honor Stop and the turn deadline, and fail with
an explicit queue-timeout message. Connection and model-response clocks begin when a slot is
available. Tool operations on the same Vibes project remain serialized. Hugin chat permits
one final answer request after the tool-round limit, within the same turn deadline.

For slow local models, increase **Model response timeout** and allow sufficient total turn
time. Discovery has its own limit. External reverse proxies can impose shorter limits;
configure those separately. Streaming chat emits waiting heartbeats, and the bundled admin
nginx allows long non-streaming requests. Limits and cancellation stop further model calls
and tool dispatches; they do not roll back a tool action already completed.
