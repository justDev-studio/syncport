<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class MediaImporter
{
    /** @param array<int, array<string, mixed>> $items @return array{map: array<int, int>, errors: array<int, array<string, mixed>>} */
    public function import(array $items): array
    {
        $map = [];
        $errors = [];
        foreach ($items as $item) {
            $sourceId = absint($item['source_id'] ?? 0);
            $targetId = $this->find((string) ($item['uuid'] ?? ''), (string) ($item['sha256'] ?? ''));
            if (!$targetId) {
                $targetId = $this->download($item);
            }
            if (is_wp_error($targetId)) {
                $errors[] = ['source_id' => $sourceId, 'message' => $targetId->get_error_message()];
                continue;
            }
            update_post_meta($targetId, '_syncport_uuid', sanitize_text_field((string) ($item['uuid'] ?? '')));
            update_post_meta($targetId, '_syncport_sha256', sanitize_text_field((string) ($item['sha256'] ?? '')));
            $map[$sourceId] = (int) $targetId;
        }
        return ['map' => $map, 'errors' => $errors];
    }

    private function find(string $uuid, string $sha256): int
    {
        foreach ([['_syncport_uuid', $uuid], ['_syncport_sha256', $sha256]] as [$key, $value]) {
            if ($value === '') {
                continue;
            }
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
            if ($ids) {
                return (int) $ids[0];
            }
        }
        return 0;
    }

    /** @param array<string, mixed> $item @return int|\WP_Error */
    private function download(array $item): int|\WP_Error
    {
        $url = esc_url_raw((string) ($item['url'] ?? ''));
        if (!wp_http_validate_url($url)) {
            return new \WP_Error('syncport_invalid_media_url', __('The media URL is invalid.', 'syncport'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temp = download_url($url, 300);
        if (is_wp_error($temp)) {
            return $temp;
        }

        $expected = (string) ($item['sha256'] ?? '');
        if ($expected !== '' && !hash_equals($expected, hash_file('sha256', $temp))) {
            wp_delete_file($temp);
            return new \WP_Error('syncport_media_checksum', __('The downloaded media checksum does not match the manifest.', 'syncport'));
        }

        $file = ['name' => sanitize_file_name((string) ($item['filename'] ?? basename($url))), 'tmp_name' => $temp];
        $id = media_handle_sideload($file, 0);
        if (is_wp_error($id)) {
            wp_delete_file($temp);
        }
        return $id;
    }
}
