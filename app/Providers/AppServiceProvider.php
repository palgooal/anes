<?php

namespace App\Providers;

use App\Models\User;
use App\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Every request that sends a WhatsApp code or an email costs money and
         * can be abused, so limit them per network address.
         */
        RateLimiter::for('login-codes', function (Request $request) {
            $tooMany = fn () => back()->withInput()->withErrors([
                'throttle' => 'محاولات كثيرة خلال وقت قصير. انتظر دقيقة ثم حاول مجدداً.',
            ]);

            return [
                Limit::perMinute(10)->by('minute:'.$request->ip())->response($tooMany),
                Limit::perHour(40)->by('hour:'.$request->ip())->response($tooMany),
            ];
        });

        /*
         * Staff password attempts are also locked per email after five
         * failures (StaffLoginRequest::throttleKey); this only caps floods.
         */
        RateLimiter::for('staff-login', fn (Request $request) => Limit::perMinute(20)->by($request->ip())->response(
            fn () => back()->withInput()->withErrors(['throttle' => 'محاولات كثيرة خلال وقت قصير. انتظر دقيقة ثم حاول مجدداً.']),
        ));

        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

            return (new MailMessage)
                ->subject('استعادة كلمة المرور')
                ->greeting('السلام عليكم '.$user->name)
                ->line('وصلنا طلب لاستعادة كلمة المرور لحسابك في بوابة فريق العمل.')
                ->action('تعيين كلمة مرور جديدة', $url)
                ->line('صلاحية الرابط ساعة واحدة. إن لم تطلب ذلك فتجاهل هذه الرسالة.');
        });

        /** Approving, adding and suspending team members is reserved for administrators. */
        Gate::define('manage-team', fn (User $user): bool => $user->role === UserRole::Admin);

        /** WhatsApp Cloud API settings: a separate permission, held by administrators for now (REQ-WA-40). */
        Gate::define('manage-whatsapp', fn (User $user): bool => $user->role === UserRole::Admin);

        View::share('waNumber', config('services.whatsapp.number'));
    }
}
