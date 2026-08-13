<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

use WP_Post;

final class ManifestBuilder
{
    private const UUID_META = '_syncport_uuid';

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function build(array $request): array
    {
        $scope = sanitize_key((string) ($request['scope'] ?? 'content'));
        $manifest = [
            'schema' => 1,
            'id' => wp_generate_uuid4(),
            'created_at' => gmdate(DATE_ATOM),
            'source' => [
                'url' => home_url(),
                'name' => get_bloginfo('name'),
                'wordpress' => get_bloginfo('version'),
                'syncport' => SYNCPORT_VERSION,
            ],
            'scope' => $scope,
            'replace' => [home_url() => (string) ($request['target_url'] ?? '')],
        ];

        if ($scope === 'database') {
            $manifest['tables'] = $this->tables((array) ($request['tables'] ?? []));
        } elseif ($scope === 'options') {
            $manifest['options'] = $this->options((array) ($request['options'] ?? []));
        } else {
            $manifest['posts'] = $this->posts($request);
        }

        $manifest['checksum'] = hash('sha256', (string) wp_json_encode($manifest));
        return $manifest;
    }

    /** @param array<string, mixed> $request @return array<int, array<string, mixed>> */
    private function posts(array $request): array
    {
        $ids = array_values(array_filter(array_map('absint', (array) ($request['post_ids'] ?? []))));
        $postTypes = array_values(array_filter(array_map('sanitize_key', (array) ($request['post_types'] ?? ['page']))));
        $args = [
            'post_type' => $postTypes ?: ['page'],
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'post__in' => $ids,
            'suppress_filters' => false,
        ];
        if ($ids === []) {
            unset($args['post__in']);
        }

        $posts = get_posts($args);
        return array_map(fn (WP_Post $post): array => $this->serializePost($post), $posts);
    }

    /** @return array<string, mixed> */
    private function serializePost(WP_Post $post): array
    {
        $uuid = (string) get_post_meta($post->ID, self::UUID_META, true);
        if ($uuid === '') {
            $uuid = wp_generate_uuid4();
            update_post_meta($post->ID, self::UUID_META, $uuid);
        }

        $author = get_userdata((int) $post->post_author);
        $meta = get_post_meta($post->ID);
        unset($meta['_edit_lock'], $meta['_edit_last'], $meta['_wp_old_slug']);

        $terms = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $assigned = wp_get_object_terms($post->ID, $taxonomy);
            if (is_wp_error($assigned)) {
                continue;
            }
            $terms[$taxonomy] = array_map(static fn ($term): array => [
                'slug' => $term->slug,
                'name' => $term->name,
                'description' => $term->description,
                'parent_slug' => $term->parent ? (string) get_term_field('slug', $term->parent, $taxonomy) : '',
            ], $assigned);
        }

