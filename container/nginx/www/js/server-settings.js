/* Server Settings page. Move the existing form controls so API IDs and provider actions
   stay shared with the admin shell; drafts live in the DOM until an explicit save. */
(() => {
    'use strict';
    const el = (tag, cls, text) => { const n = document.createElement(tag); n.className = cls || ''; if (text) n.textContent = text; return n; };
    const categories = {
        general: ['General', 'Connection details and server time.'],
        appearance: ['Appearance', 'Choose a separate look for sign-in and the player website.'],
        players: ['Players & Steam', 'Authentication and defaults for your players.'],
        backups: ['Backups', 'Protect your worlds and manage storage.'],
        updates: ['Updates & Mods', 'Keep worlds and mod catalogues up to date.'],
        ai: ['AI Helper', 'Connect Hugin to your preferred providers and models.'],
        advanced: ['Advanced', 'Logging and usage reporting.']
    };
    // id, category, label, short explanation, optional disclosure group
    const fields = [
        ['gameDNS','general','Game address','Hostname or IP used by game clients. Update each world after changing it.'],
        ['basePort','general','First game port','Each world uses two consecutive UDP ports.'],
        ['timezone','general','Timezone','Used for schedules, logs, and the dashboard clock.'],
        ['loginTheme','appearance','Login screen','Shown before players sign in. Applies on their next page load.'],
        ['publicTheme','appearance','Player website','World selection and mod viewer. Applies on the next page load.'],
        ['steamAPIKey','players','Steam API key','Required for Steam sign-in and player identities.'],
        ['sessionTimeout','players','Keep players signed in','Players sign in through Steam again when their session expires.'],
        ['disableSteamOverlay','players','Disable Steam overlay','Troubleshooting for modded Linux launches. Applies on the next launch.'],
        ['backupIntervalMinutes','backups','Backup every (minutes)','Scheduled backups run when the conditions below are met.'],
        ['backupRequireActivity','backups','Only after player activity','Skip worlds with no activity since their last backup.'],
        ['backupRetainAllHours','backups','Keep every backup for (hours)','After this period, older scheduled backups are thinned.'],
        ['backupRetainDailyDays','backups','Keep one per day for (days)','Daily retention tier.'],
        ['backupRetainWeeklyDays','backups','Keep one per week for (days)','Weekly retention tier.'],
        ['backupRetainMonthlyMonths','backups','Keep one per month for (months)','Monthly retention tier. Manual backups are never auto-deleted.'],
        ['backupCompression','backups','Compression','Reduce backup file sizes.', 'Advanced backup options'],
        ['backupCompressionHour','backups','Compress at','Use server time or compress immediately.', 'Advanced backup options'],
        ['backupCompressionLevel','backups','Compression level','0 uses the default. Gzip: 1–9; Zstd: 1–19.', 'Advanced backup options'],
        ['backupCpuPriority','backups','CPU priority','Lower priority gives game processes more CPU time.', 'Advanced backup options'],
        ['backupIoPriority','backups','Disk priority','Idle uses the disk only when other processes are not using it.', 'Advanced backup options'],
        ['autoUpdateMode','updates','Automatic world updates','Default for worlds without their own override. Pinned mods stay pinned.'],
        ['autoUpdateScope','updates','What to update','Choose the game, mods, or both.'],
        ['autoUpdateCheckIntervalHours','updates','Check every (hours)','One check covers every world.'],
        ['autoUpdateIdleMinutes','updates','Quiet for (minutes)','Wait for the Companion to report zero players for this long.'],
        ['autoUpdateMaxWaitHours','updates','Wait up to (hours)','How long to wait for a quiet world.'],
        ['autoUpdateOnTimeout','updates','After waiting','“Update anyway” can restart a world with players connected.'],
        ['autoUpdateBackupFirst','updates','Back up before updating','If the backup fails, the update is abandoned.'],
        ['autoUpdateWindowStart','updates','Maintenance starts at (hour)','Server time, 0–23. Use -1 to allow any time.'],
        ['autoUpdateWindowHours','updates','Window length (hours)','0 disables the maintenance window.'],
        ['thunderstoreEnabled','updates','Thunderstore catalogue','Disabling hides its mods from the picker; existing selections remain.'],
        ['hexiumEnabled','updates','Hexium catalogue','Disabling hides its mods from the picker; existing selections remain.'],
        ['modSyncIntervalHours','updates','Sync catalogues every (hours)','Check enabled catalogues for new and updated mods.'],
        ['thunderstoreApiKey','updates','Thunderstore API key','Optional. The public catalogue does not require a key.', 'Catalogue credentials'],
        ['hexiumApiKey','updates','Hexium API key','Optional. The public catalogue does not require a key.', 'Catalogue credentials'],
        ['maxLogSize','advanced','Rotate world logs at','Start a new log file when it reaches this size.'],
        ['analyticsEnabled','advanced','Usage analytics','Send installation and world/mod information to the developer. No player data.']
    ];
    window.initServerSettingsPage = settings => {
        const body = document.getElementById('serverSettingsBody');
        body.removeAttribute('aria-live');
        const originals = [...body.querySelectorAll('input[id^="ss-"], select[id^="ss-"]')];
        const controls = Object.fromEntries(originals.map(n => [n.id.slice(3), n]));
        if (originals.length !== fields.length || fields.some(([id]) => !controls[id])) throw new Error('Settings field map is incomplete');
        const save = document.getElementById('ssSubmitBtn'), status = document.getElementById('ssStatus');
        status.setAttribute('role', 'status'); status.setAttribute('aria-live','polite');
        const ai = document.getElementById('ss-aiProviderList').parentElement;
        const aiList = document.getElementById('ss-aiProviderList');
        const addProvider = ai.querySelector('button');
        const portNotes = [...controls.basePort.parentElement.children].filter(n => n.tagName === 'SMALL' || n.classList.contains('text-warning'));
        const overlayHelp = controls.disableSteamOverlay.closest('.mb-3').querySelector('p').textContent;
        const analyticsHelp = controls.analyticsEnabled.nextElementSibling.textContent;
        const main = el('div','settings-layout'), aside = el('aside','settings-categories'), content = el('div','settings-content');
        aside.setAttribute('aria-label','Settings categories');
        const searchLabel = el('label','settings-search-label','Find a setting'); searchLabel.htmlFor = 'settingsSearch';
        const search = el('input','form-control settings-search'); search.id = 'settingsSearch'; search.type = 'search'; search.placeholder = 'Search settings…';
        const mobileLabel = el('label','settings-mobile-label','Category'); mobileLabel.htmlFor = 'settingsCategory';
        const mobile = el('select','form-control settings-mobile'); mobile.id = 'settingsCategory';
        aside.append(searchLabel,search,mobileLabel,mobile);
        const nav = el('nav','settings-nav'); nav.setAttribute('aria-label','Settings sections'); aside.append(nav);
        const panels = {}, buttons = {}, rows = {}, groups = {};
        for (const [id,[name,desc]] of Object.entries(categories)) {
            const button = el('button','settings-category',name); button.type = 'button'; button.dataset.category = id;
            button.addEventListener('click', () => selectCategory(id,true)); nav.append(button); buttons[id] = button;
            const option = el('option','',name); option.value = id; mobile.append(option);
            const panel = el('section','settings-panel'); panel.id = 'settings-'+id; panel.setAttribute('aria-labelledby',panel.id+'-title');
            const title = el('h3','',name); title.id = panel.id+'-title';
            panel.append(title,el('p','settings-intro',desc)); content.append(panel); panels[id] = panel;
        }
        const disclosure = (parent, title, text) => {
            const d = el('details','settings-help'); d.append(el('summary','',title));
            if (text) d.append(el('p','',text)); parent.append(d); return d;
        };
        const backupSummary = el('p','settings-summary'); backupSummary.setAttribute('aria-live','polite');
        const backupStorage = el('div','settings-storage');
        backupStorage.append(el('strong','',settings.backupPathMounted ? 'Dedicated backup storage' : 'Automatic backups are disabled'));
        backupStorage.append(el('p','',settings.backupPathMounted
            ? `${settings.backupPath} · ${settings.backupCount} backups · ${(settings.backupTotalSize / 1073741824).toFixed(1)} GB used · ${settings.backupDiskFree} free`
            : 'Mount a separate host path at /opt/stateful/backups in your container configuration to enable scheduled backups.'));
        backupStorage.classList.toggle('settings-warning',!settings.backupPathMounted);
        panels.backups.append(backupStorage,backupSummary);
        const updateHelp = disclosure(panels.updates,'How automatic updates work','The Companion reports player counts every two minutes. A vanilla world cannot report that it is quiet: use a maintenance window and “Update anyway” if you want scheduled updates for it. “Update anyway” can disconnect players. World-specific overrides still apply; pinned mods are never updated.');
        const updateOptions = el('div','settings-update-options'); panels.updates.append(updateOptions);
        for (const [id,category,label,description,group] of fields) {
            const control = controls[id], row = el('div','settings-row'), copy = el('div','settings-copy'), holder = el('div','settings-control');
            row.dataset.field = id;
            const heading = el('label','',label); heading.htmlFor = control.id;
            const help = el('p','',description); help.id = control.id+'-help'; control.setAttribute('aria-describedby',help.id);
            copy.append(heading,help); row.append(copy,holder); rows[id] = row;
            control.removeAttribute('data-tip'); control.style.removeProperty('border-color'); control.style.removeProperty('box-shadow');
            // Preserve the key visibility button alongside its input.
            if (control.type === 'password') holder.append(control.parentElement); else holder.append(control);
            if (id === 'basePort') portNotes.forEach(n => holder.append(n));
            if (id === 'disableSteamOverlay') disclosure(copy,'Client support',overlayHelp);
            if (id === 'analyticsEnabled') disclosure(copy,'What is sent?',analyticsHelp);
            if (id === 'steamAPIKey') {
                const link = el('a','','Get a Steam API key ↗'); link.href = 'https://steamcommunity.com/dev/apikey'; link.target = '_blank'; link.rel = 'noopener'; copy.append(link);
            }
            if (category === 'backups' && !settings.backupPathMounted) control.disabled = true;
            let parent = panels[category];
            if (id.startsWith('autoUpdate') && id !== 'autoUpdateMode') parent = updateOptions;
            if (group) parent = groups[group] ||= disclosure(panels[category],group);
            parent.append(row);
        }
        // Put the enable switch before its dependent settings, with no value loss when off.
        updateOptions.before(rows.autoUpdateMode);
        panels.ai.append(el('p','settings-summary','Provider changes save immediately in their own dialogs. They are separate from Save changes below.'),aiList,addProvider);
        const noResults = el('p','settings-empty','No settings match your search.'); noResults.hidden = true; content.append(noResults);
        main.append(aside,content);
        const bar = el('div','settings-savebar'), dirtyText = el('span','settings-dirty'), discard = el('button','action-btn','Discard changes'); discard.type = 'button'; discard.id = 'settingsDiscard';
        bar.append(dirtyText,status,discard,save); save.textContent = 'Save changes'; save.type = 'button';
        body.replaceChildren(main,bar);
        let baseline, busy = false, disabledBeforeSave = [], active = 'general';
        const value = c => c.type === 'checkbox' ? c.checked : c.value;
        const snapshot = () => Object.fromEntries(originals.map(c => [c.id,value(c)]));
        const changed = () => originals.filter(c => value(c) !== baseline[c.id]);
        const proxies = [];
        const unitControl = (id, units) => {
            const raw = controls[id], holder = rows[id].querySelector('.settings-control'); raw.hidden = true;
            const wrap = el('div','settings-unit'), input = el('input','form-control form-control-sm'), unit = el('select','form-control form-control-sm');
            input.type = 'number'; input.min = '1'; input.step = 'any'; input.id = raw.id+'-display'; input.required = true;
            rows[id].querySelector('label').htmlFor = input.id;
            input.setAttribute('aria-describedby',raw.id+'-help'); unit.setAttribute('aria-label',id === 'sessionTimeout' ? 'Session duration unit' : 'Log size unit');
            units.forEach(([name,factor]) => { const option = el('option','',name); option.value = factor; unit.append(option); });
            const reset = () => {
                const n = Number(raw.value), chosen = units.find(([,f]) => n >= f && n % f === 0) || units[units.length-1];
                unit.value = chosen[1]; input.value = n / chosen[1]; input.setCustomValidity('');
            };
            const sync = () => {
                const n = Number(input.value) * Number(unit.value);
                input.setCustomValidity(Number.isSafeInteger(n) && n > 0 ? '' : 'Enter a positive whole number of '+(id === 'sessionTimeout' ? 'seconds.' : 'bytes.'));
                // Empty/invalid edits stay invalid, never silently fall back to a default.
                raw.value = input.value === '' ? '' : String(n); refresh();
            };
            input.addEventListener('input',sync); unit.addEventListener('change',sync);
            wrap.append(input,unit); holder.append(wrap); proxies.push({reset,input,unit}); reset();
        };
        unitControl('sessionTimeout',[['days',86400],['hours',3600],['minutes',60],['seconds',1]]);
        unitControl('maxLogSize',[['MB',1000000],['KB',1000],['bytes',1]]);
        const themeCards = [];
        ['loginTheme','publicTheme'].forEach(id => {
            const select = controls[id]; select.hidden = true; const holder = rows[id].querySelector('.settings-control');
            rows[id].classList.add('settings-theme-row');
            const choices = el('div','settings-theme-choices'); choices.setAttribute('role','group'); choices.setAttribute('aria-label',id === 'loginTheme' ? 'Login screen theme' : 'Player website theme');
            [...select.options].forEach(option => {
                const button = el('button','settings-theme-card'); button.type = 'button'; button.dataset.theme = option.value;
                const preview = el('span','settings-theme-preview '+(id === 'loginTheme' ? 'preview-login' : 'preview-worlds')); preview.setAttribute('aria-hidden','true');
                preview.append(el('span','preview-brand','PhValheim'),el('span','preview-content',id === 'loginTheme' ? 'Welcome, Viking.' : 'Your worlds'),el('span','preview-action',id === 'loginTheme' ? 'Sign in through Steam' : 'Launch'));
                button.append(preview,el('span','settings-theme-name',option.textContent));
                button.addEventListener('click',() => { select.value = option.value; refresh(); });
                choices.append(button); themeCards.push({button,select,value:option.value});
            }); holder.append(choices);
        });
        // Numeric constraints supplement the existing API without changing saved units.
        originals.filter(c => c.type === 'number').forEach(c => { if (!c.hasAttribute('min')) c.min = c.id.startsWith('ss-backupRetain') ? '0' : '1'; c.required = true; });
        controls.basePort.max = '65534';
        baseline = snapshot();
        function refresh() {
            const changes = changed(); dirtyText.textContent = changes.length ? `${changes.length} unsaved ${changes.length === 1 ? 'change' : 'changes'}` : 'All changes saved';
            save.disabled = busy || !changes.length; discard.disabled = busy || !changes.length;
            originals.forEach(c => rows[c.id.slice(3)].classList.toggle('is-dirty',changes.includes(c)));
            themeCards.forEach(({button,select,value}) => button.setAttribute('aria-pressed',String(select.value === value)));
            updateOptions.hidden = controls.autoUpdateMode.value !== '1' && !search.value.trim();
            backupSummary.textContent = settings.backupPathMounted ? `Every ${controls.backupIntervalMinutes.value} minutes · ${controls.backupRequireActivity.value === '1' ? 'Only after player activity' : 'Regardless of player activity'}` : 'Schedule and retention are unavailable until dedicated storage is mounted.';
        }
        function filter() {
            const q = search.value.trim().toLowerCase(); let matches = 0;
            for (const [id,panel] of Object.entries(panels)) {
                let count = 0;
                panel.querySelectorAll('.settings-row').forEach(row => {
                    // Search labels/help/options, never stored values or secrets.
                    const text = row.querySelector('.settings-copy').textContent+' '+row.dataset.field;
                    row.hidden = !!q && !text.toLowerCase().includes(q); if (!row.hidden) count++;
                });
                if (id === 'ai' && (!q || 'ai helper hugin providers models connection'.includes(q))) count++;
                panel.hidden = q ? !count : id !== active; matches += count;
            }
            noResults.hidden = !q || matches > 0;
            Object.values(groups).forEach(g => { if (q) g.open = [...g.querySelectorAll('.settings-row')].some(r => !r.hidden); });
            updateHelp.hidden = !!q;
            refresh();
        }
        function selectCategory(id, navigate) {
            active = categories[id] ? id : 'general'; search.value = ''; mobile.value = active;
            Object.entries(buttons).forEach(([key,button]) => { if (key === active) button.setAttribute('aria-current','page'); else button.removeAttribute('aria-current'); });
            if (navigate) history.replaceState(null,'','#'+active);
            filter();
        }
        const revealInvalid = control => {
            const row = control.closest('.settings-row'); if (!row) return;
            const category = fields.find(([id]) => id === row.dataset.field)[1]; selectCategory(category,true);
            if (row.closest('details')) row.closest('details').open = true;
            if (row.closest('.settings-update-options')) updateOptions.hidden = false;
            control.focus(); control.reportValidity();
        };
        const unlock = () => { busy = false; disabledBeforeSave.forEach(([n,disabled]) => n.disabled = disabled); disabledBeforeSave = []; save.textContent = 'Save changes'; refresh(); };
        window.serverSettingsPage = {
            isDirty: () => changed().length > 0,
            beforeSave() {
                if (busy) return false;
                const invalid = [...originals,...proxies.map(p => p.input)].find(c => !c.disabled && !c.hidden && !c.checkValidity());
                if (invalid) { revealInvalid(invalid); return false; }
                busy = true; disabledBeforeSave = [...body.querySelectorAll('input,select,button')].map(n => [n,n.disabled]);
                disabledBeforeSave.forEach(([n]) => n.disabled = true); return true;
            },
            saved(payload) {
                for (const [id,v] of Object.entries(payload)) if (controls[id]) {
                    const c = controls[id]; if (c.type === 'checkbox') c.checked = !!v; else c.value = String(v);
                }
                baseline = snapshot(); proxies.forEach(p => p.reset()); unlock();
            },
            saveFailed: unlock
        };
        discard.addEventListener('click',() => {
            originals.forEach(c => { if (c.type === 'checkbox') c.checked = baseline[c.id]; else c.value = baseline[c.id]; });
            proxies.forEach(p => p.reset()); status.textContent = ''; filter();
        });
        body.addEventListener('input',refresh); body.addEventListener('change',refresh);
        search.addEventListener('input',filter); mobile.addEventListener('change',() => selectCategory(mobile.value,true));
        window.addEventListener('hashchange',() => selectCategory(location.hash.slice(1),false));
        window.addEventListener('beforeunload',event => { if (changed().length) { event.preventDefault(); event.returnValue = ''; } });
        document.addEventListener('click',event => {
            const link = event.target.closest('a[href]');
            if (!link || link.target === '_blank' || !changed().length || link.getAttribute('href').startsWith('#')) return;
            if (!window.confirm('Leave Server Settings and discard unsaved changes?')) { event.preventDefault(); event.stopImmediatePropagation(); }
            else baseline = snapshot(); // The user already confirmed this navigation.
        },true);
        selectCategory(location.hash.slice(1),false); refresh();
    };
    document.addEventListener('DOMContentLoaded',() => showServerSettingsModal());
})();
