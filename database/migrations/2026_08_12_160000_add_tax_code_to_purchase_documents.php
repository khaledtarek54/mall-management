<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase document records WHICH input tax it carried, not only the money someone keyed.
 *
 * `vendor_bills.vat_amount` and `expenses.vat_amount` were plain typed figures, and both post to
 * `vat_recoverable` — the account the VAT return reads for input VAT. So the whole input side of a
 * filed return rested on a number with nothing saying what it was: whether the supplier was
 * registered, whether the supply was exempt, whether that figure was 14% of the net or a typo.
 *
 * ## Why the purchase side is gated more lightly than the sales side
 *
 * On an invoice the rate is **our decision**, so `App\Support\CatalogueTaxRate` re-derives it and an
 * operator without `tax_codes.override` cannot land a rate the catalogue did not produce. On a
 * supplier's bill the tax is **their number on their document**, and a system that refused to record
 * what a supplier actually charged would be wrong — the operator would enter the difference
 * somewhere worse. Odoo and SAP both let a vendor bill's tax amount be edited for this reason.
 *
 * So here the amount is *derived and editable*: picking a tax code fills it in, and a departure
 * beyond a pound demands a written reason. One pound, because a rounding difference between two
 * systems computing the same percentage is sub-unit; anything larger is a different rate or a
 * different base, which is a decision and not arithmetic.
 *
 * ## The backfill classifies only what it can prove
 *
 * Two inferences are sound and everything else is a guess:
 *
 *   - `vat_amount = 0` → **`VAT_EXEMPT_P`**. Nothing was reclaimed, which is exactly what that code
 *     says. This is not a guess about the supplier; it is a restatement of the figure.
 *   - `vat_amount` equals the standard rate applied to the net, to the piastre → **`VAT_14_P`**.
 *
 * Anything else stays NULL — a document whose tax is neither zero nor the standard rate is telling
 * us something the migration cannot read, and putting a confident code on it would bury that.
 */
return new class extends Migration
{
    /** The purchases-side codes this backfill can prove a document into. */
    private const STANDARD_INPUT = 'VAT_14_P';

    private const NO_INPUT = 'VAT_EXEMPT_P';

    /** Line table => the net column the tax was computed on, and the document's date. */
    private const TABLES = [
        'vendor_bills' => ['net' => 'subtotal', 'date' => 'bill_date'],
        'expenses' => ['net' => 'amount', 'date' => 'expense_date'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('tax_code', 32)->nullable()->after('vat_amount');
                $t->string('tax_override_reason')->nullable()->after('tax_code');
                $t->index('tax_code');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['tax_code']);
                $t->dropColumn(['tax_code', 'tax_override_reason']);
            });
        }
    }

    private function backfill(): void
    {
        $rungs = DB::table('tax_rates')
            ->join('tax_codes', 'tax_codes.id', '=', 'tax_rates.tax_code_id')
            ->where('tax_codes.code', self::STANDARD_INPUT)
            ->orderByDesc('tax_rates.effective_from')
            ->get(['tax_rates.rate', 'tax_rates.effective_from'])
            ->map(fn ($r) => [substr((string) $r->effective_from, 0, 10), (float) $r->rate])
            ->all();

        foreach (self::TABLES as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->orderBy('id')
                ->select('id', $cols['net'].' as net', 'vat_amount', $cols['date'].' as doc_date')
                ->chunk(500, function ($rows) use ($table, $rungs) {
                    foreach ($rows as $row) {
                        $vat = round((float) $row->vat_amount, 2);

                        if ($vat === 0.0) {
                            DB::table($table)->where('id', $row->id)->update(['tax_code' => self::NO_INPUT]);

                            continue;
                        }

                        $rate = $this->rateOn($rungs, substr((string) $row->doc_date, 0, 10));

                        if ($rate === null) {
                            continue;
                        }

                        if (abs(round((float) $row->net * $rate / 100, 2) - $vat) < 0.005) {
                            DB::table($table)->where('id', $row->id)->update(['tax_code' => self::STANDARD_INPUT]);
                        }
                    }
                });
        }
    }

    /** @param array<int, array{0: string, 1: float}> $rungs newest first */
    private function rateOn(array $rungs, string $date): ?float
    {
        foreach ($rungs as [$from, $rate]) {
            if ($from <= $date) {
                return $rate;
            }
        }

        return $rungs === [] ? null : end($rungs)[1];
    }
};
