<?php
# Oracle test: validateWorldPassword(), the form guard that stands between an operator and a
# world that restart-loops.
#
# Why this file exists. Valheim's FejdStartup.IsPublicPasswordValid enforces THREE rules, and
# ParseServerArguments answers a failure with Application.Quit(). That is a clean exit, so
# supervisor restarts the world and it loops, with "Error bad password:" buried in the world log.
# Decompiled from the real (un-publicized) assembly:
#
#   password.Length >= m_minimumPasswordLength      -> $menu_passwordshort
#   !world.m_name.Contains(password)                -> $menu_passwordinvalid
#   !world.m_seedName.Contains(password)            -> $menu_passwordinvalid     <- missing until 2.53
#
# The seed rule was simply absent, so a password containing the world's seed passed the form and
# killed the server the moment the world was listed. Live for vanilla worlds before 2.53.
#
# The question-mark rule is OURS, not Valheim's: the client launch payload is a '?'-delimited
# POSITIONAL string and the password is field 2, so a '?' shifts gameDNS, port, vanilla, crossplay
# and joinCode by one for every client and Companion that parses it. Nothing errors -- each one
# hands the wrong value to the wrong name. It was inert while modded worlds sent the literal
# "hammertime" and vanilla passwords were rare; 2.53 sends the real password for every world.
#
# Usage:  php dev_tools/test-password-rules.php

$root = dirname(__DIR__);
$src  = file_get_contents("$root/container/nginx/www/admin/adminAPI.php");

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail ? " -- $detail" : "") . "\n"; }
}

# Drive the REAL function out of the page rather than a copy of it here. A copy would keep
# passing after someone edited the shipped one, which is the only failure this test must catch.
if (!preg_match('/function validateWorldPassword\(.*?\n}\n/s', $src, $m)) {
    echo "could not find validateWorldPassword() in adminAPI.php -- extraction is stale\n";
    exit(1);
}
eval($m[0]);

echo "\nvalidateWorldPassword(): Valheim's three rules, plus our payload rule\n\n";

# --- the empty password is NOT an error -------------------------------------------------
# An unlisted world needs no password at all: ParseServerArguments only validates when -public
# is set. Rejecting '' here would make "no password" unreachable from the UI.
check("'' is allowed (an unlisted world needs no password)",
      validateWorldPassword('', 'myworld', '12345') === NULL);

# --- CONTROL ----------------------------------------------------------------------------
# Something must prove this function can SAY YES. Without it, a validator that rejected
# everything would pass every rejection case below and the suite would look green.
check("control: a clean password is accepted",
      validateWorldPassword('Rh9kmTqw4vXz', 'myworld', '12345') === NULL,
      'got: ' . var_export(validateWorldPassword('Rh9kmTqw4vXz', 'myworld', '12345'), true));

# --- rule 1: minimum length -------------------------------------------------------------
check('4 characters is rejected',
      validateWorldPassword('abcd', 'myworld', '12345') !== NULL);
check('5 characters is accepted (the boundary is >=, not >)',
      validateWorldPassword('abcde', 'myworld', '12345') === NULL);

# --- our rule: no '?' in the positional payload -----------------------------------------
check("a '?' is rejected",
      validateWorldPassword('abc?def', 'myworld', '12345') !== NULL);
check("the '?' message names the character, so the operator can act on it",
      strpos((string)validateWorldPassword('abc?def', 'myworld', '12345'), '?') !== false,
      'got: ' . var_export(validateWorldPassword('abc?def', 'myworld', '12345'), true));
# A '?' must be caught even when every other rule passes -- otherwise a long, unrelated
# password smuggles one through and silently corrupts every client's payload.
check("a '?' is rejected even in an otherwise perfect password",
      validateWorldPassword('Rh9km?Tqw4vXz', 'myworld', '12345') !== NULL);

# --- rule 2: not contained in the world name --------------------------------------------
check('a password inside the world name is rejected',
      validateWorldPassword('world', 'myworldhere', '12345') !== NULL);
check('and case-insensitively (stricter than Valheim, which is the safe direction)',
      validateWorldPassword('WORLD', 'myworldhere', '12345') !== NULL);

# --- rule 3: not contained in the SEED name (new in 2.53) -------------------------------
check('a password inside the world SEED is rejected',
      validateWorldPassword('seedy', 'myworld', 'xxseedyxx') !== NULL,
      'Valheim rejects this and Application.Quit()s a listed world over it');
check('the seed message names the seed, not the world name',
      stripos((string)validateWorldPassword('seedy', 'myworld', 'xxseedyxx'), 'seed') !== false,
      'got: ' . var_export(validateWorldPassword('seedy', 'myworld', 'xxseedyxx'), true));
check('and case-insensitively',
      validateWorldPassword('SEEDY', 'myworld', 'xxseedyxx') !== NULL);

# An empty seed means Valheim has not written the .fwl yet -- the engine reads the real seed back
# out of it after first start -- so there is nothing to compare against.
#
# The `$seed !== ''` guard in the shipped function is belt and braces, not load-bearing:
# stripos('', $pw) is already false for any non-empty password, so dropping the guard does not
# change this case. It is there to say out loud that an unstarted world has no seed, which is
# otherwise invisible. Verified by mutation -- removing the guard leaves these two green.
check('an empty seed skips the rule rather than rejecting everything',
      validateWorldPassword('Rh9kmTqw4vXz', 'myworld', '') === NULL,
      'a never-started world has no seed stored yet');
check('a missing seed argument behaves the same (default is "")',
      validateWorldPassword('Rh9kmTqw4vXz', 'myworld') === NULL);

# --- the generated password must survive its own rules ----------------------------------
# dbUpdate_2.53.sh generates a 16-character password for every modded world that has none. If
# that alphabet could produce something this function rejects, the upgrade would write a password
# the operator then cannot save from the Settings modal.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
check('the migration alphabet contains no ?',
      strpos($alphabet, '?') === false);
$generated = substr(str_shuffle(str_repeat($alphabet, 2)), 0, 16);
check('a 16-char generated password passes every rule',
      validateWorldPassword($generated, 'myworld', '12345') === NULL,
      "generated: $generated");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
