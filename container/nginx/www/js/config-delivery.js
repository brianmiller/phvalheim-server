/* Shared by the new-world and edit-world pickers. State outlives paging and filtering. */
class PhvConfigDelivery {
    constructor(selection, redraw) {
        this.state = {};
        this.selection = selection;
        this.redraw = redraw;
        const bar = document.createElement('div');
        bar.className = 'config-delivery-toolbar mb-2';
        bar.innerHTML = '<details><summary>Config delivery for all selected mods</summary>' +
            '<p class="small mb-1">Applies across all pages and filters. Saving the mod selection updates the world and applies delivery changes.</p>' +
            ['server', 'client'].map(side => '<span class="me-3">Push to ' + side + ': ' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-config-bulk="' + side + '" data-on="1">Select all</button> ' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-config-bulk="' + side + '" data-on="0">Deselect all</button></span>').join('') + '</details>' +
            '<p class="small mt-2 mb-1" id="configDeliveryHelp" role="status">Config defaults: Server on, Client off. Saving a shared setting enables Client automatically. Requires Client 2.0.17.</p>';
        document.getElementById('modTabBar').before(bar);
        document.addEventListener('change', ev => {
            const el = ev.target;
            if (!el.matches('.config-push-toggle')) return;
            const flags = this.flags(el.dataset.uuid);
            flags['config_' + el.dataset.side] = el.checked;
            this.state[el.dataset.uuid] = flags;
            this.explain(el.dataset.side, el.checked);
            this.redraw();
        });
        bar.addEventListener('click', ev => {
            const el = ev.target.closest('[data-config-bulk]');
            if (!el) return;
            const side = el.dataset.configBulk, enabled = el.dataset.on === '1';
            Object.keys(this.selection()).forEach(id => {
                const flags = this.flags(id);
                flags['config_' + side] = enabled;
                this.state[id] = flags;
            });
            this.explain(side, enabled);
            this.redraw();
        });
    }
    explain(side, enabled) {
        document.getElementById('configDeliveryHelp').textContent = side === 'client' && enabled
            ? 'Push to Client: run the mod once to generate its config before editing settings. For a client-only mod, import the generated .cfg in its config editor. Overridable settings supply defaults; existing player values are kept. Save the mod selection to update this world and apply.'
            : 'Delivery changes are not saved yet. Save the mod selection to update the world. Client off lets each player generate and keep their own config.';
    }
    flags(id) { return {...(this.state[id] || {config_server: true, config_client: false})}; }
    adopt(id, entry) {
        this.state[id] = {config_server: entry.config_server !== false, config_client: entry.config_client === true};
    }
    cell(id, selected) {
        if (!selected) return '<span class="dest-off">&mdash;</span>';
        const f = this.flags(id);
        return '<div class="dest-pair">' + ['server', 'client'].map(side => {
            const help = side === 'server'
                ? 'Push saved config settings to the dedicated server. Default: on. This does not install the mod; Installed on controls that.'
                : 'Push config defaults and rules to players. Default: off; saving a shared setting enables this. Run the mod once to discover settings. Requires Client 2.0.17.';
            return '<label class="dest-sw" title="' + help + '"><input type="checkbox" class="form-check-input config-push-toggle" data-uuid="' + id +
                '" data-side="' + side + '" aria-label="Push config to ' + side + '"' + (f['config_' + side] ? ' checked' : '') + '> ' + (side === 'server' ? 'Server' : 'Client') + '</label>';
        }).join('') + '</div>';
    }
}
