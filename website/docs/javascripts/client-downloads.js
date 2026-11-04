(() => {
  const repository = 'https://github.com/brianmiller/phvalheim-client';
  const suffixes = {
    windows: 'x86_64.msi', macos: 'macos-universal.pkg',
    deb: 'x86_64.deb', rpm: 'x86_64.rpm',
    flatpak: 'x86_64.flatpak', linux: 'universal-x86_64.tar.gz'
  };
  let latestRelease;

  async function refreshDownloads() {
    if (!document.querySelector('.phv-downloads')) return;
    try {
      // Keep the shipped, working links if GitHub is unavailable or rate limited.
      latestRelease ??= fetch('https://api.github.com/repos/brianmiller/phvalheim-client/releases/latest', {
        signal: AbortSignal.timeout(5000)
      }).then(response => {
        if (!response.ok) throw new Error('Release lookup unavailable');
        return response.json();
      });
      const release = await latestRelease;
      const version = release.tag_name;
      if (release.draft || release.prerelease || !/^\d+\.\d+\.\d+$/.test(version)) return;
      // Update the links, displayed version and install command together, and only
      // for a release that actually contains every package shown on this page.
      const urls = {};
      for (const [platform, suffix] of Object.entries(suffixes)) {
        const name = `phvalheim-client-${version}-${suffix}`;
        const url = `${repository}/releases/download/${version}/${name}`;
        if (!release.assets?.some(asset => asset.name === name && asset.browser_download_url === url)) return;
        urls[platform] = url;
      }
      document.querySelectorAll('[data-client-package]').forEach(link => {
        link.href = urls[link.dataset.clientPackage];
      });
      document.querySelectorAll('[data-client-version]').forEach(label => { label.textContent = version; });
      const command = document.getElementById('flatpak-command');
      if (command) command.textContent = `flatpak install --user ./phvalheim-client-${version}-x86_64.flatpak`;
    } catch {
      // The fallback release is explicitly named beside the buttons.
    }
  }

  document.addEventListener('click', async event => {
    const trigger = event.target.closest('[data-install-dialog]');
    if (trigger && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
      const dialog = document.getElementById(trigger.dataset.installDialog);
      if (dialog && typeof dialog.showModal === 'function') {
        event.preventDefault();
        dialog.querySelector('.phv-copy-status').textContent = '';
        dialog.showModal();
      }
    }
    // Native dialogs supply focus trapping, Escape dismissal and focus return.
    // A click on the backdrop also closes the dialog, without closing on padding.
    if (event.target.matches('dialog.phv-install')) {
      const box = event.target.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) event.target.close();
    }
    const copy = event.target.closest('[data-copy-command]');
    if (copy) {
      const code = document.getElementById(copy.dataset.copyCommand);
      const status = copy.closest('dialog').querySelector('.phv-copy-status');
      try {
        await navigator.clipboard.writeText(code.textContent);
        status.textContent = 'Command copied. Paste it into Terminal.';
      } catch {
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(code);
        selection.removeAllRanges();
        selection.addRange(range);
        status.textContent = 'Select and copy the highlighted command.';
      }
    }
  });

  // Also work if the theme's optional instant navigation is enabled later.
  if (typeof document$ !== 'undefined') document$.subscribe(refreshDownloads);
  else refreshDownloads();
})();
