<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Http;

use JustDev\SyncPort\Security\RequestSigner;
use WP_Error;

final class RemoteClient
{
    public function __construct(private readonly RequestSigner $signer)
    {
    }

    /** @param array<string, mixed> $connection @param array<string, mixed> $payload @return array<string, mixed>|WP_Error */
    public function post(array $connection, string $endpoint, array $payload): array|WP_Error
    {
        $route = '/syncport/v1/' . ltrim($endpoint, '/');
        $body = (string) wp_json_encode($payload);
        $url = untrailingslashit((string) $connection['url']) . '/wp-json' . $route;
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 120,
                'headers' => $this->signer->headers('POST', $route, $body, (string) $connection['key']),
                'body' => $body,
                'data_format' => 'body',
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            return new WP_Error('syncport_remote_error', __('The remote site returned an invalid response.', 'syncport'), [
                'status' => $status,
                'body' => wp_remote_retrieve_body($response),
            ]);
        }

        return $decoded;
    }
}
