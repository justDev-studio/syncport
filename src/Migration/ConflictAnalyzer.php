<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class ConflictAnalyzer
{
    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function analyze(array $manifest): array
    {
        $conflicts = [];
        $ready = [];

        foreach ((array) ($manifest['posts'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $match = $this->match($item);
            if (!$match) {
                $ready[] = ['uuid' => $item['uuid'] ?? '', 'action' => 'create'];
                continue;
            }
            $localHash = $this->localHash($match);
            if (hash_equals((string) ($item['hash'] ?? ''), $localHash)) {
                $ready[] = ['uuid' => $item['uuid'] ?? '', 'action' => 'skip', 'target_id' => $match];
                continue;
            }
            $conflicts[] = [
                'uuid' => $item['uuid'] ?? '',
                'source_id' => $item['source_id'] ?? 0,
                'source_title' => $item['post']['post_title'] ?? '',
                'target_id' => $match,
                'target_title' => get_the_title($match),
                'match' => get_post_meta($match, '_syncport_uuid', true) === ($item['uuid'] ?? '') ? 'uuid' : 'slug',
                'actions' => ['replace', 'skip', 'duplicate'],
            ];
        }

        return [
            'compatible' => $this->compatible($manifest),
            'conflicts' => $conflicts,
            'ready' => $ready,
            'summary' => [
                'posts' => count((array) ($manifest['posts'] ?? [])),
                'options' => count((array) ($manifest['options'] ?? [])),
                'tables' => count((array) ($manifest['tables'] ?? [])),
                'conflicts' => count($conflicts),
            ],
        ];
    }

    /** @param array<string, mixed> $item */
    private function match(array $item): int
    {
        $uuidMatches = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_syncport_uuid',
            'meta_value' => sanitize_text_field((string) ($item['uuid'] ?? '')),
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);
        if ($uuidMatches) {
            return (int) $uuidMatches[0];
        }

        $post = $item['post'] ?? [];
        $slugMatch = get_page_by_path((string) ($post['post_name'] ?? ''), OBJECT, (string) ($post['post_type'] ?? 'page'));
        return $slugMatch ? (int) $slugMatch->ID : 0;
    }

    private function localHash(int $postId): string
    {
        $postType = get_post_type($postId);
        $manifest = (new ManifestBuilder())->build([
            'scope' => 'content',
            'post_ids' => [$postId],
            'post_types' => [$postType ?: 'post'],
        ]);
        return (string) ($manifest['posts'][0]['hash'] ?? '');
    }

    /** @param array<string, mixed> $manifest @return array<int, string> */
    private function compatible(array $manifest): array
    {
        $issues = [];
        $sourceVersion = (string) ($manifest['source']['syncport'] ?? '0.0.0');
        if ((int) $sourceVersion !== (int) SYNCPORT_VERSION) {
            $issues[] = __('The sites use incompatible major SyncPort versions.', 'syncport');
        }
        foreach ((array) ($manifest['posts'] ?? []) as $item) {
            $postType = (string) ($item['post']['post_type'] ?? '');
            if ($postType && !post_type_exists($postType)) {
                $issues[] = sprintf(__('Post type “%s” is not registered on the target site.', 'syncport'), $postType);
            }
            if (!empty($item['language']) && !defined('ICL_SITEPRESS_VERSION')) {
                $issues[] = __('WPML is required for one or more selected entities.', 'syncport');
            }
        }
        foreach ((array) ($manifest['options'] ?? []) as $name => $value) {
            if (!str_starts_with((string) $name, '_') || !is_string($value) || !str_starts_with($value, 'field_')) {
                continue;
            }
            $targetReference = get_option((string) $name, null);
            if ($targetReference !== null && $targetReference !== $value) {
                $issues[] = sprintf(__('ACF field reference “%s” differs on the target site.', 'syncport'), ltrim((string) $name, '_'));
            }
        }
        return array_values(array_unique($issues));
    }
}
