# Public documentation

The user-facing site lives in `website/docs/`. Internal plans, release notes and test reports remain in the root `docs/` folder and are not part of the published site.

`overrides/partials/source.html` preserves the theme's GitHub metrics and adds a linked Docker Hub total-pulls badge. Shields.io refreshes the repository-wide count independently of site deployments (one-hour cache requested); the badge abbreviates large counts, for example `53k`. The same link is available in the mobile navigation drawer.

Every contribution must [review dynamic pages and status content](../CONTRIBUTING.md#always-review-dynamic-pages-and-status-content)
for needed changes, including the landing page, portal guidance, download links and
fallbacks, and GitHub Issues for work status. Record reviewed paths and updates or specific reasons for
no changes in the PR. A successful build or dynamic API refresh does not establish
that descriptions and status are current.

## Preview

```bash
python3 -m venv .venv-docs
. .venv-docs/bin/activate
pip install -r website/requirements.txt
zensical serve
```

Open the local address Zensical prints. Navigation is in `mkdocs.yml`; shared styling is in `website/docs/stylesheets/extra.css`. Keep guides task-oriented and link to the next useful action.

## Build and deploy

```bash
zensical build --clean --strict
python website/check-links.py
```

The Documentation workflow validates pull requests and deploys successful master builds to GitHub Pages using its official artifact/deployment actions. Pages uses the GitHub Actions publishing source. Dependencies are pinned in `website/requirements.txt`. Zensical reads the compatible `mkdocs.yml` configuration.

For a browser smoke check, serve `.site` under `/phvalheim-server/` and run `node website/check-browser.cjs` with Playwright available. Override `DOCS_URL` to check a deployed site. The check exercises search, setup-code copying, theme switching, video and mobile navigation.

The join page links directly to client release assets. `javascripts/client-downloads.js`
refreshes the links and Flatpak command together from GitHub's latest stable release;
the versioned links in `docs/play/join.md` remain usable when JavaScript or the API is
unavailable. When releasing a client, refresh those fallback links and the displayed
version. macOS and Flatpak open native install dialogs; modified clicks keep the
normal download link. Run `node website/check-join.cjs` for the focused browser checks.

Platform icons in `docs/assets/platforms/` come from the theme's bundled
[Font Awesome Free brands](https://fontawesome.com/license/free) (CC BY 4.0) and
[Simple Icons](https://github.com/simple-icons/simple-icons/blob/develop/LICENSE.md)
(Flatpak, CC0 1.0).

- [Zensical](https://zensical.org/docs/)
- [GitHub Pages custom workflows](https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages)

## Media

The product tour covers the complete host-to-player workflow using real development UI captures, the maintainer's Vibes screenshots and animated workflow diagrams. It is a condensed overview, not a real-time sync or gameplay recording. Captions and a linked transcript are provided. The Viking-inspired soundtrack is **Viking Intro (Loop)** by Alexander Nakarada, used with attribution under CC BY 4.0; see `media/audio/ATTRIBUTION.md` and preserve the credit when sharing.

See `media/` for the tour source and reproduction instructions. The MP4 is included so the site does not need to build video during documentation deployment.
