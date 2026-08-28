<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use Illuminate\Console\Command;

/**
 * Put the teaching lease back, through the real service.
 *
 * **Why a command and not "just do it again by hand".** The empty-mall walkthrough is built one
 * record at a time on purpose — that is what makes every figure on screen the learner's own. It
 * also means a `migrate:fresh` from ANOTHER session destroys an hour of somebody's work with no
 * warning, which is exactly what happened (2026-08-28 15:40): a second session reseeded the shared
 * development database and the hand-built Cilantro lease went with it.
 *
 * So the walkthrough's starting position is reproducible in one step. It is NOT a seeder: seeders
 * lay down reference data, and this is a worked example that goes through `LeaseCreationService`
 * exactly as the form does — the unit lock, the charge ladder, the escalation steps and all. A
 * seeder writing its own rows would restore a lease the app could never have produced.
 *
 *   php artisan atriom:learning-lease
 *   php artisan atriom:learning-lease --fresh   # empty the mall first, then rebuild
 */
class SeedLearningLeaseCommand extends Command
{
    protected $signature = 'atriom:learning-lease
        {--fresh : Reset to the empty learning mall first (destroys everything in the database)}';

    protected $description = 'Rebuild the teaching lease (Cilantro · B-02 · 4,800/m²/yr) through the real service';

    public function handle(LeaseCreationService $leases): int
    {
        if ($this->option('fresh')) {
            $this->components->task('Resetting to the empty learning mall', function () {
                $this->callSilent('migrate:fresh', [
                    '--seed' => true,
                    '--seeder' => 'Database\\Seeders\\LearningSeeder',
                    '--force' => true,
                ]);

                return true;
            });
        }

        $tenant = Tenant::where('name', 'like', 'Cilantro%')->first();
        $unit = Unit::where('code', 'B-02')->first();

        if (! $tenant || ! $unit) {
            $this->error('The learning mall is not seeded — run with --fresh, or seed LearningSeeder first.');

            return self::FAILURE;
        }

        if (Lease::whereHas('units', fn ($q) => $q->whereKey($unit->id))->exists()) {
            $this->warn("Unit {$unit->code} already has a lease — nothing to do.");

            return self::SUCCESS;
        }

        // `LeaseCreationService` reads `base_rent_monthly` directly — the FORM computes it live from
        // the rate and submits both. Derived here through the lease's own helper rather than
        // multiplied by hand, so this command can never disagree with what the panel would save.
        $rate = 4800.0;
        $monthly = round($rate * (float) $unit->area_sqm / 12, 2);

        // The figures the walkthrough is written around: 4,800/m² × 110 m² ÷ 12 = 44,000, a 7%
        // annual step, and a marketing levy the settings derive at 5% of rent.
        $lease = $leases->create([
            'tenant_mode' => 'existing',
            'tenant_id' => $tenant->id,
            'lease' => [
                'unit_id' => $unit->id,
                'commencement_date' => '2026-09-01',
                'term_months' => 36,
                'base_rent_rate_per_sqm_year' => $rate,
                'base_rent_monthly' => $monthly,
                'escalation_type' => 'percentage',
                'escalation_percentage' => 7,
                'has_marketing_levy' => true,
                'status' => 'active',
            ],
        ]);

        $lease->refresh()->load('charges');

        $this->newLine();
        $this->line("  <fg=green>✓</> {$lease->reference} — {$tenant->name} · {$unit->code} · ".number_format((float) $unit->area_sqm).' m²');
        $this->line('    rent: '.number_format((float) $lease->base_rent_monthly, 2).' / month  ('.number_format((float) $lease->base_rent_rate_per_sqm_year).' × '.number_format((float) $unit->area_sqm).' ÷ 12)');
        $this->newLine();

        $this->table(
            ['Charge', 'Amount', 'Every', 'From'],
            $lease->charges->sortBy('start_date')->map(fn ($c) => [
                $c->type,
                number_format((float) $c->amount, 2),
                $c->frequency,
                $c->start_date?->format('Y-m-d'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
