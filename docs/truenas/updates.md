# PhValheim TrueNAS update plan

The TrueNAS app follows the stable `theoriginalbrian/phvalheim-server:latest` image without a digest pin. Its Compose template uses `pull_policy: always`, so new container deployments fetch the current stable image. Brian selected this policy on 26 October 2026.

## Stable release checklist

1. Follow the existing PhValheim release gates. Promote the verified stable image to `latest` only when Brian authorizes the stable release. Confirm the versioned release tag and `latest` resolve to the same digest.
2. In the TrueNAS catalog app, keep `images.image.tag: latest` and `app_version: latest`. Increment the catalog `version` by one patch, for example `1.0.1` to `1.0.2`. The catalog revision is separate from PhValheim's running release version.
3. Add a short app README update entry recording the new stable PhValheim release, validation digest and migration or backup notes. The digest is release evidence, not the shipped image reference.
4. Run catalog rendering and a fresh install/health check against `latest`, plus an upgrade/persistence check using isolated existing data when the release changes application data or startup behavior. Regenerate/check metadata and run full catalog validation and port checks.
5. Submit the revision bump to `truenas/apps`, referencing the upstream stable release and actual checks. After maintainers merge and publish it, users receive the catalog's standard **Update** action and can choose their deployment time.

The initial app is under [TrueNAS PR 6264](https://github.com/truenas/apps/pull/6264). While that PR is still open, update its existing branch; after it merges, create a new branch from current upstream `master` for each catalog update. The catalog repository has an explicit Renovate exception for PhValheim so its bot does not add a digest or switch the stable channel to a versioned tag. This plan requires a catalog revision submission per stable release; no release-publishing automation is claimed.

## Operator updates

TrueNAS supports [monitoring Docker image updates](https://apps.truenas.com/getting-started/initial-setup/#monitoring-for-image-updates), including images used by catalog apps. Leave that setting enabled. The ordinary catalog Update flow still depends on catalog version availability; image monitoring alone should not be described as an automatic catalog release.

When the catalog revision is available, schedule an update with players and take a consistent backup of the entire data dataset and separate backup storage. Apply the update from TrueNAS. Check PhValheim's admin interface for the actual running release, then verify world status and required player syncs.

For an immediate refresh before a catalog revision lands, TrueNAS administrators can call the documented [app.pull_images API](https://api.truenas.com/v25.10/api_methods_app.pull_images.html) for their installed app name with `redeploy: true`. It pulls and redeploys the current image while preserving configured mounts. This runs through TrueNAS's own administration tools; the PhValheim container does not access the host management API. A bare container process restart does not constitute this pull-and-redeploy operation.

## Recovery

Keep versioned upstream images and full-data backups. A rollback of the catalog revision still uses `latest`, so it cannot by itself restore an earlier PhValheim image. To recover an older release, deliberately select its versioned image in a recovery deployment and restore compatible application data. TrueNAS catalog snapshot rollback covers managed app storage; mounted host paths require their own backup/restore procedure.

## Verification scope

The initial `latest` reference was independently verified to resolve to the already tested 2.59 digest `sha256:1b5ded166fb691d4f6888b474e6062c21e598a803ab0b9b3cc7a0bddc1d14405`. This is a point-in-time validation record, not a pin. Future release migrations, TrueNAS hardware updates and live player reconnection require their own release checks.
