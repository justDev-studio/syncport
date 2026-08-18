<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Security;

use WP_Error;
use WP_REST_Request;

final class RequestAuthenticator
{
    public function verify(WP_REST_Request $request): bool|WP_Error
    {
        $timestamp = (string) $request->get_header('x-syncport-timestamp');
        $nonce = (string) $request->get_header('x-syncport-nonce');
        $signature = (string) $request->get_header('x-syncport-signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return new WP_Error('syncport_missing_signature', __('Missing SyncPort signature.', 'syncport'), ['status' => 401]);
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('syncport_expired_signature', __('Expired SyncPort signature.', 'syncport'), ['status' => 401]);
        }

        $nonceKey = 'syncport_nonce_' . hash('sha256', $nonce);
        if (get_transient($nonceKey)) {
            return new WP_Error('syncport_replayed_request', __('This request has already been used.', 'syncport'), ['status' => 409]);
        }

        $key = (string) get_option('syncport_api_key', '');
        $canonical = (new RequestSigner())->canonical(
            $request->get_method(),
            $request->get_route(),
            $timestamp,
            $nonce,
            $request->get_body()
        );
        $expected = hash_hmac('sha256', $canonical, $key);
        if ($key === '' || !hash_equals($expected, $signature)) {
            return new WP_Error('syncport_invalid_signature', __('Invalid SyncPort signature.', 'syncport'), ['status' => 401]);
        }

        set_transient($nonceKey, 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }
}
