<?php

namespace App\Services;

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

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
    public function build(CamAllocation $allocation): string
    {
        $allocation->loadMissing(['pool.asset', 'lease.tenant', 'lease.unit.asset', 'lease.units']);

        // Both parents SOFT-DELETE, so either relation genuinely returns null at runtime for a
        // trashed pool or lease, and the `?->` below is load-bearing rather than defensive noise.
        // Larastan types the relation as non-null regardless of the annotation, so those reads are
        // baselined under the same `nullsafe.neverNull` exemption as every other soft-deleted
        // parent in this codebase — removing the `?->` would be a real crash, not a cleanup.
        /** @var \App\Models\CamExpensePool|null $pool */
        $pool = $allocation->pool;
        /** @var \App\Models\Lease|null $lease */
        $lease = $allocation->lease;

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('cam.statement', [
            'allocation' => $allocation,
            'pool' => $pool,
            'lease' => $lease,
            'tenant' => $lease?->tenant,
            'asset' => $pool?->asset ?? $lease?->unit?->asset,
            'facts' => $this->facts($allocation),
            'isRtl' => $isRtl,
        ])->render();

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
            'default_font' => $isRtl ? 'xbriyaz' : 'dejavusans',
            'default_font_size' => 10.5,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useSubstitutions' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Everything the statement states, derived once so the view stays a layout.
     *
     * @return array<string, mixed>
     */
    public function facts(CamAllocation $allocation): array
    {
        /** @var \App\Models\CamExpensePool|null $pool */
        $pool = $allocation->pool;
        /** @var \App\Models\Lease|null $lease */
        $lease = $allocation->lease;

        $share = (float) $allocation->pro_rata_share_pct;
        $area = $lease?->totalAreaSqm() ?? 0.0;

        // The denominator that was USED, recovered from the share that was stored — not the one
        // that would be computed today. That distinction is the whole point of a statement issued
        // against a reconciliation rather than against the live data.
        $denominator = $share > 0 ? round($area / ($share / 100), 2) : null;

        $capped = $allocation->capped_cost_amount !== null
            ? (float) $allocation->capped_cost_amount
            : (float) $allocation->allocated_amount;

        $trueUp = (float) $allocation->true_up_amount;

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
            'share_is_stated' => $lease?->statedCamSharePct((int) ($pool?->period_year ?? 0)) !== null,
            'share_pct' => $share,
            'allocated' => (float) $allocation->allocated_amount,
            'cap_amount' => $allocation->cap_amount !== null ? (float) $allocation->cap_amount : null,
            'capped_cost' => $capped,
            'cap_absorbed' => (float) $allocation->cap_absorbed_amount,
            'estimated_paid' => (float) $allocation->estimated_paid,
            'true_up' => $trueUp,
            'true_up_is_credit' => $trueUp < 0,
            'admin_fee_pct' => (float) ($pool?->admin_fee_pct ?? 0) * 100,
            'admin_fee' => (float) $allocation->admin_fee_amount,
            'admin_fee_vat' => (float) $allocation->admin_fee_vat_amount,
            'total_due' => round(max($trueUp, 0) + (float) $allocation->admin_fee_amount + (float) $allocation->admin_fee_vat_amount, 2),
            'exclusions' => is_array($allocation->exclusions) ? $allocation->exclusions : [],
            'proposed_estimate' => $allocation->proposed_monthly_estimate !== null
                ? (float) $allocation->proposed_monthly_estimate
                : null,
        ];
    }

    public function filename(CamAllocation $allocation): string
    {
        /** @var \App\Models\CamExpensePool|null $pool */
        $pool = $allocation->pool;
        /** @var \App\Models\Lease|null $lease */
        $lease = $allocation->lease;

        $year = $pool?->period_year ?? 'cam';
        $ref = $lease?->reference ?? $allocation->id;

        return "cam-statement-{$year}-{$ref}.pdf";
    }
}
