# Review: passwords and listing on modded worlds (§8.6, Q2=C)

Reviewing Brian's 2026-10-02 reply. Verdict: **the plan works and is smaller than §8.6 implies —
the Companion half is already shipped.** One premise needs correcting, one latent bug goes live
the moment this ships, and one question is a real decision rather than an implementation detail.

---

## 1. The premise, corrected

> We're not using a password for modded worlds right now because we couldn't pass a password via
> the CLI when joining. Now that we have our companion mod, this shouldn't be an issue.

Right conclusion. The mechanism is not just possible — **it is already written and already in the
2.53 DLL.**

- The launch payload has carried the password as **field 2 since before 2.53**
  (`phvBuildLaunchString()`: `launch?world?password?gameDNS?…`).
- `ConnectFlow.Begin()` already calls `SetServerPassword(payload.Password ?? "")` before *both*
  the crossplay and the IP join paths.

Verified against the un-publicized `assembly_valheim.dll` in `/tmp/realrefs`, not inferred:

```
$ apiProbe /tmp/realrefs '=FejdStartup.ServerPassword'
PUBLIC  FejdStartup.ServerPassword          # a public string property — reflection isn't even needed

$ apiProbe /tmp/realrefs '!ZNet.RPC_ClientHandshake'
    IL_0012  stsfld   ZNet.m_serverPasswordSalt
    IL_001b  ldfld    ZNet.m_passwordDialog
    IL_003c  callvirt TMP_InputField.set_text
    IL_0064  call     FejdStartup.get_ServerPassword
    IL_006c  call     FejdStartup.get_ServerPassword     # read twice: test, then pass
    IL_0071  call     ZNet.OnPasswordEntered             # auto-submit
    IL_007e  call     ZNet.SendPeerInfo
```

The handshake does read `FejdStartup.ServerPassword` and does auto-submit it. So the design doc's
claim holds at the IL level.

Two honest caveats on that dump: the probe prints only token-bearing opcodes, so **the branches
are invisible** — I cannot tell from it what `SetActive` is passed or how the two
`get_ServerPassword` reads are guarded. And the method prepares `m_passwordDialog` (sets its text,
activates the input field, adds the submit listener) *before* reading the password, so a one-frame
flash of the dialog is plausible. Not a blocker; just don't promise it is invisible until it has
been watched.

### …and it has never actually run

Modded worlds send the literal `hammertime` to a server that has no password, so the server never
asks and `RPC_ClientHandshake` never fires. **`SetServerPassword` has been a no-op whose output is
indistinguishable from success.** It must be exercised against a world with a real password before
anything is built on top of it — a working pre-fill and a silently-failed one look identical today.

---

## 2. The `?` separator — the bug this ships

