<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Shared steps of the WhatsApp code flows: who is waiting for a code (kept in
 * the session between the two screens), sending it, checking it and locking
 * the account after too many wrong codes.
 */
trait HandlesOneTimeCodes
{
    private const PENDING_KEY = 'auth.pending';

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

        $this->sendCode($user, $purpose);

        return redirect()->route($verifyRoute);
    }

    protected function sendCode(User $user, CodePurpose $purpose): void
    {
        $user->notify(new LoginCodeNotification(OneTimeCode::issueFor($user, $purpose)));
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
        $message = $oneTimeCode?->isExpired()
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
            'resendIn' => OneTimeCode::latestFor($user, $purpose)?->secondsUntilResend() ?? 0,
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
