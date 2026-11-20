<?php

namespace Tests\Feature\Console\Commands;

use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\WhatsAppAuditAction;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * whatsapp:reencrypt-secrets after an APP_KEY rotation (REQ-WA-23).
 */
class WhatsAppReencryptSecretsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_secrets_are_re_encrypted_with_the_new_key(): void
    {
        WhatsAppSetting::factory()->create(['webhook_verify_token' => 'fake-verify-token-1234']);
        $oldKey = config('app.key');
        $newKey = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));

        // Rotate: the new key is current, the old one only a previous key.
        $this->rotateKey($newKey, [$oldKey]);

        // Before the command, the stored value is still under the old key.
        $newOnly = new Encrypter(base64_decode(substr($newKey, 7)), config('app.cipher'));
        try {
            $newOnly->decryptString(DB::table('whatsapp_settings')->value('test_access_token'));
            $this->fail('The secret was already readable with the new key.');
        } catch (DecryptException) {
        }

        $this->artisan('whatsapp:reencrypt-secrets')
            ->expectsOutputToContain('أُعيد تشفير 3 من الأسرار')
            ->doesntExpectOutputToContain('fake-')
            ->assertSuccessful();

        // Readable with the new key alone: the old key can be dropped.
        $row = DB::table('whatsapp_settings')->first();
        $this->assertSame('fake-test-token-0001', $newOnly->decryptString($row->test_access_token));
        $this->assertSame('fake-production-token-0002', $newOnly->decryptString($row->production_access_token));
        $this->assertSame('fake-verify-token-1234', $newOnly->decryptString($row->webhook_verify_token));

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::SecretsReencrypted, $audit->action);
        $this->assertSame(['secrets' => ['test_access_token', 'production_access_token', 'webhook_verify_token']], $audit->changes);
        $this->assertStringNotContainsString('fake-', json_encode($audit->getAttributes()));
    }

    public function test_nothing_to_do_without_settings(): void
    {
        $this->artisan('whatsapp:reencrypt-secrets')->assertSuccessful();

        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    /**
     * @param  list<string>  $previousKeys
     */
    private function rotateKey(string $key, array $previousKeys): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        WhatsAppSetting::encryptUsing(null);
    }
}
