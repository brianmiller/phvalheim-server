# Public documentation

The user-facing site lives in `website/docs/`. Internal plans, release notes and test reports remain in the root `docs/` folder and are not part of the published site.

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

- [Zensical](https://zensical.org/docs/)
- [GitHub Pages custom workflows](https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages)

## Media

The product tour covers the complete host-to-player workflow using real development UI captures, the maintainer's Vibes screenshots and animated workflow diagrams. It is a condensed overview, not a real-time sync or gameplay recording. Captions and a linked transcript are provided. The Viking-inspired soundtrack is **Viking Intro (Loop)** by Alexander Nakarada, used with attribution under CC BY 4.0; see `media/audio/ATTRIBUTION.md` and preserve the credit when sharing.

See `media/` for the tour source and reproduction instructions. The MP4 is included so the site does not need to build video during documentation deployment.
