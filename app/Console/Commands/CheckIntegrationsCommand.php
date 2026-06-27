<?php

namespace App\Console\Commands;

use App\Services\Eta\EtaApiClient;
use App\Services\Paymob\PaymobClient;
use Illuminate\Console\Command;
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
    protected $signature = 'integrations:check {--eta : check ETA only} {--paymob : check Paymob only}';

    protected $description = 'Verify ETA + Paymob credentials and connectivity (no document submitted, no card charged)';

    public function handle(EtaApiClient $eta): int
    {
        $only = array_filter(['eta' => $this->option('eta'), 'paymob' => $this->option('paymob')]);
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

        $ok ? $this->info('✓ Integration checks passed.') : $this->error('✗ One or more integration checks failed.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function status(bool $ok, string $message): void
    {
        $this->line(($ok ? '  <fg=green>✓</>' : '  <fg=red>✗</>')." {$message}");
    }
}
