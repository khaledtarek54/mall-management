<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's withholding is a TAX CODE, not a percentage typed on the vendor.
 *
 * `vendors.withholding_tax_rate` held a bare number, and `TaxSettings::wht_default_rate` held one
 * more as the portfolio fallback. Both predate the tax catalogue, and both carry the flaw its own
 * docblock named: *"the statutory rate depends on the nature of the payment (supplies, services,
 * contracting, professional fees)… a guessed constant would look authoritative and be wrong."* A
 * free percentage box invites exactly that guess, one supplier at a time.
 *
 * The operator's own sheet lists withholding at **four rates** — 0.5 · 1 · 3 · 5% — and a supplier
 * is pointed at whichever the accountant rules applies. That is now the only way to express it.
 *
 * ## Two columns, because null was carrying two meanings
 *
 * The old column overloaded its values: **null** meant "nothing set, use the portfolio default" and
 * an explicit **0** meant "this supplier is exempt from withholding". Both were real states and the
 * distinction mattered — collapsing them would silently start withholding from an exempt vendor the
 * next time the default changed — but expressing it as a magic zero needed a paragraph of comment
 * everywhere it was read.
 *
 *   - `withholding_tax_code` — the WH code agreed with this supplier; null = use the default.
 *   - `withholding_exempt`   — this supplier is outside Egyptian withholding altogether.
 *
 * Exemption is a flag rather than a `WH_0` code deliberately: the operator's sheet has no zero
 * withholding rate, and inventing one would put a row in the catalogue that their accountant never
 * asked for — the standing instruction on that document. Not withholding is the absence of a tax,
 * not a tax of nothing.
 *
 * ## The backfill maps what the sheet can express, and refuses to round
 *
 * `0` → exempt. `0.5 / 1 / 3 / 5` → the matching code. **Any other rate is left unset**, because
 * the catalogue has no code for it and picking the nearest would change what is withheld from a
 * supplier by a decision nobody made. The feature ships disabled (`wht_enabled`), so an unset
 * vendor withholds nothing until the accountant re-picks — which is the safe direction, and the
 * vendors table flags them.
 */
return new class extends Migration
{
    /** Rate on the old column => the code on the operator's sheet that expresses it. */
    private const RATE_TO_CODE = [
        '0.50' => 'WH_0_5',
        '1.00' => 'WH_1',
        '3.00' => 'WH_3',
        '5.00' => 'WH_5',
    ];

    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('withholding_tax_code', 32)->nullable()->after('tax_id');
            $table->boolean('withholding_exempt')->default(false)->after('withholding_tax_code');
        });

        foreach (DB::table('vendors')->whereNotNull('withholding_tax_rate')->get(['id', 'withholding_tax_rate']) as $vendor) {
            $rate = number_format((float) $vendor->withholding_tax_rate, 2, '.', '');

            DB::table('vendors')->where('id', $vendor->id)->update(
                $rate === '0.00'
                    ? ['withholding_exempt' => true]
                    : ['withholding_tax_code' => self::RATE_TO_CODE[$rate] ?? null],
            );
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('withholding_tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('withholding_tax_rate', 5, 2)->nullable()->after('tax_id');
        });

        $codeToRate = array_flip(self::RATE_TO_CODE);

        foreach (DB::table('vendors')->get(['id', 'withholding_tax_code', 'withholding_exempt']) as $vendor) {
            $rate = $vendor->withholding_exempt
                ? '0.00'
                : ($codeToRate[$vendor->withholding_tax_code] ?? null);

            if ($rate !== null) {
                DB::table('vendors')->where('id', $vendor->id)->update(['withholding_tax_rate' => $rate]);
            }
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['withholding_tax_code', 'withholding_exempt']);
        });
    }
};
