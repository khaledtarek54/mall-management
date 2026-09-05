<?php

use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ListOwnerStatementRuns;
use App\Filament\Admin\Resources\RentableItems\Pages\ListRentableItems;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\AccountingPeriod;
use App\Models\Announcement;
use App\Models\FiscalYear;
use App\Models\OwnerStatementRun;
use App\Models\RentableItem;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * CLICKING A ROW OPENS THE RECORD TO WORK ON, NOT TO READ.
 *
 * Filament decides what a row click opens by walking `['view', 'edit']` — view FIRST — in both
 * `ListRecords::makeTable()` and `InteractsWithRelationshipTable::makeTable()`. Nothing about a
 * resource states that preference, so which page a click landed on was decided by whether somebody
 * had registered a `view` page for it: four admin resources have one (Tenants, Announcements,
 * RentableItems, OwnerStatementRuns) and sixty-two do not. The same click therefore meant "read
 * this" on four lists and "work on this" on the other sixty-two, with nothing on screen saying so
 * — and the two that a person notices, Tenants and RentableItems, are exactly the registers an
 * operator opens in order to CHANGE something.
 *
 * `App\Support\Filament\RowClickTarget` reverses the order to `['edit', 'view']`, once, in
 * `TableDefaults` — the panel-wide floor — so resource sixty-seven inherits it rather than
 * inheriting whichever page its author happened to register.
 *
 * WHAT ACTUALLY MOVES IS TWO RESOURCES, and this file says which of its cases are teeth and which
 * are controls, because the difference is not visible from the assertions. Filament's loop
 * `continue`s past an action resolving to no URL, and a `ViewAction` on a resource with no `view`
 * PAGE resolves to none — so the other sixty-two lists already fell through to `edit`. Removing the
 * seam turns exactly three cases red: Tenants, RentableItems (whose table carried this rule inline
 * until this change deleted it) and the DRAFT half of the announcements case. The leases, viewer,
 * owner-statement-run and array-row cases are CONTROLS: they pass with or without the seam, and
 * they are here to pin that it changed nothing it was not meant to change.
 *
 * The fallback is the half worth stating: VIEW is not dropped, it is what answers when THIS record
 * cannot be edited — by this user, in this state, or because the resource has no edit page at all.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
});

/**
 * Where a click on this record's row goes, read off the REAL list page's real table, beside the
 * two URLs it could have gone to.
 *
 * All three are resolved inside the property scope: every panel route carries the mall as its
 * `{tenant}` segment, so a URL built outside it throws rather than answering wrongly.
 *
 * @return array{row: ?string, edit: ?string, view: ?string}
 */
function rowClickTargets(string $page, string $resource, $record, $asset): array
{
    return asTenant($asset, fn (): array => [
        'row' => Livewire::test($page)->instance()->getTable()->getRecordUrl($record),
        'edit' => $resource::hasPage('edit') ? $resource::getUrl('edit', ['record' => $record]) : null,
        'view' => $resource::hasPage('view') ? $resource::getUrl('view', ['record' => $record]) : null,
    ]);
}

it('opens the EDIT page from the tenants list, not the view page', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $tenant = makeTenant();

    $targets = rowClickTargets(ListTenants::class, TenantResource::class, $tenant, $this->asset);

    expect($targets['row'])->toBe($targets['edit'])
        ->and($targets['row'])->not->toBe($targets['view']);
});

it('opens the EDIT page from the rentable items list, the other register with a view page', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $bay = RentableItem::create([
        'asset_id' => $this->asset->id, 'code' => 'P-042', 'type' => 'parking',
        'name' => 'Bay 42', 'status' => 'available', 'monthly_rate' => 500,
    ]);

    $targets = rowClickTargets(ListRentableItems::class, RentableItemResource::class, $bay, $this->asset);

    expect($targets['row'])->toBe($targets['edit'])
        ->and($targets['row'])->not->toBe($targets['view']);
});

it('keeps opening the edit page on a list that has no view page at all', function () {
    // CONTROL, not a tooth. Leases declares a modal `ViewAction` and no `view` page, so Filament's
    // own loop already skipped it and landed on `edit` — which is why the operator reported this as
    // "every other resource opens edit". The seam must not disturb the sixty-two lists in that
    // position; this is what says so.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);

    $targets = rowClickTargets(ListLeases::class, LeaseResource::class, $lease, $this->asset);

    expect($targets['view'])->toBeNull()
        ->and($targets['row'])->toBe($targets['edit']);
});

it('falls back to the view page for a reader who may not edit', function () {
    // CONTROL (Filament answered `view` here too, by preferring it): `viewer` holds every `.view`
    // and no `.edit`, and the row must still take them somewhere rather than becoming dead. What it
    // pins is that reversing the ORDER did not cost the reader their destination.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    $tenant = makeTenant();

    $targets = rowClickTargets(ListTenants::class, TenantResource::class, $tenant, $this->asset);

    expect($targets['row'])->toBe($targets['view'])
        ->and($targets['row'])->not->toBe($targets['edit']);
});

