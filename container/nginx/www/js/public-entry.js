/* The server remains the source of published package URLs and historical version gates. */
(() => {
    'use strict';
    const trigger = document.querySelector('.client_download_button_font');
    if (!trigger) return;
    const make = (tag, cls, text) => { const e = document.createElement(tag); e.className = cls; if (text) e.textContent = text; return e; };
    const mac = trigger.dataset.bsTarget === '#macInstallModal';
    const source = new DOMParser().parseFromString(trigger.dataset.bsContent || '', 'text/html');
    const platform = mac ? 'macOS' : (trigger.dataset.bsTitle || '').includes('Windows') ? 'Windows' : 'Linux';
    // The custom panel owns focus/click behavior, so Bootstrap must not open a second UI.
    window.bootstrap?.Popover.getInstance(trigger)?.dispose();
    trigger.removeAttribute('data-bs-toggle'); trigger.removeAttribute('data-bs-trigger'); trigger.removeAttribute('data-bs-target');
    trigger.classList.add('client-download-trigger');
    trigger.setAttribute('aria-expanded', 'false'); trigger.setAttribute('aria-controls', 'clientDownloadPanel');
    const panel = make('section', 'client-download-panel'); panel.id = 'clientDownloadPanel'; panel.hidden = true;
    panel.setAttribute('aria-label', 'PhValheim client downloads');
    const head = make('div', 'client-download-heading');
    const title = make('div', ''); title.append(make('strong', '', 'PhValheim Client'), make('p', '', 'Downloads for ' + platform));
    const dismiss = make('button', 'client-download-close', '×'); dismiss.type = 'button'; dismiss.setAttribute('aria-label', 'Close downloads');
    head.append(title, dismiss); panel.append(head);
    const list = make('div', 'client-download-list'); panel.append(list);
    if (mac) {
        const guide = make('button', 'client-package', ''); guide.type = 'button';
        const label = make('span', ''); label.append(make('strong', '', 'Install for macOS'), make('small', '', 'Universal client · guided installation'));
        guide.append(label, make('span', 'client-package-arrow', '→'));
        guide.addEventListener('click', () => { close(); bootstrap.Modal.getOrCreateInstance(document.getElementById('macInstallModal')).show(); });
        list.append(guide);
    } else {
        let lastVersion = '';
        source.querySelectorAll('a.client_download_os_icon').forEach(original => {
            let url; try { url = new URL(original.getAttribute('href')); } catch (_) { return; }
            if (url.protocol !== 'https:') return;
            const file = url.pathname.split('/').pop();
            const version = file.match(/^phvalheim-client-(\d+\.\d+\.\d+)-/)?.[1];
            const kind = file.endsWith('.flatpak') ? ['Flatpak', 'Installation steps included'] : file.endsWith('.deb') ? ['DEB package', 'Debian / Ubuntu'] : file.endsWith('.rpm') ? ['RPM package', 'Fedora / RHEL'] : file.endsWith('.msi') ? ['Windows installer', 'MSI · 64-bit'] : file.endsWith('.tar.gz') ? ['Linux archive', 'tar.gz · manual installation'] : null;
            if (!kind || !version) return;
            if (version !== lastVersion) { list.append(make('p', 'client-release-title', 'Version ' + version)); lastVersion = version; }
            const link = make('a', 'client-package'); link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
            const icon = original.querySelector('img');
            if (icon) { const img = make('img', ''); img.src = icon.getAttribute('src'); img.alt = ''; link.append(img); }
            const label = make('span', ''); label.append(make('strong', '', kind[0]), make('small', '', kind[1]));
            link.append(label, make('span', 'client-package-arrow', '↗'));
            if (file.endsWith('.flatpak')) link.addEventListener('click', event => {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
                event.preventDefault(); close(); window.openFlatpakInstall(link);
            });
            list.append(link);
        });
        if (!list.childElementCount) list.append(make('p', 'client-download-empty', 'No published release is available right now. Browse all releases below.'));
    }
    const more = make('a', 'client-download-more', 'All platforms & releases ↗');
    more.href = source.querySelector('.client_download_tooltip_otherbuilds a, a.client_download_tooltip_otherbuilds')?.href || 'https://github.com/brianmiller/phvalheim-client/releases';
    more.target = '_blank'; more.rel = 'noopener noreferrer'; panel.append(more); document.body.append(panel);
    function position() {
        const box = trigger.getBoundingClientRect();
        panel.style.width = Math.min(360, innerWidth - 24) + 'px';
        panel.style.left = Math.max(12, Math.min(box.right - panel.offsetWidth, innerWidth - panel.offsetWidth - 12)) + 'px';
        const below = innerHeight - box.bottom - 16, above = box.top - 16;
        const down = below >= Math.min(panel.scrollHeight, 280) || below >= above;
        panel.style.maxHeight = Math.max(100, down ? below : above) + 'px';
        panel.style.top = (down ? box.bottom + 8 : Math.max(8, box.top - panel.offsetHeight - 8)) + 'px';
    }
    function close(restore = false) { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); if (restore) trigger.focus(); }
    trigger.addEventListener('click', () => { if (!panel.hidden) { close(); return; } panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); position(); });
    trigger.addEventListener('keydown', e => { if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) trigger.click(); panel.querySelector('a,button')?.focus(); } });
    dismiss.addEventListener('click', () => close(true));
    document.addEventListener('click', e => { if (!panel.contains(e.target) && !trigger.contains(e.target)) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) { e.preventDefault(); close(true); } });
    document.addEventListener('focusin', e => { if (!panel.hidden && !panel.contains(e.target) && e.target !== trigger) close(); });
    window.addEventListener('resize', () => { if (!panel.hidden) position(); });
    window.addEventListener('scroll', () => { if (!panel.hidden) position(); }, {passive:true});
    for (const id of ['flatpakInstallModal', 'macInstallModal']) document.getElementById(id)?.addEventListener('hidden.bs.modal', () => trigger.focus());
})();
