<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// REQ-WA-22, PH-WA-7: the daily check of the saved WhatsApp access tokens. Needs the
// scheduler on the server (EXT-WA-06) and a shared cache store with locks.
Schedule::command('whatsapp:check-tokens')->dailyAt('03:17')->withoutOverlapping(30)->onOneServer();
