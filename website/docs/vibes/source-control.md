# GitHub, GitLab and imports

Every Vibe starts with a **local Git repository**. GitHub and GitLab are optional places to share or back up its source. They are separate from the local mod catalogue used by your worlds.

## Import a repository

1. Open **Hugin's Vibes → + Import**.
2. Enter the GitHub or GitLab repository URL.
3. For a private repository, configure the appropriate service connection in **Vibes Settings** first.
4. Start the import and follow its job feedback.
5. Open the imported Vibe and inspect its files and **History** before building.

Importing source does not guarantee that an arbitrary repository is a buildable Valheim mod. Check its project structure, dependencies and build instructions. An imported mod may need changes before it works with the local Build Pak.

## Connect your accounts

Use the GitHub and GitLab tabs in **Vibes Settings**. Set the service's host/API information, token and owner or group as appropriate, then use the connection test.

The account needs access to the repository and permission for the operation you want. Creating a repository requires more permission than reading an existing one. An organization's or group's policies can add restrictions even when the connection test succeeds.

## Read the History panel

| Status | Meaning | Next step |
| --- | --- | --- |
| In sync | Local and last-fetched remote history agree | Continue working |
| Ahead | Local commits have not reached the remote | Review, then Push |
| Behind | The last fetch found newer remote commits | Pull when the working tree is clean |
| Ahead and behind | Both sides have changes | Resolve the divergence before syncing |
| Changed files | Work has not been committed | Finish or preserve it before pulling |
| Detached HEAD | No current branch is checked out | Check out a branch before pushing |

**Fetch** refreshes your view of the remote without replacing local files. Ahead/behind counts describe the most recent fetch, so fetch when you want an up-to-date comparison.

**Pull** is fast-forward only. It refuses to overwrite a dirty checkout or silently resolve diverged history.

**Push** sends the current branch. Imported branches such as `master` keep their name; the first push does not assume every project uses `main`.

## When syncing fails

Read the job's error. Check the remote URL, account access, token permissions and branch status. For a conflict, preserve your work and resolve the Git history before retrying. Repeated clicks do not fix a permission or divergence problem.

[Build and publish your imported mod →](publishing.md)
