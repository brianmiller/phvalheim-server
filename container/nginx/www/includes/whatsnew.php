<?php
/**
 * Release notes for the one-shot "What's New" modal in the admin UI.
 *
 * EVERY release adds an entry here. `dev_tools/check-whatsnew.sh` fails if the version in
 * the Dockerfile has no entry, so a release cannot ship without telling operators what
 * changed. Keep each line one plain sentence about something the operator can observe --
 * this is a changelog for people running the server, not a commit log.
 *
 * Newest version first is not required; the modal sorts.
 */
function whatsNewNotes() {
    return [
        '2.47' => [
            'New: <b>PhValheim now tracks how many players are on each world.</b> Valheim gives a dedicated server no reliable live player count, so this is read out of each world&rsquo;s own log on a best-effort basis &mdash; it can lag a disconnect by up to ten minutes on a non-crossplay world, and it is labelled as approximate everywhere it appears. It is the groundwork for automatic updates, which need to know when a world is quiet.',
            'Crossplay and non-crossplay worlds are counted <b>differently, on purpose</b>. A crossplay world reports its own count on every join and leave; a non-crossplay world reports one every ten minutes, and arrivals and departures in between are followed as they happen. The single method that looked like it would cover both reports zero on a crossplay world while someone is playing on it, so it is not used there.',
            'New: you can <b>show a world&rsquo;s player count on the public page</b>, per world, under Settings &rarr; Options. It is off by default. The admin UI always shows the count. Both places label it approximate, because it is.',
            'New: <b>automatic game and mod updates</b>, off by default. Turn them on in Server Settings &rarr; Automatic Updates, or per world under Settings &rarr; Updates. PhValheim checks for a newer Valheim server build and newer mod versions, then waits for the world to go quiet before stopping it, updating and starting it again. Upgrading to 2.47 changes nothing on its own &mdash; nothing is updated until you switch it on.',
            '<b>Pinned mods are never updated automatically.</b> A pin means you chose that version, so automatic updates leave it alone and the Updates tab lists your pinned mods separately as held.',
            'You decide what happens when a world <b>never goes quiet</b>. By default PhValheim keeps waiting, so a busy world is simply never updated and nobody is kicked off mid-session. You can instead set a maximum wait after which it updates anyway, and you can restrict updates to a maintenance window.',
            'A <b>backup is taken before any automatic update</b>, and if that backup fails the update is abandoned and nothing is changed. You can turn the backup off, globally or per world.',
            'New: an <b>Updates tab</b> on each world showing the installed Valheim build against the published one, which mods have newer versions, which are pinned, and what the world is currently waiting for &mdash; plus <b>Check Now</b> and <b>Update Now</b> buttons. Update Now ignores the quiet check and says so before it acts.',
            'Per-world update settings work like the backup ones: a world uses the global defaults until you give it its own. Turning automatic updates on for all worlds <b>does not overrule</b> a world you have explicitly set to off.',
            'Worlds that are stopped are left alone entirely &mdash; they pick up updates the next time they start, as they always have.',
            'The Updates tab shows <b>which part of an update is running</b> &mdash; backup, stopping, Valheim server, mods, starting &mdash; with a progress row for each. Previously it said only &ldquo;updating&rdquo; for the whole job, so several minutes of backup looked the same as a stuck update.',
            'A world being updated now shows that on its <b>row in Active Worlds</b>, moving through Backup, Stopping, Updating and Starting, instead of sitting on &ldquo;Running&rdquo; for the whole job.',
            'New: a <b>World last updated</b> row in the Updates tab, separate from when PhValheim last checked for updates.',
            'Fixed: <b>the same scroll problem in two more places.</b> Every five seconds the dashboard also rebuilt the action buttons on every world row, measuring and re-measuring each one even when nothing had changed, which jumped Active Worlds as well as the offline list. That work is now skipped unless the buttons or the column width actually changed. And in the mod picker, redrawing the tables changed their height, so the page moved under you &mdash; the page position is now held along with the list position.',
            'Fixed: <b>the worlds table could not be scrolled.</b> The five-second refresh was re-inserting every offline world row on every tick to keep them sorted, and moving a row that is already on the page tears it out and puts it back &mdash; so the table rebuilt itself under you roughly every four seconds, throwing you back to the top and dropping any text selection. Rows are now only moved when the order has actually changed, which in normal use is never.',
            'Fixed: <b>&ldquo;Check Now&rdquo; reported every world as up to date, always.</b> The version check runs as the phvalheim user, and steamcmd needs a writable home directory it was not being given, so it failed before reporting anything. The check then found no published build and the tab drew a green &ldquo;up to date&rdquo; over it. A world several thousand builds behind looked current. A check that cannot run now says <b>could not check</b> and shows why.',
        ],
        '2.46' => [
            'Fixed: Hugin could describe a world&rsquo;s password wrongly. A world has two password-related columns &mdash; the password itself, and a setting controlling whether it is shown on the public world card &mdash; and Hugin was handed the second one as if it were a <b>second password</b>. It reported that one as "set" whichever way it was switched, and went on to describe it to operators as a separate password for the public view. No such password exists. Hugin is now told what that setting actually is.',
            'Fixed: Hugin now knows that <b>a password only applies to a vanilla world</b>. A modded world is started with no password at all &mdash; who may join is decided by its CITIZENS list &mdash; but the password you set is still stored and shown, so Hugin could tell you a modded world was password protected when nothing was checking it. It now reports both facts: whether a password is set, and whether it is actually in effect.',
            'Fixed: a world with no password could be reported to Hugin ambiguously depending on how the empty value was stored, so it might read as neither set nor unset. It now reads the same either way.',
            'Fixed: when Hugin is asked about the server as a whole rather than one world, the summary it gets now includes <b>whether each world has a password</b>. Previously that summary carried who-may-join settings and nothing about passwords, so a question about how your server is secured could only be answered from half the picture.',
            'Fixed: <b>the raven stays in view while Hugin is working.</b> The thinking indicator sat at the top of the answer, so on anything longer than the panel it scrolled away — you lost the raven, the phrase and the timer exactly when the wait was longest, and had to scroll back up to check anything was still happening. It is now pinned to the bottom of the conversation until the answer lands.',
            'Much better formatting for <b>smaller and self-hosted models</b>. Answers from those models lean heavily on tables, section rules and quotes, and none of them were being rendered: a comparison table arrived as rows of literal <code>|</code> characters and a section break as a line of dashes. Tables (with column alignment), horizontal rules, quotes, nested sub-bullets, lists numbered from something other than 1, and code blocks the model forgot to close all render properly now. Heading levels are also distinguishable, so a long answer is no longer one flat wall of text.',
            'Fixed: smaller models <b>think out loud</b>, and that working-out was ending up at the top of the answer — several paragraphs of "let me check…", "now I have enough…" before the first real sentence. Hugin now drops it: anything written before it looks something up is removed, and the tools it used are still listed under the reply. If in any doubt the text is left alone, so nothing is ever thrown away silently.',
            'New: you can <b>set which AI provider is the default</b> from Server Settings &rarr; AI Helper &mdash; there is a "Make default" button on each provider. Previously the only way to change it was to re-run the whole Add-provider wizard over an existing entry.',
            'Fixed: <b>Hugin thought every world was stopped.</b> It was reading the wrong database column for whether a world is running — one that reads "Down" for every world on the server, including the ones actively serving players. So it would tell you a world you were standing in was stopped, read its live log as ancient history, and <b>refuse to stop or restart anything</b> with "that world is already stopped". Diagnostics were affected too: findings about running worlds were being downgraded to "historical", and the restart-loop and backup-freshness checks were skipped for exactly the worlds that needed them. It now uses the same source as the admin world list, so Hugin and the UI agree.',
            'The Back and Next buttons in the Add/Edit AI provider dialog no longer sit jammed into the bottom corners.',
        ],
        '2.45' => [
            'Fixed: the AI Helper no longer breaks when a provider retires a model. It never had a built-in model list to go stale &mdash; <b>the list of models you can pick from is now fetched live from your provider</b> every time. If Google retires a Gemini model, it simply stops appearing. This was reported as "Gemini models are retired/deprecated" (issue #83); the model that was hardcoded is gone along with the practice of hardcoding one.',
            'Fixed: choosing a model that PhValheim did not recognise used to <b>silently swap it for a different one</b> and answer with that instead. Your choice is now sent exactly as you made it.',
            'New: <b>any OpenAI-compatible endpoint is supported</b>, with an API key. That covers self-hosted vLLM, LM Studio and llama.cpp, plus OpenRouter, Groq, Together, DeepSeek, Mistral and xAI. Previously the only local option was Ollama and it had no field for a key, so a server behind <code>--api-key</code> could not be used at all.',
            'Ollama is now one of eleven one-click <b>endpoint presets</b> on the OpenAI-compatible type (with vLLM, LM Studio, llama.cpp, OpenRouter, Groq and more) rather than a separate provider type. Any Ollama provider you already had is converted automatically on upgrade.',
            'New: you can configure <b>as many providers as you like</b>, including several of the same type &mdash; a cloud key for hard questions and a local model for everyday ones, or a lab box alongside a production one. Pick which to use from the dropdown in the panel header.',
            'New: an <b>Add AI provider wizard</b> in Server Settings walks through type, endpoint, credentials, a live connection test and model selection. The test tells you which of the three is wrong &mdash; endpoint, key or model &mdash; instead of failing later as a chat error, and shows the provider\'s own error message.',
            'New: the assistant can now <b>read what it needs on its own</b>. It was previously handed the last 200 lines of one log and nothing else, so it could not follow a lead or check whether the thing it was blaming was even configured. It can now list and search any log in full, read a world\'s log from the most recent start only, and look up world settings, the resolved mod list, catalogue sync state, backups and host health. Each assistant reply shows which of these it actually looked at, so you can check its work.',
            'New: a <b>health scan runs the moment you open the panel</b>, with no AI provider needed at all. It reports mod load failures, missing dependencies, mods configured but never loaded, permission errors, Steam download trouble, port conflicts, restart loops, overdue backups, failed catalogue syncs, low disk and stopped services &mdash; each with the log lines that triggered it and a one-click "Ask AI about this".',
            'New: the health scan flags a world whose <b>permitted list is enforced but empty</b>. Valheim only enforces that list when it has entries, so an empty one means the world is open to everyone while the Access tab implies it is private.',
            'New: replies <b>stream as they are written</b> instead of appearing after a long pause, and are formatted properly &mdash; headings, lists, and readable log excerpts.',
            'Changed: the AI Helper button is <b>always visible</b>. It used to be hidden until an API key was set, which hid the health scan from the operator most likely to need it.',
            'Changed: the "Context" dropdown that chose which single log to attach is gone. Pick which <b>world</b> you are asking about instead, or leave it on the whole server; the assistant fetches whatever logs the question needs.',
            'Note: existing API keys are migrated automatically. Their model is deliberately <b>not</b> carried over &mdash; it is re-resolved from your provider on first use, which is what fixes the retired-model problem for upgrades and not just fresh installs. Open the panel and confirm the model shown in the header is one you want.',
            'New: the assistant is now called <b>Hugin</b>, and it can <b>do things, not just explain them</b>. Ask it to start a world, back one up, change a world\'s settings, edit who may join, adjust a backup schedule, change the mod list, rebuild a world or restore from a backup, and it will carry it out.',
            'New: <b>nothing that matters happens without your say-so</b>. Anything that stops a service, changes configuration or destroys data is shown to you first as a card describing exactly what will change &mdash; old value to new value &mdash; with Apply and Dismiss. Starting a world and taking a backup happen immediately, because neither can lose anything.',
            'The confirmation is built <b>on the server from the validated change</b>, not from the assistant\'s description of it. If the model says one thing and the change is another, the card shows the change. Each confirmation works once, expires after fifteen minutes, and is re-checked at the moment you click &mdash; so if the world moved on in the meantime, it stops instead of acting on stale information.',
            'Deleting a world and restoring a backup additionally require you to <b>type the world\'s name</b>. Restoring is also refused outright if the backup belongs to a different world.',
            'Hugin <b>refuses changes that would quietly break something</b>: an access list that would be enforced but empty (which opens a world to everyone rather than closing it), listing a vanilla world with no password (Valheim will not start), and crossplay or a password on a modded world (where they do nothing). It also will not act on a world name it cannot find &mdash; it shows you the real list instead.',
            'New: a <b>"What can Hugin do for me?"</b> button lists everything it can inspect, everything it can do, and everything it will refuse. It is generated from the actual capability list rather than written down separately, so it cannot drift, and it works even with no AI provider configured.',
            'New: if your model <b>cannot use tools</b> &mdash; some smaller and self-hosted models cannot &mdash; Hugin now answers anyway instead of failing, and says clearly that it could not inspect anything and cannot make changes. Previously such an endpoint simply returned an error on every message.',
            'Fixed: a streamed reply could <b>silently lose words</b> mid-sentence when it contained an em dash, an accented letter or non-Latin text and the provider split the character across two chunks. The text now reassembles correctly.',
            'Anonymous analytics, if you leave them on, now include <b>counts</b> of assistant use: conversations, which tools get used, how many changes were proposed versus applied, error categories, and whether endpoints support tool calling. No prompts, replies, world names, mod names, model names, endpoints or keys are ever sent.',
            'Fixed (unrelated to the assistant): a modded world\'s log opened with <b>"has crossplay set"</b> every time you started it, which read as though crossplay was on. It never was &mdash; crossplay applies to vanilla worlds only, and modded worlds have always started without it. Only the wording was wrong, and it now states the effective setting first: <code>crossplay is OFF</code>, followed by why and what to do if you want it.',
        ],
        '2.44' => [
            'Fixed: a single mod could freeze a whole world\'s mod list. Mods packaged on Windows store their files with backslash separators &mdash; <code>SmartContainers</code> is one &mdash; and <code>unzip</code> extracts them correctly but emits a warning. That warning was being read as an install failure, which stopped the world, <b>left the client download and the mod list frozen at their previous contents</b>, and logged that the mod was missing when it was installed fine. If editing your mod list appeared to do nothing, this was why.',
            'Fixed: the <b>BepInEx mod loader no longer appears in the mod list</b>. It was listed up to three times &mdash; once per catalogue that publishes it &mdash; and one copy carried a yellow "dependency (deselected)" badge suggesting something required was missing. Nothing was: the loader is installed automatically on every modded world, always at the latest version, whatever you select. Selecting or pinning it never had any effect, so it is no longer offered. Existing worlds have the stray entry removed on first start.',
            'Fixed: the engine log no longer fills with <code>UDP_PORT_25000-25100: command not found</code>. Port variables named after a range are not valid shell identifiers, and one was being logged every two seconds &mdash; tens of thousands of lines a day in the log you send us when something is genuinely wrong.',
        ],
        '2.43' => [
            'New: mods can now come from <b>Hexium</b> as well as Thunderstore, and a world may use both at once. Search results carry a coloured pill showing which catalogue each mod came from &mdash; Thunderstore blue, Hexium purple &mdash; and the buttons above the mod list let you show or hide a catalogue.',
            'New: you can <b>pin a mod to any previously published version</b> instead of always tracking the latest. Select a mod, then pick a version from the dropdown in its row; "Latest (auto)" keeps following new releases. Pinned versions are kept even if the source stops listing them.',
            'New: the catalogue sync was rebuilt. A full build of both catalogues &mdash; over 91,000 mod versions including every historical release &mdash; now takes about half a minute instead of hours, and a routine check that finds nothing changed takes about two seconds. Thunderstore is asked with a conditional request and Hexium is compared by content hash, so an unchanged catalogue costs one HTTP call and no database writes.',
            'New: Sync &amp; Maintenance shows a live panel per catalogue &mdash; current phase, packages and versions seen, how many mods and versions were added, changed or removed, how long it took, and how that compares with the previous run. Mod counts on disk and the size of the local archive cache are shown alongside.',
            'New: the mod database now stores <b>every published version</b> of every mod with its own download URL, file size and release date, instead of only the newest. Dependencies are resolved across catalogues, so a Hexium mod that needs a Thunderstore-only dependency now resolves correctly.',
            'New: Server Settings &rarr; Mod Catalogues lets you enable or disable each catalogue, set how often they are checked, and supply an API key. <b>Neither catalogue needs a key</b> &mdash; both are public &mdash; so leave them empty unless a source starts requiring one.',
            'Fixed: two mods whose names differed only in capitalisation (for example <code>Iron_ModPack</code> and <code>Iron_Modpack</code>) were treated as the same mod and overwrote each other on every sync. 22 such pairs exist on Thunderstore today and all of them are now stored separately.',
            'Fixed: mod dependencies whose author or version contains a hyphen &mdash; <code>LVH-IT</code>, <code>sinai-dev</code>, or a prerelease like <code>2.0.6-beta.1</code> &mdash; were matched to the wrong package or not at all.',
            'New: each catalogue in Sync &amp; Maintenance has its own <b>live sync log</b>. Expand it to watch a sync as it happens — the endpoint used, how change detection decided to fetch or skip, what was added, updated or delisted <b>by name</b>, which dependencies could not be resolved, and a per-phase timing breakdown showing where the time went. A <b>per-mod detail</b> toggle hides the individual mod lines when you only want the summary.',
            'Changed: the <b>Thunderstore Sync</b> button has been removed from the sidebar, along with its confirm dialog and stop button. Catalogue syncing needs no attention now — both catalogues are checked <b>every time the server starts</b> and hourly after that, an unchanged catalogue costs about a second, and Sync &amp; Maintenance shows live state per catalogue with its own <b>sync</b> link if you want to force one. The <b>Thunderstore Local Sync</b> and <b>Thunderstore Chunk Size</b> settings are gone too; they configured the old parallel-worker sync and no longer changed anything.',
            'Fixed: a world could end up with <b>two copies of the same mod</b> when it drew on both catalogues &mdash; most often BepInEx, which Thunderstore and Hexium both publish. Only one was ever installed, but the mod list showed it twice and the mod count was one too high. Selecting the same mod from both catalogues now keeps one and tells you which copy it kept.',
            'Fixed: world cards and the mod editor reported <b>0 mods</b> for every world. They counted the pre-2.43 mod columns, which the new catalogue no longer writes. Backup manifests recorded an empty mod list for the same reason.',
            'Note: your existing mod selections are migrated automatically on first start. The previous Thunderstore tables are left untouched, so nothing is discarded. If a world had selected a mod that has since been delisted, the engine log names that world at startup so you can re-pick it.',
        ],
        '2.42' => [
            'Fixed: on Unraid, automatic backups stayed disabled even with a dedicated backup volume mounted. The admin UI said "dedicated volume" while the scheduler disagreed and skipped every run. Check Logs &rarr; Backups to confirm scheduled backups now start.',
        ],
    ];
}

/**
 * Which release notes to show: everything newer than the operator has already seen.
 *
 * $shownVersion is settings.whatsNewShownVersion -- the version whose notes were last
 * dismissed. Empty means this database has never shown the modal, in which case only the
 * running version's notes appear; replaying every historical release at someone who just
 * upgraded once is noise.
 *
 * Entries newer than the running version are skipped so that notes can be written ahead
 * of a release without leaking into the previous one.
 */
function whatsNewSince($shownVersion, $currentVersion, $notes = null) {
    $shownVersion = trim((string)$shownVersion);
    $currentVersion = trim((string)$currentVersion);
    if ($currentVersion === '') return [];

    $out = [];
    foreach (($notes === null ? whatsNewNotes() : $notes) as $version => $items) {
        $version = (string)$version;
        if (empty($items)) continue;
        if (version_compare($version, $currentVersion, '>')) continue;

        if ($shownVersion === '') {
            if ($version !== $currentVersion) continue;
        } elseif (version_compare($version, $shownVersion, '<=')) {
            continue;
        }
        $out[$version] = $items;
    }

    uksort($out, function ($a, $b) { return version_compare($b, $a); });
    return $out;
}
