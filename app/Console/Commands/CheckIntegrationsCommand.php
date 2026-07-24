<?php

namespace App\Console\Commands;

use App\Services\Eta\EtaApiClient;
use App\Services\Paymob\PaymobClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * `php artisan integrations:check [--eta] [--paymob]`
 *
 * Non-destructive preflight for the external integrations: verifies credentials
 * and connectivity WITHOUT submitting a document to ETA or charging a card on
 * Paymob. Run it the moment new credentials are pasted to confirm they're valid
 * and reachable — the fastest feedback loop during live certification.
 */
class CheckIntegrationsCommand extends Command
{
    protected $signature = 'integrations:check {--eta : check ETA only} {--paymob : check Paymob only} {--mail : check outbound email only}';

    protected $description = 'Verify ETA + Paymob + mail credentials and connectivity (no document submitted, no card charged, no email sent)';

    public function handle(EtaApiClient $eta): int
    {
        $only = array_filter(['eta' => $this->option('eta'), 'paymob' => $this->option('paymob'), 'mail' => $this->option('mail')]);
        $run = fn (string $k): bool => $only === [] || array_key_exists($k, $only);

        $ok = true;
        $this->newLine();

        if ($run('eta')) {
            $this->line('<options=bold>ETA e-invoicing</>');
            $r = $eta->verifyCredentials();
            $this->status($r['ok'], "[{$r['mode']}] {$r['message']}");
            if ($r['mode'] === 'real' && ! config('eta.signing.enabled')) {
                $this->line('  <fg=yellow>! Document signing is OFF — ETA production rejects unsigned B2B documents. OK for preprod plumbing only.</>');
            }
            $ok = $ok && $r['ok'];
            $this->newLine();
        }

        if ($run('paymob')) {
            $this->line('<options=bold>Paymob card payments</>');
            if (! config('integrations.paymob.enabled')) {
                $this->status(true, '[disabled] PAYMOB_ENABLED=false — card payments off (demo-pay endpoint active).');
            } else {
                try {
                    $token = PaymobClient::fromConfig()->authenticate();
                    $this->status($token !== '', '[live] Authenticated with Paymob (token received).');
                    $ok = $ok && $token !== '';
                } catch (Throwable $e) {
                    $this->status(false, '[live] '.$e->getMessage());
                    $ok = false;
                }
            }
            $this->newLine();
        }

        if ($run('mail')) {
            $this->line('<options=bold>Outbound email</>');
            $ok = $this->checkMail() && $ok;
            $this->newLine();
        }

        $ok ? $this->info('✓ Integration checks passed.') : $this->error('✗ One or more integration checks failed.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Non-destructive mail preflight: confirms the mailer is a real transport, the
     * from-address is set, and — for MailerSend — that the API key authenticates,
     * by reading the sending quota rather than sending anything.
     */
    private function checkMail(): bool
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->status(true, "[disabled] MAIL_MAILER={$mailer} — mail is written to the log, not delivered.");

            return true;
        }

        $ok = true;

        if ($from === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL) || str_contains($from, 'REPLACE-WITH')) {
            $this->status(false, "MAIL_FROM_ADDRESS is not a usable address ({$from}).");
            $ok = false;
        }

        if ($mailer !== 'mailersend') {
            $this->status($ok, "[{$mailer}] from={$from} — credentials not verifiable without sending; run `php artisan mail:test`.");

            return $ok;
        }

        $key = (string) config('mailersend-driver.api_key');

        if ($key === '') {
            $this->status(false, '[mailersend] MAILERSEND_API_KEY is empty.');

            return false;
        }

        try {
            $response = Http::withToken($key)
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->timeout(10)
                ->get('https://'.config('mailersend-driver.host', 'api.mailersend.com').'/'.config('mailersend-driver.api_path', 'v1').'/api-quota');

            if (! $response->successful()) {
                $this->status(false, '[mailersend] API key rejected: '.trim((string) $response->json('message', $response->status())));

                return false;
            }

            $this->status(true, sprintf(
                '[mailersend] Key authenticated. Quota %s/%s remaining (resets %s). from=%s',
                $response->json('remaining'), $response->json('quota'), $response->json('reset'), $from
            ));
        } catch (Throwable $e) {
            $this->status(false, '[mailersend] '.$e->getMessage());

            return false;
        }

        if (! app()->environment('production') && filled($alwaysTo = config('mail.always_to'))) {
            $this->line("  <fg=yellow>! MAIL_ALWAYS_TO={$alwaysTo} — every outgoing email is redirected there.</>");
        }

        return $ok;
    }

    private function status(bool $ok, string $message): void
    {
        $this->line(($ok ? '  <fg=green>✓</>' : '  <fg=red>✗</>')." {$message}");
    }
}
