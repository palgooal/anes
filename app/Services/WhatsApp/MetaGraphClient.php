<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Calls to Meta's Graph API used by the settings panel.
 *
 * Errors are rethrown as MetaGraphException with a fixed, secret-free message:
 * HTTP client errors can echo the request URL, and debug_token carries the
 * token in its query string (REQ-WA-19).
 */
class MetaGraphClient
{
    /**
     * Inspect an access token with debug_token (REQ-WA-20). The token inspects itself.
     *
     * @throws MetaGraphException when Meta cannot be reached or does not answer with token data.
     */
    public function inspectToken(string $apiVersion, #[SensitiveParameter] string $token): TokenInspection
    {
        try {
            $response = Http::baseUrl("https://graph.facebook.com/{$apiVersion}")
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->get('debug_token', ['input_token' => $token]);
        } catch (Throwable) {
            throw new MetaGraphException('Meta could not be reached to check the access token.');
        }

        $data = $response->json('data');

        if ($response->failed() || ! is_array($data)) {
            throw new MetaGraphException('Meta did not confirm the access token (HTTP '.$response->status().').');
        }

        return TokenInspection::fromDebugToken($data);
    }
}
