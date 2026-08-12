<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActivityLogChangeRenderer;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Domain helpers — keep tests terse by hiding scaffolding here.
|--------------------------------------------------------------------------
*/

/**
 * Create the role catalogue Pest tests assume exists. Run from beforeEach
 * blocks in tests that touch roles.
 */
function seedRoles(): void
{
    foreach (['super_admin', 'manager', 'leasing', 'operations', 'viewer', 'owner'] as $role) {
        Role::findOrCreate($role, 'web');
    }
}

/**
 * Ensure the synthetic "All Properties" Asset row exists in the in-memory
 * test DB. The production migration seeds it; tests that need it before
 * the migration runs can call this in beforeEach.
 */
function ensureAllPropertiesAsset(): Asset
{
    return Asset::firstOrCreate(
        ['code' => Asset::ALL_PROPERTIES_CODE],
        [
            'name' => 'All Properties',
            'type' => 'mall',
            'city' => '—',
            'country' => '—',
            'currency' => 'EGP',
            'is_active' => false,
        ],
    );
}

/**
 * The rows a Filament table actually returned.
 *
 * ALWAYS use this instead of `collect($page->getTableRecords())`. A table that
 * paginates hands back a LengthAwarePaginator, and collect()ing a paginator
 * wraps the paginator OBJECT rather than its items — so `->pluck('id')` yields
 * a column of nulls and `->sum()` yields 0, silently, with no error. Several
 * tests have been written wrong this way; this is the one correct accessor.
 *
 * @param  Testable|object  $component  a Livewire test handle or a page instance
 */
function tableRows(object $component): Collection
{
    $instance = method_exists($component, 'instance') ? $component->instance() : $component;
    $records = $instance->getTableRecords();

    if (method_exists($records, 'getCollection')) {
        return $records->getCollection();
    }

    return $records instanceof Collection ? $records : collect($records);
}

function makeAsset(array $attrs = []): Asset
{
    return Asset::create(array_merge([
        'name' => 'Asset '.uniqid(),
        'code' => strtoupper(substr(uniqid(), -6)),
        'type' => 'mall',
        'city' => 'Cairo',
        'country' => 'EG',
        'total_area_sqm' => 1000,
        'leasable_area_sqm' => 800,
        'currency' => 'EGP',
        'is_active' => true,
    ], $attrs));
}

function makeUnit(Asset $asset, array $attrs = []): Unit
{
    return Unit::create(array_merge([
        'asset_id' => $asset->id,
        'code' => 'U-'.uniqid(),
        'area_sqm' => 100,
        'status' => 'vacant',
        'category' => 'retail',
    ], $attrs));
}

function makeTenant(array $attrs = []): Tenant
{
    return Tenant::create(array_merge([
        'name' => 'Tenant '.uniqid(),
        'email' => uniqid().'@t.test',
        'type' => 'company',
        'status' => 'active',
        // The default is a COMPANY, and a company is what gets filed with ETA — which
        // refuses a submission whose buyer address is incomplete. Seeded here so an
        // e-invoicing test fails on the thing it is testing, not on fixture data.
        // Pass explicit nulls to test the refusal itself.
        'address' => '1 Test Street, Cairo',
        'address_governorate' => 'Cairo',
        'address_city' => 'Nasr City',
        'address_street' => 'Test Street',
        'address_building_number' => '1',
    ], $attrs));
}

/** A portal login for a tenant (admin by default). actingAs(.., 'portal'). */
function makeTenantUser(Tenant $tenant, bool $isAdmin = true): TenantUser
{
    return TenantUser::create([
        'tenant_id' => $tenant->id,
        'name' => $tenant->name.' user',
        'email' => 'tu'.uniqid().'@test.local',
        'password' => bcrypt('password'),
        'is_admin' => $isAdmin,
    ]);
}

function makeLease(Unit $unit, ?Tenant $tenant = null, array $attrs = []): Lease
{
    $tenant ??= makeTenant();

    return Lease::create(array_merge([
        'reference' => 'L-'.uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'term_months' => 24,
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
        'currency' => 'EGP',
        'payment_terms_days' => 7,
    ], $attrs));
}

function makeInvoice(Lease $lease, array $attrs = []): Invoice
{
    return Invoice::create(array_merge([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-02-01',
        'due_date' => '2026-02-10',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'subtotal' => 10000,
        'vat_amount' => 1400,
        'total' => 11400,
        'paid_amount' => 0,
        'balance' => 11400,
        'currency' => 'EGP',
    ], $attrs));
}

