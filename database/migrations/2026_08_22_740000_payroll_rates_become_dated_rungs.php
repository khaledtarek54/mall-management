<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Egypt's statutory payroll numbers become a DATED LADDER, and gain the insurable-wage band
 * they never had (EG-03, findings P-1 and P-3).
 *
 * `GeneratePayrollService` read three flat settings with **no date argument**, so a January run
 * generated in March used March's numbers, a rise could not be entered in advance, and nothing
 * recorded what was in force when a past run was computed. Egypt has raised the insurable-wage band
 * on a January cadence for several years running, which makes an undated scalar wrong every year by
 * construction. The correct shape was already in this codebase — `TaxCode::rateOn($code, $on)`, a
 * rung with a start date and no end date — and this is that shape for payroll.
 *
 * ## A row is a SET of figures, not a key/value pair
 *
 * Egypt publishes these together: one decree sets the band and the contribution rates, effective
 * 1 January. So a row here is *"the statutory numbers in force from this date"*, which is both how
 * the accountant receives them and how they will want to enter them — one row a year.
 *
 * It also avoids inventing a classification column. A `key`/`value` table would need a
 * `ValueSets` entry to stop `insurable_wage_ceiling` being saved as `insurable_wage_celing`, and a
 * set of columns cannot be mistyped at all.
 *
 * ## The band, and why it is the fix for P-1
 *
 * Social insurance is charged on the **insurable wage** — the gross clamped into a floor/ceiling
 * band — not on the whole salary. The service applied the rate to `base_salary` outright, so every
 * employee above the ceiling was over-deducted and the employer over-accrued. The employer-share
 * line even carried the comment *"Employer SI is a company cost — it does NOT reduce net, so no cap
 * needed"*, which misreads the rule: the cap is on the WAGE, and it binds both shares.
 *
 * Null floor and null ceiling mean *no band*, which is what every period before 1 Jan 2026 gets
 * here — we know the band was raised **to** 2,700/16,700 on that date and this migration does not
 * claim to know what preceded it. Uncapped is also exactly today's behaviour, so no historical run
 * changes.
 *
 * ## Seed the vocabulary, not the numbers
 *
 * The 1 Jan 2026 rung carries the **band** (EGP 2,700 / 16,700, NOSI) because that is a statutory
 * fact with a published date, not a policy choice — the same standing this project gives
 * `Vat::DEFAULT_STANDARD_RATE`.
 *
 * It does **not** seed the contribution rates, even though 11% employee is equally published. The
 * install ships them at 0 and the settings screen's own help offers *"leave at 0 and enter it per
 * employee"* as a supported posture; a migration that started withholding 11% from every salary
 * would be this software deciding to deduct money from people. The rates carried over are whatever
 * the operator already had. Same decision, and the same reason, as `TaxCodeSeeder` shipping the tax
 * vocabulary with its codes switched off.
 *
 * Which means **the band changes nothing on deploy**: it only bites through a non-zero rate, and a
 * rate of zero times any wage is zero.
 */
return new class extends Migration
{
    /** NOSI, effective 1 January 2026. The rise this ladder exists to make expressible. */
    private const BAND_FROM = '2026-01-01';

    private const FLOOR = 2700.00;

    private const CEILING = 16700.00;

    public function up(): void
    {
        Schema::create('payroll_rates', function (Blueprint $table) {
            $table->id();

            // Unique, not a from/to pair. A rung runs until the next one starts; a second date
            // column makes overlapping and missing windows representable, and this project has
            // already been bitten by exactly that on charge schedules.
            $table->date('effective_from')->unique();

            $table->decimal('employee_social_insurance_rate', 6, 3)->default(0);
            $table->decimal('employer_social_insurance_rate', 6, 3)->default(0);
            $table->decimal('salary_tax_rate', 6, 3)->default(0);

            // Nullable = no band. Not 0, which would read as "the ceiling is zero" and clamp every
            // insurable wage to nothing.
            $table->decimal('insurable_wage_floor', 12, 2)->nullable();
            $table->decimal('insurable_wage_ceiling', 12, 2)->nullable();

            $table->string('note')->nullable();
            $table->timestamps();
        });

        $rates = $this->currentSettingRates();

        // Every payroll already run must resolve to the numbers it was computed with, so the
        // carried-over rung starts no later than the earliest payroll month on the books.
        $earliest = DB::table('payrolls')->min('period_month');
        $earliest = $earliest === null ? null : substr((string) $earliest, 0, 10);

        if ($earliest !== null && $earliest < self::BAND_FROM) {
            DB::table('payroll_rates')->insert($rates + [
                'effective_from' => $earliest,
                'insurable_wage_floor' => null,
                'insurable_wage_ceiling' => null,
                'note' => 'Carried over from settings; no insurable-wage band recorded for this period.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('payroll_rates')->insert($rates + [
            'effective_from' => self::BAND_FROM,
            'insurable_wage_floor' => self::FLOOR,
            'insurable_wage_ceiling' => self::CEILING,
            'note' => 'NOSI insurable-wage band from 1 Jan 2026 (2,700 / 16,700). Contribution rates carried over from settings — set them here.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_rates');
    }

    /**
     * The three rates as the operator has them today, read straight from the settings table.
     *
     * Read from the ROW rather than through `app(PayrollSettings::class)`: a migration that
     * resolves a settings class breaks the day a property is renamed or removed, which is exactly
     * what the companion settings migration does to these three.
     *
     * @return array<string, float>
     */
    private function currentSettingRates(): array
    {
        $value = function (string $name, float $default): float {
            $raw = DB::table('settings')->where('group', 'payroll')->where('name', $name)->value('payload');

            return $raw === null ? $default : (float) json_decode((string) $raw, true);
        };

        return [
            'employee_social_insurance_rate' => $value('social_insurance_rate', 0.0),
            'employer_social_insurance_rate' => $value('employer_social_insurance_rate', 0.0),
            'salary_tax_rate' => $value('salary_tax_rate', 0.0),
        ];
    }
};
