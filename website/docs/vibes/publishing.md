# Package and share your mod

There are three different things you can publish: **source code**, a **local mod package**, and a **hosted catalogue package**.

| Goal | Use |
| --- | --- |
| Let your own PhValheim worlds use the mod | **Publish to Catalogue** |
| Share the source or collaborate on it | [GitHub or GitLab](source-control.md) |
| Distribute it through Thunderstore or Hexium | Payload ZIP download or hosted publishing |

## Make the package

Build the project successfully, open **Package**, and click **Package**. The job status explains what is happening and shows success or failure. Download links appear when the payloads are available.

The ZIP includes the plugin, manifest, README, icon and optional changelog. It excludes the Valheim and framework reference DLLs used to compile the project. Review the metadata, version, description and instructions before sharing it.

## Publish locally

Click **Publish to Catalogue** to add the packaged version to your own Hugin's Vibes catalogue. Select it in a world's Mods list, update the world and sync clients.

The button switches to **Unpublish from Catalogue** for a published version. Unpublishing removes local catalogue availability; it does not remotely uninstall files from worlds or clients that already received the mod. Review any worlds using that version before removing it.

## Publish to Thunderstore or Hexium

You can download the compatible ZIP and upload it through the service yourself. Or configure the service under **Vibes Settings → Publishing**, including its token, team and categories, and use the hosted publish action.

Publication depends on your account's permissions and the catalogue's validation. Read the job result and confirm the published version on the destination service. Keep credentials in Settings, never in project source or README files.

## Test before sharing widely

A successful compiler run checks the code can be built. Test the actual behavior on a modded test world, including the client side when required. Include setup and configuration instructions so other players know what to expect.
