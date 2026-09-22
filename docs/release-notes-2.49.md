Two long-standing bugs, both of which hid the same way: the thing that was broken was the thing
that would have told you it was broken.

## Your world log stopped listing the mods it was loading

The lines naming each plugin as it starts come from the mod loader's own logging, and that
logging was being switched off behind your back.

Rebuilding a world clears out the configuration files belonging to mods you have removed. That
sweep was also deleting the **loader's** own configuration file — which is not a mod's, and
which nothing put back. The loader then started on its built-in defaults, and its built-in
default is to log nothing at all.

Your mods were loading the whole time. You just could not see it happen.

## The BepInEx window stopped appearing for your players

Same single cause. The client receives a copy of the loader folder exactly as it exists on the
server, so once that file was missing on the server, every client got a copy without it, and the
window that normally opens alongside the game stopped opening.

PhValheim now treats the loader's configuration as its own to look after, the same way it
already looks after the loader itself: kept through a rebuild, restored from the installed
loader package if it ever does go missing, and checked on every rebuild. Configuration files
belonging to your mods are cleared exactly as before.

Affected worlds repair themselves — **rebuild the world** and the logging comes back, for that
world and for everyone who syncs it afterwards.

## Changing Game DNS never reached your players

Open for years, and it was two separate faults stacked on each other.

Each world stored a copy of the address at the moment it was created, and **nothing ever updated
that copy**. So editing Game DNS in Server Settings changed the Steam launch button — which
reads the setting live — while every world's QuickConnect entry kept pointing at the old
hostname, permanently. The two ways of joining disagreed, which is exactly why this was so hard
to pin down.

Underneath that, the engine read Game DNS **once when it started** and then ran for weeks on
that value. So even after the first fix, a world updated right after a DNS change still got the
old address written into it.

Both are fixed. Updating a world now rewrites its QuickConnect address from the current setting.

Because that file is only written when a world is updated, PhValheim now **tells you so**:
change Game DNS and you get a notice saying every world needs updating before players see the
new address. Worlds you do not update keep the old one.

Also fixed: an **imported** world had no QuickConnect address at all — the import wrote the file
with an empty hostname, so the entry it created could never connect.

## Upgrading

Nothing to do beyond pulling the new image. Both fixes take effect as you rebuild or update each
world; worlds you leave alone keep behaving exactly as they do now.

If you have changed Game DNS in the past and QuickConnect has been sending players to the wrong
address, updating each world will finally correct it.

## Verified

Both fixes were tested on a live server with real worlds and a real client before this release.
The Game DNS fix in particular shipped once in a release candidate, failed that live test, and
was corrected — the engine-startup fault above was found that way rather than by reading code.
