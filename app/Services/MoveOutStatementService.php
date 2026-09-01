<?php

namespace App\Services;

use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The one document that settles a departing tenant (story MF-03, scenario S8).
 *
 * **What was wrong.** Move-out is the moment a tenancy's money is settled, and it was a sequence of
 * unconnected manual acts: deposit refund and forfeit were two separate `DepositTransaction` events
 * with nothing netting them, nothing itemising them, and nothing checking the balance actually held
 * against the lease's contractual `security_deposit`. Meanwhile the year's CAM true-up would not be
 * known until March, so the account was settled before the last number existed — and nobody was
 * told that.
 *
 * **This computes; it does not post.** Every figure is derived from the records that already exist
 * (deposit transactions, open invoices, unapplied credit notes, unreconciled CAM pools), so the
 * statement cannot disagree with the ledger it summarises. `SettleMoveOutService` is what writes.
 *
 * **The net position it reports is the one the settlement carries out.** `SettleMoveOutService`
 * nets the arrears off the deposit through `ApplyDepositToInvoiceService` (Dr Deposits Held / Cr AR
 * — a fourth channel into `Invoice::recomputeTotals()`, added by the full registry route), then
 * deducts, then refunds the remainder. So the figures below are a forecast of the settlement rather
 * than a description of a position somebody has to act on separately.
 */
class MoveOutStatementService
{
    /**
     * @return array{
     *   lease: Lease,
     *   as_of: CarbonImmutable,
     *   contractual_deposit: float,
     *   deposit_held: float,
     *   deposit_shortfall: float,
     *   open_invoices: Collection<int, Invoice>,
     *   open_ar: float,
     *   tenant_credit: float,
     *   pending_trueups: array<int, array{kind: string, detail: string}>,
     *   net_to_tenant: float,
     *   residual_debt: float
     * }
     */
    public function for(Lease $lease, ?CarbonImmutable $asOf = null, ?float $depositHeld = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        // `$depositHeld` is supplied by the SETTLEMENT, which must read the pot under a lock — the
        // figure a refund is written from cannot come from the display twin. It is a parameter
        // rather than something the caller overrides on the returned array, because three keys are
        // DERIVED from it here (`deposit_shortfall`, `net_to_tenant`, `residual_debt`) and two of
        // those are frozen onto the immutable lease event. Overriding the array afterwards left the
        // signed document stating a net-to-tenant computed from a deposit balance the same document
        // said was different — one figure in, one derivation, or the statement contradicts itself.
        $depositHeld = $depositHeld ?? $this->depositHeld($lease);
        $contractual = (float) ($lease->security_deposit ?? 0);

        $openInvoices = Invoice::query()
            ->where('lease_id', $lease->id)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->whereCollectable()
            ->orderBy('due_date')
            ->with('writeOffs')
            ->get();

        // COLLECTABLE, and this is the one reader where the difference is money rather than a
        // misstatement: `$openAr` is subtracted from the deposit below, so counting a forgiven slice
        // here withholds that much of the tenant's own deposit for a debt the operator wrote off.
        $openAr = round((float) $openInvoices->sum(fn (Invoice $i): float => $i->collectableBalance()), 2);

        // Credit notes with a balance are money owed BACK to the tenant — typically the unearned
        // rent this very termination just credited (MF-02). Netting them here is the difference
        // between a final account and half of one.
        $tenantCredit = round((float) CreditNote::query()
            ->where('lease_id', $lease->id)
            // `onTheBooks()`, not a status list. This read `['issued', 'partially_paid']` —
            // `partially_paid` is an INVOICE status and cannot exist on `credit_notes.status`
            // (`draft | issued | applied | void`), and the list omitted `applied`. It was masked
            // only by the invariant that a note with a balance is `issued`, set in four places in
            // `CreditNoteService`; the day that slips, this WITHHOLDS a departing tenant's own
            // credit from their final account. Same copy-paste as the VAT return's phantom
            // `cancelled`, on the money going back to a person who is leaving.
            ->onTheBooks()
            ->where('balance', '>', 0)
            ->sum('balance'), 2);

        // What the tenant is owed, before any deductions the operator itemises at settlement.
        $net = round($depositHeld + $tenantCredit - $openAr, 2);

        return [
            'lease' => $lease,
            'as_of' => $asOf,
            'contractual_deposit' => $contractual,
            'deposit_held' => $depositHeld,
            // A held balance BELOW the contract is a fact worth stating: the deposit was never
            // fully collected, or part was already drawn down, and nobody reconciled the two.
            'deposit_shortfall' => round(max($contractual - $depositHeld, 0), 2),
            'open_invoices' => $openInvoices,
            'open_ar' => $openAr,
            'tenant_credit' => $tenantCredit,
            'pending_trueups' => $this->pendingTrueUps($lease, $asOf),
            'net_to_tenant' => (float) max($net, 0),
            'residual_debt' => $net < 0 ? (float) abs($net) : 0.0,
        ];
    }

