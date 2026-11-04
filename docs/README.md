# What is in `docs/`

Internal working documents. None of this is the public documentation site, which lives in
`website/docs/` and is published to GitHub Pages. Nothing here is required reading for a
contributor; `CONTRIBUTING.md` at the repo root is.

| Kind | Files | What they are for |
| --- | --- | --- |
| **Procedure** | `RELEASING.md` | How a release is built, verified, tagged and published. Maintainer's procedure, and the list of traps that have bitten real releases. |
| **Release notes** | `release-notes-*.md`, `RELEASE-NOTES-*.md` | The text attached to each GitHub release. One per version. |
| **Discord posts** | `discord-*.md` | The announcement posted to the community Discord for each release. |
| **Design** | `RELEASE-*-DESIGN.md`, `RELEASE-*-PLAN.md`, `DESIGN-*.md`, `HUGINS-VIBES-DESIGN.md`, `CONFIG-POLICY-2.60.md`, `CONTAINER-GAME-PORTS.md`, `TYPOGRAPHY.md` | Why a feature is shaped the way it is. Read the one for the area you are changing. |
| **Validation** | `WORKBENCH-*-VALIDATION.md`, `TEST-PLAN-*.md`, `TEST-RUN-*.md` | What was actually exercised before a release shipped, and what was not. |
| **Next steps** | `NEXT-STEPS-*.md` | Known gaps left open when a release shipped. |
| **Security** | `SECURITY-*.md` | Security reviews and remediation plans. |
| **Reviews** | `REVIEW-*.md` | Code reviews of a specific area. |
| **TrueNAS** | `truenas/` | The TrueNAS catalogue submission packet. |
| **Backlog** | `BACKLOG.md` | Ideas not yet scheduled. |

File names are kept stable because the changelog and published GitHub releases link to them.
Do not rename or move files in here.
