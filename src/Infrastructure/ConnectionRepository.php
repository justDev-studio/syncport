<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Infrastructure;

final class ConnectionRepository
{
    private const OPTION = 'syncport_connections';

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $connections = get_option(self::OPTION, []);
        return is_array($connections) ? $connections : [];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $connections = $this->all();
        return isset($connections[$id]) && is_array($connections[$id]) ? $connections[$id] : null;
    }

    /** @param array<string, mixed> $connection */
    public function save(array $connection): string
    {
        $connections = $this->all();
        $id = sanitize_key((string) ($connection['id'] ?? ''));
        if ($id === '') {
            $id = wp_generate_uuid4();
        }

        $connections[$id] = [
            'id' => $id,
            'name' => sanitize_text_field((string) ($connection['name'] ?? '')),
            'url' => untrailingslashit(esc_url_raw((string) ($connection['url'] ?? ''))),
            'key' => sanitize_text_field((string) ($connection['key'] ?? '')),
            'allow_push' => !empty($connection['allow_push']),
            'allow_pull' => !empty($connection['allow_pull']),
        ];

        update_option(self::OPTION, $connections, false);
        return $id;
    }

    public function delete(string $id): void
    {
        $connections = $this->all();
        unset($connections[$id]);
        update_option(self::OPTION, $connections, false);
    }
}
