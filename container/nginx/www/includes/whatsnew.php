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
