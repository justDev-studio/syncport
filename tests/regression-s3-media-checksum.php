<?php

declare(strict_types=1);

final class WP_Post
{
    public int $ID = 17;
    public string $post_content = '';
}

final class WP_Error
{
    public function __construct(private string $code, private string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

$wordpressRoot = sys_get_temp_dir() . '/syncport-regression-' . getmypid() . '/';
foreach (['file.php', 'media.php', 'image.php'] as $include) {
    $directory = $wordpressRoot . 'wp-admin/includes/';
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    touch($directory . $include);
}
define('ABSPATH', $wordpressRoot);
register_shutdown_function(static function () use ($wordpressRoot): void {
    foreach (['file.php', 'media.php', 'image.php'] as $include) {
        @unlink($wordpressRoot . 'wp-admin/includes/' . $include);
    }
    @rmdir($wordpressRoot . 'wp-admin/includes');
    @rmdir($wordpressRoot . 'wp-admin');
    @rmdir($wordpressRoot);
});

function absint(mixed $value): int { return abs((int) $value); }
function __(string $message): string { return $message; }
function esc_url_raw(string $url): string { return $url; }
function wp_http_validate_url(string $url): bool { return str_starts_with($url, 'https://'); }
function sanitize_text_field(string $value): string { return $value; }
function sanitize_file_name(string $value): string { return $value; }
function get_post_thumbnail_id(WP_Post $post): int { return 91; }
function get_attached_file(int $id): false { return false; }
function wp_get_attachment_url(int $id): string { return 'https://bucket.example/media/photo.jpg'; }
function wp_get_attachment_metadata(int $id): array { return ['filesize' => 16]; }
function wp_parse_url(string $url, int $component): string { return (string) parse_url($url, $component); }
function get_post_mime_type(int $id): string { return 'image/jpeg'; }
function get_post_type(int $id): string { return 'attachment'; }
function is_serialized(mixed $value): bool { return false; }
function maybe_unserialize(mixed $value): mixed { return $value; }
function get_posts(array $args): array { return []; }
function update_post_meta(int $id, string $key, mixed $value): void {}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_delete_file(string $path): void { @unlink($path); }
function download_url(string $url, int $timeout): string
{
    $path = tempnam(sys_get_temp_dir(), 'syncport-s3-');
    file_put_contents($path, 'actual-s3-object');
    return $path;
}
function media_handle_sideload(array $file, int $postId): int
{
    @unlink($file['tmp_name']);
    return 301;
}
function get_post_meta(int $id, string $key, bool $single = false): string
{
    return match ($key) {
        '_syncport_uuid' => '8cc61124-94df-44ec-a8ec-7dc78055af4d',
        '_syncport_sha256' => hash('sha256', 'old-local-object'),
        default => '',
    };
}

require dirname(__DIR__) . '/src/Migration/ManifestBuilder.php';
require dirname(__DIR__) . '/src/Migration/MediaImporter.php';

$builder = new JustDev\SyncPort\Migration\ManifestBuilder();
$method = new ReflectionMethod($builder, 'media');
$media = $method->invoke($builder, new WP_Post(), []);
$result = (new JustDev\SyncPort\Migration\MediaImporter())->import($media);

if ($result['errors'] !== []) {
    fwrite(STDERR, $result['errors'][0]['message'] . PHP_EOL);
    exit(1);
}
if (($media[0]['sha256'] ?? null) !== '') {
    fwrite(STDERR, "Remote-only S3 media must not reuse a cached checksum.\n");
    exit(1);
}

echo "S3 media imports without a stale checksum mismatch.\n";
