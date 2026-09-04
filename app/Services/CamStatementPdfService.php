<?php

namespace App\Services;

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Models\UnitOwnership;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;

/**
 * The reconciliation statement a tenant can actually audit (story RC-06).
 *
 * **Why this exists.** Almost every commercial lease gives the tenant a right to audit the service
 * charge, and Atriom's answer to "show me how you got this number" was an invoice line reading
 * "CAM Recovery 2028". The tenant could see what they were being charged and nothing about how it
 * was derived — not the pool, not the basis, not the denominator, not their share, not the cap, not
 * what they had already paid. An audit right you cannot exercise is not a right.
 *
 * **Every figure is READ from the allocation, never recomputed.** The statement must show what the
 * tenant was actually billed, and a statement that re-derived its numbers would quietly disagree
 * with the invoice the moment anything downstream changed — an area edit, a new participant, a
 * re-run. `pro_rata_share_pct` and the capped/absorbed amounts are stored precisely so the
 * arithmetic can be replayed years later; this replays it rather than redoing it.
 *
 * The denominator is derived from the stored share (`area ÷ share`) for the same reason: it is the
 * denominator that was used, not the one that would be used today.
 *
 * Bilingual by locale, like every other document here — the same mPDF config and RTL font switch as
 * `InvoicePdfService`.
 */
class CamStatementPdfService
{
    /**
     * The service-charge reconciliation statement, in the language its READER reads — and the reader
     * is a tenant OR a unit owner, resolved the same way the party block is.
     */
    public function build(CamAllocation $allocation, ?string $locale = null): string
    {
        return $this->document($allocation, $locale)->render();
    }

    /**
     * The configured document, before mpdf touches it.
     *
     * Split from {@see build()} for the reason `InvoicePdfService::document()` was: a test that has
     * to inflate a PDF's compressed streams to find out whether the settlement block prints its VAT
     * will not be written, and the one written instead rebuilds the view data in the test and proves
     * only that the test agrees with itself.
     */
    public function document(CamAllocation $allocation, ?string $locale = null): PdfDocument
    {
        // The ownership side had never been eager-loaded at all, so the owner, his unit and his
        // property were three lazy loads on a document that reads all three.
        $allocation->loadMissing([
            'pool.asset', 'lease.tenant', 'lease.unit.asset', 'lease.units',
            'unitOwnership.owner', 'unitOwnership.unit', 'unitOwnership.asset',
        ]);

        // Both parents SOFT-DELETE, so either relation genuinely returns null at runtime for a
        // trashed pool or lease, and the `?->` below is load-bearing rather than defensive noise.
        // Larastan types the relation as non-null regardless of the annotation, so those reads are
        // baselined under the same `nullsafe.neverNull` exemption as every other soft-deleted
        // parent in this codebase — removing the `?->` would be a real crash, not a cleanup.
        /** @var CamExpensePool|null $pool */
        $pool = $allocation->pool;
        /** @var Lease|null $lease */
        $lease = $allocation->lease;
        /** @var UnitOwnership|null $ownership */
        $ownership = $allocation->unitOwnership;

        // A CAM allocation belongs to a lease OR a unit ownership — `assertBelongsToExactlyOneAgreement()`
        // enforces exactly one — and `CamReconciliationService` creates ownership allocations for every
        // HandedOver tenure. Resolving the party through the lease alone left this document, whose ENTIRE
        // purpose is showing an owner the working behind a true-up he did not expect, with a blank party,
        // a blank unit, a blank reference and 0.00 m² over correct money. The portal lists these
        // deliberately — `CamAllocationResource::getEloquentQuery()` ORs in `unitOwnership` with a comment
        // naming this exact reader.
        //
        // **The unit, the reference and the area were fixed then; the PARTY was not.** This line read
        // `$ownership?->tenant`, and the ownership's relation is `owner` — an undefined relation
        // resolves to NULL rather than throwing, so the fix looked complete and the party block on
        // every owner's statement stayed empty. Measured 2026-09-05 on `mall_management_qa`,
        // `unit_ownerships#1`: `owner->name` = "Ashraf El-Gindy", `->tenant` = NULL,
        // `method_exists($o, 'tenant')` = false. It also cost the document its LANGUAGE, because
        // `DocumentLocale::resolve()` reads the recipient's stored `locale` and a null recipient
        // skips that tier. `CamAllocation::counterparty()` is now the one answer, shared with the
        // two download buttons that had the identical line.
        $tenant = $allocation->counterparty();
        $asset = $pool?->asset ?? $lease?->unit?->asset ?? $ownership?->asset;
        $unitCodes = $lease !== null
            ? ($lease->units->pluck('code')->implode(', ') ?: $lease->unit?->code)
            : $ownership?->unit?->code;

        return PdfDocument::make('cam.statement')
            ->locale(DocumentLocale::resolve($locale, $tenant))
            ->data(fn (): array => [
                'allocation' => $allocation,
                'pool' => $pool,
                'lease' => $lease,
                'ownership' => $ownership,
                'agreementReference' => $lease?->reference ?? $ownership?->reference,
                'unitCodes' => $unitCodes,
                'tenant' => $tenant,
                'asset' => $asset,
                'facts' => $this->facts($allocation),
                ...IssuingEntity::forView($asset),
            ])
            ->reference($lease?->reference ?? $ownership?->reference)
            ->bleed();
    }

