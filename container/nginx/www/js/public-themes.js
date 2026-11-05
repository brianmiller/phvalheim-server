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
    const heading = make('h1', '', 'Your worlds'); const total = make('span', 'theme-total');
    toolbar.append(heading, total);
    if (theme === 'reforged') {
        const search = make('input', 'theme-search'); search.type = 'search'; search.placeholder = 'Search worlds…'; search.setAttribute('aria-label', 'Search worlds');
        search.addEventListener('input', () => cards.forEach(c => { c.hidden = !c.dataset.world.toLowerCase().includes(search.value.toLowerCase()); })); toolbar.append(search);
    }
    if (theme === 'reforged') main.append(toolbar);
    const wrapper = make('div', 'theme-worlds'); main.append(wrapper);
    const bosses = ['Eikthyr', 'The Elder', 'Bonemass', 'Moder', 'Yagluth', 'The Queen', 'Fader'];
    cards.forEach((card, index) => {
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
        if (theme === 'reforged' && index > 0) {
            const overview = make('table', 'theme-facts theme-overview'); overview.append(document.createElement('tbody'));
            [...groups[0].rows].filter(row => /^(Type|Access)$/.test(row.cells[0].textContent)).forEach(row => overview.tBodies[0].append(row));
            card.insertBefore(overview, trophies || details);
            const disclosure = make('details', 'theme-disclosure'); disclosure.append(make('summary', '', 'World details'), details); card.append(disclosure);
        }
        const refresh = () => { const online = !card.classList.contains('card_dimmed'); status.textContent = online ? 'Online' : 'Offline'; status.dataset.online = String(online); total.textContent = cards.filter(c => !c.classList.contains('card_dimmed')).length + ' online · ' + cards.length + ' worlds'; };
        refresh(); new MutationObserver(refresh).observe(card, {attributes:true, attributeFilter:['class']});
        wrapper.append(card);
    });
    legacy.remove();
    if (theme === 'reforged' || !cards.length) return;
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
