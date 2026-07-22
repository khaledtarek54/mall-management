<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Notifications\VendorCoiExpiringNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Chase vendor Certificates of Insurance before — and after — they lapse (module 12).
 *
 * The compliance gate (`Vendor::assignable()` / `MaintenanceWorkOrder::saving()`) already refuses to
 * dispatch a vendor with a lapsed COI. But it did so SILENTLY: the contractor simply stopped
 * appearing in every picker, with no warning beforehand and no explanation after. This closes that —
 * 30 days out the operator is told to chase the renewal, and on lapse they're told the vendor is now
 * un-assignable.
 *
 * Idempotent + lock-safe (the scheduled-scan invariant): each vendor row is locked and re-checked
 * inside its own transaction, and the alert is stamped with BOTH the stage and the exact COI date it
 * fired for. So a re-run never re-nags; escalating expiring → expired alerts once more; and renewing
 * the cert (a new `coi_expires_at`) re-arms the whole cycle by itself.
 *
 * Vendors are a shared, portfolio-wide catalog, so "who cares" is derived from engagement: staff of
 * the properties where this vendor holds an active contract, falling back to portfolio roles when it
 * holds none.
 */
class ScanVendorCoiExpiryCommand extends Command
{
    protected $signature = 'vendors:scan-coi-expiry
        {--dry-run : Print who would be alerted without sending}';

    protected $description = 'Alert staff about vendor COIs that are lapsing within 30 days or have already lapsed (idempotent).';

    public function handle(): int
    {
        $lock = Cache::lock('vendors:scan-coi-expiry', 600);

        if (! $lock->get()) {
            $this->warn('Another COI scan is already running.');

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

        Vendor::query()->coiNeedsAttention()->select('id')->get()->each(function ($row) use (&$alerted, &$skipped) {
            // Per-vendor containment: one bad row must never stop the rest of the portfolio
            // from being chased (the same reason preventive generation contains per plan).
            try {
                $this->alertFor((int) $row->id, $alerted, $skipped);
            } catch (\Throwable $e) {
                $this->warn("  COI alert failed for vendor #{$row->id}: {$e->getMessage()}");
            }
        });

        if ($this->option('dry-run')) {
            $this->warn("Would alert on {$alerted} vendor COI(s); {$skipped} already alerted.");

            return self::SUCCESS;
        }

        $this->info("Alerted on {$alerted} vendor COI(s); {$skipped} already alerted.");

        return self::SUCCESS;
    }

    private function alertFor(int $vendorId, int &$alerted, int &$skipped): void
    {
        DB::transaction(function () use ($vendorId, &$alerted, &$skipped) {
            /** @var Vendor|null $vendor */
            $vendor = Vendor::query()->whereKey($vendorId)->lockForUpdate()->first();

            if (! $vendor instanceof Vendor) {
                return;
            }

            // Re-check the stage under the lock — the COI may have been renewed since the scan query.
            $stage = $vendor->coiAlertStage();

            if ($stage === null) {
                return;
            }

            $coiDate = $vendor->coi_expires_at?->toDateString();

            // Already alerted for this exact (stage, cert date) → don't re-nag. A renewal changes
            // the date; an escalation changes the stage. Either re-arms this check.
            if ($vendor->coi_alert_stage === $stage && $vendor->coi_alert_for?->toDateString() === $coiDate) {
                $skipped++;

                return;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would alert %s · %s · expires %s', $vendor->name, $stage, $coiDate ?? '—'));
                $alerted++;

                return;
            }

            // A delivery failure must not cost the operator the whole cycle: resolving recipients
            // hits spatie's role() scope (which throws outright if a role isn't seeded) and the
            // mail channel sends in-process. Warn and carry on — the dashboard card surfaces the
            // same set live off Vendor::coiNeedsAttention(), independently of this stamp, so a
            // dropped notification can't make a lapsing cert invisible.
            try {
                $recipients = $this->recipientsFor($vendor);

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new VendorCoiExpiringNotification($vendor, $stage));
                }
            } catch (\Throwable $e) {
                $this->warn("  COI alert delivery failed for {$vendor->name}: {$e->getMessage()}");
            }

            // Stamped even when nobody is assigned to the engaged properties — otherwise an
            // unstaffed portfolio would re-alert on every run forever.
            $vendor->forceFill(['coi_alert_stage' => $stage, 'coi_alert_for' => $coiDate])->save();
            $alerted++;
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    private function recipientsFor(Vendor $vendor): \Illuminate\Support\Collection
    {
        $resolver = app(AssetStaffRecipients::class);
        $roles = ['manager', 'operations'];

        $assetIds = VendorContract::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'active')
            ->whereNotNull('asset_id')
            ->distinct()
            ->pluck('asset_id');

        if ($assetIds->isEmpty()) {
            return $resolver->for(null, $roles);
        }

        return $assetIds
            ->flatMap(fn ($assetId) => $resolver->for((int) $assetId, $roles))
            ->unique('id')
            ->values();
    }
}
