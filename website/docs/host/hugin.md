# Ask Hugin for help

Hugin is optional. Use him to investigate your server and propose changes; use [Hugin’s Vibes](../vibes/first-mod.md) to write mods.

Add one or more providers under **Server Settings → AI Setup**. A five-step wizard tests the
endpoint, the credential and the model separately, so a failure tells you which of the three
is wrong instead of surfacing later as a chat error.

| Provider type | What you need |
|---|---|
| **OpenAI-compatible** | Endpoint URL + API key. One-click presets for OpenAI, Ollama, vLLM, LM Studio, llama.cpp, OpenRouter, Groq, Together, DeepSeek, Mistral and xAI — or type any endpoint of your own. |
| **Anthropic** | API key |
| **Google Gemini** | API key |

The model picker asks your configured endpoint for available models instead of relying on a fixed built-in list. You can also enter a model name directly. Configure multiple providers and switch between them from the panel header.

## What Hugin can do

Hugin reads logs, world settings, the resolved mod list, catalogue sync state, backups and host
health on its own, and shows which of them it consulted in every reply. It can also **act**:
start, stop, restart, update or delete a world, take or restore a backup, and change world
options, access lists, backup policy, mods or server settings.

Nothing that matters happens without your say-so. Anything that stops a service, changes
configuration or destroys data is shown first as a card listing every change, old value to new,
with **Apply** and **Dismiss**. That card is built on the server from the validated change — not
from Hugin's description of it — works once, expires after fifteen minutes, and is re-checked at
the moment you click. Deleting a world and restoring a backup additionally require you to type
the world's name.

The
**"What can Hugin do for me?"** button lists every capability and every refusal, generated from
the live capability list, and works with no provider configured.

If your model cannot call tools — some smaller self-hosted models cannot — Hugin answers anyway
and tells you it could not inspect anything and cannot make changes.
