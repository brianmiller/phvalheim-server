/* Opt-in portal layouts. Move existing action nodes so their handlers and polling hooks survive. */
(() => {
    'use strict';
    const theme = document.body.dataset.publicTheme;
    if (!['reforged', 'gate', 'ember'].includes(theme)) return;
    const make = (tag, cls, text) => { const e = document.createElement(tag); e.className = cls; if (text) e.textContent = text; return e; };
    const brand = make('a', 'theme-brand'); brand.href = '/';
    brand.innerHTML = '<img src="/images/themes/hugin.svg" alt=""><span>Ph<span>Valheim</span></span>';
    const cards = [...document.querySelectorAll('.catbox[data-world]')];
    if (!cards.length && !document.querySelector('.google_header')) {
        document.querySelector('.login_header')?.replaceChildren(brand); return;
    }
    const legacy = document.querySelector('.google_header')?.closest('table');
    const portal = make('div', 'theme-portal'); portal.id = 'publicPortal';
    legacy.before(portal);
    const header = make('header', 'theme-header');
    const account = make('div', 'theme-account');
    const avatar = document.querySelector('.google_header img');
    const name = document.querySelector('.name_header');
    const player = make('span', 'theme-player', name?.childNodes[0]?.textContent.trim().replace(/^Welcome,\s*/, '').replace(/!$/, '') || 'Player');
    const identity = document.querySelector('.steamid-self');
    if (avatar) { avatar.alt = ''; avatar.addEventListener('error', () => { avatar.hidden = true; }); if (!avatar.getAttribute('src')) avatar.hidden = true; account.append(avatar); }
    account.append(player);
    if (identity) { identity.setAttribute('role', 'button'); identity.tabIndex = 0; identity.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); identity.click(); } }); account.append(identity); }
    const downloads = document.querySelector('.client_download_button');
    const signout = document.querySelector('.signout-icon');
    if (signout) signout.append(document.createTextNode('Sign out'));
    header.append(brand, account);
    if (downloads) header.append(downloads);
    if (signout) header.append(signout);
    portal.append(header);
    const main = make('main', 'theme-main'); portal.append(main);
    const toolbar = make('div', 'theme-toolbar');
    const heading = make('h1', '', 'Your worlds'); const total = make('span', 'theme-total', '0 online · 0 worlds');
    toolbar.append(heading, total);
    let search, reforgedNav, selectedWorld;
    const reforgedButtons = new Map();
    if (theme === 'reforged') {
        search = make('input', 'theme-search'); search.type = 'search'; search.placeholder = 'Search worlds…'; search.setAttribute('aria-label', 'Search worlds');
        search.addEventListener('input', filterWorlds); toolbar.append(search);
    }
    if (theme === 'reforged') main.append(toolbar);
    const wrapper = make('div', 'theme-worlds'); main.append(wrapper);
    const bosses = ['Eikthyr', 'The Elder', 'Bonemass', 'Moder', 'Yagluth', 'The Queen', 'Fader'];
    cards.forEach(card => {
        const title = make('h2', 'card_worldName', card.dataset.world);
        const status = make('span', 'theme-status');
        const cardHead = make('div', 'theme-card-heading'); cardHead.append(title, status);
        const launch = make('div', 'theme-launch'); const link = card.querySelector('.launch-link'); if (link) launch.append(link);
        const details = make('div', 'theme-details');
        const groups = [make('table', 'theme-facts'), make('table', 'theme-facts')]; groups.forEach(g => { g.append(document.createElement('tbody')); details.append(g); });
        const typeRow = document.createElement('tr'); typeRow.innerHTML = '<td class="card_worldInfo">Type</td><td class="card_worldInfo">' + (card.classList.contains('catbox-vanilla') ? 'Vanilla' : 'Modded') + '</td>'; groups[0].tBodies[0].append(typeRow);
        [...card.querySelectorAll('tr')].filter(r => r.querySelector('td.card_worldInfo')).forEach(row => {
            const cells = row.querySelectorAll('td'); if (cells.length < 2) return;
            const label = cells[0].textContent.replace(/\s*:\s*$/, '').trim(); cells[0].textContent = label;
            if (label === 'Type') typeRow.remove();
            const group = /^(Seed|Deployed|Updated|Players|Memory|MD5)/.test(label) ? 1 : 0;
            groups[group].tBodies[0].append(row);
        });
        const hint = card.querySelector('.vanilla-hint');
        if (hint) { const note = make('p', 'theme-join-hint'); note.append(...hint.childNodes); details.append(note); }
        const trophies = card.querySelector('.trophy-table');
        let progress;
        if (trophies) {
            trophies.querySelector('caption')?.remove();
            const caption = document.createElement('caption');
            progress = make('strong', 'theme-progress');
            caption.append(make('span', '', 'Bosses defeated'), progress, make('small', 'theme-legend', '✓ Defeated   ○ Not defeated'));
            trophies.prepend(caption);
            trophies.querySelectorAll('.trophy_icon').forEach((cell, i) => {
                const original = cell.querySelector('img');
                const sprite = make('span', 'theme-boss'); sprite.style.backgroundPosition = `${(i % 4) * 100 / 3}% ${i < 4 ? 0 : 100}%`; sprite.setAttribute('aria-hidden', 'true');
                const label = make('span', 'theme-boss-name', bosses[i] || '');
                const mark = make('span', 'theme-boss-mark'); mark.setAttribute('aria-hidden', 'true'); label.prepend(mark);
                cell.append(sprite, label);
                const update = () => {
                    const defeated = original?.title.includes('has been defeated');
                    cell.dataset.defeated = defeated ? 'true' : 'false';
                    mark.textContent = defeated ? '✓' : '○';
                    if (original) original.alt = original.title;
                    progress.textContent = [...trophies.querySelectorAll('[data-defeated="true"]')].length + ' / ' + bosses.length;
                };
                update(); if (original) new MutationObserver(update).observe(original, {attributes:true, attributeFilter:['title']});
            });
        }
        card.replaceChildren(cardHead, launch, details);
        if (trophies) card.append(trophies);
        if (theme === 'reforged') {
            // Keep real action nodes in the overview, including offline mod libraries.
            const overview = make('table', 'theme-facts theme-overview'); overview.append(document.createElement('tbody'));
            groups.flatMap(group => [...group.rows]).filter(row => /^(Type|Mods|Access|Password|Server)$/.test(row.cells[0].textContent) || row.querySelector('.world-joincode') || row.querySelector('.world-players')).forEach(row => overview.tBodies[0].append(row));
            const disclosure = make('details', 'theme-disclosure');
            const summary = make('summary', '', 'World details');
            summary.setAttribute('aria-label', 'World details for ' + card.dataset.world);
            disclosure.append(summary, details);
            const info = make('div', 'theme-reforged-info'); info.append(overview, disclosure);
            cardHead.append(launch);
            card.replaceChildren(cardHead, info);
            if (trophies) card.append(trophies);
        }
        const refresh = () => { const online = !card.classList.contains('card_dimmed'); status.textContent = online ? 'Online' : 'Offline'; status.dataset.online = String(online); total.textContent = cards.filter(c => !c.classList.contains('card_dimmed')).length + ' online · ' + cards.length + ' worlds'; };
        refresh(); new MutationObserver(refresh).observe(card, {attributes:true, attributeFilter:['class']});
        wrapper.append(card);
    });
    legacy.remove();
    if (theme === 'reforged') {
        reforgedNav = make('nav', 'theme-world-nav');
        reforgedNav.setAttribute('aria-label', 'Choose a world');
        wrapper.before(reforgedNav);
        cards.forEach(card => {
            const button = make('button', 'theme-world-choice'); button.type = 'button';
            button.append(make('strong', '', card.dataset.world));
            const state = make('small', ''); button.append(state);
            const refresh = () => {
                const online = !card.classList.contains('card_dimmed');
                state.textContent = (online ? 'Online' : 'Offline') + ' · ' + (card.classList.contains('catbox-vanilla') ? 'Vanilla' : 'Modded');
                state.dataset.online = String(online);
            };
            refresh(); new MutationObserver(refresh).observe(card, {attributes:true, attributeFilter:['class']});
            button.addEventListener('click', () => { selectedWorld = card; filterWorlds(); });
            reforgedButtons.set(card, button); reforgedNav.append(button);
        });
        selectedWorld = cards.find(card => !card.classList.contains('card_dimmed')) || cards[0];
        const empty = make('div', 'theme-empty'); empty.hidden = true;
        empty.append(make('p', '', cards.length ? 'No worlds match your search.' : 'No worlds are available yet.'));
        if (cards.length) {
            const clear = make('button', '', 'Clear search'); clear.type = 'button';
            clear.addEventListener('click', () => { search.value = ''; filterWorlds(); search.focus(); });
            empty.append(clear);
        }
        wrapper.after(empty);
        filterWorlds();
        return;
    }
    function filterWorlds() {
        const query = search.value.trim().toLowerCase();
        const visible = cards.filter(card => card.dataset.world.toLowerCase().includes(query));
        if (!visible.includes(selectedWorld)) selectedWorld = visible[0];
        cards.forEach(card => {
            const selected = card === selectedWorld;
            card.hidden = !selected;
            const button = reforgedButtons.get(card);
            button.hidden = !visible.includes(card);
            button.setAttribute('aria-pressed', String(selected));
        });
        reforgedNav.hidden = visible.length === 0;
        const empty = main.querySelector('.theme-empty');
        if (empty) empty.hidden = visible.length !== 0;
    }
    if (!cards.length) return;
    const nav = make('nav', 'theme-world-nav'); nav.setAttribute('aria-label', 'Choose a world');
    const buttons = cards.map((card, i) => {
        const b = make('button', 'theme-world-choice'); b.type = 'button';
        b.append(make('span', 'theme-world-number', String(i+1).padStart(2,'0')), make('strong', '', card.dataset.world));
        const state = make('small', ''); b.append(state);
        const update = () => { const online = !card.classList.contains('card_dimmed'); state.textContent = online ? 'Online' : 'Offline'; state.dataset.online = String(online); };
        update(); new MutationObserver(update).observe(card, {attributes:true, attributeFilter:['class']});
        b.addEventListener('click', () => select(i)); nav.append(b); return b;
    });
    function select(index) { cards.forEach((c, i) => { c.hidden = i !== index; buttons[i].setAttribute('aria-current', String(i === index)); }); }
    main.prepend(nav); select(0);
})();