it('answers per RECORD: a sent announcement opens its view page, an unsent one opens edit', function () {
    // `AnnouncementResource::canEdit()` refuses a broadcast notice — it is evidence of what was
    // sent. Two rows of one list, one operator, two different destinations.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $draft = Announcement::create([
        'asset_id' => $this->asset->id, 'title' => 'Draft notice',
        'body' => 'Body', 'status' => 'draft',
    ]);
    $sent = Announcement::create([
        'asset_id' => $this->asset->id, 'title' => 'Sent notice',
        'body' => 'Body', 'status' => 'sent', 'sent_at' => now(),
    ]);

    $draftTargets = rowClickTargets(ListAnnouncements::class, AnnouncementResource::class, $draft, $this->asset);
    $sentTargets = rowClickTargets(ListAnnouncements::class, AnnouncementResource::class, $sent, $this->asset);

    expect($sent->isEditable())->toBeFalse()
        ->and($draftTargets['row'])->toBe($draftTargets['edit'])
        ->and($sentTargets['row'])->toBe($sentTargets['view'])
        ->and($sentTargets['row'])->not->toBe($sentTargets['edit']);
});

it('leaves a resource with no edit page alone', function () {
    // CONTROL. Also the only screen that reaches the resource-page fallback at all — the other
    // three lists declaring no row actions are index-only resources with no page to fall back to.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $year = FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]);
    $period = AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 6,
        'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30', 'status' => 'open',
    ]);
    $run = OwnerStatementRun::create([
        'accounting_period_id' => $period->id, 'posting_date' => '2026-06-30',
        'reference' => 'OSR-1', 'asset_id' => $this->asset->id, 'basis' => 'accrual',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft',
    ]);

    $targets = rowClickTargets(ListOwnerStatementRuns::class, OwnerStatementRunResource::class, $run, $this->asset);

    expect(OwnerStatementRunResource::hasPage('edit'))->toBeFalse()
        ->and($targets['row'])->toBe($targets['view']);
});

it('links nothing from a table whose rows are arrays, and does not fatal on one', function () {
    // Filament types a row `Model | array`: eight admin pages are fed from `->records([...])`
    // rather than a query — the four financial statements, the VAT return, weekly spend, the
    // report hub, the configuration health board. An array row addresses no record and belongs to
    // no resource, so there is nothing to link to; typing the seam's parameter `Model` is not a
    // wrong ANSWER but a fatal on mount. 23 admin pages and 2 relation managers are array-backed,
    // 17 of the pages set no `recordUrl` of their own and so arrive here; `AdminPageSmokeTest`
    // caught it via the eight of them it happens to mount.
    $this->actingAs(makeUser('accounting', [$this->asset->id]));

    asTenant($this->asset, function () {
        $table = Livewire::test(IncomeStatement::class)->assertOk()->instance()->getTable();

        expect($table->getRecordUrl(['code' => '41101', 'name' => 'Base rent', 'amount' => 0]))->toBeNull();
    });
});

it('leaves the row-click target to the one seam on every resource list', function () {
    // The seam is a FLOOR: `Table::configureUsing()` runs first, so a resource that declares its
    // own `recordUrl` silently wins and gets its own answer — which is exactly how RentableItems
    // came to carry this rule as a local decision while sixty-five other lists did not, and how the
    // next one would come to disagree with it. Report pages, widgets and maps DO set their own (all
    // fourteen of them point at `edit`); a resource LIST has no business doing so.
    //
    // Source-swept rather than mounted: re-declaring a record URL is a source-level act, and
    // mounting sixty-six list pages to learn it would cost a great deal for a rule that is visible
    // in the file. Comments are stripped first — a docblock that discusses `recordUrl` (this one
    // included, once it is quoted somewhere) is prose, not a call.
    //
    // BLIND SPOT, stated rather than implied: a resource declaring `table()` inline in its own
    // `*Resource.php`, or a `Pages/Manage*.php`, is not swept. Neither shape exists here today
    // (checked), and both would need the glob widened the day one does.
    $exempt = [
        // path => reason. Empty on purpose: no resource list overrides the seam today.
    ];

    $files = collect(glob(app_path('Filament/*/Resources/*/Tables/*.php')))
        ->merge(glob(app_path('Filament/*/Resources/*/Pages/List*.php')))
        ->values();

    // The premise, asserted per HALF: 151 files pass a combined `> 60` with either glob deleted,
    // so a sweep that had quietly stopped looking at the Tables classes would still report clean.
    $tables = $files->filter(fn (string $p): bool => str_contains($p, '/Tables/'));
    $lists = $files->reject(fn (string $p): bool => str_contains($p, '/Tables/'));

    expect($tables->count())->toBeGreaterThan(60)
        ->and($lists->count())->toBeGreaterThan(60);

    $offenders = $files
        ->filter(function (string $path): bool {
            $code = collect(token_get_all(file_get_contents($path)))
                ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token) => is_array($token) ? $token[1] : $token)
                ->implode('');

            return str_contains($code, '->recordUrl(');
        })
        ->map(fn (string $path): string => str_replace(base_path().'/', '', $path))
        ->reject(fn (string $path): bool => array_key_exists($path, $exempt))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
