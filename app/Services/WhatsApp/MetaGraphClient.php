<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Read-only calls to Meta's Graph API used by the settings panel. None of them
 * sends a message (AC-WA-21).
 *
 * Errors are rethrown as MetaGraphException with a fixed, secret-free message:
 * HTTP client errors can echo the request URL, and debug_token carries the
 * token in its query string (REQ-WA-19).
 */
class MetaGraphClient
{
    /** Phone number fields read by the connection test (PND-WA-04). */
    public const PHONE_NUMBER_FIELDS = 'display_phone_number,verified_name,quality_rating,code_verification_status';

    /** Template fields read by the connection test (PND-WA-04). */
    public const TEMPLATE_FIELDS = 'name,language,status,category';

    /** Template fields read by the readiness checks, components included (REQ-WA-24, PND-WA-04). */
    public const TEMPLATE_FIELDS_WITH_COMPONENTS = 'name,language,status,category,components';

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

    /**
     * The phone number registered under a Phone Number ID (REQ-WA-35).
     *
     * @return array{display_phone_number: ?string, verified_name: ?string, quality_rating: ?string, code_verification_status: ?string}
     *
     * @throws MetaGraphException
     */
    public function phoneNumber(string $apiVersion, #[SensitiveParameter] string $token, string $phoneNumberId): array
    {
        $data = $this->get($apiVersion, $token, $phoneNumberId, ['fields' => self::PHONE_NUMBER_FIELDS])->json();

        return [
            'display_phone_number' => $this->text($data['display_phone_number'] ?? null),
            'verified_name' => $this->text($data['verified_name'] ?? null),
            'quality_rating' => $this->text($data['quality_rating'] ?? null),
            'code_verification_status' => $this->text($data['code_verification_status'] ?? null),
        ];
    }

    /**
     * Every language version of a template in a WhatsApp Business Account (REQ-WA-35).
     * With $withComponents, each version also carries its components as Meta returned
     * them, or null when Meta returned none (REQ-WA-24).
     *
     * @return list<array{name: ?string, language: ?string, status: ?string, category: ?string, components?: ?list<array<string, mixed>>}>
     *
     * @throws MetaGraphException
     */
    public function templates(string $apiVersion, #[SensitiveParameter] string $token, string $wabaId, string $name, bool $withComponents = false): array
    {
        $fields = $withComponents ? self::TEMPLATE_FIELDS_WITH_COMPONENTS : self::TEMPLATE_FIELDS;
        $data = $this->get($apiVersion, $token, "{$wabaId}/message_templates", ['name' => $name, 'fields' => $fields])->json('data');

        return collect(is_array($data) ? $data : [])
            ->filter(fn ($template) => is_array($template))
            ->map(fn (array $template) => [
                'name' => $this->text($template['name'] ?? null),
                'language' => $this->text($template['language'] ?? null),
                'status' => $this->text($template['status'] ?? null),
                'category' => $this->text($template['category'] ?? null),
            ] + ($withComponents ? ['components' => $this->components($template['components'] ?? null)] : []))
            // Meta matches names loosely: keep the exact name only.
            ->filter(fn (array $template) => $template['name'] === $name)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws MetaGraphException
     */
    private function get(string $apiVersion, #[SensitiveParameter] string $token, string $path, array $query): Response
    {
        try {
            $response = Http::baseUrl("https://graph.facebook.com/{$apiVersion}")
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->get($path, $query);
        } catch (Throwable) {
            throw new MetaGraphException('Meta could not be reached.');
        }

        if ($response->failed()) {
            $code = $response->json('error.code');

            throw new MetaGraphException('Meta refused the request (HTTP '.$response->status().(is_scalar($code) ? ', error '.(int) $code : '').').');
        }

        return $response;
    }

    /**
     * @return ?list<array<string, mixed>>
     */
    private function components(mixed $components): ?array
    {
        if (! is_array($components) || ! array_is_list($components)) {
            return null;
        }

        return array_values(array_filter($components, 'is_array'));
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 120) : null;
    }
}
