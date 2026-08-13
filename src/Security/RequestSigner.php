<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Security;

final class RequestSigner
{
    /** @return array<string, string> */
    public function headers(string $method, string $route, string $body, string $key): array
    {
        $timestamp = (string) time();
        $nonce = wp_generate_password(24, false, false);
        $canonical = $this->canonical($method, $route, $timestamp, $nonce, $body);

        return [
            'Content-Type' => 'application/json',
            'X-SyncPort-Timestamp' => $timestamp,
            'X-SyncPort-Nonce' => $nonce,
            'X-SyncPort-Signature' => hash_hmac('sha256', $canonical, $key),
            'X-SyncPort-Version' => SYNCPORT_VERSION,
        ];
    }

    public function canonical(string $method, string $route, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [strtoupper($method), $route, $timestamp, $nonce, hash('sha256', $body)]);
    }
}
