# Discord message — ask the 2.53 reporter to test 2.54 on :rc (Unraid)

Do NOT post until `:rc` in the registry has moved off `sha256:44bda5a1fd9f` (that digest is 2.53).

---

**Found it — and it was our bug, not anything you did.** 🙏

When you updated your world, 2.53 gave it a password (that part is intended). But the admin
dashboard refreshes its world table every 5 seconds, and the refreshed table was rebuilding the
**Launch** link with a leftover placeholder password instead of your world's real one. So Launch
worked if you clicked it the instant the page loaded, and failed with *wrong password* if you
waited — which is why it looked random. You never saw a password prompt because the PhValheim
mod had already answered it, with the wrong value.

Your workaround was the right instinct, and the **public world page was never affected** — that
Launch link was always correct.

Fixed in **2.54**. No world update needed, no new client, nothing to reinstall.

**To try it on Unraid:**

1. **Docker** tab → toggle **Advanced View** (top right)
2. Click the **PhValheim** container → **Edit**
3. In **Repository**, change the tag on the end to `:rc` — so it reads
   `theoriginalbrian/phvalheim-server:rc`
4. **Apply**. Unraid pulls the new image and recreates the container; your `/opt/stateful`
   appdata volume is untouched.
5. If you're already on `:rc`, skip the edit and just hit **Force Update** on the container
   instead — same result.

You should see a **What's New** popup on the admin page explaining the fix.

**Then please test this, because it's the part that proves it:**

- **Put a password back on the world you cleared** — Settings → Options, any value you like, then
  restart the world. With no password set there's nothing for the bug to get wrong, so a
  cleared world will connect either way and won't tell us anything.
- Open the dashboard and **wait 10+ seconds** before clicking Launch. That's the case that
  failed on 2.53.
- You're still on the CITIZENS access list, so that keeps working on top of the password as
  normal.

Let me know either way and I'll get it onto `:latest`. 🍻

**To go back to the stable tag later:** same steps, change `:rc` back to `:latest`.
