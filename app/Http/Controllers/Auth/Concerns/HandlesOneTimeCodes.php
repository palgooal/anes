<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\CodeChannel;
use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Notifications\TwoFactorCodeMail;
use App\Services\Auth\TwoFactorChannels;
use App\Services\WhatsApp\WhatsAppUnavailableException;
use App\WhatsAppMessagePurpose;
use App\WhatsAppSendStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

/**
 * Shared steps of the code flows: who is waiting for a code (kept in the session
 * between the two screens), sending it, checking it and locking the account after
 * too many wrong codes.
 *
 * Beneficiary login codes always go to WhatsApp. The staff two-factor code goes to
 * WhatsApp when it really reaches Meta, otherwise by email (DEC-AUTH-009). That
 * channel is chosen once, before the code is issued, kept in the session, and used
 * again for every resend; it never changes during a pending code screen.
 */
trait HandlesOneTimeCodes
{
    private const PENDING_KEY = 'auth.pending';

    private const SEND_FAILED = 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.';

    private const MAIL_SEND_FAILED = 'تعذّر إرسال الرمز إلى بريدك الإلكتروني الآن. حاول بعد قليل، وإن تكرر ذلك تواصل مع الإدارة.';

    private const NO_CHANNEL = 'تعذّر إرسال رمز التحقق الآن: لا تتوفر قناة إرسال. تواصل مع الإدارة.';

    private const CHANNEL_UNAVAILABLE = 'قناة إرسال الرمز غير متاحة الآن. حاول بعد قليل، أو سجّل الدخول من جديد.';

    private const SESSION_RESTART = 'انتهت جلسة التحقق. سجّل الدخول من جديد.';

    /**
     * Remember who is signing in, send them a code and go to the code screen.
     */
    protected function sendCodeAndContinue(Request $request, User $user, CodePurpose $purpose, bool $remember, string $verifyRoute): RedirectResponse
    {
        $pending = [
            'user_id' => $user->id,
            'purpose' => $purpose->value,
            'remember' => $remember,
        ];
        $channel = CodeChannel::WhatsApp;

        // DEC-AUTH-009: the staff two-factor channel is chosen here, once, before any code exists.
        if ($purpose === CodePurpose::TwoFactor) {
            $channel = app(TwoFactorChannels::class)->choose();

            if ($channel === null) {
                $request->session()->forget(self::PENDING_KEY);
                Log::warning('Two-factor code: no channel is available (WhatsApp does not send to Meta and no real mailer is configured).');

                return back()->withInput($request->except('password'))->withErrors(['send' => self::NO_CHANNEL]);
            }

            $pending['channel'] = $channel->value;
        }

        $request->session()->put(self::PENDING_KEY, $pending);

        if (! $this->sendCode($user, $purpose, $channel)) {
            $request->session()->forget(self::PENDING_KEY);

            return back()->withInput($request->except('password'))->withErrors(['send' => $this->sendFailedMessage($channel)]);
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
    protected function sendCode(User $user, CodePurpose $purpose, CodeChannel $channel = CodeChannel::WhatsApp): bool
    {
        if ($channel === CodeChannel::Mail) {
            return $this->sendCodeByMail($user, $purpose);
        }

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

            $this->reportSendFailure($exception);

            return false;
        }

        return true;
    }

    /**
     * Issue the code and send it by email (DEC-AUTH-009). Any failure invalidates the
     * code at once and is logged as a fixed message with the exception class only:
     * never the code, the address, the message body or what the mail server answered.
     */
    private function sendCodeByMail(User $user, CodePurpose $purpose): bool
    {
        [$oneTimeCode, $plain] = OneTimeCode::issue($user, $purpose);

        try {
            $user->notify(new TwoFactorCodeMail($plain));
        } catch (Throwable $exception) {
            $oneTimeCode->invalidateBecause(OneTimeCode::MAIL_FAILED);
            Log::warning('Two-factor code: the email could not be sent.', ['error' => $exception::class]);

            return false;
        }

        return true;
    }

    private function sendFailedMessage(CodeChannel $channel): string
    {
        return $channel === CodeChannel::Mail ? self::MAIL_SEND_FAILED : self::SEND_FAILED;
    }

    /**
     * The channel kept for the pending staff two-factor code. Null when the session
     * predates DEC-AUTH-009 and holds none: such a session is never sent a code again
     * (the user signs in once more), so no channel is ever picked silently.
     */
    protected function pendingChannel(Request $request): ?CodeChannel
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        return is_array($pending) ? CodeChannel::tryFrom((string) ($pending['channel'] ?? '')) : null;
    }

    /**
     * Log a failed send without anything Meta answered and without the request URL:
     * a fixed message with the HTTP status and Meta's numeric error code only. The
     * raw exception message carries up to 120 characters of the answer, and a
     * connection error carries the URL with the Phone Number ID.
     */
    private function reportSendFailure(Throwable $exception): void
    {
        if ($exception instanceof WhatsAppUnavailableException) {
            // Source, mode, environment, template and blocked reason only: no value is secret.
            report($exception);

            return;
        }

        $code = $exception instanceof RequestException ? $exception->response->json('error.code') : null;

        Log::warning('WhatsApp: the code could not be sent.', $exception instanceof RequestException
            ? ['http_status' => $exception->response->status(), 'meta_code' => is_numeric($code) ? (int) $code : null]
            : ['error' => 'connection_error']);
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

        $channel = CodeChannel::WhatsApp;

        // DEC-AUTH-009: a staff resend uses the channel kept for this code screen, never another one.
        if ($purpose === CodePurpose::TwoFactor) {
            $channel = $this->pendingChannel($request);

            if ($channel === null) {
                $request->session()->forget(self::PENDING_KEY);

                return redirect()->route($loginRoute)->withErrors(['send' => self::SESSION_RESTART]);
            }

            if (! app(TwoFactorChannels::class)->isAvailable($channel)) {
                return redirect()->route($verifyRoute)->withErrors(['send' => self::CHANNEL_UNAVAILABLE]);
            }
        }

        if (! $this->sendCode($user, $purpose, $channel)) {
            return redirect()->route($verifyRoute)->withErrors(['send' => $this->sendFailedMessage($channel)]);
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
            // Using the code is one conditional write (PH-WA-8.5): it is refused when the
            // code was invalidated, used or expired since it was read.
            if ($oneTimeCode->consumeIfUsable()) {
                RateLimiter::clear($throttleKey);

                return null;
            }

            // No usable code was left: the failure path below answers as it does then.
            $oneTimeCode = null;
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
     * By email, the address is shown masked; on WhatsApp, the last digits of the phone.
     *
     * @return array{phone: string, resendIn: int, channel: string, destination: string}
     */
    protected function codeScreenData(User $user, CodePurpose $purpose, ?CodeChannel $channel = null): array
    {
        $channel ??= CodeChannel::WhatsApp;

        return [
            'phone' => (string) $user->phone,
            'resendIn' => OneTimeCode::latestIssuedFor($user, $purpose)?->secondsUntilResend() ?? 0,
            'channel' => $channel->value,
            'destination' => $channel === CodeChannel::Mail ? self::maskEmail((string) $user->email) : '',
        ];
    }

    /**
     * "h•••@example.com": the first character of the name and the domain only.
     */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '•••';
        }

        return mb_substr($email, 0, 1).'•••'.substr($email, $at);
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
