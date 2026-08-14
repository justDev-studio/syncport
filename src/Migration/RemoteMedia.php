<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class RemoteMedia
{
    public function register(): void
    {
        add_filter('wp_get_attachment_url', [$this, 'attachmentUrl'], 20, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'imageSrcset'], 20, 5);
    }

    public function attachmentUrl(string $url, int $attachmentId): string
    {
        $remoteUrl = get_post_meta($attachmentId, '_syncport_remote_url', true);
        return is_string($remoteUrl) && $remoteUrl !== '' ? $remoteUrl : $url;
    }

    /** @param array<int, array<string, mixed>> $sources @return array<int, array<string, mixed>> */
    public function imageSrcset(array $sources, array $size, string $imageSrc, array $metadata, int $attachmentId): array
    {
        $remoteUrl = $this->attachmentUrl('', $attachmentId);
        $parts = wp_parse_url($remoteUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return $sources;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $directory = trailingslashit(dirname((string) $parts['path']));
        $baseUrl = $parts['scheme'] . '://' . $parts['host'] . $port . $directory;
        foreach ($sources as &$source) {
            $path = (string) wp_parse_url((string) ($source['url'] ?? ''), PHP_URL_PATH);
            if ($path !== '') {
                $source['url'] = $baseUrl . wp_basename($path);
            }
        }
        unset($source);
        return $sources;
    }
}
