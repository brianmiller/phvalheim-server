**Nexus Mods as a 3rd catalogue — research done. Short version: metadata yes, installs no.**

**Why installs are blocked (policy, not code)**
Thunderstore and Hexium both give us a permanent public download URL. Nexus gives us nothing we can use:
- Their CDN returns `403 Missing parameters` without a signed link
- The API only mints download links for **premium** accounts; free users have to click through nexusmods.com for an `nxm://` link that expires
- Their Acceptable Use Policy specifically forbids storing API keys server-side and using them without the user clicking something — which is exactly what our hourly mod sync does
- And the client payload zips the server's whole BepInEx tree, so we'd be re-distributing Nexus files to every player who joins

**Metadata, though, is easy**
Their documented API has no mod search at all. The only way to list a game's catalogue is an **undocumented GraphQL endpoint** that needs no API key. Pulled all **2,983 Valheim mods in 50 requests** — about the same cost as our existing sync.

**Three things that would have bitten us**
- The obvious query hits a complexity cap and returns **HTTP 200 with zero rows** — indistinguishable from a clean "nothing changed" tick
- **58% of Nexus archives are a bare .dll at the root** with no `plugins/` folder. Jotunn would install "successfully" and never load
- **18% of versions aren't comparable** — real values include `"Deprecated"`, `"004"`, `"Beta-1.2.1"` — which would quietly break pinning and update detection

**One nice surprise**
985 of Nexus's dependency links point *back at Thunderstore*, and our resolver is already cross-source. A small URL parser would absorb most of their dependency graph.

**Recommendation**
Metadata-only: Nexus mods browsable in the picker, linking out to the mod page, not installable. No API key, no policy problem, none of the archive mess. Worth emailing Nexus support before we consider real installs.
