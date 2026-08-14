<?php

declare(strict_types=1);

$GLOBALS['syncportFilters'] = [];

function add_filter(string $hook, callable $callback, int $priority, int $arguments): void
{
    $GLOBALS['syncportFilters'][$hook] = [$callback, $priority, $arguments];
}

function get_post_meta(int $id, string $key, bool $single = false): string
{
    return $id === 501 && $key === '_syncport_remote_url'
        ? 'https://bucket.example/media/photo.jpg'
        : '';
}

function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
{
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function trailingslashit(string $value): string
{
    return rtrim($value, '/\\') . '/';
}

function wp_basename(string $path): string
{
    return basename($path);
}

require dirname(__DIR__) . '/src/Migration/RemoteMedia.php';

$remoteMedia = new JustDev\SyncPort\Migration\RemoteMedia();
$remoteMedia->register();
if (!isset($GLOBALS['syncportFilters']['wp_get_attachment_url'], $GLOBALS['syncportFilters']['wp_calculate_image_srcset'])) {
    fwrite(STDERR, "Remote media URL filters were not registered.\n");
    exit(1);
}
if ($remoteMedia->attachmentUrl('https://target.example/uploads/photo.jpg', 501) !== 'https://bucket.example/media/photo.jpg') {
    fwrite(STDERR, "The attachment URL does not point to S3.\n");
    exit(1);
}
if ($remoteMedia->attachmentUrl('https://target.example/uploads/local.jpg', 502) !== 'https://target.example/uploads/local.jpg') {
    fwrite(STDERR, "A local attachment URL was unexpectedly replaced.\n");
    exit(1);
}

$sources = $remoteMedia->imageSrcset([
    150 => [
        'url' => 'https://target.example/wp-content/uploads/2026/08/photo-150x150.jpg',
        'descriptor' => 'w',
        'value' => 150,
    ],
], [150, 150], '', [], 501);
if (($sources[150]['url'] ?? '') !== 'https://bucket.example/media/photo-150x150.jpg') {
    fwrite(STDERR, "The responsive image URL does not point to S3.\n");
    exit(1);
}

echo "Remote attachment and responsive image URLs point to S3.\n";

