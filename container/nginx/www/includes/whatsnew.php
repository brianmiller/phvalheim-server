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
