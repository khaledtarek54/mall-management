<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A document line records WHICH TAX it was billed under, not only the number that came out.
 *
 * Until now an invoice line stored `vat_rate` and nothing else, and that is one fact short in two
 * places that matter:
 *
 *   1. **The VAT return cannot tell exempt from zero-rated.** `VatReturnService` splits the taxable
 *      base on `vat_rate > 0`, so a zero-rated supply and an out-of-scope one land in the same
 *      bucket. They are different lines on a filed return. The distinction exists on the tax code
 *      and was simply not being carried onto the document.
 *   2. **A rate typed by hand is indistinguishable from the catalogue's.** Once the line names its
 *      tax code, an override is *detectable* — the stored rate differs from what that code resolves
 *      for the document's date — which is what makes a rights-gated override auditable rather than
 *      merely permitted. `tax_override_reason` is where the operator says why.
 *
 * The line still stores its own `vat_rate`, and that stays the figure every downstream path reads.
 * The code is a classification carried alongside it, never a pointer the totals are re-derived
 * through: an invoice issued at 14% is a 14% document forever, and re-resolving through the code
 * would undo exactly that.
 *
 * ## The backfill classifies only what it can prove
 *
 * For an existing line, the tax code it was billed under is knowable only by inference: its charge
 * code's current tax code, resolved at the parent document's date. That inference is sound when the
 * rate it produces **equals the rate actually stored on the line**, and unsound otherwise — a charge
 * code re-pointed since the invoice was raised, or a rate typed by hand back when nothing stopped
 * it, would both produce a confident-looking classification that nobody ever made.
 *
 * So the backfill sets the code where the rates agree and leaves it NULL where they do not. A null
 * here reads as "this line predates the classification", which is true, rather than as a guess on a
 * filed document.
 */
return new class extends Migration
{
    /**
     * Line table => where the column sits, and whether the backfill can classify it.
     *
     * Only invoice lines can be classified retrospectively: they carry a `type` (a charge code),
     * which is what the inference below runs on. **A credit-note line has no charge code at all** —
     * it inherits its rate from the invoice line it reverses, and nothing links the two rows — so
     * there is nothing to infer from and the column stays null on existing notes. Going forward the
     * form copies the source line's tax code across, which is the only correct answer: a credit note
     * reverses a supply at that supply's own treatment.
     */
    private const TABLES = [
        'invoice_items' => ['after' => 'type', 'backfill' => ['parent' => 'invoices', 'fk' => 'invoice_id', 'date' => 'issue_date']],
        'credit_note_items' => ['after' => 'description', 'backfill' => null],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $config) {
            Schema::table($table, function (Blueprint $t) use ($config) {
                // By code, matching `charge_codes.tax_code` — the string is the identity, and an id
                // would make the seeded catalogue's auto-increment part of the document.
                $t->string('tax_code', 32)->nullable()->after($config['after']);

                // Set only when the operator overrode the catalogue's rate. Its presence IS the
                // flag; there is no separate boolean to fall out of step with it.
                $t->string('tax_override_reason')->nullable()->after('vat_rate');

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

    /**
     * Classify existing lines where the inference is provable, and leave the rest null.
     */
    private function backfill(): void
    {
        $chargeCodeTax = DB::table('charge_codes')->pluck('tax_code', 'code')->filter()->all();

        if ($chargeCodeTax === []) {
            return;
        }

        // The whole ladder in memory: a handful of codes with a handful of rungs each, versus one
        // query per line across every invoice ever raised.
        $ladders = [];
        foreach (DB::table('tax_rates')
            ->join('tax_codes', 'tax_codes.id', '=', 'tax_rates.tax_code_id')
            ->orderByDesc('tax_rates.effective_from')
            ->get(['tax_codes.code', 'tax_codes.treatment', 'tax_rates.rate', 'tax_rates.effective_from']) as $rung) {
            $ladders[$rung->code]['treatment'] ??= $rung->treatment;
            $ladders[$rung->code]['rungs'][] = [substr((string) $rung->effective_from, 0, 10), (float) $rung->rate];
        }

        foreach (self::TABLES as $table => $config) {
            $parent = $config['backfill'];

            if ($parent === null) {
                continue;
            }

            DB::table($table)
                ->join($parent['parent'], "{$parent['parent']}.id", '=', "{$table}.{$parent['fk']}")
                ->orderBy("{$table}.id")
                ->select("{$table}.id", "{$table}.type", "{$table}.vat_rate", "{$parent['parent']}.{$parent['date']} as doc_date")
                ->chunk(500, function ($lines) use ($table, $chargeCodeTax, $ladders) {
                    foreach ($lines as $line) {
                        $code = $chargeCodeTax[$line->type] ?? null;

                        if ($code === null || ! isset($ladders[$code])) {
                            continue;
                        }

                        $resolved = $ladders[$code]['treatment'] === 'standard'
                            ? $this->rateOn($ladders[$code]['rungs'], substr((string) $line->doc_date, 0, 10))
                            : 0.0;

                        // Only where the inference reproduces what was actually billed.
                        if ($resolved !== null && abs($resolved - (float) $line->vat_rate) < 0.0005) {
                            DB::table($table)->where('id', $line->id)->update(['tax_code' => $code]);
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

        // Before the earliest rung the earliest rate applies — the same reading `TaxCode` uses.
        return $rungs === [] ? null : end($rungs)[1];
    }
};
