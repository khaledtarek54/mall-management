<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * `php artisan mail:test [recipient] [--from=] [--raw]`
 *
 * Sends ONE real email through whatever mailer is configured — the fastest way to
 * prove the outbound path end-to-end (credentials → transport → verified sending
 * domain → inbox) without triggering a business flow. Sends inline, never queued,
 * so a failure surfaces here instead of in a worker log.
 *
 * The sibling of `integrations:check` for ETA/Paymob: run it the moment new mail
 * credentials are pasted.
 */
class SendTestMailCommand extends Command
{
    protected $signature = 'mail:test
        {recipient? : Inbox to send to (defaults to MAIL_ALWAYS_TO)}
        {--from= : Override MAIL_FROM_ADDRESS — must be on a verified sending domain}
        {--raw : Send plain text instead of the HTML template}';

    protected $description = 'Send a single test email through the configured mailer (nothing queued, no business data).';

    /** MAIL_FROM_ADDRESS ships with this placeholder until the operator pastes a verified domain. */
    private const PLACEHOLDER = 'REPLACE-WITH-VERIFIED-MAILERSEND-DOMAIN';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $from = (string) ($this->option('from') ?: config('mail.from.address'));
        $recipient = (string) ($this->argument('recipient') ?: config('mail.always_to'));
        $alwaysTo = (string) config('mail.always_to');

        if (str_contains($from, self::PLACEHOLDER)) {
            $this->error('MAIL_FROM_ADDRESS is still the placeholder.');
            $this->line('  Set it to an address on a domain you have VERIFIED with your mail provider');
            $this->line('  (MailerSend → Domains), or pass one now: <options=bold>--from=noreply@your-domain.com</>');

            return self::FAILURE;
        }

        foreach (['from' => $from, 'recipient' => $recipient] as $label => $address) {
            if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $this->error($address === ''
                    ? "No {$label} address — pass one (mail:test you@example.com) or set MAIL_ALWAYS_TO."
                    : "Invalid {$label} address: {$address}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->line("  mailer     <options=bold>{$mailer}</>");
        $this->line("  from       <options=bold>{$from}</>");
        $this->line("  to         <options=bold>{$recipient}</>");

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->line("  <fg=yellow>! MAIL_MAILER={$mailer} — this will NOT leave the box (check storage/logs/laravel.log).</>");
        }

        // The non-production catch-all (AppServiceProvider) overrides the envelope
        // AFTER this command hands the message off, so say so rather than reporting
        // a delivery to an address that never receives it.
        if ($alwaysTo !== '' && $alwaysTo !== $recipient && ! app()->environment('production')) {
            $this->line("  <fg=yellow>! MAIL_ALWAYS_TO is set — actually delivered to {$alwaysTo}.</>");
        }

        $this->newLine();

        $payload = [
            'app' => (string) config('app.name'),
            'env' => (string) config('app.env'),
            'url' => (string) config('app.url'),
            'mailer' => $mailer,
            'sentAt' => now()->format('Y-m-d H:i:s T'),
        ];

        try {
            Mail::send(
                $this->option('raw') ? [] : ['html' => 'emails.test'],
                $payload,
                function ($message) use ($from, $recipient, $payload) {
                    $message->from($from, (string) config('mail.from.name'))
                        ->to($recipient)
                        ->subject("[{$payload['app']}] Test email — {$payload['sentAt']}");

                    if ($this->option('raw')) {
                        $message->text("Test email from {$payload['app']} ({$payload['env']}) via the '{$payload['mailer']}' mailer at {$payload['sentAt']}.");
                    }
                }
            );
        } catch (Throwable $e) {
            $this->error('✗ Send failed: '.$e->getMessage());

            foreach ($this->hintsFor($e->getMessage()) as $hint) {
                $this->line("  <fg=yellow>→ {$hint}</>");
            }

            return self::FAILURE;
        }

        $this->info('✓ Handed to the transport without error — check the inbox (and the spam folder).');

        return self::SUCCESS;
    }

    /**
     * Translate the provider's error codes into the actual fix. MailerSend returns
     * these as opaque `#MSxxxxx` references in an otherwise generic message.
     *
     * @return list<string>
     */
    private function hintsFor(string $message): array
    {
        return match (true) {
            str_contains($message, 'MS42207') => ['The from-address domain is not verified in MailerSend. Dashboard → Domains → add + verify (DNS records), then use an address on that domain.'],
            str_contains($message, 'MS42225') => ['Trial accounts may only send to the account owner\'s own email address. Verify a domain to lift this.'],
            str_contains($message, 'MS40301') => ['The API token lacks the "Email" send permission. Create a new token with full/email access.'],
            str_contains($message, '401') || str_contains($message, 'Unauthenticated') => ['MAILERSEND_API_KEY is missing or wrong — check .env, then `php artisan config:clear`.'],
            str_contains($message, '429') => ['Daily sending quota exhausted (trial = 100/day). It resets at 00:00 UTC.'],
            default => [],
        };
    }
}
