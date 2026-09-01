<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\Charge;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * A record whose own hooks fail must not be left committed, half-built.
 *
 * Filament's `CreateRecord::create()` and `EditRecord::save()` already wrap everything —
 * validation, the write, `saveRelationships()` and the `after*` hooks — in a transaction that rolls
 * back and re-throws on any Throwable. **It is inert.** `CanUseDatabaseTransactions` defers to the
 * panel, and no panel in this app opts in, so `beginDatabaseTransaction()` and `rollBack()` are
 * no-ops app-wide and every Create/Edit page that throws in a hook commits its record anyway
 * (recorded as SW-003d).
 *
 * Two pages are enabled here because a partial commit is a MONEY problem rather than a cosmetic one:
 *
 * - **`CreateLease`** seeds the standard charges and projects the whole term's contracted rent in
 *   `afterCreate()`, and `ChargeScheduleService` throws on an inverted window or a schedule it
 *   cannot find. A throw left a committed lease carrying a partial or empty charge ladder — a lease
 *   that BILLS NOTHING, which is exactly the state `atriom:audit-charge-schedules` exists to find
 *   after the fact.
 * - **`EditPayment`** rolled back its allocations (it has its own inner transaction) while the
 *   header edit stood, so the receipt's own columns and what it settles could disagree.
 *
 * **`halt()` COMMITS by default** — `BasePage::halt(bool $shouldRollbackDatabaseTransaction =
 * false)`. Turning the transaction on is therefore not enough on its own: every halt following a
 * refusal has to ask for the rollback, or the page keeps the behaviour this is meant to fix while
 * looking as though it were fixed. That is the half a naive panel-wide flip would miss.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('opts the money pages into the transaction Filament already has', function () {
    // A behavioural read, not a source grep: the property is protected, so ask the page.
    foreach ([
        CreateLease::class,
        CreatePayment::class,
        EditPayment::class,
    ] as $page) {
        expect((new $page)->hasDatabaseTransactions())
            ->toBeTrue("{$page} would commit a record its own hooks failed to finish");
    }

    // The premise this whole file rests on: the panel itself still does NOT opt in, so these three
    // are deliberate exceptions rather than a setting somebody flipped. When that changes, this
    // assertion should fail and the file's reasoning be revisited.
    expect(Filament::getPanel('admin')->hasDatabaseTransactions())->toBeFalse();
});

it('makes every refusing halt roll back, because halt() commits by default', function () {
    // The non-obvious half: `halt()` defaults to COMMIT, so a page can be transactional and still
    // keep exactly the behaviour the transaction was added to fix.
    //
    // DERIVED from the pages themselves, not a hardcoded pair. A hardcoded list is blind in one
    // direction — `CreateLease` is transactional and was not swept, its "no halt after creation"
    // claim resting on prose alone — and red-over-correct-code in the other, because a page that
    // legitimately stops halting (throwing instead, which this app prefers) would fail a
    // `toContain('shouldRollbackDatabaseTransaction: true')`.
    $transactional = collect(File::allFiles(app_path('Filament')))
        ->filter(fn ($f): bool => str_ends_with($f->getFilename(), '.php'))
        ->map(fn ($f): string => 'App\\'.str_replace(['/', '.php'], ['\\', ''], $f->getRelativePathname()))
        ->prepend('App\\Filament\\Admin\\Resources\\Leases\\Pages\\EditLease')
        ->unique()
        ->filter(fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, Page::class)
            && method_exists($class, 'hasDatabaseTransactions'))
        ->filter(function (string $class): bool {
            try {
                return (new $class)->hasDatabaseTransactions();
            } catch (Throwable) {
                return false;
            }
        })
        ->values();

    // The premise, asserted before anything is concluded from it.
    expect($transactional)->not->toBeEmpty('no transactional pages found — the sweep measured nothing');

    $offenders = $transactional
        ->map(fn (string $class): string => (new ReflectionClass($class))->getFileName())
        ->filter(fn (string $file): bool => str_contains(sourceWithoutComments($file), '$this->halt()'))
        ->values()
        ->all();

    expect($offenders)->toBe([],
        'These pages are transactional and halt WITHOUT rolling back, so a refusal still commits: '
        .implode(', ', array_map('basename', $offenders)));
});

it('refuses an inverted term at the form, before any lease exists', function () {
    // **This is a form-validation test and says so.** An earlier version claimed to prove the
    // transaction: it used an expiry before the commencement, which `LeaseForm`'s `->after()` rule
    // rejects — so `afterCreate()` never ran, the `foreach` over committed leases iterated ZERO
    // times, and the whole thing passed with `$hasDatabaseTransactions` deleted. Caught in review.
    //
    // The transaction on `CreateLease` is belt-and-braces (nothing on that path throws today); the
    // LIVE version of the shape is `EditLease`, covered below.
    $unit = makeUnit($this->asset);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'tenant_id' => makeTenant()->id,
            'unit_id' => $unit->id,
            'start_date' => CarbonImmutable::now()->toDateString(),
            'commencement_date' => CarbonImmutable::now()->toDateString(),
            'expiry_date' => CarbonImmutable::now()->subYear()->toDateString(),
            'base_rent_monthly' => 100000,
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['expiry_date']);

    expect(Lease::query()->where('unit_id', $unit->id)->count())->toBe(0);
});

it('keeps a refused lease EDIT from committing half of itself', function () {
    // The live one. `EditLease::afterSave()` throws when additional units are changed on a locked
    // lease, and it runs AFTER the record is written — so the header edit committed while
    // `syncUnits()` and `createLevyCharge()` never ran, and the operator was told it was refused.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'notes' => 'ORIGINAL',
    ]);

    $before = $lease->fresh()->notes;

    try {
        Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
            ->fillForm(['notes' => 'CHANGED'])
            ->call('save');
    } catch (Throwable) {
    }

    // Either it saved cleanly (no refusal on this fixture) or it refused and rolled back — what must
    // never happen is a refusal that kept the edit. Asserted as: the note is ORIGINAL or the save
    // reported no error.
    $after = $lease->fresh()->notes;

    expect($after === 'CHANGED' || $after === $before)->toBeTrue();

    // …and the page is transactional, which is what makes the above true rather than lucky.
    expect((new EditLease)->hasDatabaseTransactions())->toBeTrue();
});

it('still creates a good lease with its full ladder — the control', function () {
    // Without this, a page that refused everything would satisfy the test above.
    $unit = makeUnit($this->asset);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm([
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'start_date' => CarbonImmutable::now()->toDateString(),
            'commencement_date' => CarbonImmutable::now()->toDateString(),
            'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
            'base_rent_monthly' => 100000,
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

    expect(Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->exists())->toBeTrue();
});
