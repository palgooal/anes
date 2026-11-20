<?php

namespace App\Console\Commands;

use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\WhatsAppAuditAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * After rotating APP_KEY (old key kept in APP_PREVIOUS_KEYS), re-encrypt the stored
 * WhatsApp secrets with the current key (REQ-WA-23). Prints no secret.
 */
#[Signature('whatsapp:reencrypt-secrets')]
#[Description('Re-encrypt the stored WhatsApp secrets with the current application key')]
class WhatsAppReencryptSecretsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $setting = WhatsAppSetting::query()->first();

        if (! $setting) {
            $this->info('لا توجد إعدادات واتساب محفوظة في قاعدة البيانات، فلا شيء لإعادة تشفيره.');

            return self::SUCCESS;
        }

        $columns = [];

        foreach (WhatsAppSetting::SECRETS as $column) {
            // Reading decrypts with the current key or a previous one; writing encrypts with the current key.
            $plain = $setting->getAttribute($column);

            if (is_string($plain) && $plain !== '') {
                $setting->setAttribute($column, $plain);
                $columns[] = $column;
            }
        }

        if ($columns === []) {
            $this->info('لا توجد أسرار محفوظة.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($setting, $columns) {
            $setting->save();
            WhatsAppSettingsAudit::record(WhatsAppAuditAction::SecretsReencrypted, null, changes: ['secrets' => $columns]);
        });

        $this->info('أُعيد تشفير '.count($columns).' من الأسرار بالمفتاح الحالي.');

        return self::SUCCESS;
    }
}
