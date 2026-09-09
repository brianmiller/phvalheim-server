<?php
/**
 * Valheim access lists: permittedlist.txt, adminlist.txt, bannedlist.txt
 *
 * Single source of truth for WHERE these files live, WHAT they look like, and HOW they
 * are written. Before this existed each caller built its own path and called
 * file_put_contents() directly, ignoring the return value -- so a failed write was
 * reported to the operator as "Saved successfully" while the file kept its old contents.
 * That is what made the CITIZENS editor look like it stopped being respected: the
 * database updated, the file did not, and Valheim kept enforcing the stale list.
 *
 * VERIFIED against the real Valheim dedicated server (see dev_tools/test-accesslists.sh):
 * the server reads and writes all three files in the -savedir ROOT, creates any that are
 * missing at startup, and does NOT overwrite entries written from outside -- neither
 * before it starts nor while it is running. So writing these files from PHP is safe; the
 * only thing that ever went wrong was the write itself failing unnoticed.
 *
 * WRITES ARE ATOMIC (write temp + rename) for two reasons:
 *   1. rename() needs write permission on the DIRECTORY, not on the target file. A list
 *      file left owned by root -- by an older engine, or by a restore that runs as root --
 *      is then still replaceable by php-fpm running as phvalheim. Plain file_put_contents()
 *      is not, and that is the exact failure reproduced above.
 *   2. Valheim can read the file at any moment. A rename swaps it in whole; a truncating
 *      write can be observed half-written.
 */

# Header lines are copied byte-for-byte from what the real Valheim server writes when it
# creates these files itself. Note the DOUBLE space in the admin and banned headers -- that
# is Valheim's own spacing, not a typo. They are only comments, but matching them keeps a
# PhValheim-written file indistinguishable from a Valheim-written one.
$PHVALHEIM_ACCESS_LISTS = [
	'citizens' => [
		'file'   => 'permittedlist.txt',
		'header' => '// List permitted players ID ONE per line',
		'column' => 'citizens',
		'label'  => 'Citizens',
	],
	'admins' => [
		'file'   => 'adminlist.txt',
		'header' => '// List admin players ID  ONE per line',
		'column' => 'admins',
		'label'  => 'Admins',
	],
	'banned' => [
		'file'   => 'bannedlist.txt',
		'header' => '// List banned players ID  ONE per line',
		'column' => 'banned',
		'label'  => 'Banned',
	],
];

/**
 * The directory Valheim is started with as -savedir. Must stay in lockstep with the
 * -savedir argument in container/games/valheim/scripts/startWorld.sh.
 */
function accessListDir($world) {
	return "/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim";
}

function accessListPath($world, $kind) {
	global $PHVALHEIM_ACCESS_LISTS;
	if (!isset($PHVALHEIM_ACCESS_LISTS[$kind])) {
		return null;
	}
	return accessListDir($world) . '/' . $PHVALHEIM_ACCESS_LISTS[$kind]['file'];
}

/**
 * Normalise a free-form textarea value into a single space separated string.
 * Accepts newlines, commas, tabs and runs of spaces.
 */
function normaliseIdList($raw) {
	$raw = str_replace(["\r\n", "\r", "\n", ",", "\t"], ' ', (string)$raw);
	return trim(preg_replace('!\s+!', ' ', $raw));
}

/**
 * Split a normalised list into [valid, rejected].
 *
 * Valheim silently ignores anything in these files that is not a SteamID64, which from the
 * operator's side looks identical to "I added them and nothing happened". Rejecting the
 * input outright is the only way they find out.
 */
function partitionSteamIds($normalised) {
	$valid = [];
	$rejected = [];
	foreach (array_filter(explode(' ', $normalised)) as $candidate) {
		if (preg_match('/^\d{17}$/', $candidate)) {
			$valid[] = $candidate;
		} else {
			$rejected[] = $candidate;
		}
	}
	return [$valid, $rejected];
}

/**
 * Write one access list file for a world.
 *
 * $ids is a space separated string (or empty for "no entries").
 * Returns ['ok' => bool, 'error' => string|null].
 *
 * Every failure path returns an error rather than being swallowed. Callers MUST surface it;
 * reporting success for a write that did not happen is the bug this module exists to kill.
 */
function writeAccessList($world, $kind, $ids) {
	global $PHVALHEIM_ACCESS_LISTS;

	if (!isset($PHVALHEIM_ACCESS_LISTS[$kind])) {
		return ['ok' => false, 'error' => "Unknown access list '$kind'"];
	}

	$dir    = accessListDir($world);
	$target = accessListPath($world, $kind);
	$header = $PHVALHEIM_ACCESS_LISTS[$kind]['header'];

	# The world may not have been prepped yet (created but still deploying). Creating the
	# directory here is safe: Valheim is given exactly this path as -savedir.
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return ['ok' => false, 'error' => "Could not create $dir"];
		}
	}

	if (!is_writable($dir)) {
		return ['ok' => false, 'error' => "Directory not writable by the web user: $dir"];
	}

	# Trailing newline after the last entry: Valheim copes without it, but every file it
	# writes itself ends with one, and it keeps diffs and appends well behaved.
	$body = $header . "\n";
	$ids  = trim((string)$ids);
	if ($ids !== '') {
		$body .= str_replace(' ', "\n", $ids) . "\n";
	}

	$tmp = @tempnam($dir, '.list');
	if ($tmp === false) {
		return ['ok' => false, 'error' => "Could not create a temporary file in $dir"];
	}

	if (@file_put_contents($tmp, $body) === false) {
		@unlink($tmp);
		return ['ok' => false, 'error' => "Could not write $target"];
	}

	# tempnam() creates 0600. Valheim runs as the same user as php-fpm today, but the world
	# process has not always been that user, so keep the file group readable/writable.
	@chmod($tmp, 0664);

	if (!@rename($tmp, $target)) {
		@unlink($tmp);
		return ['ok' => false, 'error' => "Could not replace $target"];
	}

	return ['ok' => true, 'error' => null];
}
