<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\TenantDocument;
use App\Models\User;
use App\Notifications\TenantDocumentExpiringNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Chase tenant compliance documents before — and after — they lapse (Yardi gap row 92).
 *
 * The mirror of `vendors:scan-document-expiry`, and for a sharper reason: an uninsured contractor is
 * at least stopped at the dispatch gate, whereas an uninsured RETAILER simply keeps trading. Nothing
 * in the system could previously notice, because the lease's insurance obligation was written into
 * the contract and then never checked again.
 *
 * Idempotent + lock-safe (the scheduled-scan invariant): each row is locked and re-checked inside
 * its own transaction, and the alert is stamped with BOTH the stage and the exact expiry it fired
 * for. A re-run never re-nags, expiring escalates to expired exactly once, and renewing a document
 * (a new `expires_on`) re-arms its cycle by itself.
 *
 * Tenants are shared across the portfolio, so "who cares" is derived from occupancy: staff of the
 * properties where this tenant holds an ACTIVE lease — the same derivation the vendor chase makes
 * from active contracts. A tenant with no active lease reaches super_admins only, because
 * `AssetStaffRecipients` matches the property-team roles through `assignedAssets` and there is no
 * asset to match them against.
 */
class ScanTenantDocumentExpiryCommand extends Command
{
    protected $signature = 'tenants:scan-document-expiry
        {--dry-run : Print what would be alerted without sending}';

    protected $description = 'Alert staff about tenant compliance documents (insurance, tax card…) lapsing within 30 days or already lapsed (idempotent).';

    public function handle(): int
    {
        $lock = Cache::lock('tenants:scan-document-expiry', 600);

        if (! $lock->get()) {
            $this->warn('Another tenant-document scan is already running.');

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

        TenantDocument::query()
            ->needsAttention()
            // Only chase paperwork for tenants still on the books — a former retailer's expired
            // certificate is nobody's problem.
            ->whereHas('tenant', fn ($q) => $q->where('status', 'active'))
            ->select('id')
            ->get()
            ->each(function ($row) use (&$alerted, &$skipped) {
                // Per-document containment: one bad row must never stop the rest being chased.
                try {
                    $this->alertFor((int) $row->id, $alerted, $skipped);
                } catch (\Throwable $e) {
                    $this->warn("  alert failed for document #{$row->id}: {$e->getMessage()}");
                }
            });

        if ($this->option('dry-run')) {
            $this->warn("Would alert on {$alerted} tenant document(s); {$skipped} already alerted.");

            return self::SUCCESS;
        }

        $this->info("Alerted on {$alerted} tenant document(s); {$skipped} already alerted.");

        return self::SUCCESS;
    }

    private function alertFor(int $documentId, int &$alerted, int &$skipped): void
    {
        DB::transaction(function () use ($documentId, &$alerted, &$skipped) {
            /** @var TenantDocument|null $document */
            $document = TenantDocument::query()->whereKey($documentId)->lockForUpdate()->first();

            if (! $document instanceof TenantDocument) {
                return;
            }

            // Re-check the stage under the lock — the document may have been renewed since the
            // outer query ran.
            $stage = $document->alertStage();

            if ($stage === null) {
                return;
            }

            $expiry = $document->expires_on?->toDateString();

            // Already alerted for this exact (stage, expiry) → don't re-nag. A renewal changes the
            // date; an escalation changes the stage. Either re-arms this check.
            if ($document->alert_stage === $stage && $document->alert_for?->toDateString() === $expiry) {
                $skipped++;

                return;
            }

            $document->loadMissing('tenant');

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would alert %s · %s · %s · expires %s',
                    $document->tenant?->name, $document->type, $stage, $expiry ?? '—'));
                $alerted++;

                return;
            }

            // A delivery failure must not cost the operator the whole cycle: resolving recipients
            // hits spatie's role() scope (which throws outright if a role isn't seeded). Warn and
            // carry on — the tenant screen surfaces the same set live off
            // `Tenant::documentsNeedAttention()`, independently of this stamp, so a dropped
            // notification can never make a lapsed certificate invisible.
            // Stamped even when nobody is assigned to the occupied properties — otherwise an
            // unstaffed portfolio would re-alert on every run forever.
            $document->forceFill(['alert_stage' => $stage, 'alert_for' => $expiry])->save();

            // …and delivered AFTER the commit, never under the lock (SW-213): this notification is
            // not `ShouldQueue`, so sent inside the transaction the X lock on `tenant_documents` was
            // held across a synchronous MailerSend round-trip per recipient. The `catch` travels
            // with the `try`, or the containment the comment above relies on is gone.
            DB::afterCommit(function () use ($document, $stage) {
                try {
                    $recipients = $this->recipientsFor($document);

                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new TenantDocumentExpiringNotification($document, $stage));
                    }
                } catch (\Throwable $e) {
                    $this->warn("  delivery failed for document #{$document->id}: {$e->getMessage()}");
                }
            });

            $alerted++;
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(TenantDocument $document): Collection
    {
        $resolver = app(AssetStaffRecipients::class);
        // Leasing owns the tenant relationship and the paperwork chase; the manager is the
        // escalation. Deliberately not operations — this is a contract obligation, not a work order.
        $roles = ['manager', 'leasing'];

        $assetIds = Lease::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('status', 'active')
            // A lease reaches its property through its unit, which is the derivation every other
            // tenant-to-property answer in the system uses.
            ->with('unit:id,asset_id')
            ->get()
            ->pluck('unit.asset_id')
            ->filter()
            ->unique();

        if ($assetIds->isEmpty()) {
            return $resolver->for(null, $roles);
        }

        return $assetIds
            ->flatMap(fn ($assetId) => $resolver->for((int) $assetId, $roles))
            ->unique('id')
            ->values();
    }
}
