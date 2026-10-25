**Hugin's Vibes is optional.** It installs its separate Build Pak only when you choose to set it up. An AI provider and a world with installed Valheim server files are needed to get started.

## 🐦 Let the raven cook

Ever thought, "I wish Valheim had a little mod that…"?

Now you can take that thought straight to **Tools → Hugin's Vibes**. Create a Vibe, describe your idea, and work with Hugin to turn it into a mod you can build, package and try on your own world.

> "Give me a small stamina tweak with a setting I can change later."

Hugin writes the C#, looks up the game's APIs, runs the compiler and works through its diagnostics. You can watch the tools, edits and build results as they happen, then ask for the next change in the same conversation.

After the one-time setup, starting a new Vibe takes seconds. How quickly the finished mod comes together depends on your model and the idea.

## The workshop sets itself up

Hugin handles the plumbing that normally comes before the fun:

- **The tools:** the separate Build Pak supplies .NET, the compiler, Git, BepInEx and Harmony references.
- **The game libraries:** Hugin gathers the assemblies from one of your worlds' installed dedicated-server files and prepares local reference copies for modding, including access to the game internals mods need. Your game files stay on your server.
- **The project:** a ready-to-build template wires those references together. Each Vibe gets a local Git repository, version history, its own conversation and project notes.
- **The package:** click **Package** to assemble the compiled plugin, manifest, README, icon and optional changelog into a ZIP in the format used by Thunderstore and Hexium. Valheim and framework reference DLLs are kept out of the package.

The default workflow builds changes and commits a successful build, so you can see how your mod evolved and come back to improve it later.

## Your own mod catalogue, right next to the others

**Publish to Catalogue** adds the built version to your server's **Hugin's Vibes** catalogue. It appears beside Thunderstore and Hexium in the world's mod picker.

1. Create a Vibe and describe your idea.
2. Work with Hugin until the build is green, then click **Package**.
3. Click **Publish to Catalogue**.
4. Select the mod in your test world's Mods list and update that world.
5. Sync your client and play.

Want the stamina cost a little lower? Go back to the conversation, ask for the change, and repeat. You choose when a version reaches a world through the normal world-update workflow.

Local publishing does not require a GitHub, GitLab, Thunderstore or Hexium account. **Unpublish from Catalogue** removes a version from local availability.

## Keep it local, or share it

- **GitHub and GitLab:** import an existing repository or connect your Vibe to either service. History shows the branch, changed files, remote URL and ahead/behind counts, with Fetch, fast-forward Pull and Push controls. Imported branch names such as `master` are preserved on first push.
- **Thunderstore and Hexium:** download compatible payload ZIPs from the Package tab. Direct publishing is available with the appropriate token, team and categories under **Vibes Settings → Publishing**.
- **Your choice of model:** use a compatible model with tool support through Hugin's existing hosted or self-hosted providers. The model picker updates the header so you can see which model you're using.

## A workshop you can follow

Live tool cards, compiler feedback and persistent job results make the work visible. Read back through the conversation without being pulled to the bottom; jump to the latest activity when you're ready. Token totals, estimated context usage and automatic compaction help keep longer sessions moving.

Collapse the Vibes list, Forge or Project Notes for more room. The workbench also fits a phone. Hugin even moves a little while he's busy. A raven has to stay entertained.

## Also in 2.59

- **Companion 1.0.6 can recover a late join code.** The menu polls for connection data and shows readiness, retry and expiry feedback, so a code that becomes available after launch can arrive without restarting the game. Update the world and sync clients to receive the new Companion.
- **Catalogue buttons respond properly.** Thunderstore, Hexium and Hugin's Vibes reflect the saved filter, preserve search and sorting, and filter the list without rebuilding thousands of rows. Selected mods remain visible.
- **Hugin counts backups from the database.** Backup totals and listings now use the same records as the Backups tab.

## Issues

- Fixes #94: Hugin could drop a streamed `0`, corrupting timestamps, durations and other numbers on some models.

## Upgrading

After this release is published, pull `theoriginalbrian/phvalheim-server:2.59` and recreate your container with its existing data volume. Database migrations run at startup.

For the connection-recovery changes, update each world and sync its clients to install **Companion 1.0.6**. To start making mods, open **Tools → Hugin's Vibes** and follow the optional setup.

## Validation and remaining live checks

167 automated test files have passing results across the full runs and a corrected browser-test rerun. The new SCM/import suite passes 58 assertions, including importing, compiling, packaging and downloading a mod. GitHub import, authenticated push, fetch and pull were also exercised live; the temporary branch was removed afterward.

GitLab write flows were tested using real Git transfers and an isolated API fixture; live GitLab account permissions still need validation. Real-client Unity/PlayFab connection recovery and credentialed Thunderstore/Hexium uploads remain operator checks. Build and package success do not replace trying your mod in game.

[Companion 1.0.6 changes](https://github.com/brianmiller/phvalheim-companion/commit/511d77b8da598dba221ea127f32ab7d28643ec18) · [Full changelog](https://github.com/brianmiller/phvalheim-server/blob/master/CHANGELOG.md) · [Changes since v2.58](https://github.com/brianmiller/phvalheim-server/compare/v2.58...v2.59)


<a href="https://github.com/user-attachments/assets/34fb4ced-c988-4d29-8970-0668d069e7af"><img width="600" alt="Start a Vibe with an idea or a starter prompt" src="https://github.com/user-attachments/assets/34fb4ced-c988-4d29-8970-0668d069e7af" /></a>

<a href="https://github.com/user-attachments/assets/c84f274c-172f-4627-bff2-9d257d1226f6"><img width="600" alt="Hugin’s Vibes workbench with chat, tool activity and build results" src="https://github.com/user-attachments/assets/c84f274c-172f-4627-bff2-9d257d1226f6" /></a>