<?php
/**
 * Boss trophy ("hung heads") registry.
 *
 * This is the single source of truth for the boss progression feature. Adding a boss
 * should be one entry here, one DB column, and one PNG -- nothing else.
 *
 * How a hung head actually gets here:
 *
 *   player hangs a trophy on the sacrificial stone
 *     -> ItemStand.DelayedPowerActivation                     [Valheim]
 *       -> HungHeads.Postfix                                  [phvalheim-companion]
 *          reads __instance.m_supportedItems[0].name          <- the PREFAB name
 *       -> POST {action:"<prefab>", world:"<name>"} to /api.php
 *         -> public/api.php validates against THIS list
 *           -> setHungHeads() UPDATE worlds SET <column>=1
 *
 * Because the companion mod reads the prefab name off the item stand at runtime, a new
 * boss needs NO phvalheim-client release and NO phvalheim-companion release. The server
 * side list below is the only gate.
 *
 * SECURITY: setHungHeads() interpolates the column name straight into SQL. It is safe
 * only because the caller validates against this registry first. If you add a lookup
 * path that does not go through bossColumnForPrefab(), you have created an injection.
 * The 'column' values must therefore stay [a-z_]+ and must never come from user input.
 */

/**
 * Ordered by progression -- this is also the left-to-right render order of the trophy row.
 *
 *   key    : stable identifier used in the JSON API and in CSS class names (.trophy-<key>)
 *   column : worlds table column, always strtolower(prefab)
 *   prefab : the exact Valheim prefab name the companion mod sends as `action`
 *   name   : display name shown to players
 *   icon   : filename under container/nginx/www/images/
 */
$PHVALHEIM_BOSSES = [
    [
        'key'    => 'eikthyr',
        'column' => 'trophyeikthyr',
        'prefab' => 'TrophyEikthyr',
        'name'   => 'Eikthyr',
        'icon'   => 'TrophyEikthyr.png',
    ],
    [
        'key'    => 'theElder',
        'column' => 'trophytheelder',
        'prefab' => 'TrophyTheElder',
        'name'   => 'The Elder',
        'icon'   => 'TrophyTheElder.png',
    ],
    [
        'key'    => 'bonemass',
        'column' => 'trophybonemass',
        'prefab' => 'TrophyBonemass',
        'name'   => 'Bonemass',
        'icon'   => 'TrophyBonemass.png',
    ],
    [
        'key'    => 'dragonQueen',
        'column' => 'trophydragonqueen',
        'prefab' => 'TrophyDragonQueen',
        'name'   => 'Moder',
        'icon'   => 'TrophyDragonQueen.png',
    ],
    [
        'key'    => 'goblinKing',
        'column' => 'trophygoblinking',
        'prefab' => 'TrophyGoblinKing',
        'name'   => 'Yagluth',
        'icon'   => 'TrophyGoblinKing.png',
    ],
    [
        'key'    => 'seekerQueen',
        'column' => 'trophyseekerqueen',
        'prefab' => 'TrophySeekerQueen',
        'name'   => 'The Queen',
        'icon'   => 'TrophySeekerQueen.png',
    ],
    [
        'key'    => 'fader',
        'column' => 'trophyfader',
        'prefab' => 'TrophyFader',
        'name'   => 'Fader',
        'icon'   => 'TrophyFader.png',
    ],

    // --- Deep North (Valheim 1.0) ---
    //
    // BLOCKED on the real trophy prefab name. 'prefab' must be the literal string the
    // companion mod POSTs, which is the prefab's name and NOT the display name.
    //
    // To find it: hang the trophy once on any modded world and read the companion log:
    //     "Completed BossStone Detected: <prefab>"
    // Rejected Trophy* actions are also logged by public/api.php (see logUnknownBoss()),
    // so the first real kill on any world answers this without anyone watching a console.
    //
    // Then: uncomment, add the matching column in dbUpdate_2.40.sh, drop the PNG in
    // images/, and add a .trophy-<key> rule to css/phvalheimStyles.css.
    //
    //[
    //    'key'    => '<newboss>',
    //    'column' => 'trophy<newboss>',
    //    'prefab' => 'Trophy<NewBoss>',
    //    'name'   => '<Display Name>',
    //    'icon'   => 'Trophy<NewBoss>.png',
    //],
];

/**
 * Resolve a prefab name posted by the companion mod to its worlds column.
 * Returns NULL for anything not in the registry -- callers MUST treat NULL as a reject.
 */
function bossColumnForPrefab($prefab) {
    global $PHVALHEIM_BOSSES;
    foreach ($PHVALHEIM_BOSSES as $boss) {
        if ($boss['prefab'] === $prefab) {
            return $boss['column'];
        }
    }
    return NULL;
}

/**
 * Does this look like a boss trophy we simply do not know about yet?
 * Used to tell "Deep North boss we haven't registered" apart from ordinary junk input.
 */
function looksLikeBossPrefab($action) {
    return is_string($action)
        && $action !== ''
        && strncmp($action, 'Trophy', 6) === 0
        && preg_match('/^Trophy[A-Za-z0-9_]{1,48}$/', $action) === 1;
}

/**
 * Record a Trophy* prefab we do not recognise.
 *
 * This exists so the Deep North boss identifies itself: the first player to hang the new
 * head anywhere writes its exact prefab name to the log, and that string is all we need
 * to finish the feature. Without it the POST is a silent no-op and we would be waiting on
 * someone to notice.
 *
 * Best-effort only -- never let logging break the API response.
 */
function logUnknownBoss($prefab, $world) {
    $logFile = '/opt/stateful/logs/phvalheim.log';
    $line = sprintf(
        "%s [NOTICE : phvalheim] UNKNOWN BOSS TROPHY: prefab='%s' world='%s' -- add it to includes/bosses.php and dbUpdate_2.40.sh\n",
        date('D M j H:i:s T Y'),
        $prefab,
        (string)$world
    );
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Every boss's defeated/undefeated state for one world, in progression order.
 * Shape: [ key => ['defeated'=>bool, 'name'=>string, 'icon'=>string, 'status'=>string] ]
 */
function getBossProgression($pdo, $world) {
    global $PHVALHEIM_BOSSES;
    $out = [];
    foreach ($PHVALHEIM_BOSSES as $boss) {
        $defeated = (bool)getBossTrophyStatus($pdo, $world, $boss['column']);
        $out[$boss['key']] = [
            'defeated' => $defeated,
            'name'     => $boss['name'],
            'icon'     => $boss['icon'],
            'status'   => $defeated
                ? $boss['name'] . ' has been defeated'
                : $boss['name'] . ' is undefeated',
        ];
    }
    return $out;
}
?>
