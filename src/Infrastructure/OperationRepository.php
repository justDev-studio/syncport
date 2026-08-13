<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Infrastructure;

final class OperationRepository
{
    /** @param array<string, mixed> $request */
    public function create(array $request, string $connectionId = ''): string
    {
        global $wpdb;

        $uuid = wp_generate_uuid4();
        $now = current_time('mysql', true);
        $wpdb->insert(
            $this->table(),
            [
                'operation_uuid' => $uuid,
                'direction' => sanitize_key((string) ($request['direction'] ?? 'push')),
                'scope' => sanitize_key((string) ($request['scope'] ?? 'content')),
                'status' => 'created',
                'connection_id' => $connectionId,
                'request' => wp_json_encode($request),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $uuid;
    }

    /** @param array<string, mixed> $values */
    public function update(string $uuid, array $values): void
    {
        global $wpdb;

        $allowed = ['status', 'manifest', 'result'];
        $data = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $data[$key] = is_array($values[$key]) ? wp_json_encode($values[$key]) : (string) $values[$key];
        }
        $data['updated_at'] = current_time('mysql', true);

        $wpdb->update($this->table(), $data, ['operation_uuid' => $uuid]);
    }

    /** @return array<int, object> */
    public function recent(int $limit = 20): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d", $limit));
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE operation_uuid = %s", $uuid), ARRAY_A);
        if (!$row) {
            return null;
        }

        foreach (['request', 'manifest', 'result'] as $key) {
            $row[$key] = $row[$key] ? json_decode((string) $row[$key], true) : null;
        }
        return $row;
    }

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'syncport_operations';
    }
}
