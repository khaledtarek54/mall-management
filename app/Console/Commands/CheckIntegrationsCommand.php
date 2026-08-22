<?php

namespace App\Console\Commands;

use App\Services\Eta\EtaApiClient;
use App\Services\Paymob\PaymobClient;
use App\Support\Modules;
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

    protected $description = 'Verify Paymob + mail credentials and connectivity (no card charged, no email sent). ETA is frozen — pass --eta to check its dormant credentials anyway';

    public function handle(EtaApiClient $eta): int
    {
        $only = array_filter(['eta' => $this->option('eta'), 'paymob' => $this->option('paymob'), 'mail' => $this->option('mail')]);
        $run = fn (string $k): bool => $only === [] || array_key_exists($k, $only);

        $ok = true;
        $this->newLine();

        // A FROZEN module is silent here too. `integrations:check` with no flags is what an operator
        // runs after pasting new credentials, and reporting on an integration that cannot run —
        // green or red — is the same false signal the settings tab was: it says ETA is part of this
        // deployment's surface. An EXPLICIT `--eta` still reports, because someone who typed the
        // flag is asking about the dormant code on purpose, and answering nothing at all would read
        // as the command being broken.
        if ($run('eta') && (Modules::enabled('eta') || $this->option('eta'))) {
            $this->line('<options=bold>ETA e-invoicing</>');
            if (Modules::frozen('eta')) {
                $this->line('  <fg=yellow>! Module 16 is FROZEN (App\Support\Modules::FROZEN) — nothing submits. Checking the dormant credentials because you asked with --eta.</>');
            }
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
            // A green tick on `MAIL_MAILER=log` is a fail-open. In production it means every tenant
            // invoice, every overdue reminder and every ledger-drift alert is written to a file
            // nobody reads, while the preflight whose job is to catch exactly that says fine. It is
            // a perfectly normal setting everywhere else, so it only FAILS where it is a defect.
            $inProduction = app()->environment('production');

            $this->status(! $inProduction, $inProduction
                ? "MAIL_MAILER={$mailer} in PRODUCTION — nothing is delivered. Every invoice, reminder and alert goes to the log."
                : "[disabled] MAIL_MAILER={$mailer} — mail is written to the log, not delivered (expected outside production).");

            return ! $inProduction;
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

            if (! $this->probeSendPermission($key)) {
                return false;
            }
        } catch (Throwable $e) {
            $this->status(false, '[mailersend] '.$e->getMessage());

            return false;
        }

        if (! app()->environment('production') && filled($alwaysTo = config('mail.always_to'))) {
            $this->line("  <fg=yellow>! MAIL_ALWAYS_TO={$alwaysTo} — every outgoing email is redirected there.</>");
        }

        return $ok;
    }

    /**
     * Prove the token may SEND, not merely that it authenticates.
     *
     * `/api-quota` answers 200 for any valid token regardless of its scopes, so
     * a token created without the Email permission passed this check while every
     * notification in the app failed with `403 Forbidden` — a green "Outbound
     * email" row over an inbox that could never receive anything (found 2026-08-17,
     * after a payment receipt failed to send on a box this command called healthy).
     *
     * MailerSend has no dry-run, so the probe is a deliberately EMPTY payload and
     * the answer is read from WHICH refusal comes back: a token that may send is
     * stopped by validation (422), one that may not is stopped by authorization
     * (403). Nothing is delivered on either branch and the quota is untouched.
     *
     * Anything else passes with a note rather than failing — an unrecognised
     * status means the probe has stopped being able to tell, and a check that
     * cannot tell must not claim either answer.
     */
    private function probeSendPermission(string $key): bool
    {
        $probe = Http::withToken($key)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->withBody('{}', 'application/json')
            ->post('https://'.config('mailersend-driver.host', 'api.mailersend.com').'/'.config('mailersend-driver.api_path', 'v1').'/email');

        if ($probe->status() === 403) {
            $this->status(false, '[mailersend] The token authenticates but is NOT permitted to send: '
                .trim((string) $probe->json('message', 'This action is unauthorized.'))
                // ASCII on purpose: the console formatter eats "→" here, and an
                // instruction with the arrow missing reads as two unrelated words.
                .' Give the token "Email: Full access" in the MailerSend dashboard'
                .' (Integrations > API tokens), or issue a new token.');

            return false;
        }

        if ($probe->status() !== 422) {
            $this->line("  <fg=yellow>! [mailersend] Send-permission probe returned {$probe->status()}, which it does not "
                .'recognise — treating as inconclusive. Confirm with `php artisan mail:test <you@example.com>`.</>');
        }

        return true;
    }

    private function status(bool $ok, string $message): void
    {
        $this->line(($ok ? '  <fg=green>✓</>' : '  <fg=red>✗</>')." {$message}");
    }
}
