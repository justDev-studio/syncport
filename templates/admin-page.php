<?php
/** @var array<string, array<string, mixed>> $connections */
/** @var array<int, object> $operations */
/** @var array<string, WP_Post_Type> $postTypes */
/** @var array<int, array<string, mixed>> $contentItems */
/** @var array<int, string> $tables */
/** @var string $connectionInfo */
?>
<div class="wrap syncport">
    <header class="syncport__header">
        <div>
            <h1><?php esc_html_e('SyncPort', 'syncport'); ?></h1>
            <p><?php esc_html_e('Migrate selected WordPress data between connected sites.', 'syncport'); ?></p>
        </div>
        <span class="syncport__version">v<?php echo esc_html(SYNCPORT_VERSION); ?></span>
    </header>

    <nav class="nav-tab-wrapper" role="tablist" aria-label="<?php esc_attr_e('SyncPort sections', 'syncport'); ?>">
        <button id="syncport-tab-migrate" class="nav-tab nav-tab-active" type="button" role="tab" aria-selected="true" aria-controls="syncport-panel-migrate" tabindex="0" data-syncport-tab="migrate"><?php esc_html_e('Migrate', 'syncport'); ?></button>
        <button id="syncport-tab-connections" class="nav-tab" type="button" role="tab" aria-selected="false" aria-controls="syncport-panel-connections" tabindex="-1" data-syncport-tab="connections"><?php esc_html_e('Connections', 'syncport'); ?></button>
        <button id="syncport-tab-history" class="nav-tab" type="button" role="tab" aria-selected="false" aria-controls="syncport-panel-history" tabindex="-1" data-syncport-tab="history"><?php esc_html_e('History', 'syncport'); ?></button>
        <button id="syncport-tab-settings" class="nav-tab" type="button" role="tab" aria-selected="false" aria-controls="syncport-panel-settings" tabindex="-1" data-syncport-tab="settings"><?php esc_html_e('Settings', 'syncport'); ?></button>
    </nav>

    <section id="syncport-panel-migrate" class="syncport__panel is-active" role="tabpanel" aria-labelledby="syncport-tab-migrate" tabindex="0" data-syncport-panel="migrate">
        <form id="syncport-migration-form" class="syncport__card">
            <h2><?php esc_html_e('New migration', 'syncport'); ?></h2>
            <?php if (!$connections) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Add a connection before starting a migration.', 'syncport'); ?></p></div>
            <?php endif; ?>
            <div class="syncport__grid">
                <label>
                    <span><?php esc_html_e('Direction', 'syncport'); ?></span>
                    <select name="direction">
                        <option value="push"><?php esc_html_e('Push — this site to remote', 'syncport'); ?></option>
                        <option value="pull"><?php esc_html_e('Pull — remote to this site', 'syncport'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Connected site', 'syncport'); ?></span>
                    <select name="connection_id" required>
                        <option value=""><?php esc_html_e('Select a site', 'syncport'); ?></option>
                        <?php foreach ($connections as $connection) : ?>
                            <option value="<?php echo esc_attr((string) $connection['id']); ?>"><?php echo esc_html((string) $connection['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <fieldset class="syncport__scope">
                <legend><?php esc_html_e('Data scope', 'syncport'); ?></legend>
                <label><input type="radio" name="scope" value="content" checked> <?php esc_html_e('Posts, pages, and custom post types', 'syncport'); ?></label>
                <label><input type="radio" name="scope" value="options"> <?php esc_html_e('ACF Options values', 'syncport'); ?></label>
                <label><input type="radio" name="scope" value="database"> <?php esc_html_e('Full database or selected tables', 'syncport'); ?></label>
            </fieldset>

            <div data-scope-fields="content">
                <h3><?php esc_html_e('Content selection', 'syncport'); ?></h3>
                <div class="syncport__choices">
                    <?php foreach ($postTypes as $postType) : ?>
                        <?php if ($postType->name === 'attachment') { continue; } ?>
                        <label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($postType->name); ?>" <?php checked($postType->name, 'page'); ?>> <?php echo esc_html($postType->labels->name); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="syncport__field">
                    <label for="syncport-post-ids"><?php esc_html_e('Entities', 'syncport'); ?></label>
                    <select id="syncport-post-ids" class="syncport__entities" name="post_ids[]" multiple size="12" aria-describedby="syncport-post-ids-hint">
                        <?php foreach ($contentItems as $item) : ?>
                            <option value="<?php echo esc_attr((string) $item['id']); ?>">
                                <?php echo esc_html(sprintf('%1$s — %2$s%3$s (#%4$d)', $item['title'], $item['post_type_label'], $item['language'] ? ' · ' . strtoupper((string) $item['language']) : '', $item['id'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small id="syncport-post-ids-hint"><?php esc_html_e('Select one or more entities. Leave the selection empty to migrate all entities of the selected post types.', 'syncport'); ?></small>
                </div>
                <label><input type="checkbox" name="include_media" value="1" checked> <?php esc_html_e('Include directly used media files', 'syncport'); ?></label>
            </div>

            <div data-scope-fields="options" hidden>
                <h3><?php esc_html_e('ACF Options values', 'syncport'); ?></h3>
                <label class="syncport__field">
                    <span><?php esc_html_e('Option names', 'syncport'); ?></span>
                    <textarea name="options" rows="5" placeholder="options_header_logo, options_company_phone" aria-describedby="syncport-options-hint"></textarea>
                    <small id="syncport-options-hint"><?php esc_html_e('Only values are migrated. Field groups must already exist on the target site.', 'syncport'); ?></small>
                </label>
            </div>

            <div data-scope-fields="database" hidden>
                <h3><?php esc_html_e('Database tables', 'syncport'); ?></h3>
                <div class="syncport__table-actions">
                    <button class="button" type="button" data-select-tables="all"><?php esc_html_e('Select all', 'syncport'); ?></button>
                    <button class="button" type="button" data-select-tables="none"><?php esc_html_e('Clear', 'syncport'); ?></button>
                </div>
                <select class="syncport__tables" name="tables[]" multiple size="10" aria-describedby="syncport-tables-hint">
                    <?php foreach ($tables as $table) : ?>
                        <option value="<?php echo esc_attr($table); ?>"><?php echo esc_html($table); ?></option>
                    <?php endforeach; ?>
                </select>
                <small id="syncport-tables-hint"><?php esc_html_e('Leave all tables unselected to migrate the full WordPress database. Select tables only for a partial migration.', 'syncport'); ?></small>
                <div class="syncport__choices">
                    <label><input type="radio" name="table_mode" value="replace" checked> <?php esc_html_e('Replace full database or selected tables', 'syncport'); ?></label>
                    <label><input type="radio" name="table_mode" value="merge"> <?php esc_html_e('Merge full database or selected tables', 'syncport'); ?></label>
                    <label><input type="checkbox" name="include_media" value="1"> <?php esc_html_e('Include media library', 'syncport'); ?></label>
                    <label><input type="checkbox" name="mirror_media" value="1"> <?php esc_html_e('Mirror media library and remove extra local files', 'syncport'); ?></label>
                </div>
            </div>

            <p class="submit"><button class="button button-primary" type="submit" <?php disabled(!$connections); ?>><?php esc_html_e('Run preflight', 'syncport'); ?></button></p>
        </form>
        <div id="syncport-progress" class="syncport__card syncport__progress" role="status" aria-live="polite" aria-atomic="true" hidden>
            <div class="syncport__progress-header">
                <strong id="syncport-progress-label"><?php esc_html_e('Preparing migration…', 'syncport'); ?></strong>
                <span id="syncport-progress-state"><?php esc_html_e('In progress', 'syncport'); ?></span>
            </div>
            <progress id="syncport-progress-bar" max="100"><?php esc_html_e('In progress', 'syncport'); ?></progress>
        </div>
        <div id="syncport-preflight" class="syncport__card" hidden></div>
    </section>

    <section id="syncport-panel-connections" class="syncport__panel" role="tabpanel" aria-labelledby="syncport-tab-connections" tabindex="0" data-syncport-panel="connections" hidden>
        <div class="syncport__columns">
            <form id="syncport-connection-form" class="syncport__card">
                <h2><?php esc_html_e('Add connection', 'syncport'); ?></h2>
                <label class="syncport__field"><span><?php esc_html_e('Name', 'syncport'); ?></span><input type="text" name="name" required></label>
                <label class="syncport__field">
                    <span><?php esc_html_e('Connection info', 'syncport'); ?></span>
                    <textarea name="connection_info" rows="3" placeholder="https://example.com&#10;remote-api-key" required spellcheck="false" autocomplete="off"></textarea>
                    <small><?php esc_html_e('Paste the complete connection info copied from the remote site.', 'syncport'); ?></small>
                </label>
                <div class="syncport__choices">
                    <label><input type="checkbox" name="allow_push" value="1" checked> <?php esc_html_e('Allow push', 'syncport'); ?></label>
                    <label><input type="checkbox" name="allow_pull" value="1" checked> <?php esc_html_e('Allow pull', 'syncport'); ?></label>
                </div>
                <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Save connection', 'syncport'); ?></button></p>
            </form>
            <div class="syncport__card">
                <h2><?php esc_html_e('Connected sites', 'syncport'); ?></h2>
                <ul class="syncport__connections">
                    <?php foreach ($connections as $connection) : ?>
                        <li>
                            <div><strong><?php echo esc_html((string) $connection['name']); ?></strong><code><?php echo esc_html((string) $connection['url']); ?></code></div>
                            <div><button class="button" type="button" data-test-connection="<?php echo esc_attr((string) $connection['id']); ?>"><?php esc_html_e('Test', 'syncport'); ?></button> <button class="button-link-delete" type="button" data-delete-connection="<?php echo esc_attr((string) $connection['id']); ?>"><?php esc_html_e('Delete', 'syncport'); ?></button></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <section id="syncport-panel-history" class="syncport__panel" role="tabpanel" aria-labelledby="syncport-tab-history" tabindex="0" data-syncport-panel="history" hidden>
        <div class="syncport__card">
            <h2><?php esc_html_e('Migration history', 'syncport'); ?></h2>
            <table class="widefat striped">
                <caption class="screen-reader-text"><?php esc_html_e('Recent SyncPort migration operations', 'syncport'); ?></caption>
                <thead><tr><th><?php esc_html_e('Operation', 'syncport'); ?></th><th><?php esc_html_e('Direction', 'syncport'); ?></th><th><?php esc_html_e('Scope', 'syncport'); ?></th><th><?php esc_html_e('Status', 'syncport'); ?></th><th><?php esc_html_e('Created', 'syncport'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($operations as $operation) : ?>
                    <tr><td><code><?php echo esc_html($operation->operation_uuid); ?></code></td><td><?php echo esc_html($operation->direction); ?></td><td><?php echo esc_html($operation->scope); ?></td><td><?php echo esc_html($operation->status); ?></td><td><?php echo esc_html($operation->created_at); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="syncport-panel-settings" class="syncport__panel" role="tabpanel" aria-labelledby="syncport-tab-settings" tabindex="0" data-syncport-panel="settings" hidden>
        <form id="syncport-settings-form" class="syncport__card">
            <h2><?php esc_html_e('Incoming requests', 'syncport'); ?></h2>
            <div class="syncport__choices">
                <label><input type="checkbox" name="allow_push" value="1" <?php checked((bool) get_option('syncport_allow_push')); ?>> <?php esc_html_e('Accept push requests', 'syncport'); ?></label>
                <label><input type="checkbox" name="allow_pull" value="1" <?php checked((bool) get_option('syncport_allow_pull')); ?>> <?php esc_html_e('Accept pull requests', 'syncport'); ?></label>
            </div>
            <div class="syncport__field">
                <label for="syncport-connection-info"><?php esc_html_e('Connection info', 'syncport'); ?></label>
                <textarea id="syncport-connection-info" rows="3" readonly spellcheck="false"><?php echo esc_textarea($connectionInfo); ?></textarea>
                <small><?php esc_html_e('Copy and paste this complete value into the connection form on another site.', 'syncport'); ?></small>
                <div><button class="button" type="button" data-copy-connection-info><?php esc_html_e('Copy connection info', 'syncport'); ?></button></div>
            </div>
            <label><input type="checkbox" name="regenerate_key" value="1"> <?php esc_html_e('Regenerate the API key and revoke existing connections', 'syncport'); ?></label>
            <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'syncport'); ?></button></p>
        </form>
    </section>

    <div id="syncport-notice" role="status" aria-live="polite"></div>
</div>
