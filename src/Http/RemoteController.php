<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Http;

use JustDev\SyncPort\Migration\ConflictAnalyzer;
use JustDev\SyncPort\Migration\DatabaseMigrator;
use JustDev\SyncPort\Migration\ManifestBuilder;
use JustDev\SyncPort\Migration\PostImporter;
use JustDev\SyncPort\Security\RequestAuthenticator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RemoteController
{
    public function __construct(
        private readonly RequestAuthenticator $authenticator,
        private readonly ManifestBuilder $builder,
        private readonly ConflictAnalyzer $analyzer,
        private readonly PostImporter $importer,
        private readonly DatabaseMigrator $database
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            foreach (['handshake', 'entities', 'manifest', 'preflight', 'apply'] as $endpoint) {
                register_rest_route('syncport/v1', '/' . $endpoint, [
                    'methods' => 'POST',
                    'callback' => [$this, $endpoint],
                    'permission_callback' => '__return_true',
                ]);
            }
            foreach ([
                'database-chunk' => 'databaseChunk',
                'database-apply-chunk' => 'databaseApplyChunk',
                'database-sql-chunk' => 'databaseSqlChunk',
                'database-apply-sql-chunk' => 'databaseApplySqlChunk',
                'database-finalize' => 'databaseFinalize',
            ] as $route => $callback) {
                register_rest_route('syncport/v1', '/' . $route, [
                    'methods' => 'POST',
                    'callback' => [$this, $callback],
                    'permission_callback' => '__return_true',
                ]);
            }
        });
    }

    public function handshake(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        return new WP_REST_Response([
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'syncport_version' => SYNCPORT_VERSION,
            'database_protocol' => DatabaseMigrator::PROTOCOL,
            'table_prefix' => $GLOBALS['wpdb']->prefix,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'acf' => defined('ACF_VERSION') ? ACF_VERSION : null,
            'wpml' => defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : null,
        ]);
    }

    public function entities(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_pull', false)) {
            return new WP_Error('syncport_pull_disabled', __('Pull requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        return new WP_REST_Response([
            'entities' => $this->builder->listEntities((array) ($payload['post_types'] ?? [])),
        ]);
    }

    public function manifest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_pull', false)) {
            return new WP_Error('syncport_pull_disabled', __('Pull requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        return new WP_REST_Response($this->builder->build($this->payload($request)));
    }

    public function preflight(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_push', false)) {
            return new WP_Error('syncport_push_disabled', __('Push requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        return new WP_REST_Response($this->analyzer->analyze((array) ($payload['manifest'] ?? [])));
    }

    public function apply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_push', false)) {
            return new WP_Error('syncport_push_disabled', __('Push requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        $preflight = $this->analyzer->analyze((array) ($payload['manifest'] ?? []));
        if ($preflight['compatible']) {
            return new WP_Error('syncport_incompatible_manifest', implode(' ', $preflight['compatible']), ['status' => 409]);
        }
        $resolutions = (array) ($payload['resolutions'] ?? []);
        foreach ($preflight['conflicts'] as $conflict) {
            $resolution = $resolutions[$conflict['uuid']] ?? '';
            if (!in_array($resolution, $conflict['actions'], true)) {
                return new WP_Error('syncport_unresolved_conflict', __('Every conflict must be resolved before applying a migration.', 'syncport'), ['status' => 409]);
            }
        }
        return new WP_REST_Response($this->importer->import(
            (array) ($payload['manifest'] ?? []),
            $resolutions
        ));
    }

    public function databaseChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_pull', false)) {
            return new WP_Error('syncport_pull_disabled', __('Pull requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        $chunk = $this->database->exportChunk(
            (string) ($payload['table'] ?? ''),
            (int) ($payload['offset'] ?? 0),
            (int) ($payload['limit'] ?? DatabaseMigrator::CHUNK_SIZE)
        );
        return is_wp_error($chunk) ? $chunk : new WP_REST_Response($chunk);
    }

    public function databaseApplyChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_push', false)) {
            return new WP_Error('syncport_push_disabled', __('Push requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        $result = $this->database->applyChunk(
            sanitize_text_field((string) ($payload['operation'] ?? '')),
            (array) ($payload['table'] ?? []),
            (array) ($payload['chunk'] ?? []),
            sanitize_key((string) ($payload['mode'] ?? 'replace')),
            (string) ($payload['source_prefix'] ?? ''),
            (array) ($payload['replace'] ?? []),
            (array) ($payload['selected_tables'] ?? [])
        );
        return is_wp_error($result) ? $result : new WP_REST_Response($result);
    }

    public function databaseSqlChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_pull', false)) {
            return new WP_Error('syncport_pull_disabled', __('Pull requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $result = $this->database->exportSqlChunk($this->payload($request));
        return is_wp_error($result) ? $result : new WP_REST_Response($result);
    }

    public function databaseApplySqlChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_push', false)) {
            return new WP_Error('syncport_push_disabled', __('Push requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        $result = $this->database->applySqlChunk((array) ($payload['chunk'] ?? []));
        return is_wp_error($result) ? $result : new WP_REST_Response($result);
    }

    public function databaseFinalize(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($verified = $this->authenticator->verify($request))) {
            return $verified;
        }
        if (!get_option('syncport_allow_push', false)) {
            return new WP_Error('syncport_push_disabled', __('Push requests are disabled on this site.', 'syncport'), ['status' => 403]);
        }
        $payload = $this->payload($request);
        $result = $this->database->finalizeReplace(
            sanitize_text_field((string) ($payload['operation'] ?? '')),
            (array) ($payload['tables'] ?? []),
            (string) ($payload['source_prefix'] ?? '')
        );
        return is_wp_error($result) ? $result : new WP_REST_Response($result);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = json_decode($request->get_body(), true);
        return is_array($payload) ? $payload : [];
    }
}
