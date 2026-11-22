<?php

namespace App\Services\Auth;

use App\CodeChannel;
use App\Services\WhatsApp\WhatsAppSettings;

/**
 * Which channel can carry the staff two-factor code (DEC-AUTH-009).
 *
 * - WhatsApp only when the effective settings send to Meta for real: the "log"
 *   driver and blocked settings (disabled, incomplete) do not count as sending.
 * - Otherwise email, if a real mailer is configured: in production the "log" and
 *   "array" transports, an SMTP mailer without host or port, and a missing sender
 *   address all count as no email.
 * - Neither: no channel, and no code is issued.
 *
 * Reads configuration only: nothing is sent, and no secret is read or logged.
 */
class TwoFactorChannels
{
    /** Transports that never deliver a message. */
    private const FAKE_TRANSPORTS = ['log', 'array'];

    public function __construct(private WhatsAppSettings $whatsApp) {}

    public function choose(): ?CodeChannel
    {
        return match (true) {
            $this->whatsAppReady() => CodeChannel::WhatsApp,
            $this->mailReady() => CodeChannel::Mail,
            default => null,
        };
    }

    public function isAvailable(CodeChannel $channel): bool
    {
        return match ($channel) {
            CodeChannel::WhatsApp => $this->whatsAppReady(),
            CodeChannel::Mail => $this->mailReady(),
        };
    }

    public function whatsAppReady(): bool
    {
        return $this->whatsApp->current()->sendsToMeta();
    }

    public function mailReady(): bool
    {
        $from = config('mail.from.address');

        if (! is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $mailer = (string) config('mail.default');

        // Outside production the log and array transports are allowed (development, tests).
        return app()->isProduction()
            ? $this->delivers($mailer, [])
            : is_array(config('mail.mailers.'.$mailer));
    }

    /**
     * Whether this mailer can really deliver: no fake transport anywhere in it.
     *
     * @param  list<string>  $seen
     */
    private function delivers(string $mailer, array $seen): bool
    {
        $config = config('mail.mailers.'.$mailer);

        if ($mailer === '' || in_array($mailer, $seen, true) || ! is_array($config)) {
            return false;
        }

        $transport = $config['transport'] ?? null;

        if (! is_string($transport) || $transport === '' || in_array($transport, self::FAKE_TRANSPORTS, true)) {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $members = array_values(array_filter((array) ($config['mailers'] ?? []), 'is_string'));

            return $members !== [] && array_reduce(
                $members,
                fn (bool $all, string $member) => $all && $this->delivers($member, [...$seen, $mailer]),
                true,
            );
        }

        if ($transport === 'smtp') {
            return filled($config['host'] ?? null) && filled($config['port'] ?? null);
        }

        return true;
    }
}
