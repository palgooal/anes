<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppMessageLog;
use App\Notifications\AdminTestCodeNotification;
use App\Notifications\LoginCodeNotification;
use App\Services\WhatsApp\WhatsAppConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AC-WA-22: an administrator's test code never reaches the log, even through the
 * log driver locally, where login codes are written for development (REQ-WA-34).
 */
class AdminTestCodeNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_log_driver_never_writes_a_test_code(): void
    {
        Http::preventStrayRequests();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = json_encode($event->context);
        });

        $config = new WhatsAppConfig(WhatsAppConfig::SOURCE_ENV, WhatsAppConfig::DRIVER_LOG, null, null, null, null, 'v23.0', '', 'ar');

        Notification::route('whatsapp', '966551234567')->notify(new AdminTestCodeNotification('482913', $config));
        Notification::route('whatsapp', '966551234567')->notify(new LoginCodeNotification('517204'));

        $this->assertCount(2, $logged);
        $this->assertStringNotContainsString('482913', $logged[0]);
        $this->assertStringContainsString('****', $logged[0]);
        // Login codes keep their local development behaviour.
        $this->assertStringContainsString('517204', $logged[1]);
        $this->assertSame(0, WhatsAppMessageLog::count());
    }
}
