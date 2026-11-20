<?php

namespace App\Services\WhatsApp;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;

/**
 * Administrators' test codes (REQ-WA-36, REQ-WA-37, AC-WA-43).
 *
 * Never stored in one_time_codes. Each scope (an administrator, or one quota of
 * whatsapp:test) holds at most one code, kept only as a keyed hash in the cache
 * for 5 minutes. A code allows 5 checks and is discarded after a correct one,
 * when it expires, or once the checks run out.
 */
final class AdminTestCodes
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public const VERIFIED = 'verified';

    public const WRONG = 'wrong';

    public const EXPIRED = 'expired';

    public const EXHAUSTED = 'exhausted';

    /** No test code is waiting in this scope. */
    public const MISSING = 'missing';

    /** @var (callable(): string)|null Tests only: makes the generated code predictable. */
    private static $generator = null;

    /**
     * Tests only: generate codes with the given callback (null restores random codes).
     */
    public static function generateUsing(?callable $generator): void
    {
        self::$generator = $generator;
    }

    /**
     * Create a code for the scope, replacing any earlier one. Returns the plain
     * code: it goes straight into the message and is never shown or logged.
     *
     * @param  array<string, string|null>  $context  Secret-free details kept with the code (environment, last 4 digits).
     */
    public function issue(string $scope, array $context): string
    {
        $length = (int) config('auth.one_time_codes.length', 6);
        $code = self::$generator
            ? (string) (self::$generator)()
            : str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        $this->store()->put($this->key($scope), [
            'hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
            'context' => $context,
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * The waiting code's details, without its hash, or null.
     *
     * @return array{expires_at: int, attempts_left: int, context: array<string, string|null>}|null
     */
    public function pending(string $scope): ?array
    {
        $entry = $this->store()->get($this->key($scope));

        if (! is_array($entry) || $entry['expires_at'] <= now()->getTimestamp()) {
            return null;
        }

        return [
            'expires_at' => $entry['expires_at'],
            'attempts_left' => self::MAX_ATTEMPTS - $entry['attempts'],
            'context' => $entry['context'],
        ];
    }

    /**
     * Check a code typed by the person who received it.
     *
     * @return array{outcome: string, context: array<string, string|null>|null, attempts_left: int}
     */
    public function verify(string $scope, #[SensitiveParameter] string $input): array
    {
        return $this->store()->lock($this->key($scope).':lock', 5)->block(3, function () use ($scope, $input) {
            $key = $this->key($scope);
            $entry = $this->store()->get($key);

            if (! is_array($entry)) {
                return ['outcome' => self::MISSING, 'context' => null, 'attempts_left' => 0];
            }

            if ($entry['expires_at'] <= now()->getTimestamp()) {
                $this->store()->forget($key);

                return ['outcome' => self::EXPIRED, 'context' => $entry['context'], 'attempts_left' => 0];
            }

            if (hash_equals($entry['hash'], $this->hash(trim($input)))) {
                $this->store()->forget($key);

                return ['outcome' => self::VERIFIED, 'context' => $entry['context'], 'attempts_left' => 0];
            }

            $entry['attempts']++;

            if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
                $this->store()->forget($key);

                return ['outcome' => self::EXHAUSTED, 'context' => $entry['context'], 'attempts_left' => 0];
            }

            $this->store()->put($key, $entry, max(1, $entry['expires_at'] - now()->getTimestamp()));

            return ['outcome' => self::WRONG, 'context' => $entry['context'], 'attempts_left' => self::MAX_ATTEMPTS - $entry['attempts']];
        });
    }

    public function discard(string $scope): void
    {
        $this->store()->forget($this->key($scope));
    }

    private function hash(#[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function key(string $scope): string
    {
        return 'whatsapp:admin-test-code:'.$scope;
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
