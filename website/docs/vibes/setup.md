# Set up the workshop

The Build Pak keeps the modding toolchain separate from the server image. It is downloaded into your persistent data only when you enable Hugin's Vibes.

## What is prepared for you

| Part | What it does |
| --- | --- |
| .NET and compiler | Turn the C# project into a plugin |
| Git | Track each Vibe's source and version history |
| BepInEx and Harmony references | Provide the interfaces commonly used by Valheim mods |
| Local Valheim references | Give the project access to the game's APIs |
| Project template | Connect the tools and references in a ready-to-build project |

Valheim assemblies come from **your own world's installed dedicated-server files**. PhValheim prepares local reference copies for modding; it does not distribute those game files in your mod ZIP.

## Choose an AI provider

Configure providers under **Server Settings → AI Setup**. The workbench uses Hugin's existing provider connections and offers its own model picker.

Use a model that supports tools and coding. Hosted and self-hosted providers are supported; the amount of work a model can complete depends on its capabilities and context size.

## Follow setup feedback

The workbench checks the Build Pak, libraries and available disk space. Follow its action buttons when something is missing or needs updating. If preparation fails, read the job's result before retrying.

Vibes data lives beneath `/opt/stateful/vibes/`. Keep the persistent volume when upgrading PhValheim.

## Long conversations

The workbench shows token totals and an estimate of context usage. Automatic compaction summarizes earlier context between tool rounds when the configured budget is approached. Put essential project decisions in **Project Notes**, and use a fresh session for a substantially different task.

### Time and tool limits in 2.60

Under **Vibes Settings → Development**, three limits apply to each new turn:

| Control | Default | When to adjust it |
| --- | --- | --- |
| Tool rounds per turn | 40 | A longer task needs more model responses and tool actions |
| Turn time limit | 900 seconds | The overall task needs more time |
| Model response limit | 900 seconds | A slow model needs more time for one reply, including streaming |

The first limit reached stops the turn. A tool-round limit is **not a timeout**. The result explains which limit was reached; saved project files remain available. Send **Continue** in the same session to resume, or adjust the relevant limit before sending your next message. If the model is repeating an unsuccessful action, give it a narrower instruction instead of simply increasing the limit.

**Stop** also works while a model request is silent or streaming. Interrupted tool calls are discarded, and no automatic build or commit starts after a stop. Model calls, compaction, and retries obey the remaining turn time. Running tools retain their own bounded timeouts; the turn limit is checked between tools.

## Removing the workshop

**Delete Hugin's Vibes** in its Settings lets you choose what to retain, including projects, uploaded assets and the local catalogue. Review those choices before deleting data that a world still uses.

[Create your first mod →](first-mod.md)
