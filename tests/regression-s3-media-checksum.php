<?php

declare(strict_types=1);

final class WP_Post
{
    public int $ID = 17;
    public string $post_content = '';
    public string $post_title = 'Photo';
    public string $post_name = 'photo';
    public string $post_excerpt = 'Caption';
    public string $post_date = '2026-08-14 08:00:00';
    public string $post_date_gmt = '2026-08-14 06:00:00';
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
    @unlink($wordpressRoot . 'local-photo.jpg');
    foreach (['file.php', 'media.php', 'image.php'] as $include) {
        @unlink($wordpressRoot . 'wp-admin/includes/' . $include);
    }
    @rmdir($wordpressRoot . 'wp-admin/includes');
    @rmdir($wordpressRoot . 'wp-admin');
    @rmdir($wordpressRoot);
});
$GLOBALS['syncportAttachedFile'] = false;
$GLOBALS['syncportAttachmentUrl'] = 'https://bucket.example/media/photo.jpg';
$GLOBALS['syncportDownloadCalls'] = 0;
$GLOBALS['syncportInsertedAttachments'] = [];
$GLOBALS['syncportUpdatedMeta'] = [];
$GLOBALS['syncportAttachmentMetadata'] = [];
$GLOBALS['syncportExistingAttachedFileId'] = 0;