The payload is `?`-delimited and positional. Field 2 is the password. A password containing `?`
shifts every field after it: host, port, vanilla, crossplay, join code. Both parsers record this
as a known limitation (`LaunchPayload.cs:128`, and phvalheim-client's parser has the same note).

It is inert today **only because modded worlds always send `hammertime`**. The moment real
passwords flow for modded worlds it is live for every modded world, and it is already live for
vanilla: `validateWorldPassword()` rejects `< 5` characters and a password contained in the world
name, and **does not reject `?`**.

**Fix: one line in `validateWorldPassword()` rejecting `?`.** That closes it for vanilla and
modded at once, without touching the wire format on either side. Do this first — it is the cheapest
item on the list and the only one that silently corrupts a join.

---

## 3. Removing `serverblankpassword`

> We'll want to remove the noserverpassword mod entirely, it's not needed

The mod is `thunderstore|1010101110|serverblankpassword` (id 881) and it is **`requiredMods`** in
`phvalheim-static.conf` — force-merged into every modded world by `mergeRequiredTsMods()`.

### 3a. Removing it from the conf does not remove it from worlds

`mergeRequiredTsMods()` only ever `INSERT IGNORE`s. Dropping it from `requiredMods` stops *new*
worlds getting it and leaves every existing world's `world_mods` row intact — and `world_mods` is
the source of truth for what gets installed. `purgeWorldModsConfigsPatchers()` deletes the plugin
files before each rebuild and the reinstall reads the row straight back out and puts it there
again.

This is exactly the QuickConnect bug 2.53 already had to solve. **Reuse that pattern**: the
one-time, settings-flagged migration in `dbUpdate_2.53.sh`. Do not try to fix it in the install
path.

### 3b. It is dead weight TODAY — settled, and it corrects my first answer

I first wrote that this was unresolvable without booting a world. It is resolvable: the
requirement lives in managed code, in the assembly in `/tmp/realrefs`. Decompiled
`FejdStartup.ParseServerArguments` with branches visible (the token-only dump hides them, which is
how I nearly concluded the opposite):

```
IL_001f  ldc.i4.1 ; stloc.s L5          # L5 defaults to TRUE -- i.e. -public is 1 when absent
...
IL_0132  ldstr "-public"
IL_0148  ldstr ""                       # only assigned when a value was supplied
IL_0156  ldstr "1" ; IL_0160 stloc.s L5 # L5 = (value == "1")
...
IL_04c5  ldloc.s L5
IL_04c7  brfalse.s -> IL_04f7           # <<< NOT PUBLIC: SKIP THE PASSWORD CHECK ENTIRELY
IL_04cd  call FejdStartup.IsPublicPasswordValid
IL_04d2  brtrue.s  -> IL_04f7
IL_04eb  call ZLog.LogError             # "Error bad password:" + $menu_passwordshort/invalid
IL_04f0  call Application.Quit          # the server process exits
IL_04ff  call ZNet.SetServer            # the healthy path
```

**The password requirement is gated on `-public`.** `startWorld.sh` already passes `-public 0`
for every modded world, so the validator has never run on one. `serverblankpassword` has been
removing a requirement that was not being applied.

Consequences, all of which make the plan safer:

- Removing the mod is safe **even for a world that is never updated**. There is no window in
  which a world has lost the mod and still needs it.
- The generated password is therefore **not load-bearing for an unlisted world**. It is required
  only to make listing possible, which is the feature being added.
- `startWorld.sh`'s vanilla "no password, not listed" branch is fine after all — unexercised, but
  correct. No world on this install has ever been in that state (all three vanilla worlds have
  passwords), so it was worth checking.

Two things to carry forward rather than forget:

- **`-public` defaults to 1 when the argument is absent.** `startWorld.sh` always passes it
  explicitly on both branches, so this is latent, not live. It deserves a verify marker, because
  dropping that argument would both list the world and start enforcing passwords.
- `m_minimumPasswordLength` is a serialized Unity field, so its real value comes from the prefab
  and is **not knowable from the assembly**. PhValheim's hardcoded 5 matches observed behaviour;
  do not treat the agreement as proof.

---

## 4. Decided: backfill and announce

**Brian's call, 2026-10-02:** backfill. Updating a world after upgrading to 2.53 removes
`serverblankpassword` — the same way QuickConnect goes — and generates a random password. World
Settings then lets the operator change it, exactly as it does for unmodded worlds.

Why the timing is right: generating the password **in the world-update path**, not in the
migration, lands both halves together. The mod row goes, the plugin files go on the next rebuild,
and the password appears, as one operator-visible action. A world that is never updated keeps the
mod and no password — byte-for-byte today's behaviour, and the same promise 2.53's release notes
already make ("nothing changes until you update a world").

Who notices:

- Players joining through **Launch** notice nothing. The password rides field 2 of the payload and
  the Companion pre-fills it.
- Players joining by **IP:PORT by hand**, and **console players on a crossplay world**, need a
  password they did not need yesterday. This is what "announce it" has to cover: the generated
  password must be visible in World Settings and in the world card, and the update dialog should
  say a password was generated.

Two details to decide deliberately rather than inherit:

- `worlds.password_public` defaults to `1`, which prints the password on the **public** world
  card. For a generated password on a modded world that is probably right (it is how a hand-joining
  player finds it) — but it is a deliberate choice, not a default to drift into.
- Generate from an unambiguous alphabet. The password has to survive §2's `?` rule, the §5 seed
  rule below, and being read aloud.

---

## 4b. NEW: Valheim also rejects a password contained in the SEED name

`FejdStartup.IsPublicPasswordValid(password, world)` enforces **three** rules:

```
password.Length >= m_minimumPasswordLength      else $menu_passwordshort
!world.m_name.Contains(password)                else $menu_passwordinvalid
!world.m_seedName.Contains(password)            else $menu_passwordinvalid
```

`validateWorldPassword()` implements the first two and **not the third**. It checks
`stripos($world, $password)` — the world name — and never looks at the seed.

So an operator can set a password that PhValheim accepts and that makes Valheim call
`Application.Quit()` the moment the world is listed. Because that is a clean exit, supervisor
restart-loops it, with the reason buried in the world log — precisely the failure
`validateWorldPassword()` was written to prevent, through the one hole left in it.

Live today for vanilla listed worlds; it becomes live for modded worlds the moment they can be
listed. **Add the seed check to `validateWorldPassword()`** — it needs the world's seed, which the
function does not currently receive.

---

## 5. Listing a modded world

> Allow the operator to select to publish the world into the in-game browser

Works — `-public 1` is independent of BepInEx. Two things to decide rather than just allow:

- ~~**`listed` + `crossplay` are mutually exclusive in practice.**~~ **WRONG — retracted
  2026-10-03, after it shipped as a hard block in `:rc` and Brian caught it.** The claim was that
  `-public 1` lists on the *Steam* browser and a PlayFab world has no address to advertise, so the
  combination "almost certainly lists nothing". That hedge was the whole evidence: no crossplay
  world was ever put in front of the in-game browser to check. Brian had been running crossplay
  worlds listed successfully **before** 2.53, so the block removed a working feature.

  Worse, the counterexample was already in his database — world 56 `BayArea`, `vanilla=1
  crossplay=1 listed=1`, saved under the old rules. I found it, recorded it as a "pre-existing
  combination the new rule blocks", and filed it as an anomaly to tidy up instead of reading it
  as the rule being wrong. **A row that my new invariant says is impossible, on a system that
  works, is evidence against the invariant — not a leftover.**

  `listed` depends on the password and nothing else. Do not re-add a crossplay term; the `cpf`
  and `cpfb` markers pin it out of both the PHP and the JS.
- **`listed` + CITIZENS composes technically but reads badly.** `permittedlist.txt` is enforced
  server-side, so the two do compose exactly as §8.6 says. The *result* is a world that advertises
  itself to strangers and then refuses all of them. Legal, and worth a word in the UI.

---

## 6. The gate that will go stale

`startWorld.sh:142-157` writes the runtime-options file:

```sh
effectiveCrossplay=0
effectiveListed=0
effectivePasswordHash=""
[ "$isCrossplay" = "1" ] && effectiveCrossplay=1      # moved OUT of the vanilla branch in 2.53
if [ "$isVanilla" = "1" ]; then
        effectiveListed=$isListed
        effectivePasswordHash=$(printf '%s' "$worldPasswordDb" | sha256sum | …)
fi
```

`listed` and `passwordhash` are still computed **inside** the vanilla branch. The restart-pending
check reads `passwordhash` to notice a password changed. Leave the gate there and changing a
modded world's password will silently fail to raise "restart required" — the two sides answering
different questions, with no operator action able to clear it. Crossplay was already moved out for
the same reason; these two have to follow.

---

## Order of work

Password validation first, because two of its three rules are live bugs today and everything
downstream generates or forwards passwords.

1. **`validateWorldPassword()`**: reject `?` (§2) and add the **seed-name** rule (§4b). The
   function needs the world's seed passed in. Fixes two live vanilla bugs before anything new
   depends on them.
2. **`getLaunchString()`**: send the real password instead of `hammertime`. No payload change —
   field 2 already exists, so every client/server pair stays compatible.
3. **`startWorld.sh`**: un-gate `-public` / `-password`, and move `effectiveListed` and
   `effectivePasswordHash` out of the vanilla branch (§6) so a modded password change raises
   "restart required".
4. **World update path**: drop the `serverblankpassword` `world_mods` row and generate a random
   password, together, per §4. Model the row removal on the one-time QuickConnect block in
   `dbUpdate_2.53.sh`; `mergeRequiredTsMods()` only `INSERT IGNORE`s, so the conf change alone
   does nothing to existing worlds.
5. **`requiredMods`**: drop `thunderstore|1010101110|serverblankpassword` from
   `phvalheim-static.conf` so new worlds never get it.
6. **UI**: password (set or generate) and `listed` in World Settings for modded worlds, same as
   unmodded. Block listed-without-password **at the form**, not with a warning. (This step also
   said to block listed-with-crossplay; that was retracted — see §above.) Surface the generated
   password where a hand-joining player can find it.
7. **Prove `SetServerPassword` against a world that actually has a password.** It has never run
   (§1). Until then the pre-fill is unverified, and a silent failure looks exactly like success.
8. Verify markers: the `-public 0` argument on the modded branch (it defaults to **1** when
   absent), the seed rule, the `?` rule, and the row removal.
9. `test-create-access-guards.sh` must pass untouched throughout.
