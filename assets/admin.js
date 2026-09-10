(() => {
    const config = window.syncportAdmin;
    const notice = document.querySelector('#syncport-notice');
    const entitySelect = document.querySelector('#syncport-post-ids');
    const migrationForm = document.querySelector('#syncport-migration-form');
    const progressRegion = document.querySelector('#syncport-progress');
    const progressBar = document.querySelector('#syncport-progress-bar');
    const progressLabel = document.querySelector('#syncport-progress-label');
    const progressState = document.querySelector('#syncport-progress-state');
    let readyResolutions = {};
    let entityRequest = 0;

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

    const updateProgress = (label, state = 'running', value = null) => {
        if (!progressRegion || !progressBar || !progressLabel || !progressState) return;
        progressRegion.hidden = false;
        progressRegion.classList.toggle('is-complete', state === 'complete');
        progressRegion.classList.toggle('is-error', state === 'error');
        progressLabel.textContent = label;
        progressState.textContent = state === 'complete' ? '100%' : state === 'error' ? config.strings.operationFailed : config.strings.working;
        if (state === 'complete' || state === 'error') {
            progressBar.value = 100;
        } else if (Number.isFinite(value)) {
            progressBar.value = Math.max(0, Math.min(100, value));
            progressState.textContent = `${progressBar.value}%`;
        } else {
            progressBar.removeAttribute('value');
        }
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

    const updateTableScope = () => {
        const selectedInput = migrationForm?.querySelector('input[name="table_scope"]:checked');
        const selection = migrationForm?.querySelector('[data-table-selection]');
        if (!selectedInput || !selection) return;
        const selected = selectedInput.value === 'selected';
        selection.hidden = !selected;
        selection.querySelectorAll('select, button').forEach((control) => {
            control.disabled = !selected || selection.closest('[data-scope-fields]').hidden;
        });
        migrationForm.querySelectorAll('input[name="table_scope"]').forEach((input) => {
            input.setAttribute('aria-expanded', String(input.checked && selected));
        });
    };

    const updateScope = (input) => {
        document.querySelectorAll('[data-scope-fields]').forEach((fields) => {
            const active = fields.dataset.scopeFields === input.value;
            fields.hidden = !active;
            fields.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !active;
            });
        });
        updateTableScope();
    };

    document.querySelectorAll('input[name="scope"]').forEach((input) => {
        input.addEventListener('change', () => {
            updateScope(input);
            if (input.value === 'content') loadEntities();
        });
    });
    updateScope(document.querySelector('input[name="scope"]:checked'));

    document.querySelectorAll('input[name="table_scope"]').forEach((input) => {
        input.addEventListener('change', updateTableScope);
    });

    document.querySelectorAll('[data-select-tables]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.syncport__tables option').forEach((option) => {
                option.selected = button.dataset.selectTables === 'all';
            });
        });
    });

    const bindForm = (selector, action, onSuccess = () => window.location.reload(), progress = null) => {
        document.querySelector(selector)?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            form.setAttribute('aria-busy', 'true');
            if (progress) updateProgress(progress.start);
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
                if (progress) updateProgress(progress.complete, 'complete');
            } catch (error) {
                message(error.message, 'error');
                if (progress) updateProgress(error.message, 'error');
            } finally {
                submit.disabled = false;
                form.removeAttribute('aria-busy');
            }
        });
    };

    bindForm('#syncport-connection-form', 'syncport_save_connection');
    bindForm('#syncport-settings-form', 'syncport_save_settings', (result) => {
        document.querySelector('#syncport-connection-info').value = result.connectionInfo;
        message(result.message);
    });

    const entityLabel = (entity) => {
        const language = entity.language ? ` · ${String(entity.language).toUpperCase()}` : '';
        return `${entity.title} — ${entity.post_type_label}${language} (#${entity.id})`;
    };

    const loadEntities = async () => {
        if (!entitySelect || !migrationForm) return;
        const direction = migrationForm.elements.direction.value;
        const connectionId = migrationForm.elements.connection_id.value;
        const postTypes = [...migrationForm.querySelectorAll('input[name="post_types[]"]:checked')].map((input) => input.value);
        const requestId = ++entityRequest;
        if (direction === 'pull' && !connectionId) {
            entitySelect.replaceChildren(new Option(config.strings.selectEntity, ''));
            entitySelect.disabled = true;
            return;
        }

        entitySelect.replaceChildren(new Option(config.strings.loadingEntities, ''));
        entitySelect.disabled = true;
        try {
            const result = await request('syncport_list_entities', {
                direction,
                connection_id: connectionId,
                post_types: postTypes,
            });
            if (requestId !== entityRequest) return;
            const entities = result.entities || [];
            entitySelect.replaceChildren(...entities.map((entity) => new Option(entityLabel(entity), entity.id)));
            if (!entities.length) {
                entitySelect.append(new Option(config.strings.noEntities, ''));
            }
        } catch (error) {
            if (requestId !== entityRequest) return;
            entitySelect.replaceChildren(new Option(error.message, ''));
            message(error.message, 'error');
        } finally {
            if (requestId === entityRequest) {
                entitySelect.disabled = migrationForm.elements.scope.value !== 'content';
            }
        }
    };

    migrationForm?.elements.direction.addEventListener('change', loadEntities);
    migrationForm?.elements.connection_id.addEventListener('change', loadEntities);
    migrationForm?.querySelectorAll('input[name="post_types[]"]').forEach((input) => input.addEventListener('change', loadEntities));

    document.querySelector('[data-copy-connection-info]')?.addEventListener('click', async () => {
        const field = document.querySelector('#syncport-connection-info');
        try {
            await navigator.clipboard.writeText(field.value);
            message(config.strings.connectionCopied);
        } catch (error) {
            field.focus();
            field.select();
            const copied = document.execCommand('copy');
            message(copied ? config.strings.connectionCopied : config.strings.copyFailed, copied ? 'success' : 'error');
        }
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
        readyResolutions = {
            ...Object.fromEntries((preflight.ready || []).map((item) => [item.uuid, item.action])),
            ...Object.fromEntries(conflicts.map((item) => [item.uuid, 'replace'])),
        };
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
                ${conflicts.map((conflict) => `<label><span>${escapeHtml(conflict.source_title)} → ${escapeHtml(conflict.target_title)}</span><select data-resolution="${escapeHtml(conflict.uuid)}"><option value="replace" selected>${escapeHtml(config.strings.replace)}</option><option value="skip">${escapeHtml(config.strings.skip)}</option><option value="duplicate">${escapeHtml(config.strings.duplicate)}</option></select></label>`).join('')}
            </div>
            <button class="button button-primary" type="button" data-apply-operation="${escapeHtml(result.operation)}" ${blocked ? 'disabled' : ''}>${escapeHtml(config.strings.apply)}</button>`;
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, {
        start: config.strings.preparingPreflight,
        complete: config.strings.preflightComplete,
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
        button.setAttribute('aria-busy', 'true');
        updateProgress(config.strings.applyingMigration);
        try {
            let result;
            do {
                result = await request('syncport_apply', { operation: button.dataset.applyOperation, resolutions: JSON.stringify(resolutions) });
                const database = result.result?.database;
                if (result.status === 'running' && database) {
                    const label = config.strings.databaseProgress
                        .replace('%1$d', Number(database.processed))
                        .replace('%2$d', Number(database.total));
                    updateProgress(label, 'running', Number(database.percent));
                }
            } while (result.status === 'running');
            const errors = result.result?.errors || [];
            if (errors.length) {
                const details = [...new Set(errors.map((error) => error.message).filter(Boolean))].join(' ');
                const errorMessage = config.strings.migrationErrors
                    .replace('%1$d', errors.length)
                    .replace('%2$s', details);
                message(errorMessage, 'error');
                updateProgress(errorMessage, 'error');
            } else {
                message(config.strings.migrationStatus.replace('%s', result.status));
                updateProgress(config.strings.migrationComplete, 'complete');
            }
            button.remove();
        } catch (error) {
            message(error.message, 'error');
            updateProgress(error.message, 'error');
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });
})();
