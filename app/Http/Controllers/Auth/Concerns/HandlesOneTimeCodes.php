<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\WhatsApp\WhatsAppUnavailableException;
use App\WhatsAppMessagePurpose;
use App\WhatsAppSendStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

/**
 * Shared steps of the WhatsApp code flows: who is waiting for a code (kept in
 * the session between the two screens), sending it, checking it and locking
 * the account after too many wrong codes.
 */
trait HandlesOneTimeCodes
{
    private const PENDING_KEY = 'auth.pending';

    private const SEND_FAILED = 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.';

    /**
     * Remember who is signing in, send them a code and go to the code screen.
     */
    protected function sendCodeAndContinue(Request $request, User $user, CodePurpose $purpose, bool $remember, string $verifyRoute): RedirectResponse
    {
        $request->session()->put(self::PENDING_KEY, [
            'user_id' => $user->id,
            'purpose' => $purpose->value,
            'remember' => $remember,
        ]);

        if (! $this->sendCode($user, $purpose)) {
            $request->session()->forget(self::PENDING_KEY);

            return back()->withInput($request->except('password'))->withErrors(['send' => self::SEND_FAILED]);
        }

        return redirect()->route($verifyRoute);
    }

    /**
     * Issue and send a code. Returns false (and reports the error) when WhatsApp could not be reached.
     *
     * Fail-safe (REQ-WA-27, REQ-WA-28): unless Meta accepted the request, the code is
     * invalidated at once, even if the message might still arrive later. Unexpected
     * errors invalidate the code too, then keep propagating as before.
     */
    protected function sendCode(User $user, CodePurpose $purpose): bool
    {
        [$oneTimeCode, $plain] = OneTimeCode::issue($user, $purpose);

        try {
            $user->notify(new LoginCodeNotification($plain, $oneTimeCode->id, WhatsAppMessagePurpose::fromCodePurpose($purpose)));
        } catch (Throwable $exception) {
            $oneTimeCode->invalidate(WhatsAppSendStatus::fromException($exception));

            if (! $exception instanceof RequestException
                && ! $exception instanceof ConnectionException
                && ! $exception instanceof WhatsAppUnavailableException) {
                throw $exception;
            }

            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Ask for a new code on the code screen, at most once a minute.
     */
    protected function resendCode(Request $request, CodePurpose $purpose, string $loginRoute, string $verifyRoute): RedirectResponse
    {
        $user = $this->pendingUser($request, $purpose);

        if (! $user) {
            return redirect()->route($loginRoute);
        }

        if ($this->codeScreenData($user, $purpose)['resendIn'] > 0) {
            return redirect()->route($verifyRoute);
        }

        if (! $this->sendCode($user, $purpose)) {
            return redirect()->route($verifyRoute)->withErrors(['send' => self::SEND_FAILED]);
        }

        return redirect()->route($verifyRoute)->with('status', 'أرسلنا رمزاً جديداً.');
    }

    /**
     * The user waiting for a code of this purpose, if any.
     */
    protected function pendingUser(Request $request, CodePurpose $purpose): ?User
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ($pending['purpose'] ?? null) !== $purpose->value) {
            return null;
        }

        return User::find($pending['user_id'] ?? null);
    }

    protected function codeThrottleKey(User $user): string
    {
        return 'one-time-code:'.$user->id;
    }

    /**
     * Seconds the user is still locked out for after too many wrong codes (0 = not locked).
     */
    protected function lockedSeconds(string $throttleKey): int
    {
        return RateLimiter::tooManyAttempts($throttleKey, (int) config('auth.one_time_codes.max_attempts'))
            ? RateLimiter::availableIn($throttleKey)
            : 0;
    }

    protected function lockedView(int $seconds, string $retryRoute, string $variant): View
    {
        return view('auth.locked', [
            'seconds' => $seconds,
            'retryRoute' => $retryRoute,
            'variant' => $variant,
        ]);
    }

    /**
     * Check the submitted code. Returns null on success, otherwise the response to send back.
     */
    protected function checkCode(User $user, CodePurpose $purpose, string $code, string $retryRoute, string $variant): RedirectResponse|View|null
    {
        $throttleKey = $this->codeThrottleKey($user);

        if ($seconds = $this->lockedSeconds($throttleKey)) {
            return $this->lockedView($seconds, $retryRoute, $variant);
        }

        $oneTimeCode = OneTimeCode::latestFor($user, $purpose);

        if ($oneTimeCode && $oneTimeCode->matches($code)) {
            $oneTimeCode->markAsConsumed();
            RateLimiter::clear($throttleKey);

            return null;
        }

        RateLimiter::hit($throttleKey, (int) config('auth.one_time_codes.lockout_seconds'));

        if ($seconds = $this->lockedSeconds($throttleKey)) {
            return $this->lockedView($seconds, $retryRoute, $variant);
        }

        $left = RateLimiter::remaining($throttleKey, (int) config('auth.one_time_codes.max_attempts'));
        $failedSend = ! $oneTimeCode && OneTimeCode::latestIssuedFor($user, $purpose)?->isInvalidated();
        $message = $failedSend || $oneTimeCode?->isExpired()
            ? 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.'
            : 'الرمز غير صحيح. بقي لك '.self::arabicCount($left).'.';

        return back()->withErrors(['code' => $message]);
    }

    /**
     * Sign the user in and send them to their own page.
     */
    protected function completeLogin(Request $request, User $user): RedirectResponse
    {
        $remember = (bool) data_get($request->session()->get(self::PENDING_KEY), 'remember', false);

        Auth::login($user, $remember);
        $request->session()->forget(self::PENDING_KEY);
        $request->session()->regenerate();

        return redirect()->intended(route($user->role->isBeneficiary() ? 'portal' : 'staff.dashboard'));
    }

    /**
     * Data for the code screen: where the code went and when a new one may be requested.
     *
     * @return array{phone: string, resendIn: int}
     */
    protected function codeScreenData(User $user, CodePurpose $purpose): array
    {
        return [
            'phone' => (string) $user->phone,
            'resendIn' => OneTimeCode::latestIssuedFor($user, $purpose)?->secondsUntilResend() ?? 0,
        ];
    }

    /**
     * "محاولة واحدة" / "محاولتان" / "٣ محاولات".
     */
    private static function arabicCount(int $count): string
    {
        $digits = strtr((string) $count, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        return match (true) {
            $count === 1 => 'محاولة واحدة',
            $count === 2 => 'محاولتان',
            $count <= 10 => "{$digits} محاولات",
            default => "{$digits} محاولة",
        };
    }
}