    /**
     * Everything the statement states, derived once so the view stays a layout.
     *
     * @return array<string, mixed>
     */
    public function facts(CamAllocation $allocation): array
    {
        /** @var CamExpensePool|null $pool */
        $pool = $allocation->pool;
        /** @var Lease|null $lease */
        $lease = $allocation->lease;

        $share = (float) $allocation->pro_rata_share_pct;
        // Both agreement shapes, and the same source the reconciliation apportioned from:
        // `CamReconciliationService::areaForPeriod()` reads `unit->area_sqm` for an ownership.
        $area = $lease !== null
            ? $lease->totalAreaSqm()
            : (float) ($allocation->unitOwnership?->unit?->area_sqm ?? 0.0);

        // The denominator that was USED, recovered from the share that was stored — not the one
        // that would be computed today. That distinction is the whole point of a statement issued
        // against a reconciliation rather than against the live data.
        // Also guarded on the AREA, not the share alone: with a non-zero share and an unresolved area
        // this printed "your area 0.00 m² of 0.00 m²" beside a real percentage — three mutually
        // contradictory figures. Null renders as an em-dash, which says "not stated" rather than "zero".
        $denominator = ($share > 0 && $area > 0) ? round($area / ($share / 100), 2) : null;

        $capped = $allocation->capped_cost_amount !== null
            ? (float) $allocation->capped_cost_amount
            : (float) $allocation->allocated_amount;

        $trueUp = (float) $allocation->true_up_amount;

        // ── THE VAT THE DOCUMENTS ACTUALLY CARRY ────────────────────────────────────────────
        //
        // A positive true-up is recovered on an invoice (`CamReconciliationService::
        // billChargeImmediately()`) and a negative one is credited on a credit note
        // (`billCredit()`); BOTH put the pool's `recovery_vat_rate` on top of the net figure, and
        // that column ships `default(14.00)`. This method summed the true-up, the management fee
        // and the fee's VAT and stopped, so the last line of the document a tenant audits was not
        // the amount they were asked for. Read at HEAD: a 50,000 shortfall with a 10% management
        // fee printed a 61,400.00 "Total now due" against a recovery invoice of 68,400.00, and the
        // mirror case printed a 38,600.00 net credit where the credit note less the fee invoice is
        // 45,600.00. `explainAllocation()` — the OPERATOR'S breakdown modal — had it right the
        // whole time, which is why nobody held the two against each other.
        //
        // Exactly one of these two is non-zero, and both go through the one definition on the
        // allocation so a fifth reader cannot arrive at a fifth answer.
        $recovery = max($trueUp, 0.0);
        $credit = max(-$trueUp, 0.0);
        $recoveryVat = $allocation->recoveryVatOn($recovery);
        $creditVat = $allocation->recoveryVatOn($credit);
        $fee = (float) $allocation->admin_fee_amount;
        $feeVat = (float) $allocation->admin_fee_vat_amount;

        return [
            'year' => (int) ($pool?->period_year ?? 0),
            'pool_total' => (float) ($pool?->total_actual_expense ?? 0),
            // How the pool total was arrived at — a tenant auditing the charge is entitled to know
            // whether it came out of the ledger or was typed in.
            'expense_basis' => $pool?->expense_basis ?? CamExpensePool::BASIS_STATED,
            'estimate_basis' => $pool?->estimate_basis ?? CamExpensePool::BASIS_STATED,
            'ledger_accounts' => $pool && $pool->expense_basis === CamExpensePool::BASIS_LEDGER
                ? $pool->ledgerAccounts
                    ->map(fn ($a) => "{$a->code} · ".(app()->getLocale() === 'ar' ? $a->name_ar : $a->name_en))
                    ->all()
                : [],
            // The gross-up, when one applied (RC-04). Null when it did not, so the statement can
            // omit the section rather than printing a no-op line on every document in the mall.
            'gross_up_pct' => $pool?->gross_up_pct !== null ? (float) $pool->gross_up_pct : null,
            'grossed_up_expense' => $pool?->grossed_up_expense !== null
                ? (float) $pool->grossed_up_expense
                : null,
            'area_sqm' => $area,
            'denominator_sqm' => $denominator,
            'denominator_basis' => $pool?->denominator_basis ?? CamExpensePool::DENOMINATOR_OCCUPIED,
            // A share the contract NAMED rather than one derived from area — the tenant should see
            // which of the two they are reading.
            // WITH THE POOL CODE (SW-169). `03133a13` made a cap and a stated share belong to a
            // recovery POOL rather than to a year and routed the reconciliation, the relation
            // manager and the model through it — and missed the two calls in this file, so the
            // DOCUMENT resolved `camTermFor($year, null)`: the portfolio-wide fallback term, not
            // the one the calculation beside it used. A share the contract NAMED then printed as
            // one derived from floor area, which is a different argument in a dispute.
            'share_is_stated' => $lease?->statedCamSharePct((int) ($pool?->period_year ?? 0), $pool?->pool_code) !== null,
            'share_pct' => $share,
            'allocated' => (float) $allocation->allocated_amount,
            'cap_amount' => $allocation->cap_amount !== null ? (float) $allocation->cap_amount : null,
            'capped_cost' => $capped,
            'cap_absorbed' => (float) $allocation->cap_absorbed_amount,
            // Same omission, and this one silently drops the note explaining that only CONTROLLABLE
            // cost was capped — the clause that decided the number the tenant is reading.
            'cap_scope' => $lease?->camCapScope((int) ($pool?->period_year ?? 0), $pool?->pool_code) ?? 'total',
            'cap_headroom_used' => (float) $allocation->cap_headroom_used,
            'cap_headroom_banked' => (float) $allocation->cap_headroom_banked,
            'estimated_paid' => (float) $allocation->estimated_paid,
            'true_up' => $trueUp,
            'true_up_is_credit' => $trueUp < 0,
            'admin_fee_pct' => (float) ($pool?->admin_fee_pct ?? 0) * 100,
            'admin_fee' => $fee,
            'admin_fee_vat' => $feeVat,
            'recovery_vat_rate' => $allocation->recoveryVatRate(),
            'recovery_vat' => $recoveryVat + $creditVat,
            'total_due' => round($recovery + $recoveryVat + $fee + $feeVat, 2),
            // The credit side's arithmetic lived in the TEMPLATE, which is how it came to be the
            // half still wrong after the recovery side had been looked at. This method's own
            // contract is "derived once so the view stays a layout" — so it is derived here.
            'net_credit' => round($credit + $creditVat - $fee - $feeVat, 2),
            'exclusions' => is_array($allocation->exclusions) ? $allocation->exclusions : [],
            'proposed_estimate' => $allocation->proposed_monthly_estimate !== null
                ? (float) $allocation->proposed_monthly_estimate
                : null,
        ];
    }

    public function filename(CamAllocation $allocation): string
    {
        /** @var CamExpensePool|null $pool */
        $pool = $allocation->pool;
        /** @var Lease|null $lease */
        $lease = $allocation->lease;

        $year = $pool?->period_year ?? 'cam';
        $ref = $lease?->reference ?? $allocation->id;

        return "cam-statement-{$year}-{$ref}.pdf";
    }
}