function absint(mixed $value): int { return abs((int) $value); }
function __(string $message): string { return $message; }
function esc_url_raw(string $url): string { return $url; }
function wp_http_validate_url(string $url): bool { return str_starts_with($url, 'https://'); }
function sanitize_text_field(string $value): string { return $value; }
function sanitize_textarea_field(string $value): string { return $value; }
function sanitize_file_name(string $value): string { return $value; }
function sanitize_key(string $value): string { return $value; }
function sanitize_title(string $value): string { return $value; }
function wp_kses_post(string $value): string { return $value; }
function wp_slash(array $value): array { return $value; }
function home_url(): string { return 'https://source.example'; }
function get_post_thumbnail_id(WP_Post $post): int { return 91; }
function get_attached_file(int $id): string|false { return $GLOBALS['syncportAttachedFile']; }
function wp_get_attachment_url(int $id): string { return $GLOBALS['syncportAttachmentUrl']; }
function wp_get_attachment_metadata(int $id): array
{
    return [
        'file' => '2026/08/photo.jpg',
        'filesize' => 16,
        'width' => 1200,
        'height' => 800,
        'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150]],
    ];
}
function wp_parse_url(string $url, int $component): string { return (string) parse_url($url, $component); }
function get_post(int $id): WP_Post { return new WP_Post(); }
function get_post_mime_type(int $id): string { return 'image/jpeg'; }
function get_post_type(int $id): string { return 'attachment'; }
function is_serialized(mixed $value): bool { return false; }
function maybe_unserialize(mixed $value): mixed { return $value; }
function get_posts(array $args): array
{
    if (($args['meta_key'] ?? '') === '_wp_attached_file' && ($args['meta_value'] ?? '') === '2026/08/photo.jpg') {
        $id = (int) $GLOBALS['syncportExistingAttachedFileId'];
        return $id ? [$id] : [];
    }
    return [];
}
function update_post_meta(int $id, string $key, mixed $value): void
{
    $GLOBALS['syncportUpdatedMeta'][$id][$key] = $value;
}
function wp_insert_attachment(array $attachment, false $file, int $parentId, bool $wpError): int
{
    $GLOBALS['syncportInsertedAttachments'][501] = $attachment;
    return 501;
}
function wp_update_attachment_metadata(int $id, array $metadata): bool
{
    $GLOBALS['syncportAttachmentMetadata'][$id] = $metadata;
    return true;
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_delete_file(string $path): void { @unlink($path); }
function download_url(string $url, int $timeout): string
{
    $GLOBALS['syncportDownloadCalls']++;
    $path = tempnam(sys_get_temp_dir(), 'syncport-s3-');
    file_put_contents($path, 'actual-s3-object');
    return $path;
}
function media_handle_sideload(array $file, int $postId): int
{
    @unlink($file['tmp_name']);
    return 301;
}
function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    if (isset($GLOBALS['syncportUpdatedMeta'][$id][$key])) {
        return $GLOBALS['syncportUpdatedMeta'][$id][$key];
    }
    return match ($key) {
        '_syncport_uuid' => '8cc61124-94df-44ec-a8ec-7dc78055af4d',
        '_syncport_sha256' => hash('sha256', 'old-local-object'),
        '_wp_attached_file' => '2026/08/photo.jpg',
        '_wp_attachment_image_alt' => 'Photo alt',
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

$legacyMedia = $media;
$legacyMedia[0]['sha256'] = hash('sha256', 'old-local-object');
unset($legacyMedia[0]['checksum_verified'], $legacyMedia[0]['storage']);
$legacyResult = (new JustDev\SyncPort\Migration\MediaImporter())->import($legacyMedia);
if ($legacyResult['errors'] !== []) {
    fwrite(STDERR, $legacyResult['errors'][0]['message'] . PHP_EOL);
    exit(1);
}

$verifiedMedia = $media;
$verifiedMedia[0]['sha256'] = hash('sha256', 'old-local-object');
$verifiedMedia[0]['checksum_verified'] = true;
$verifiedMedia[0]['storage'] = 'local';
$verifiedResult = (new JustDev\SyncPort\Migration\MediaImporter())->import($verifiedMedia);
if (($verifiedResult['errors'][0]['message'] ?? '') !== 'The downloaded media checksum does not match the manifest.') {
    fwrite(STDERR, "Verified media checksum mismatches must still fail.\n");
    exit(1);
}

$GLOBALS['syncportAttachedFile'] = $wordpressRoot . 'local-photo.jpg';
file_put_contents($GLOBALS['syncportAttachedFile'], 'old-local-object');
$offloadedMedia = $method->invoke($builder, new WP_Post(), []);
$downloadCalls = $GLOBALS['syncportDownloadCalls'];
$offloadedResult = (new JustDev\SyncPort\Migration\MediaImporter())->import($offloadedMedia);
if ($offloadedResult['errors'] !== []) {
    fwrite(STDERR, $offloadedResult['errors'][0]['message'] . PHP_EOL);
    exit(1);
}
if (($offloadedMedia[0]['checksum_verified'] ?? null) !== false) {
    fwrite(STDERR, "An S3 URL must not trust the checksum of a local copy.\n");
    exit(1);
}
if ($GLOBALS['syncportDownloadCalls'] !== $downloadCalls) {
    fwrite(STDERR, "An S3 attachment must be registered without downloading its bytes.\n");
    exit(1);
}
if (($offloadedResult['map'][91] ?? 0) !== 501) {
    fwrite(STDERR, "An S3 attachment must map to its new database record.\n");
    exit(1);
}
if (($GLOBALS['syncportUpdatedMeta'][501]['_syncport_remote_url'] ?? '') !== 'https://bucket.example/media/photo.jpg') {
    fwrite(STDERR, "An S3 attachment must retain its remote URL.\n");
    exit(1);
}
if (($GLOBALS['syncportAttachmentMetadata'][501]['sizes']['thumbnail']['file'] ?? '') !== 'photo-150x150.jpg') {
    fwrite(STDERR, "An S3 attachment must retain its image metadata.\n");
    exit(1);
}

$GLOBALS['syncportExistingAttachedFileId'] = 777;
$insertedAttachments = count($GLOBALS['syncportInsertedAttachments']);
$downloadCalls = $GLOBALS['syncportDownloadCalls'];
$existingResult = (new JustDev\SyncPort\Migration\MediaImporter())->import($offloadedMedia);
if (($existingResult['map'][91] ?? 0) !== 777) {
    fwrite(STDERR, "An existing S3 attachment must be reused by its object key.\n");
    exit(1);
}
if (count($GLOBALS['syncportInsertedAttachments']) !== $insertedAttachments || $GLOBALS['syncportDownloadCalls'] !== $downloadCalls) {
    fwrite(STDERR, "An existing S3 attachment must not be recreated or downloaded.\n");
    exit(1);
}

$GLOBALS['syncportAttachmentUrl'] = 'https://source.example/wp-content/uploads/photo.jpg';
$localMedia = $method->invoke($builder, new WP_Post(), []);
if (($localMedia[0]['checksum_verified'] ?? null) !== true) {
    fwrite(STDERR, "A same-site media URL must preserve local checksum verification.\n");
    exit(1);
}
if (($localMedia[0]['sha256'] ?? '') !== hash('sha256', 'old-local-object')) {
    fwrite(STDERR, "A same-site media URL must use the local file checksum.\n");
    exit(1);
}

echo "S3 and legacy media import without a stale checksum mismatch.\n";
