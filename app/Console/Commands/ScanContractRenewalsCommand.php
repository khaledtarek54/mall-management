<?php

namespace App\Console\Commands;

use App\Models\VendorContract;
use App\Notifications\VendorContractRenewalDueNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alert on vendor contracts that have reached their NOTICE deadline (module 12b).
 *
 * `vendors:expire-contracts` flips a contract to `expired` on its end_date — useful housekeeping,
 * useless as a decision aid: by the end date every choice has already been made for you. The date a
 * contract manager actually works to is `end_date − notice_period_days`. Miss it and either the
 * contract auto-renews for another term at the old rate, or the mall arrives at day one with no
 * cleaning contractor.
 *
 * Idempotent + lock-safe (the scheduled-scan invariant): each contract row is locked and re-checked
 * inside its own transaction, and the alert is stamped with the end_date it fired for — so a re-run
 * never re-nags, while re-signing the contract (a new end_date) re-arms the alert by itself.
 */
class ScanContractRenewalsCommand extends Command
{
    protected $signature = 'vendors:scan-contract-renewals
        {--dry-run : Print what would be alerted without sending}';

    protected $description = 'Alert staff about vendor contracts that have reached their renewal-notice deadline (idempotent).';

    public function handle(): int
    {
        $lock = Cache::lock('vendors:scan-contract-renewals', 600);

        if (! $lock->get()) {
            $this->warn('Another contract-renewal scan is already running.');

            return self::SUCCESS;
        }

        try {
            return $this->scan();
        } finally {
            $lock->release();
        }
    }

    private function scan(): int
    {
        $alerted = 0;
        $skipped = 0;

        VendorContract::query()->noticeDue()->select('id')->get()->each(function ($row) use (&$alerted, &$skipped) {
            // Per-contract containment: one bad row must never stop the rest of the portfolio.
            try {
                $this->alertFor((int) $row->id, $alerted, $skipped);
            } catch (\Throwable $e) {
                $this->warn("  alert failed for contract #{$row->id}: {$e->getMessage()}");
            }
        });

        if ($this->option('dry-run')) {
            $this->warn("Would alert on {$alerted} contract(s); {$skipped} already alerted.");

            return self::SUCCESS;
        }

        $this->info("Alerted on {$alerted} contract(s); {$skipped} already alerted.");

        return self::SUCCESS;
    }

    private function alertFor(int $contractId, int &$alerted, int &$skipped): void
    {
        DB::transaction(function () use ($contractId, &$alerted, &$skipped) {
            /** @var VendorContract|null $contract */
            $contract = VendorContract::query()->whereKey($contractId)->lockForUpdate()->first();

            if (! $contract instanceof VendorContract || ! $contract->isNoticeDue()) {
                return;
            }

            $endDate = $contract->end_date?->toDateString();

            // Already alerted for this term → don't re-nag. Re-signing changes end_date, which
            // re-arms this check without anyone having to clear a flag.
            if ($contract->renewal_alert_for?->toDateString() === $endDate) {
                $skipped++;

                return;
            }

            $contract->loadMissing('vendor');

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would alert %s · %s · notice by %s',
                    $contract->vendor?->name, $contract->name, $contract->noticeDeadline()?->toDateString() ?? '—'));
                $alerted++;

                return;
            }

            // Delivery failures warn but still stamp — the Action Required card reads
            // VendorContract::noticeDue() live, independently of this stamp, so a dropped
            // notification cannot make a due decision invisible.
            $contract->forceFill(['renewal_alert_for' => $endDate])->save();

            // After the commit, never under the lock (SW-213). `VendorContractRenewalDueNotification`
            // is not `ShouldQueue` and mails as well as belling, so sent inside the transaction the
            // X lock on `vendor_contracts` was held across a synchronous MailerSend round-trip per
            // recipient. The `catch` travels with the `try`, or a deferred failure escapes it.
            DB::afterCommit(function () use ($contract) {
                try {
                    $recipients = app(AssetStaffRecipients::class)->for($contract->asset_id, ['manager', 'operations']);

                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new VendorContractRenewalDueNotification($contract));
                    }
                } catch (\Throwable $e) {
                    $this->warn("  delivery failed for contract #{$contract->id}: {$e->getMessage()}");
                }
            });

            $alerted++;
        });
    }
}
