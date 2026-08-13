(() => {
    const config = window.syncportAdmin;
    const notice = document.querySelector('#syncport-notice');
    let readyResolutions = {};

    const escapeHtml = (value) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const request = async (action, data = {}) => {
        const body = new URLSearchParams({ action, nonce: config.nonce });
        Object.entries(data).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(`${key}[]`, item));
            } else {
                body.append(key, value);
            }
        });
        const response = await fetch(config.ajaxUrl, { method: 'POST', body });
        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.data?.message || config.strings.failed);
        }
        return payload.data;
    };

    const message = (text, type = 'success') => {
        const wrapper = document.createElement('div');
        const paragraph = document.createElement('p');
        wrapper.className = `notice notice-${type}`;
        paragraph.textContent = text;
        wrapper.append(paragraph);
        notice.replaceChildren(wrapper);
    };

    document.querySelectorAll('[data-syncport-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('[data-syncport-tab]').forEach((item) => {
                const active = item === tab;
                item.classList.toggle('nav-tab-active', active);
                item.setAttribute('aria-selected', String(active));
                item.tabIndex = active ? 0 : -1;
            });
            document.querySelectorAll('[data-syncport-panel]').forEach((panel) => {
                const active = panel.dataset.syncportPanel === tab.dataset.syncportTab;
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });
        });
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const tabs = [...document.querySelectorAll('[data-syncport-tab]')];
            const current = tabs.indexOf(tab);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            tabs[next].focus();
            tabs[next].click();
        });
    });

    const updateScope = (input) => {
            document.querySelectorAll('[data-scope-fields]').forEach((fields) => {
                const active = fields.dataset.scopeFields === input.value;
                fields.hidden = !active;
                fields.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    control.disabled = !active;
                });
            });
    };

    document.querySelectorAll('input[name="scope"]').forEach((input) => {
        input.addEventListener('change', () => updateScope(input));
    });
    updateScope(document.querySelector('input[name="scope"]:checked'));

    document.querySelectorAll('[data-select-tables]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.syncport__tables option').forEach((option) => {
                option.selected = button.dataset.selectTables === 'all';
            });
        });
    });

    const bindForm = (selector, action, onSuccess = () => window.location.reload()) => {
        document.querySelector(selector)?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            try {
                const data = Object.fromEntries(new FormData(form));
                form.querySelectorAll('select[multiple]').forEach((select) => {
                    data[select.name.replace('[]', '')] = [...select.selectedOptions].map((option) => option.value);
                });
                form.querySelectorAll('input[type="checkbox"][name$="[]"]:checked').forEach((input) => {
                    const key = input.name.replace('[]', '');
                    data[key] = [...form.querySelectorAll(`input[name="${input.name}"]:checked`)].map((item) => item.value);
                });
                const result = await request(action, data);
                onSuccess(result);
            } catch (error) {
                message(error.message, 'error');
            } finally {
                submit.disabled = false;
            }
        });
    };

    bindForm('#syncport-connection-form', 'syncport_save_connection');
    bindForm('#syncport-settings-form', 'syncport_save_settings', (result) => {
        document.querySelector('#syncport-api-key').value = result.key;
        message(result.message);
    });

    document.querySelectorAll('[data-test-connection]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const result = await request('syncport_test_connection', { connection_id: button.dataset.testConnection });
                message(`${result.name}: SyncPort ${result.syncport_version}, WordPress ${result.wordpress_version}`);
            } catch (error) {
                message(error.message, 'error');
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-delete-connection]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm(config.strings.confirmDelete)) return;
            await request('syncport_delete_connection', { id: button.dataset.deleteConnection });
            window.location.reload();
        });
    });

    bindForm('#syncport-migration-form', 'syncport_preflight', (result) => {
        const target = document.querySelector('#syncport-preflight');
        const preflight = result.preflight;
        const conflicts = preflight.conflicts || [];
        readyResolutions = Object.fromEntries((preflight.ready || []).map((item) => [item.uuid, item.action]));
        const blocked = (preflight.compatible || []).length > 0;
        const summary = config.strings.summary
            .replace('%1$d', Number(preflight.summary.posts))
            .replace('%2$d', Number(preflight.summary.options))
            .replace('%3$d', Number(preflight.summary.tables))
            .replace('%4$d', Number(preflight.summary.conflicts));
        target.hidden = false;
        target.innerHTML = `
            <h2>${escapeHtml(config.strings.preflight)}</h2>
            <p>${escapeHtml(summary)}</p>
            ${(preflight.compatible || []).map((issue) => `<div class="notice notice-error inline"><p>${escapeHtml(issue)}</p></div>`).join('')}
            <div class="syncport__conflicts">
                ${conflicts.map((conflict) => `<label><span>${escapeHtml(conflict.source_title)} → ${escapeHtml(conflict.target_title)}</span><select data-resolution="${escapeHtml(conflict.uuid)}"><option value="">${escapeHtml(config.strings.choose)}</option><option value="replace">${escapeHtml(config.strings.replace)}</option><option value="skip">${escapeHtml(config.strings.skip)}</option><option value="duplicate">${escapeHtml(config.strings.duplicate)}</option></select></label>`).join('')}
            </div>
            <button class="button button-primary" type="button" data-apply-operation="${escapeHtml(result.operation)}" ${blocked ? 'disabled' : ''}>${escapeHtml(config.strings.apply)}</button>`;
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-apply-operation]');
        if (!button || !window.confirm(config.strings.confirmApply)) return;
        const resolutions = { ...readyResolutions };
        document.querySelectorAll('[data-resolution]').forEach((select) => { resolutions[select.dataset.resolution] = select.value; });
        if ([...document.querySelectorAll('[data-resolution]')].some((select) => !select.value)) {
            message(config.strings.resolveAll, 'error');
            return;
        }
        button.disabled = true;
        try {
            const result = await request('syncport_apply', { operation: button.dataset.applyOperation, resolutions: JSON.stringify(resolutions) });
            message(config.strings.migrationStatus.replace('%s', result.status));
            button.remove();
        } catch (error) {
            message(error.message, 'error');
            button.disabled = false;
        }
    });
})();