        $media = $this->media($post, $meta);
        $data = [
            'uuid' => $uuid,
            'source_id' => $post->ID,
            'post' => [
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_title' => $post->post_title,
                'post_name' => $post->post_name,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_date' => $post->post_date,
                'post_date_gmt' => $post->post_date_gmt,
                'post_modified' => $post->post_modified,
                'post_modified_gmt' => $post->post_modified_gmt,
                'menu_order' => $post->menu_order,
                'comment_status' => $post->comment_status,
                'ping_status' => $post->ping_status,
                'post_password' => $post->post_password,
            ],
            'author' => $author ? ['email' => $author->user_email, 'login' => $author->user_login] : null,
            'meta' => $meta,
            'terms' => $terms,
            'language' => $this->language($post->ID),
            'media' => $media,
        ];
        $data['hash'] = $this->semanticHash($data);
        return $data;
    }

    /** @return array<string, mixed>|null */
    private function language(int $postId): ?array
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return null;
        }
        $details = apply_filters('wpml_post_language_details', null, $postId);
        return is_array($details) ? [
            'code' => $details['language_code'] ?? null,
            'source_code' => $details['source_language_code'] ?? null,
            'trid' => apply_filters('wpml_element_trid', null, $postId, 'post_' . get_post_type($postId)),
        ] : null;
    }

    /** @param array<string, mixed> $meta @return array<int, array<string, mixed>> */
    private function media(WP_Post $post, array $meta): array
    {
        $ids = [];
        $thumbnail = get_post_thumbnail_id($post);
        if ($thumbnail) {
            $ids[] = $thumbnail;
        }
        if (preg_match_all('/wp-image-(\d+)/', $post->post_content, $matches)) {
            $ids = array_merge($ids, array_map('absint', $matches[1]));
        }
        array_walk_recursive($meta, static function ($value) use (&$ids): void {
            if (is_numeric($value) && get_post_type((int) $value) === 'attachment') {
                $ids[] = (int) $value;
            }
        });

        $items = [];
        foreach (array_unique($ids) as $id) {
            $path = get_attached_file($id);
            if (!$path || !is_readable($path)) {
                continue;
            }
            $uuid = (string) get_post_meta($id, self::UUID_META, true);
            if ($uuid === '') {
                $uuid = wp_generate_uuid4();
                update_post_meta($id, self::UUID_META, $uuid);
            }
            $items[] = [
                'uuid' => $uuid,
                'source_id' => $id,
                'filename' => basename($path),
                'mime_type' => get_post_mime_type($id),
                'url' => wp_get_attachment_url($id),
                'size' => filesize($path),
                'sha256' => hash_file('sha256', $path),
            ];
        }
        return $items;
    }

    /** @param array<int, mixed> $names @return array<string, mixed> */
    private function options(array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            $name = sanitize_key((string) $name);
            if ($name !== '') {
                $values[$name] = get_option($name);
                $reference = '_' . $name;
                if (get_option($reference, null) !== null) {
                    $values[$reference] = get_option($reference);
                }
            }
        }
        return $values;
    }

    /** @param array<int, mixed> $selected @return array<int, array<string, mixed>> */
    private function tables(array $selected): array
    {
        global $wpdb;
        $allowed = array_map(static fn ($row): string => (string) $row[0], $wpdb->get_results('SHOW FULL TABLES', ARRAY_N));
        $selected = $selected === [] ? $allowed : array_intersect(array_map('sanitize_text_field', $selected), $allowed);
        $tables = [];
        foreach ($selected as $table) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($table) . "`");
            $tables[] = ['name' => $table, 'rows' => $count];
        }
        return $tables;
    }

    /** @param array<string, mixed> $data */
    private function semanticHash(array $data): string
    {
        $mediaMap = [];
        $canonicalMedia = [];
        foreach ((array) ($data['media'] ?? []) as $media) {
            $mediaMap[(int) $media['source_id']] = 'media:' . (string) $media['sha256'];
            $canonicalMedia[] = [
                'uuid' => $media['uuid'],
                'sha256' => $media['sha256'],
                'filename' => $media['filename'],
                'mime_type' => $media['mime_type'],
            ];
        }
        usort($canonicalMedia, static fn (array $a, array $b): int => strcmp((string) $a['uuid'], (string) $b['uuid']));

        $canonical = [
            'post' => $this->canonicalValue($data['post'] ?? [], $mediaMap),
            'author' => $data['author'] ?? null,
            'meta' => $this->canonicalValue($data['meta'] ?? [], $mediaMap),
            'terms' => $data['terms'] ?? [],
            'language' => [
                'code' => $data['language']['code'] ?? null,
                'source_code' => $data['language']['source_code'] ?? null,
            ],
            'media' => $canonicalMedia,
        ];
        return hash('sha256', (string) wp_json_encode($canonical));
    }

    /** @param mixed $value @param array<int, string> $mediaMap @return mixed */
    private function canonicalValue(mixed $value, array $mediaMap): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->canonicalValue($item, $mediaMap);
            }
            return $result;
        }
        if (is_int($value) && isset($mediaMap[$value])) {
            return $mediaMap[$value];
        }
        if (!is_string($value)) {
            return $value;
        }
        if (ctype_digit($value) && isset($mediaMap[(int) $value])) {
            return $mediaMap[(int) $value];
        }
        foreach ($mediaMap as $sourceId => $marker) {
            $value = str_replace('wp-image-' . $sourceId, 'wp-image-' . $marker, $value);
        }
        return str_replace([home_url(), untrailingslashit(home_url())], '{site_url}', $value);
    }
}
