<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocument;
use App\Notifications\VendorDocumentExpiringNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Chase vendor compliance documents before — and after — they lapse (module 12).
 *
 * The dispatch gate (`Vendor::assignable()` / `MaintenanceWorkOrder::saving()`) already refuses to
 * send a vendor to site on a lapsed insurance certificate. But it did so SILENTLY: the contractor
 * simply stopped appearing in every picker, with no warning beforehand and no explanation after.
 * The statutory documents (بطاقة ضريبية, سجل تجاري, شهادة تأمينات اجتماعية) had no chase at all —
 * before module 12b they did not exist as records.
 *
 * Idempotent + lock-safe (the scheduled-scan invariant): each document row is locked and re-checked
 * inside its own transaction, and the alert is stamped with BOTH the stage and the exact expiry it
 * fired for. So a re-run never re-nags, escalating expiring → expired alerts once more, and renewing
 * a document (a new `expires_on`) re-arms its cycle by itself.
 *
 * Vendors are a shared, portfolio-wide catalog, so "who cares" is derived from engagement: staff of
 * the properties where this vendor holds an active contract, falling back to portfolio roles when it
 * holds none.
 */
class ScanVendorDocumentExpiryCommand extends Command
{
    protected $signature = 'vendors:scan-document-expiry
        {--dry-run : Print what would be alerted without sending}';

    protected $description = 'Alert staff about vendor compliance documents lapsing within 30 days or already lapsed (idempotent).';

    public function handle(): int
    {
        $lock = Cache::lock('vendors:scan-document-expiry', 600);

        if (! $lock->get()) {
            $this->warn('Another vendor-document scan is already running.');

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

        VendorDocument::query()
            ->needsAttention()
            // Only chase documents for vendors still on the books — an inactive or blacklisted
            // supplier's paperwork is nobody's problem.
            ->whereHas('vendor', fn ($q) => $q->where('status', Vendor::STATUS_ACTIVE))
            ->select('id')
            ->get()
            ->each(function ($row) use (&$alerted, &$skipped) {
                // Per-document containment: one bad row must never stop the rest of the
                // portfolio being chased.
                try {
                    $this->alertFor((int) $row->id, $alerted, $skipped);
                } catch (\Throwable $e) {
                    $this->warn("  alert failed for document #{$row->id}: {$e->getMessage()}");
                }
            });

        if ($this->option('dry-run')) {
            $this->warn("Would alert on {$alerted} vendor document(s); {$skipped} already alerted.");

            return self::SUCCESS;
        }

        $this->info("Alerted on {$alerted} vendor document(s); {$skipped} already alerted.");

        return self::SUCCESS;
    }

    private function alertFor(int $documentId, int &$alerted, int &$skipped): void
    {
        DB::transaction(function () use ($documentId, &$alerted, &$skipped) {
            /** @var VendorDocument|null $document */
            $document = VendorDocument::query()->whereKey($documentId)->lockForUpdate()->first();

            if (! $document instanceof VendorDocument) {
                return;
            }

            // Re-check the stage under the lock — the document may have been renewed since the
            // outer query ran.
            $stage = $document->alertStage();

            if ($stage === null) {
                return;
            }

            $expiry = $document->expires_on?->toDateString();

            // Already alerted for this exact (stage, expiry) → don't re-nag. A renewal changes
            // the date; an escalation changes the stage. Either re-arms this check.
            if ($document->alert_stage === $stage && $document->alert_for?->toDateString() === $expiry) {
                $skipped++;

                return;
            }

            $document->loadMissing('vendor');

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would alert %s · %s · %s · expires %s',
                    $document->vendor?->name, $document->type, $stage, $expiry ?? '—'));
                $alerted++;

                return;
            }

            // A delivery failure must not cost the operator the whole cycle: resolving recipients
            // hits spatie's role() scope (which throws outright if a role isn't seeded) and the
            // mail channel sends in-process. Warn and carry on — the dashboard card surfaces the
            // same set live off Vendor::documentsNeedAttention(), independently of this stamp, so
            // a dropped notification can't make a lapsing document invisible.
            try {
                $recipients = $this->recipientsFor($document);

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new VendorDocumentExpiringNotification($document, $stage));
                }
            } catch (\Throwable $e) {
                $this->warn("  delivery failed for document #{$document->id}: {$e->getMessage()}");
            }

            // Stamped even when nobody is assigned to the engaged properties — otherwise an
            // unstaffed portfolio would re-alert on every run forever.
            $document->forceFill(['alert_stage' => $stage, 'alert_for' => $expiry])->save();
            $alerted++;
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    private function recipientsFor(VendorDocument $document): \Illuminate\Support\Collection
    {
        $resolver = app(AssetStaffRecipients::class);
        $roles = ['manager', 'operations'];

        $assetIds = VendorContract::query()
            ->where('vendor_id', $document->vendor_id)
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
