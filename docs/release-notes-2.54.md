A one-bug release. 2.53's Launch button on the admin dashboard handed players the wrong
password, so joining a modded world failed with a wrong-password error — even for someone on
the CITIZENS access list.

**No schema change, no world update, no new client.** Upgrade and you are done.

## What you would have seen

Launch worked if you clicked it the instant the dashboard finished loading, and failed if you
waited — which is why it looked random rather than broken.

The dashboard refreshes its world table every five seconds. The refreshed table was rebuilding
the Launch link with a placeholder password left over from before modded worlds could have
one. The player never saw a password prompt, because the Companion had already answered it —
with the wrong value.

**The public world page was never affected.** Its Launch link was always correct, so sending
players there was a valid workaround.

If you cleared a world's password to get people connected, you can set one again in
**Settings → Options** and restart the world.

## What actually broke

2.53 decoupled access control from world type and gave every updated modded world a real
password. It fixed the launch string in two of the **three** callers of
`phvBuildLaunchString()` — `includes/db_gets.php` (the public card) and `admin/index.php` (the
dashboard's server-side render). The third, `getWorldsJson()` in `admin/adminAPI.php`, kept the
pre-2.53 line:

```php
$password = $vanilla ? ($row['password'] ?: "") : "hammertime";
```

That endpoint is the five-second poll. It redraws the world table and rewrites every
`launchHref`, so the correct link the page loaded with survived exactly one refresh.

`db_gets.php:240` had predicted this failure in a comment — for the caller sitting next to it.

## The check that let it through

The build's verify marker read *"the ternary is gone from BOTH launch string builders"* and
grepped two named files. Both returned clean on the build that shipped the bug, because
neither looked at the third caller. `CLAUDE.md` enumerated the same two files. A
count-the-known-sites check cannot see a site nobody counted.

It is replaced by a marker that greps the whole served tree for either the ternary or the
literal passed into the builder, so a fourth caller cannot be added without tripping it, plus
two positive checks that the real column is read — deleting the line would satisfy the
negative while sending an *empty* password, which is the same failure with a different cause.
All three were run against 2.53's code first to confirm they actually fire on the bug.

## Verified

Confirmed inside the published image, not just in the build log: `phvalheimVersion=2.54`, the
fixed line present in `adminAPI.php`, zero code occurrences of the placeholder anywhere in the
served tree (the remaining mentions are comments explaining this bug), and the What's New
entry resolving for both a fresh upgrader and one coming from 2.53.

The fix was confirmed working on a live server before this release was published.
