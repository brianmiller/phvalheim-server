# PhValheim TrueNAS update plan

The TrueNAS catalog uses stable version tags pinned to image digests, starting with `theoriginalbrian/phvalheim-server:2.59@sha256:1b5ded166fb691d4f6888b474e6062c21e598a803ab0b9b3cc7a0bddc1d14405`. Brian selected version tags for the catalog on 26 October 2026. General Docker installations can continue using `:latest`.

## Stable release checklist

1. Follow the existing PhValheim build and release gates. Publish a numeric release tag such as `2.60` only for a release intended for stable users. Confirm that tag and the authorized `latest` promotion resolve to the verified release image. Pre-release testing uses `dev`, `rc` or an explicitly suffixed pre-release tag; do not publish a plain numeric tag for a release candidate.
2. TrueNAS's standard Renovate Docker versioning recognizes two-component tags such as `2.59` and `2.60`; no PhValheim-specific version rule is needed. It keeps the current tag's numeric precision and compatibility suffix, so rolling tags and suffixed release candidates do not replace a plain numeric stable tag. The catalog's normal digest-pinning and post-upgrade tasks remain enabled. Renovate can propose the new version/digest and bump the catalog revision automatically; a rebuilt version tag can also produce a digest update PR.
3. Check the TrueNAS catalog for the resulting update PR. Include release notes and migration or backup requirements when review needs them. Run or inspect the catalog render/install checks and perform an upgrade/persistence test when startup or application data changes.
4. After TrueNAS maintainers review, merge and publish the catalog change, installed apps receive the normal **Update** action. A bot-created PR is not an automatic production deployment.
5. If Renovate has not picked up a new upstream tag after the contribution guide's two-day window, open an issue so maintainers can investigate discovery. For an urgent fix, coordinate a manual catalog PR using the same version-plus-digest pin, catalog revision bump and validation. Avoid duplicating an existing bot PR.

The initial app is under [TrueNAS PR 6264](https://github.com/truenas/apps/pull/6264). While it is open, update that branch; after it merges, start maintenance branches from current upstream `master`. Keep `app_version` equal to the upstream numeric release and increment the catalog `version` for image or wrapper changes. These are separate version numbers.

## Operator updates

Use TrueNAS's normal [application update workflow](https://apps.truenas.com/managing-apps/managing-installed-apps/#updating-apps). Schedule downtime with players, take a consistent full-data backup and apply the available catalog update. Check PhValheim's admin interface for the running release and verify world status and required player syncs.

Pulling the currently installed pinned reference fetches that exact image; it does not move the app to a newer release. To advance to a newer PhValheim release, install the catalog revision that selects it. Publishing `latest` continues to serve ordinary Docker users independently of the TrueNAS catalog's review and publication cycle.

## Recovery

Retain versioned images, their pinned manifests and compatible full-data backups. Each catalog revision selects its own image digest, including during rollback. Database or world changes can still require restoring compatible data. TrueNAS snapshot rollback covers managed app storage; mounted host paths need their own backup and restore procedure.

## Validation and maintenance scope

The initial `2.59` tag and its digest were independently verified against Docker Hub. The standard Docker versioning implementation was checked with stable, rolling and suffixed release-candidate examples, and the catalog's regular update/pinning tasks remain enabled. Actual future bot discovery depends on TrueNAS's service and maintainer review; no future PR or deployment is claimed as already tested.

References: [TrueNAS contribution guide](https://github.com/truenas/apps/blob/master/CONTRIBUTIONS.md), [catalog Renovate configuration](https://github.com/truenas/apps/blob/master/.github/renovate-config.js), [Renovate Docker versioning](https://docs.renovatebot.com/modules/versioning/docker/).
