<?php

/*
|--------------------------------------------------------------------------
| A demo for a prospect carries THEIR mall's name, on every document
|--------------------------------------------------------------------------
| Asked for on 2026-09-01: the demo is for Val Plaza, not for the fictional
| "Atriom Walk" the seeder ships.
|
| **Why this could not be a rename after seeding.** The mall CODE is baked into
| every document number the run allocates — `LSE-VP-2026-0007`, `INV-VP-0341`,
| `BILL-VP-0004`. Seeding as one mall and renaming the asset afterwards leaves
| every invoice, lease and receipt in the demo carrying the previous mall's
| initials, on the page a client reads first. It has to be set before the first
| document is allocated, which means inside the seeder.
|
| **Why a subclass and not a fork.** `DemoSeeder` is two thousand lines of a
| mall mid-life. A copy would be a second dataset to keep in step, and the one
| not being reseeded daily would rot — the failure this repo already records for
| parallel doc sets. So the identity is a few overridable methods and the
| subclass is the only thing that changes.
*/

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ValPlazaSeeder;

it('brands the estate and every document it allocates', function () {
    $this->seed(ValPlazaSeeder::class);

    expect(Asset::where('code', 'VP')->value('name'))->toBe('Val Plaza')
        ->and(Asset::where('code', 'VA')->value('name'))->toBe('Val Annex')
        // The whole reason this lives in the seeder rather than in a rename.
        ->and(Lease::query()->pluck('reference')->every(fn ($r) => str_starts_with($r, 'LSE-VP-')))->toBeTrue()
        ->and(Invoice::query()->pluck('number')->filter()->every(fn ($n) => str_starts_with($n, 'INV-VP-')))->toBeTrue()
        // Nothing of ours left on the estate the client is shown.
        ->and(Asset::query()->pluck('name')->contains(fn ($n) => str_contains($n, 'Atriom')))->toBeFalse();
});

/*
| CONTROL — the default seeder is untouched.
|
| Without this, "Val Plaza works" could be satisfied by renaming the demo estate
| for everyone, which would break 46 files' worth of references and every test
| that reads `code = 'AW'`.
*/
it('leaves the default demo estate exactly as it was', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Asset::where('code', 'AW')->value('name'))->toBe('Atriom Walk')
        ->and(Lease::query()->pluck('reference')->every(fn ($r) => str_starts_with($r, 'LSE-AW-')))->toBeTrue();
});

/*
| The prerequisites are shared, not re-typed. `--seeder=` runs ONE class, so
| ValPlazaSeeder has to lay down the reference data itself; taking that list from
| DatabaseSeeder is what stops the two drifting until a run dies half-way
| through on a missing role, having already written half a mall.
*/
it('takes its reference data from the one list', function () {
    expect(DatabaseSeeder::REFERENCE)->not->toBeEmpty()
        ->and(DatabaseSeeder::REFERENCE)->not->toContain(\Database\Seeders\DemoSeeder::class);

    $source = file_get_contents(base_path('database/seeders/ValPlazaSeeder.php'));

    expect($source)->toContain('DatabaseSeeder::REFERENCE');
});

/*
| It must NOT satisfy the blocking tax-identity check on the operator's behalf.
| That row exists so an install cannot issue a document titled "Tax Invoice"
| carrying no registration; a seeder that quietly turned it green would make the
| check answer for itself.
*/
it('does not invent a tax registration', function () {
    $this->seed(ValPlazaSeeder::class);

    expect(app(\App\Settings\TaxSettings::class)->seller_tax_registration_number)->toBeEmpty();
});