function makeTenantRequest(array $attrs = []): TenantRequest
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();

    return TenantRequest::create(array_merge([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'title' => 'Test',
        'description' => 'Test description',
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'electrical',
        'submitted_at' => now(),
    ], $attrs));
}

/**
 * Authorization header carrying a fresh Sanctum token for a tenant — the
 * mobile API auth path. Keeps /api/v1 tests a single call away from "as this
 * tenant".
 *
 * @return array<string,string>
 */
function apiHeaders(Tenant $tenant, string $device = 'test-device'): array
{
    return ['Authorization' => 'Bearer '.$tenant->createToken($device, ['tenant:*'])->plainTextToken];
}

function makeUser(string $role = 'manager', array $assetIds = []): User
{
    seedRoles();

    $user = User::create([
        'name' => $role.' user',
        'email' => $role.uniqid().'@test.local',
        'password' => bcrypt('password'),
    ]);
    $user->syncRoles([$role]);

    if ($assetIds) {
        $user->assignedAssets()->sync(array_fill_keys($assetIds, [
            'assigned_at' => now(),
        ]));
    }

    return $user;
}

/**
 * Run a closure with the given Asset set as the active Filament tenant.
 * Restores the previous tenant afterwards so tests don't bleed state.
 * Requires an authenticated user — Filament's TenantSet event needs one.
 */
function asTenant(Asset $tenant, callable $callback): mixed
{
    if (! auth()->check()) {
        auth()->login(makeUser('super_admin'));
    }

    Filament::setTenant($tenant);
    try {
        return $callback();
    } finally {
        // Pass false to skip the TenantSet event when clearing.
        Filament::setTenant(null, isQuiet: true);
    }
}

/**
 * Get a fully-scoped resource query as Filament would build it for a
 * ListRecords page — applies both the resource's getEloquentQuery() AND
 * the tenant-ownership scope. Use in tests to verify the full filter chain.
 *
 * @param  class-string  $resourceClass
 */
function scopedResourceQuery(string $resourceClass): Builder
{
    $query = $resourceClass::getEloquentQuery();

    if ($tenant = Filament::getTenant()) {
        if ($resourceClass::isScopedToTenant()) {
            $query = $resourceClass::scopeEloquentQueryToTenant($query, $tenant);
        }
    }

    return $query;
}

/**
 * Put a record into the trashed state WITHOUT going through the deletion policy.
 *
 * `DeletionPolicy` refuses `->delete()` on money records (`NEVER_DELETABLE`) and on
 * master data that history points at (`WHEN_UNUSED`) — `RefusesDeletionOfCommittedRecords`
 * and `RefusesDeletionWhenReferenced` throw from the `deleting` hook. That is the product
 * decision and it is correct.
 *
 * It also means a test can no longer ARRANGE the trashed state through the normal path,
 * and several behaviours that still have to work are only observable from it:
 *
 *   - rows trashed BEFORE the refusal shipped (2026-07-31) are still in the database, and
 *     `accounting:sync-ledger` must still void their journal entries;
 *   - cascade paths trash children with the parent's `deleted_at` (fixed-asset depreciation
 *     charges, vendor-bill payments), and the windowed sweep must still find them;
 *   - readers must degrade safely when a parent has gone missing — the occupancy map with no
 *     property, a payslip line whose employee left, a stock movement whose warehouse is gone.
 *
 * So this drops the refusal listener for the duration of the delete and re-arms it straight
 * after — deliberately NOT `withoutEvents()`, which would also suppress `deleted` and
 * `restoring`. Those carry the soft-delete CASCADES (a vendor bill stamps its payments with
 * its own `deleted_at`; a fixed asset stamps its depreciation charges), and the sweep tests
 * exist precisely to prove the cascade reaches the GL. Muting them would leave those tests
 * green over a cascade that never ran.
 *
 * The refusal trait is the only `deleting` listener on every model this applies to, so
 * forgetting that one event key removes the refusal and nothing else.
 *
 * Use it ONLY to arrange. Never use it to assert that deleting is possible — that is what
 * `DeletionPolicyConformanceTest` and the per-model refusal tests are for, and they must keep
 * calling `->delete()` directly so the refusal stays proven.
 *
 * @template T of Model
 *
 * @param  T  $model
 * @return T
 */
function trashBypassingDeletionPolicy(Model $model): Model
{
    return withoutDeletionRefusal($model, fn () => $model->delete());
}

