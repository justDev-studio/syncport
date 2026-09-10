<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class PostImporter
{
    private MediaImporter $mediaImporter;

    public function __construct(?MediaImporter $mediaImporter = null)
    {
        $this->mediaImporter = $mediaImporter ?? new MediaImporter();
    }

    /** @param array<string, mixed> $manifest @param array<string, string> $resolutions @return array<string, mixed> */
    public function import(array $manifest, array $resolutions): array
    {
        $result = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        foreach ((array) ($manifest['posts'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uuid = sanitize_text_field((string) ($item['uuid'] ?? ''));
            $action = sanitize_key((string) ($resolutions[$uuid] ?? 'create'));
            if ($action === 'skip') {
                $result['skipped'][] = $uuid;
                continue;
            }

            $targetId = $action === 'replace' ? $this->findByUuidOrSlug($item) : 0;
            $media = $this->mediaImporter->import((array) ($item['media'] ?? []));
            $result['errors'] = array_merge($result['errors'], $media['errors']);
            $postData = $this->replaceUrls((array) ($item['post'] ?? []), (array) ($manifest['replace'] ?? []));
            $postData = $this->replaceMediaReferences($postData, $media['map']);
            if ($targetId) {
                $postData['ID'] = $targetId;
            }
            $authorId = $this->authorId((array) ($item['author'] ?? []));
            if (!$authorId) {
                $result['errors'][] = ['uuid' => $uuid, 'message' => __('The author could not be matched.', 'syncport')];
                continue;
            }
            $postData['post_author'] = $authorId;

            $postId = wp_insert_post(wp_slash($postData), true);
            if (is_wp_error($postId)) {
                $result['errors'][] = ['uuid' => $uuid, 'message' => $postId->get_error_message()];
                continue;
            }

            if ($targetId) {
                $this->replaceMeta((int) $postId, (array) ($item['meta'] ?? []), (array) ($manifest['replace'] ?? []), $media['map']);
            } else {
                $this->replaceMeta((int) $postId, (array) ($item['meta'] ?? []), (array) ($manifest['replace'] ?? []), $media['map']);
            }
            update_post_meta((int) $postId, '_syncport_uuid', $uuid);
            $this->replaceTerms((int) $postId, (array) ($item['terms'] ?? []));
            $this->applyLanguage((int) $postId, (array) ($item['language'] ?? []), (string) ($postData['post_type'] ?? 'post'));
            $result[$targetId ? 'updated' : 'created'][] = (int) $postId;
        }

        foreach ((array) ($manifest['options'] ?? []) as $name => $value) {
            update_option(sanitize_key((string) $name), $this->replaceUrls($value, (array) ($manifest['replace'] ?? [])), false);
        }

        return $result;
    }

    /** @param array<string, mixed> $item */
    private function findByUuidOrSlug(array $item): int
    {
        $ids = get_posts([
            'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1,
            'meta_key' => '_syncport_uuid', 'meta_value' => (string) ($item['uuid'] ?? ''),
        ]);
        if ($ids) {
            return (int) $ids[0];
        }
        $post = (array) ($item['post'] ?? []);
        $match = get_page_by_path((string) ($post['post_name'] ?? ''), OBJECT, (string) ($post['post_type'] ?? 'page'));
        return $match ? (int) $match->ID : 0;
    }

    /** @param array<string, mixed> $author */
    private function authorId(array $author): int
    {
        $user = !empty($author['email']) ? get_user_by('email', (string) $author['email']) : false;
        $user = $user ?: (!empty($author['login']) ? get_user_by('login', (string) $author['login']) : false);
        if ($user) {
            return (int) $user->ID;
        }

        $administrators = get_users([
            'role' => 'administrator',
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
            'fields' => 'ids',
        ]);
        return (int) ($administrators[0] ?? 0);
    }

    /** @param array<string, mixed> $meta @param array<string, string> $replace @param array<int, int> $mediaMap */
    private function replaceMeta(int $postId, array $meta, array $replace, array $mediaMap): void
    {
        foreach (array_keys(get_post_meta($postId)) as $key) {
            if (!$this->isProtectedMeta((string) $key)) {
                delete_post_meta($postId, $key);
            }
        }
        foreach ($meta as $key => $values) {
            if ($this->isProtectedMeta((string) $key)) {
                continue;
            }
            foreach ((array) $values as $value) {
                $decoded = maybe_unserialize($value);
                $decoded = $this->replaceUrls($decoded, $replace);
                add_post_meta($postId, sanitize_key((string) $key), $this->replaceMediaReferences($decoded, $mediaMap));
            }
        }
    }

    /** @param array<string, array<int, array<string, mixed>>> $terms */
    private function replaceTerms(int $postId, array $terms): void
    {
        foreach ($terms as $taxonomy => $items) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $termIds = [];
            foreach ($items as $item) {
                $exists = term_exists((string) $item['slug'], $taxonomy);
                if (!$exists) {
                    $exists = wp_insert_term((string) $item['name'], $taxonomy, [
                        'slug' => (string) $item['slug'],
                        'description' => (string) ($item['description'] ?? ''),
                    ]);
                }
                if (!is_wp_error($exists)) {
                    $termIds[] = (int) (is_array($exists) ? $exists['term_id'] : $exists);
                }
            }
            wp_set_object_terms($postId, $termIds, $taxonomy, false);
        }
    }

    /** @param array<string, mixed> $language */
    private function applyLanguage(int $postId, array $language, string $postType): void
    {
        if (!$language || !defined('ICL_SITEPRESS_VERSION')) {
            return;
        }
        do_action('wpml_set_element_language_details', [
            'element_id' => $postId,
            'element_type' => 'post_' . $postType,
            'trid' => false,
            'language_code' => $language['code'] ?? null,
            'source_language_code' => $language['source_code'] ?? null,
        ]);
    }

    /** @param mixed $value @param array<string, string> $replace @return mixed */
    private function replaceUrls(mixed $value, array $replace): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->replaceUrls($item, $replace), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        foreach ($replace as $old => $new) {
            if ($old !== '' && $new !== '') {
                $value = str_replace($old, $new, $value);
            }
        }
        return $value;
    }

    /** @param mixed $value @param array<int, int> $map @return mixed */
    private function replaceMediaReferences(mixed $value, array $map): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->replaceMediaReferences($item, $map), $value);
        }
        if (is_int($value) && isset($map[$value])) {
            return $map[$value];
        }
        if (!is_string($value)) {
            return $value;
        }
        if (ctype_digit($value) && isset($map[(int) $value])) {
            return (string) $map[(int) $value];
        }
        foreach ($map as $source => $target) {
            $value = str_replace('wp-image-' . $source, 'wp-image-' . $target, $value);
        }
        return $value;
    }

    private function isProtectedMeta(string $key): bool
    {
        $protected = $key === '_syncport_uuid'
            || $key === '_edit_lock'
            || $key === '_edit_last'
            || str_starts_with($key, '_wpml_')
            || str_starts_with($key, '_icl_');

        return (bool) apply_filters('syncport_protected_post_meta', $protected, $key);
    }
}
