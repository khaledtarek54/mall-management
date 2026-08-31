<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One DISPOSABLE lease per state, so a browser sweep can reach the state-gated screens.
 *
 * **Why this exists.** The demo books hold 33 leases and all 33 are `active`, so five of the seven
 * states `ValueSets` allows had never been opened in a browser — and three lease actions gate on
 * state (`finalAccount` needs terminated or expired, `convertToHoldover` needs a term that has run
 * out, `releaseRentableItem` needs a holding). A sweep over the demo data reports "clean" having
 * never mounted them, which is the same failure as a sweep that signs in wrong: a green result
 * about a set it could not see.
 *
 * **Disposable, and named so.** Every lease is referenced `SWEEP-<STATE>` on a vacant unit with a
 * throwaway tenant, so `--drop` can find and remove them without touching a real record. They carry
 * no charges, no invoices and no GL, so nothing they do reaches the books.
 *
 * Not part of `DemoSeeder`: a demo mall with a cancelled and an expired lease sitting in it is a
 * worse teaching set, and these exist to be swept and thrown away.
 */
class SeedLeaseStatesCommand extends Command
{
    protected $signature = 'atriom:seed-lease-states {--asset=AW} {--drop : Remove the sweep leases instead}';

    protected $description = 'Create one disposable lease per state so a browser sweep can reach every state-gated screen.';

    private const PREFIX = 'SWEEP-';

    /** state => lease id, read by tools/leasing-sweep.mjs so it navigates by ID rather than search. */
    public const MAP = 'sweep-lease-states.json';

    public function handle(): int
    {
        $asset = Asset::where('code', $this->option('asset'))->first();

        if (! $asset) {
            $this->error("No property with code {$this->option('asset')}.");

            return self::FAILURE;
        }

        if ($this->option('drop')) {
            return $this->drop();
        }

        $tenant = Tenant::firstOrCreate(
            ['name' => 'Sweep Fixture Co.'],
            ['email' => 'sweep@atriom.test', 'phone' => '+20 100 000 0000', 'status' => 'active'],
        );

        $units = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('code')->get();

        // The seven `ValueSets` states, plus HOLDOVER — which is not a status at all but a lease
        // that is still `active` with a term that has run out. `Lease::isHoldover()` is that pair,
        // and `convertToHoldover` is the only header action gated on it, so without this row the
        // sweep can never mount it however many statuses it walks.
        $states = [...ValueSets::allowed('leases', 'status'), 'holdover'];

        if ($units->count() < count($states)) {
            $this->error("Need {$states} vacant units in {$asset->code}; found {$units->count()}.");

            return self::FAILURE;
        }

        $today = CarbonImmutable::today();
        $made = 0;

        foreach ($states as $i => $state) {
            $reference = self::PREFIX.strtoupper($state);

            if (Lease::where('reference', $reference)->exists()) {
                continue;
            }

            $unit = $units[$i];

            // An EXPIRED lease needs a term that has actually run out, and a terminated one needs an
            // expiry in the past too — otherwise `convertToHoldover` and `finalAccount` stay hidden
            // and the sweep mounts nothing, which is the whole failure this command exists to fix.
            $ended = in_array($state, ['expired', 'terminated', 'cancelled', 'renewed', 'holdover'], true);

            $lease = new Lease([
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'commencement_date' => $today->subYears(2),
                'expiry_date' => $ended ? $today->subMonths(2) : $today->addYears(2),
                'term_months' => 24,
                'base_rent_monthly' => 10_000,
                'service_charge_monthly' => 1_500,
                'currency' => 'EGP',
                // A holdover is ACTIVE with an expiry behind it — that pair IS the state.
                'status' => $state === 'holdover' ? 'active' : $state,
            ]);

            $lease->reference = $reference;
            $lease->save();
            $lease->units()->syncWithoutDetaching([$unit->id]);

            $made++;
        }

        // The sweep navigates BY ID. It used to find these by `?tableSearch=SWEEP-…`, which does
        // not bind Filament's table state from a plain URL — every search returned the whole list
        // and the sweep opened the same active lease seven times, reporting seven "ok"s about one
        // record. A green result about a set it could not see, which is the failure this whole
        // command exists to prevent.
        // Keyed on the REFERENCE suffix, not on `status`: holdover shares `active`'s status and
        // would otherwise overwrite it in the map, silently dropping the one row that reaches
        // `convertToHoldover`.
        $map = Lease::where('reference', 'like', self::PREFIX.'%')
            ->get()
            ->mapWithKeys(fn (Lease $l) => [strtolower(str_replace(self::PREFIX, '', $l->reference)) => $l->id])
            ->all();

        Storage::disk('local')->put(self::MAP, json_encode($map, JSON_PRETTY_PRINT));

        $this->info("{$made} sweep lease(s) created. Remove them with --drop.");
        $this->line('  id map → '.Storage::disk('local')->path(self::MAP));
        $this->table(
            ['Reference', 'Status', 'Unit'],
            Lease::where('reference', 'like', self::PREFIX.'%')->with('unit')->get()
                ->map(fn (Lease $l) => [$l->reference, $l->status, $l->unit?->code])->all(),
        );

        return self::SUCCESS;
    }

    private function drop(): int
    {
        $leases = Lease::where('reference', 'like', self::PREFIX.'%')->get();

        foreach ($leases as $lease) {
            $lease->units()->detach();
            $lease->forceDelete();
        }

        Tenant::where('name', 'Sweep Fixture Co.')->whereDoesntHave('leases')->forceDelete();

        Storage::disk('local')->delete(self::MAP);

        $this->info("{$leases->count()} sweep lease(s) removed.");

        return self::SUCCESS;
    }
}