    /**
     * The deposit actually held: receipts less what has already been refunded or forfeited.
     *
     * Only RECORDED transactions count — a draft is an intention, and settling against intentions
     * is how a landlord refunds money it never received.
     */
    public function depositHeld(Lease $lease): float
    {
        // Delegates: the calculation moved onto the model so the lease list, the lease page and the
        // tenant's portal could all ask the same question. Kept here because callers already use it
        // and a final account reads better naming its own inputs.
        return $lease->depositHeld();
    }

    /**
     * Numbers that are not knowable yet — the reason a final account can be wrong even when every
     * figure on it is right.
     *
     * S8's case: a tenant leaving in September, whose share of the year's CAM will not be computed
     * until the following March. Saying so on the document is the whole point; a statement that
     * silently omits a pending true-up reads as final when it is not.
     *
     * @return array<int, array{kind: string, detail: string}>
     */
    private function pendingTrueUps(Lease $lease, CarbonImmutable $asOf): array
    {
        $pending = [];

        $assetId = $lease->unit?->asset_id;

        if ($assetId !== null) {
            // Any CAM pool covering a year this lease traded in that has not been reconciled yet.
            $unreconciled = CamExpensePool::query()
                ->where('asset_id', $assetId)
                ->whereIn('status', ['draft', 'reconciling'])
                ->when($lease->commencement_date, fn ($q) => $q->where('period_year', '>=', CarbonImmutable::instance($lease->commencement_date)->year))
                ->where('period_year', '<=', $asOf->year)
                ->orderBy('period_year')
                ->pluck('period_year');

            foreach ($unreconciled as $year) {
                $pending[] = [
                    'kind' => 'cam',
                    'detail' => __('admin.move_out.pending_cam', ['year' => $year]),
                ];
            }
        }

        if ($lease->has_percentage_rent) {
            // Counted directly rather than through `Lease::missingSalesDeclarationsFor()`, which
            // filters to ACTIVE leases — by the time a final account is drawn the lease is usually
            // already terminated, so that helper would report a clean sheet for exactly the tenant
            // whose declarations matter most.
            $commenced = CarbonImmutable::instance($lease->commencement_date);
            $from = $commenced->greaterThan($asOf->startOfYear())
                ? $commenced->startOfMonth()
                : $asOf->startOfYear();

            $monthsTraded = max(0, ($asOf->year - $from->year) * 12 + ($asOf->month - $from->month));

            $declared = $lease->salesDeclarations()
                ->whereDate('period_start', '>=', $from->toDateString())
                ->whereDate('period_start', '<=', $asOf->toDateString())
                ->count();

            if ($declared < $monthsTraded) {
                $pending[] = [
                    'kind' => 'percentage_rent',
                    'detail' => __('admin.move_out.pending_percentage_rent', ['year' => $asOf->year]),
                ];
            }
        }

        return $pending;
    }
}
