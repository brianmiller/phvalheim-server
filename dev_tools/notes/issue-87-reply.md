Thanks for the writeup — this is built and shipping in **2.47**. It's in the `:rc` image now for testing.

It works close to what you described, with one honest caveat I want to be upfront about.

### What it does

**Per-world on/off, plus a global default.** Server Settings → Automatic Updates sets the default; each world can override it under Settings → Updates. Same inheritance model as the backup system, including that turning it on globally does *not* overrule a world you've explicitly set to off.

**Checks both the game and mods.** A newer Valheim server build is detected by comparing the published build against each world's installed one. Mod updates come from comparing what the world last installed against the current catalogue.

**Waits for the world to be quiet, then stops, updates and restarts.** Default is 30 minutes with no players, and a 24-hour maximum wait.

**You choose what happens at the end of that wait.** The default is *keep waiting* — a world that's always busy is simply never updated, and nobody gets kicked mid-session. You can instead set *update anyway*. There's also an optional maintenance window if you'd rather confine updates to certain hours.

**Pinned mods are never updated.** If you've pinned a mod to a version, that's a decision, and automatic updates leave it alone. The Updates tab lists your pinned mods separately as held so it's clear what's being skipped.

**A backup is taken first**, and if the backup fails the update is abandoned rather than proceeding without a way back. That's configurable too.

**Offline worlds are untouched** — they pick up updates the next time they start, as they always have.

There's also a **Check Now** and an **Update Now** button per world. Update Now skips the quiet check, and says so before it acts.

### The caveat: "nobody is actively playing" is best-effort

Valheim's dedicated server publishes no reliable live player count, and the obvious ways to get one don't survive contact with how PhValheim ships. Socket inspection doesn't work because the server serves every player through a single UDP socket. Connection tracking would need elevated container privileges we can't ask of everyone running this on Unraid, Kubernetes or plain Docker. And our existing tick-monitor plugin does report an accurate count — but it's a BepInEx plugin, so vanilla worlds can never load it.

What we ended up with is reading each world's own server log. That works, but it's **approximate**, and it differs by world type: a crossplay world reports its player count on every join and leave, while a non-crossplay world reports one every ten minutes with connects and disconnects tracked in between.

So the count can lag a disconnect by up to ten minutes on a non-crossplay world. That's why the default quiet period is 30 minutes rather than something short — it needs to absorb both the lag and the reconnect flapping that happens during a network wobble. The UI labels the number as approximate everywhere it appears, and says "no players at last check" with a timestamp rather than claiming a world is empty, because that's the strongest claim the data actually supports.

If you turn this on and it behaves oddly, the Updates tab shows which detection method that world is using and when the count was last observed, which should make any wrong number diagnosable rather than mysterious.

Nothing changes on upgrade — automatic updates are off by default until you switch them on.
