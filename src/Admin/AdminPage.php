<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Admin;

use JustDev\SyncPort\Http\RemoteClient;
use JustDev\SyncPort\Infrastructure\ConnectionRepository;
use JustDev\SyncPort\Infrastructure\OperationRepository;
use JustDev\SyncPort\Migration\ConflictAnalyzer;
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
        wp_enqueue_style('syncport-admin', SYNCPORT_URL . 'assets/admin.css', [], SYNCPORT_VERSION);
        wp_enqueue_script('syncport-admin', SYNCPORT_URL . 'assets/admin.js', [], SYNCPORT_VERSION, true);
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
        $tables = $this->tables();
        require SYNCPORT_PATH . 'templates/admin-page.php';
    }

    public function saveConnection(): void
    {
        $this->guard();
        $url = esc_url_raw((string) ($_POST['url'] ?? ''));
        $key = sanitize_text_field((string) ($_POST['key'] ?? ''));
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
            wp_send_json_error(['message' => __('Database application requires the chunk runner and is not available in this foundation release.', 'syncport')], 501);
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

    public function saveSettings(): void
    {
        $this->guard();
        update_option('syncport_allow_push', !empty($_POST['allow_push']), false);
        update_option('syncport_allow_pull', !empty($_POST['allow_pull']), false);
        if (!empty($_POST['regenerate_key'])) {
            update_option('syncport_api_key', wp_generate_password(64, false, false), false);
        }
        wp_send_json_success(['message' => __('Settings saved.', 'syncport'), 'key' => get_option('syncport_api_key')]);
    }

    /** @return array<string, mixed> */
    private function migrationRequest(): array
    {
        $postIds = preg_split('/[\s,]+/', sanitize_text_field((string) ($_POST['post_ids'] ?? ''))) ?: [];
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
        return array_map(static fn ($row): string => (string) $row[0], $wpdb->get_results('SHOW FULL TABLES', ARRAY_N));
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
