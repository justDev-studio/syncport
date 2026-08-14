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
            $checksumVerified = ($item['checksum_verified'] ?? false) === true;
            $sha256 = $checksumVerified ? sanitize_text_field((string) ($item['sha256'] ?? '')) : '';
            $remote = sanitize_key((string) ($item['storage'] ?? '')) === 'remote';
            $remoteUrl = $remote ? esc_url_raw((string) ($item['url'] ?? '')) : '';
            if ($remote && !wp_http_validate_url($remoteUrl)) {
                $errors[] = ['source_id' => $sourceId, 'message' => __('The media URL is invalid.', 'syncport')];
                continue;
            }
            $targetId = $this->find(
                sanitize_text_field((string) ($item['uuid'] ?? '')),
                $sha256,
                $remoteUrl,
                $remote ? sanitize_text_field((string) ($item['attached_file'] ?? '')) : ''
            );
            if (!$targetId) {
                $targetId = $remote ? $this->registerRemote($item) : $this->download($item);
            }
            if (is_wp_error($targetId)) {
                $errors[] = ['source_id' => $sourceId, 'message' => $targetId->get_error_message()];
                continue;
            }
            if ($sha256 === '') {
                $targetPath = get_attached_file($targetId);
                if (is_string($targetPath) && is_readable($targetPath)) {
                    $sha256 = hash_file('sha256', $targetPath);
                }
            }
            update_post_meta($targetId, '_syncport_uuid', sanitize_text_field((string) ($item['uuid'] ?? '')));
            if ($remote) {
                update_post_meta($targetId, '_syncport_remote_url', $remoteUrl);
            }
            if ($sha256 !== '') {
                update_post_meta($targetId, '_syncport_sha256', $sha256);
            }
            $map[$sourceId] = (int) $targetId;
        }
        return ['map' => $map, 'errors' => $errors];
    }

    private function find(string $uuid, string $sha256, string $remoteUrl, string $attachedFile): int
    {
        $identities = [
            ['_syncport_uuid', $uuid],
            ['_syncport_sha256', $sha256],
            ['_syncport_remote_url', $remoteUrl],
            ['_wp_attached_file', $attachedFile],
        ];
        foreach ($identities as [$key, $value]) {
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
    private function registerRemote(array $item): int|\WP_Error
    {
        $url = esc_url_raw((string) ($item['url'] ?? ''));
        if (!wp_http_validate_url($url)) {
            return new \WP_Error('syncport_invalid_media_url', __('The media URL is invalid.', 'syncport'));
        }

        $sourcePost = (array) ($item['post'] ?? []);
        $attachment = [
            'guid' => $url,
            'post_status' => 'inherit',
            'post_mime_type' => sanitize_text_field((string) ($item['mime_type'] ?? '')),
            'post_title' => sanitize_text_field((string) ($sourcePost['post_title'] ?? $item['filename'] ?? '')),
            'post_name' => sanitize_title((string) ($sourcePost['post_name'] ?? '')),
            'post_excerpt' => sanitize_textarea_field((string) ($sourcePost['post_excerpt'] ?? '')),
            'post_content' => wp_kses_post((string) ($sourcePost['post_content'] ?? '')),
            'post_date' => sanitize_text_field((string) ($sourcePost['post_date'] ?? '')),
            'post_date_gmt' => sanitize_text_field((string) ($sourcePost['post_date_gmt'] ?? '')),
        ];
        $attachment = array_filter($attachment, static fn (string $value): bool => $value !== '');
        $id = wp_insert_attachment(wp_slash($attachment), false, 0, true);
        if (is_wp_error($id)) {
            return $id;
        }
        if (!$id) {
            return new \WP_Error('syncport_media_insert', __('The remote media database record could not be created.', 'syncport'));
        }

        $attachedFile = sanitize_text_field((string) ($item['attached_file'] ?? ''));
        if ($attachedFile !== '') {
            update_post_meta($id, '_wp_attached_file', $attachedFile);
        }
        $metadata = (array) ($item['metadata'] ?? []);
        if ($metadata !== []) {
            wp_update_attachment_metadata($id, $metadata);
        }
        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) ($item['alt'] ?? '')));
        update_post_meta($id, '_syncport_remote_url', $url);
        return $id;
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

        $expected = ($item['checksum_verified'] ?? false) === true
            ? sanitize_text_field((string) ($item['sha256'] ?? ''))
            : '';
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