/**
 * The hard-delete sibling of {@see trashBypassingDeletionPolicy()}.
 *
 * Only for arranging the aftermath of a row that is genuinely gone — e.g. proving a pivot
 * cascades via the database's own foreign key. Same rule: arrange only, never assert.
 *
 * @template T of Model
 *
 * @param  T  $model
 * @return T
 */
function forceDeleteBypassingDeletionPolicy(Model $model): Model
{
    return withoutDeletionRefusal($model, fn () => $model->forceDelete());
}

/**
 * Run $operation with the model's deletion-refusal listener detached, then re-arm it.
 *
 * Re-arming matters: a test that arranges a trashed row and then asserts the refusal still
 * bites would otherwise be asserting against a model whose guard we had quietly removed.
 *
 * @template T of Model
 *
 * @param  T  $model
 * @return T
 */
function withoutDeletionRefusal(Model $model, callable $operation): Model
{
    $event = 'eloquent.deleting: '.$model::class;

    Event::forget($event);

    try {
        $operation();
    } finally {
        // The trait's own boot method re-registers the `deleting` hook.
        foreach (['bootRefusesDeletionOfCommittedRecords', 'bootRefusesDeletionWhenReferenced'] as $boot) {
            if (method_exists($model, $boot)) {
                $model::{$boot}();
            }
        }
    }

    return $model;
}

/**
 * The late-fee line(s) charged BECAUSE this invoice went unpaid.
 *
 * Since 2026-08-11 (FS-27) a late fee is its own dated invoice rather than a line appended to the
 * overdue one — appending it restated an issued document and, because the entry is dated from
 * `issue_date`, booked April's penalty as January revenue. Tests ask the same question they always
 * did; only where the answer lives has moved, and it moved to the far side of
 * `Invoice::late_fee_invoice_id`.
 *
 * Returns a query so `->count()`, `->first()` and `->sole()` all read as before. An invoice with no
 * fee yields an empty query rather than an error, which is what makes the "not charged" assertions
 * work unchanged.
 *
 * @return Builder<InvoiceItem>
 */
function lateFeeItems(Invoice $invoice): Builder
{
    return InvoiceItem::query()
        ->where('type', 'late_fee')
        ->where('invoice_id', $invoice->fresh()?->late_fee_invoice_id);
}

/**
 * Settle an invoice the way the application settles one — a captured payment, allocated.
 *
 * **Do not reach for `$invoice->update(['balance' => 0, 'status' => 'paid'])`.** As of 2026-08-12
 * the model reverts it: `balance` and `paid_amount` are derived from
 * {@see Invoice::recomputeTotals()} and a client-supplied value is discarded, because
 * that write was reachable from a crafted Livewire payload and made an unpaid invoice read settled
 * everywhere except the general ledger.
 *
 * Four fixtures were doing exactly that to fake a paid invoice, and each was green over a state no
 * operator could produce. Going through a payment is both the honest setup and a stronger test:
 * it exercises the allocation pivot and the recompute the real flow depends on.
 */
function settleInvoiceInFull(Invoice $invoice): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'payment_date' => now(),
        'amount' => (float) $invoice->total,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'currency' => $invoice->currency ?? 'EGP',
    ]);

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);
    $invoice->recomputeTotals();

    return $payment;
}

/**
 * Render an activity row's Changes cell exactly as the Activity Log page and the
 * ActivitiesRelationManager do.
 *
 * Lives here rather than file-scope in a test because TWO test files need it —
 * ActivityLogRenderTest and ActivityLogVocabularyConformanceTest — and Pest parallelises per
 * FILE, so a worker that loads only one of them would not see a helper declared in the other,
 * while a worker that loads both would die on a redeclaration.
 */
function renderActivityChanges(Activity $activity): string
{
    return app(ActivityLogChangeRenderer::class)->render($activity);
}

/**
 * Every PHP file under app/Filament, sorted.
 *
 * Shared here rather than declared at file scope in a test, and this one had already gone wrong:
 * `ManufacturedLabelConformanceTest` and `UniqueRuleScopeConformanceTest` each declared their own
 * `filamentSources()`, in two different commits, so any single process that loaded both files died
 * on a FATAL redeclaration before a single test ran — and the whole suite exited with no output at
 * all. `--parallel` masks it only while the two files land on different workers, which is luck, not
 * isolation. The fourth occurrence of this exact bug in the project.
 *
 * The sweep is the whole tree, not `Resources/`: forms live in `Schemas/`, but also in relation
 * managers (`app/Filament/Admin/RelationManagers/` sits outside `Resources/` entirely) and
 * occasionally on pages.
 *
 * @return array<int, string>
 */
function filamentSources(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
