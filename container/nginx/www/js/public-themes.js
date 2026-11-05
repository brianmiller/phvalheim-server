/* Presentation only: retain the original cards and polling hooks for every action. */
(() => {
    'use strict';
    const theme = document.body.dataset.publicTheme;
    if (!['reforged', 'gate', 'ember'].includes(theme)) return;
    const brand = document.createElement('a');
    brand.className = 'theme-brand';
    brand.href = '/';
    brand.innerHTML = '<img src="/images/themes/hugin.svg" alt=""><span>PhValheim<small>Your worlds await</small></span>';
    const headerTable = document.querySelector('.google_header')?.closest('table');
    if (headerTable) {
        const header = document.createElement('header');
        header.className = 'theme-header';
        headerTable.before(header);
        const account = document.createElement('table');
        const accountBody = document.createElement('tbody');
        account.append(accountBody);
        // The legacy outer table also contains the entire world grid.
        // Move only its account rows into the new header.
        for (const row of [...headerTable.tBodies[0].children]) {
            if (row.querySelector('.outer')) break;
            accountBody.append(row);
        }
        header.append(brand, account);
    } else {
        document.querySelector('.login_header')?.replaceChildren(brand);
    }
    const cards = [...document.querySelectorAll('.catbox[data-world]')];
    const bosses = ['Eikthyr', 'The Elder', 'Bonemass', 'Moder', 'Yagluth', 'The Queen', 'Fader'];
    for (const card of cards) {
        const trophies = card.querySelector('.trophy-table');
        if (!trophies) continue;
        const caption = document.createElement('caption');
        caption.textContent = 'Bosses defeated';
        const legend = document.createElement('small');
        legend.textContent = 'Lit trophies mark defeated bosses';
        caption.append(legend);
        trophies.prepend(caption);
        trophies.querySelectorAll('.trophy_icon').forEach((cell, i) => {
            const sprite = document.createElement('span');
            sprite.className = 'theme-boss';
            sprite.style.backgroundPosition = `${(i % 4) * 100 / 3}% ${i < 4 ? 0 : 100}%`;
            sprite.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'theme-boss-name';
            label.textContent = bosses[i] || '';
            // The original image/title stays in the DOM for live status and accessibility.
            cell.append(sprite, label);
            const original = cell.querySelector('img');
            if (original) {
                const describe = () => { original.alt = original.title; };
                describe();
                new MutationObserver(describe).observe(original, {attributes:true, attributeFilter:['title']});
            }
        });
    }
    if (!cards.length || theme === 'reforged') return;
    const wrapper = cards[0].parentElement;
    const navigation = document.createElement('nav');
    navigation.className = 'theme-world-nav';
    navigation.setAttribute('aria-label', 'Choose a world');
    const buttons = cards.map((card, i) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'theme-world-choice';
        const number = document.createElement('span');
        number.className = 'theme-world-number';
        number.textContent = String(i + 1).padStart(2, '0');
        const name = document.createElement('strong');
        name.textContent = card.dataset.world;
        const status = document.createElement('small');
        const update = () => { status.textContent = card.classList.contains('card_dimmed') ? 'Offline' : 'Online'; };
        update();
        new MutationObserver(update).observe(card, {attributes: true, attributeFilter: ['class']});
        button.append(number, name, status);
        button.addEventListener('click', () => select(i));
        navigation.append(button);
        return button;
    });
    function select(index) {
        cards.forEach((card, i) => {
            card.hidden = i !== index;
            buttons[i].setAttribute('aria-current', i === index ? 'true' : 'false');
        });
    }
    wrapper.prepend(navigation);
    select(0);
})();
