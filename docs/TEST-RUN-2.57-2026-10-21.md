# 2.57 full test run, 2026-10-21

Image under test: `theoriginalbrian/phvalheim-server:dev` = `sha256:5d7153b7` (2.57: PHP2Shell
fix, public multipart 403, join-code session mark, version bump). Control: `:2.56` =
`sha256:f22b36d2`, same volume, same host, same invocation.

## Verdict

No regression attributable to 2.57. Every failure in the container bucket either fails
identically on 2.56 in the same environment, or passes when the test is invoked the way its own
header says, or was a transient on a container that had just been upgraded.

## Buckets

| Bucket | Result |
| --- | --- |
| Standalone (62, what CI runs) | 62 passed |
| Cross-repo (4) | 4 passed |
| Container (69) on 2.57 via `run-oracles.sh --container` | 30 passed, 39 failed |
| Container (69) on 2.56 control, same way | 32 passed, 37 failed |
| Failures only on 2.57 | 2, both pass on rerun (see below) |
| Failures only on 2.56 | 0 |

Where it ran: the dev box's `phvalheim-dev` was renamed to `phvalheim-dev-old` and stopped, its data
volume `phvalheim-dev-data` (11.2 GB, six fixture worlds, 2.5x-era schema) was snapshotted to
`phvalheim-dev-data-bak-20261021`, and a new `phvalheim-dev` was started from `:dev` on the
original volume. That is a real upgrade path: migrations applied cleanly, all six worlds listed,
settings intact. the maintainer's production instance was only read (it runs the earlier
`sha256:8e708871` build: PHP patched, still labelled 2.56, no join-code fix).

## The two 2.57-only failures

`test-access-id-prefix.sh` and `test-crossplay-any-world.sh` reported empty values read back
through the in-container `sql` tool while the API saves and the written files were correct. Both
ran within minutes of the volume upgrade while the engine was reconciling. Rerun against the same
2.57 container once it had settled: 17/17 and 23/23. Transient, not a code difference.

## The 37 common failures, by cause

Pass when invoked as their header says (the runner cannot do this):
- `test-config-manual-match-live.php` 31/31, `test-event-log-live.php` 20/20,
  `test-config-migration-delete.php` 8/8: `docker cp` + `docker exec php` in the container.
- `test-mod-config-editor.sh` 51/51: inside the image with the repo mounted (needs the
  `phvalheim` user).
- `test-accesslists.sh` 33/33 with the container name as `$1` (its default is `phvtest`).
- `test-backend-session-scoped.sh` 9/9 after fixing a stale one-argument `getWorldJoinCode()`
  call (signature gained `$pdo` in 2.56; broken since then). Fix is in the working tree.
- `test-access-tab-refactor.js`, `test-modsync-panel.js`: pass once the one-shot Hugin, Connect
  and What's New notices are dismissed; the modals covered the dashboard in both runs.

Need arguments the runner does not pass: `test-config-accordion.js`,
`test-config-migration-gate.js`, `test-config-migration-orphan.js`,
`test-configs-unmatched-back.js`, `test-log-viewer-escaping.js`, `test-save-summary.php`.

Need a precondition the environment lacked: `test-access-id-notice.js` and
`test-access-switch-notice-ui.js` (notice must be re-armed), `test-password-row-styling.js`
(port 18080), `test-public-card-render.js` (Steam auth bypass), `test-restart-pending.sh`,
`test-world-stats-clear.js`, `test-vanilla-seed.sh`, `test-crossplay-join-modal.js` (a running
world; also needs `dev_tools` as cwd), the five Hugin tests `test-ai-actions.sh`,
`test-ai-actions-mutations.sh`, `test-ai-e2e.sh`, `test-ai-telemetry.sh`, `test-proposal-ui.sh`
(an LLM endpoint), `test-mod-destinations-live.sh` (a pre-2.53 git state to diff against).

Stale tests, dated: `test-plugin-unzip-parent-dir.sh` greps a variable name replaced on
2026-10-02 (`treeRoot`); `test-create-access-guards.sh` counts the placeholder ID in a comment
in `accesslists.php` left by the 2026-09-10 commit that dropped it; `test-admin-crossplay-launch.sh`
asserts the abandoned `-joincode` argument (known); `test-mods-hub.js` three wording assertions
(known since 2.56).

Pre-existing, unexplained, identical on 2.56, worth a look in their own right:
- `test-multisource-picker.js`: no `select.mod-version` rendered for a checked multi-version mod.
- `test-empty-accesslist-modal.js`: `showSettingsModal` did not consult the predicate.
- `test-settings-modal-layout.js`, `test-steamid-lookup-format.js`: settings tab button present
  but never visible.
- `test-modsync-log-pane.js`: log pane did not grow during a sync.
- `test-analytics-payload-size.sh`: no payload file written.
- `test-duplicate-plugin.sh`: install plan has 0 BepInExPack rows.
- `test-access-list-toggle.sh`, `test-engine-survives-bad-world.sh`, `test-ai-e2e.sh` (4 `ai_*`
  tables, expects 2).

## Host state left behind on the dev box

- `phvalheim-dev`: running `:dev` 2.57 on the original `phvalheim-dev-data` volume; the notices
  and What's New are dismissed.
- `phvalheim-dev-old`: stopped, old image, points at the SAME volume. Do not start it again; the
  schema has moved on. Safe to `docker rm`.
- `phvalheim-dev-data-bak-20261021`: 11.2 GB snapshot taken before the upgrade. Delete when happy.
- Logs: `/tmp/phv-oracles-container2.log` (2.57), `/tmp/phv-oracles-control.log` (2.56),
  `/tmp/phv-oracles-incontainer.log`, per-test logs in `.oracle-logs/`.
