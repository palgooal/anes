<?php

namespace App\Console\Commands;

use App\Notifications\LoginCodeNotification;
use App\PhoneNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Notification;

#[Signature('whatsapp:test {phone : The mobile number, e.g. 0599123456 or +970599123456} {--country= : Calling code used for a national number (966, 970 or 972)}')]
#[Description('Send a test login code on WhatsApp to check the Meta connection')]
class WhatsAppTestCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $phone = PhoneNumber::normalize($this->argument('phone'), $this->option('country'));

        if (! $phone) {
            $this->error('رقم غير صحيح، أو دولته غير مسموحة في AUTH_PHONE_COUNTRIES ('.implode(',', PhoneNumber::allowedCountries()).').');

            return self::FAILURE;
        }

        $config = config('services.whatsapp');
        $this->line('Driver: '.$config['driver'].' · To: '.PhoneNumber::format($phone).' · Template: '.$config['login_template'].' ('.$config['template_language'].')');

        if ($config['driver'] === 'meta' && (! $config['phone_number_id'] || ! $config['access_token'])) {
            $this->error('WHATSAPP_PHONE_NUMBER_ID و WHATSAPP_ACCESS_TOKEN مطلوبان عند WHATSAPP_DRIVER=meta.');

            return self::FAILURE;
        }

        $length = (int) config('auth.one_time_codes.length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        try {
            Notification::route('whatsapp', $phone)->notify(new LoginCodeNotification($code));
        } catch (RequestException $exception) {
            $this->error('رفضت Meta الرسالة: '.($exception->response->json('error.message') ?? $exception->getMessage()));

            return self::FAILURE;
        } catch (ConnectionException $exception) {
            $this->error('تعذّر الاتصال بخوادم Meta: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($config['driver'] === 'meta'
            ? "أُرسل الرمز {$code}. إن لم يصل خلال دقيقة فراجع حالة الرسالة في WhatsApp Manager."
            : "WHATSAPP_DRIVER={$config['driver']}: لم يُرسل شيء، وكُتب الرمز {$code} في storage/logs/laravel.log.");

        return self::SUCCESS;
    }
}
