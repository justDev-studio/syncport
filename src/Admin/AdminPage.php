<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Admin;

use JustDev\SyncPort\Http\RemoteClient;
use JustDev\SyncPort\Infrastructure\ConnectionRepository;
use JustDev\SyncPort\Infrastructure\OperationRepository;
use JustDev\SyncPort\Migration\ConflictAnalyzer;
use JustDev\SyncPort\Migration\DatabaseMigrator;
use JustDev\SyncPort\Migration\ManifestBuilder;
use JustDev\SyncPort\Migration\PostImporter;
use JustDev\SyncPort\Security\RequestSigner;

final class AdminPage
{
    private RemoteClient $client;

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly OperationRepository $operations,
        private readonly ManifestBuilder $builder,
        private readonly ConflictAnalyzer $analyzer,
        private readonly PostImporter $importer,
        private readonly DatabaseMigrator $database,
        RequestSigner $signer
    ) {
        $this->client = new RemoteClient($signer);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_syncport_save_connection', [$this, 'saveConnection']);
        add_action('wp_ajax_syncport_delete_connection', [$this, 'deleteConnection']);
        add_action('wp_ajax_syncport_test_connection', [$this, 'testConnection']);
        add_action('wp_ajax_syncport_list_entities', [$this, 'listEntities']);
        add_action('wp_ajax_syncport_preflight', [$this, 'preflight']);
        add_action('wp_ajax_syncport_apply', [$this, 'apply']);
        add_action('wp_ajax_syncport_save_settings', [$this, 'saveSettings']);
    }

    public function menu(): void
    {
        add_management_page(
            __('SyncPort', 'syncport'),
            __('SyncPort', 'syncport'),
            'manage_syncport',
            'syncport',
            [$this, 'render']
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'tools_page_syncport') {
            return;
        }
        wp_enqueue_style(
            'syncport-admin',
            SYNCPORT_URL . 'assets/admin.css',
            [],
            $this->assetVersion('assets/admin.css')
        );
        wp_enqueue_script(
            'syncport-admin',
            SYNCPORT_URL . 'assets/admin.js',
            [],
            $this->assetVersion('assets/admin.js'),
            true
        );
        wp_localize_script('syncport-admin', 'syncportAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('syncport_admin'),
            'strings' => [
                'working' => __('Working…', 'syncport'),
                'failed' => __('The request failed.', 'syncport'),
                'confirmApply' => __('Apply this migration? A backup will be created before destructive table operations.', 'syncport'),
                'confirmDelete' => __('Delete this connection?', 'syncport'),
                'preflight' => __('Preflight', 'syncport'),
                'summary' => __('%1$d entities, %2$d options, %3$d tables, %4$d conflicts.', 'syncport'),
                'choose' => __('Choose…', 'syncport'),
                'replace' => __('Replace', 'syncport'),
                'skip' => __('Skip', 'syncport'),
                'duplicate' => __('Create duplicate', 'syncport'),
                'apply' => __('Apply migration', 'syncport'),
                'resolveAll' => __('Resolve every conflict before applying the migration.', 'syncport'),
                'migrationStatus' => __('Migration status: %s.', 'syncport'),
                'loadingEntities' => __('Loading entities…', 'syncport'),
                'noEntities' => __('No entities found for the selected post types.', 'syncport'),
                'selectEntity' => __('Select one or more entities, or leave empty to migrate all.', 'syncport'),
                'connectionCopied' => __('Connection info copied.', 'syncport'),
                'copyFailed' => __('Could not copy connection info.', 'syncport'),
                'preparingPreflight' => __('Analyzing the selected data and checking the remote site…', 'syncport'),
                'preflightComplete' => __('Preflight complete. Review the results before applying the migration.', 'syncport'),
                'applyingMigration' => __('Applying the migration on the target site…', 'syncport'),
                'databaseProgress' => __('Migrating database rows: %1$d of %2$d…', 'syncport'),
                'migrationComplete' => __('Migration completed.', 'syncport'),
                'migrationErrors' => __('Migration completed with %1$d error(s): %2$s', 'syncport'),
                'operationFailed' => __('The operation failed.', 'syncport'),
            ],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_syncport')) {
            wp_die(esc_html__('You are not allowed to use SyncPort.', 'syncport'));
        }
        $connections = $this->connections->all();
        $operations = $this->operations->recent();
        $postTypes = get_post_types(['show_ui' => true], 'objects');
        $contentItems = $this->builder->listEntities(['page']);
        $tables = $this->tables();
        $connectionInfo = $this->connectionInfo();
        require SYNCPORT_PATH . 'templates/admin-page.php';
    }

    public function saveConnection(): void
    {
        $this->guard();
        [$url, $key] = $this->parseConnectionInfo((string) ($_POST['connection_info'] ?? ''));
        if (!wp_http_validate_url($url) || $key === '') {
            wp_send_json_error(['message' => __('A valid URL and API key are required.', 'syncport')], 422);
        }
        $id = $this->connections->save([
            'id' => sanitize_text_field((string) ($_POST['id'] ?? '')),
            'name' => sanitize_text_field((string) ($_POST['name'] ?? '')),
            'url' => $url,
            'key' => $key,
            'allow_push' => !empty($_POST['allow_push']),
            'allow_pull' => !empty($_POST['allow_pull']),
        ]);
        wp_send_json_success(['id' => $id, 'message' => __('Connection saved.', 'syncport')]);
    }

    public function deleteConnection(): void
    {
        $this->guard();
        $this->connections->delete(sanitize_text_field((string) ($_POST['id'] ?? '')));
        wp_send_json_success(['message' => __('Connection deleted.', 'syncport')]);
    }

    public function testConnection(): void
    {
        $this->guard();
        $connection = $this->connection();
        $response = $this->client->post($connection, 'handshake', []);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message(), 'data' => $response->get_error_data()], 502);
        }
        wp_send_json_success($response);
    }

    public function listEntities(): void
    {
        $this->guard();
        $postTypes = array_values(array_filter(array_map('sanitize_key', (array) ($_POST['post_types'] ?? []))));
        $direction = ($_POST['direction'] ?? '') === 'pull' ? 'pull' : 'push';

        if ($direction === 'pull') {
            $connection = $this->connection();
            if (empty($connection['allow_pull'])) {
                wp_send_json_error(['message' => __('Pull is disabled for this connection.', 'syncport')], 403);
            }
            $response = $this->client->post($connection, 'entities', ['post_types' => $postTypes]);
            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()], 502);
            }
            wp_send_json_success(['entities' => (array) ($response['entities'] ?? [])]);
        }

        wp_send_json_success(['entities' => $this->builder->listEntities($postTypes)]);
    }

    public function preflight(): void
    {
        $this->guard();
        $request = $this->migrationRequest();
        if ($request['scope'] === 'database' && !current_user_can('manage_syncport_tables')) {
            wp_send_json_error(['message' => __('You are not allowed to migrate database tables.', 'syncport')], 403);
        }

        $connection = $this->connection((string) $request['connection_id']);
        if ($request['direction'] === 'push' && empty($connection['allow_push'])) {
            wp_send_json_error(['message' => __('Push is disabled for this connection.', 'syncport')], 403);
        }
        if ($request['direction'] === 'pull' && empty($connection['allow_pull'])) {
            wp_send_json_error(['message' => __('Pull is disabled for this connection.', 'syncport')], 403);
        }
        if ($request['scope'] === 'database') {
            $handshake = $this->client->post($connection, 'handshake', []);
            if (is_wp_error($handshake)) {
                wp_send_json_error(['message' => $handshake->get_error_message()], 502);
            }
            if (version_compare((string) ($handshake['syncport_version'] ?? '0.0.0'), '0.2.0', '<')) {
                wp_send_json_error(['message' => __('Update SyncPort on both sites before migrating database tables.', 'syncport')], 409);
            }
        }
        $operation = $this->operations->create($request, (string) $request['connection_id']);
        if ($request['direction'] === 'pull') {
            $remote = $this->client->post($connection, 'manifest', $request + ['target_url' => home_url()]);
            if (is_wp_error($remote)) {
                $this->fail($operation, $remote->get_error_message());
            }
            $manifest = $remote;
            $preflight = $this->analyzer->analyze($manifest);
        } else {
            $manifest = $this->builder->build($request + ['target_url' => (string) $connection['url']]);
            $remote = $this->client->post($connection, 'preflight', ['manifest' => $manifest]);
            if (is_wp_error($remote)) {
                $this->fail($operation, $remote->get_error_message());
            }
            $preflight = $remote;
        }

        $this->operations->update($operation, ['status' => 'preflight', 'manifest' => $manifest, 'result' => $preflight]);
        wp_send_json_success(['operation' => $operation, 'preflight' => $preflight]);
    }

    public function apply(): void
    {
        $this->guard();
        $uuid = sanitize_text_field((string) ($_POST['operation'] ?? ''));
        $operation = $this->operations->find($uuid);
        if (!$operation || !is_array($operation['manifest'])) {
            wp_send_json_error(['message' => __('Migration operation not found.', 'syncport')], 404);
        }
        $resolutions = json_decode(wp_unslash((string) ($_POST['resolutions'] ?? '{}')), true);
        $resolutions = is_array($resolutions) ? array_map('sanitize_key', $resolutions) : [];
        $request = (array) $operation['request'];

        if (($request['scope'] ?? '') === 'database') {
            if (!current_user_can('manage_syncport_tables')) {
                wp_send_json_error(['message' => __('You are not allowed to migrate database tables.', 'syncport')], 403);
            }
            $this->applyDatabase($uuid, $operation, $request);
        }

        if (($operation['direction'] ?? '') === 'pull') {
            $result = $this->importer->import($operation['manifest'], $resolutions);
        } else {
            $connection = $this->connection((string) $operation['connection_id']);
            $result = $this->client->post($connection, 'apply', ['manifest' => $operation['manifest'], 'resolutions' => $resolutions]);
            if (is_wp_error($result)) {
                $this->fail($uuid, $result->get_error_message());
            }
        }
        $status = !empty($result['errors']) ? 'completed_with_errors' : 'completed';
        $this->operations->update($uuid, ['status' => $status, 'result' => $result]);
        wp_send_json_success(['operation' => $uuid, 'status' => $status, 'result' => $result]);
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $request */
    private function applyDatabase(string $uuid, array $operation, array $request): never
    {
        $manifest = (array) $operation['manifest'];
        $tables = array_values(array_filter((array) ($manifest['tables'] ?? []), 'is_array'));
        $storedResult = ($operation['status'] ?? '') === 'running' ? (array) ($operation['result'] ?? []) : [];
        $state = (array) ($storedResult['database'] ?? []);
        if ($state === []) {
            $state = [
                'table_index' => 0,
                'offset' => 0,
                'processed' => 0,
                'total' => array_sum(array_map(static fn (array $table): int => (int) ($table['rows'] ?? 0), $tables)),
                'tables_completed' => 0,
                'tables_total' => count($tables),
                'percent' => 0,
            ];
        }

        $tableIndex = (int) $state['table_index'];
        if (isset($tables[$tableIndex])) {
            $table = $tables[$tableIndex];
            $offset = (int) $state['offset'];
            if (($operation['direction'] ?? '') === 'pull') {
                $connection = $this->connection((string) $operation['connection_id']);
                $chunk = $this->client->post($connection, 'database-chunk', [
                    'table' => $table['name'] ?? '',
                    'offset' => $offset,
                    'limit' => DatabaseMigrator::CHUNK_SIZE,
                ]);
                if (is_wp_error($chunk)) {
                    $this->fail($uuid, $chunk->get_error_message());
                }
                $applied = $this->database->applyChunk(
                    $uuid,
                    $table,
                    $chunk,
                    (string) ($request['table_mode'] ?? 'replace'),
                    (string) ($manifest['source']['table_prefix'] ?? ''),
                    (array) ($manifest['replace'] ?? [])
                );
            } else {
                $chunk = $this->database->exportChunk((string) ($table['name'] ?? ''), $offset);
                if (is_wp_error($chunk)) {
                    $this->fail($uuid, $chunk->get_error_message());
                }
                $connection = $this->connection((string) $operation['connection_id']);
                $applied = $this->client->post($connection, 'database-apply-chunk', [
                    'operation' => $uuid,
                    'table' => $table,
                    'chunk' => $chunk,
                    'mode' => (string) ($request['table_mode'] ?? 'replace'),
                    'source_prefix' => (string) ($manifest['source']['table_prefix'] ?? ''),
                    'replace' => (array) ($manifest['replace'] ?? []),
                ]);
            }
            if (is_wp_error($applied)) {
                $this->fail($uuid, $applied->get_error_message());
            }

            $state['processed'] = (int) $state['processed'] + (int) ($applied['written'] ?? 0);
            $state['offset'] = (int) ($applied['next_offset'] ?? $offset);
            if (!empty($applied['done'])) {
                $state['table_index'] = $tableIndex + 1;
                $state['tables_completed'] = (int) $state['tables_completed'] + 1;
                $state['offset'] = 0;
            }
        }

        $complete = (int) $state['table_index'] >= count($tables);
        $total = (int) $state['total'];
        $state['percent'] = $complete ? 100 : ($total > 0 ? min(99, (int) floor((int) $state['processed'] * 100 / $total)) : 0);
        $result = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => [], 'database' => $state];
        $status = $complete ? 'completed' : 'running';
        $this->operations->update($uuid, ['status' => $status, 'result' => $result]);
        wp_send_json_success(['operation' => $uuid, 'status' => $status, 'result' => $result]);
    }

    public function saveSettings(): void
    {
        $this->guard();
        update_option('syncport_allow_push', !empty($_POST['allow_push']), false);
        update_option('syncport_allow_pull', !empty($_POST['allow_pull']), false);
        if (!empty($_POST['regenerate_key'])) {
            update_option('syncport_api_key', wp_generate_password(64, false, false), false);
        }
        wp_send_json_success(['message' => __('Settings saved.', 'syncport'), 'connectionInfo' => $this->connectionInfo()]);
    }

    /** @return array<string, mixed> */
    private function migrationRequest(): array
    {
        $postIds = (array) ($_POST['post_ids'] ?? []);
        $options = preg_split('/[\s,]+/', sanitize_text_field((string) ($_POST['options'] ?? ''))) ?: [];
        return [
            'direction' => in_array($_POST['direction'] ?? '', ['push', 'pull'], true) ? $_POST['direction'] : 'push',
            'connection_id' => sanitize_text_field((string) ($_POST['connection_id'] ?? '')),
            'scope' => in_array($_POST['scope'] ?? '', ['content', 'options', 'database'], true) ? $_POST['scope'] : 'content',
            'post_ids' => array_values(array_filter(array_map('absint', $postIds))),
            'post_types' => array_values(array_filter(array_map('sanitize_key', (array) ($_POST['post_types'] ?? ['page'])))),
            'options' => array_values(array_filter(array_map('sanitize_key', $options))),
            'tables' => array_values(array_filter(array_map('sanitize_text_field', (array) ($_POST['tables'] ?? [])))),
            'table_mode' => in_array($_POST['table_mode'] ?? '', ['replace', 'merge'], true) ? $_POST['table_mode'] : 'replace',
            'include_media' => !empty($_POST['include_media']),
            'mirror_media' => !empty($_POST['mirror_media']),
        ];
    }

    /** @return array<string, mixed> */
    private function connection(?string $id = null): array
    {
        $id ??= sanitize_text_field((string) ($_POST['connection_id'] ?? ''));
        $connection = $this->connections->find($id);
        if (!$connection) {
            wp_send_json_error(['message' => __('Connection not found.', 'syncport')], 404);
        }
        return $connection;
    }

    /** @return array<int, string> */
    private function tables(): array
    {
        global $wpdb;
        return array_values(array_filter(
            array_map(
                static fn ($row): string => (string) $row[0],
                array_filter(
                    $wpdb->get_results('SHOW FULL TABLES', ARRAY_N),
                    static fn (array $row): bool => !isset($row[1]) || strtoupper((string) $row[1]) === 'BASE TABLE'
                )
            ),
            static fn (string $table): bool => !in_array($table, [$wpdb->prefix . 'syncport_operations', $wpdb->prefix . 'syncport_chunks'], true)
                && !str_starts_with($table, $wpdb->prefix . 'syncport_bak_')
        ));
    }

    /** @return array{0: string, 1: string} */
    private function parseConnectionInfo(string $connectionInfo): array
    {
        $parts = preg_split('/\s+/', trim(wp_unslash($connectionInfo)), 2) ?: [];
        return [
            esc_url_raw((string) ($parts[0] ?? '')),
            sanitize_text_field((string) ($parts[1] ?? '')),
        ];
    }

    private function connectionInfo(): string
    {
        return home_url() . "\n" . (string) get_option('syncport_api_key');
    }

    private function assetVersion(string $relativePath): string
    {
        $hash = hash_file('sha256', SYNCPORT_PATH . $relativePath);
        return $hash ? SYNCPORT_VERSION . '.' . substr($hash, 0, 12) : SYNCPORT_VERSION;
    }

    private function guard(): void
    {
        check_ajax_referer('syncport_admin', 'nonce');
        if (!current_user_can('manage_syncport')) {
            wp_send_json_error(['message' => __('Permission denied.', 'syncport')], 403);
        }
    }

    private function fail(string $operation, string $message): never
    {
        $this->operations->update($operation, ['status' => 'failed', 'result' => ['message' => $message]]);
        wp_send_json_error(['message' => $message, 'operation' => $operation], 502);
    }
}
