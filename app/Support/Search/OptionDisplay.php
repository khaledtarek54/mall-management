<?php

namespace App\Support\Search;

use App\Models\Area;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Concerns\HasSearchText;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\FixedAsset;
use App\Models\Floor;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LedgerAccount;
use App\Models\MarketingPost;
use App\Models\PurchaseRequest;
use App\Models\RentableItem;
use App\Models\ServicePlan;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Models\Warehouse;
use App\Support\PropertyIsolation;
use App\Support\TenantScope;
use BackedEnum;
use Closure;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * What a record looks like when it is being PICKED, and what an operator may type to find it.
 *
 * One registry, three surfaces: the options in a dropdown, the value shown once one is chosen,
 * and the details under a global-search hit. They were three different answers before this —
 * a dropdown showed one column, the chosen value showed the same column, and global search
 * showed whatever the resource happened to define — so the same record introduced itself three
 * different ways in one session.
 *
 * ## What this registry decides, and what it deliberately does not
 *
 * It decides **presentation** (`presenters()`), **cost** (`EAGER`, `PRELOAD`) and **reach**
 * (the picker scope). It does NOT decide what a query matches: that is `HasSearchText`'s folded
 * `search_text` blob, which already answers the top search bar and every list. Pointing the
 * pickers at the same blob is the entire fix for "I can only search a tenant by name" — the
 * blob has held their phone, WhatsApp, tax ID, commercial register, trade name (EN and AR) and
 * contact person all along, and the dropdowns were the one surface not reading it.
 *
 * ## Property isolation is DERIVED, never re-listed
 *
 * `PropertyIsolation` already knows, per model, whether it is owned by a property and through
 * which relation (`#[PropertyOwned(via: 'unit')]`). So `scope()` reads that register rather than
 * carrying a second copy that could disagree with it — the copy that drifts is always the one
 * nobody tests. Before this, the tenant picker was scoped three different ways in three files:
 *
 *   - `InvoiceForm`  — `whereHas('leases.unit')`. A unit OWNER holds no lease, so owners could be
 *                      invoiced by the billing services but never picked on the invoice form.
 *   - `PaymentForm`  — `leases.unit` OR `unitOwnerships.unit`. The correct one.
 *   - `TenantScope::selectableTenantOptions()` — `leases.unit` OR `doesntHave('leases')`, which
 *                      offered a tenant with no lease but WITH an ownership in another property
 *                      to every property in the portfolio. That is a cross-property leak, and it
 *                      is invisible until a tenant happens to be both.
 *
 * `PICKER_SCOPES` holds the one correct answer for the models where "which records belong in
 * this list" is not simply "the ones this property owns".
 *
 * ## The fallback is a feature
 *
 * A model with no presenter still renders — through its existing `label()` / `displayName()`
 * convention (the same one `ActivityVocabulary` reads), then `name`/`code`/`reference`/`number`/
 * `title`. So adding a model to a picker can never produce a blank dropdown; it produces a plain
 * one, and `SelectSearchConformanceTest` names it so the plain one is a decision rather than an
 * oversight.
 */
class OptionDisplay
{
    /**
     * Options returned per server-side search.
     *
     * Filament's own default is 50. Twenty-five is two comfortable scrolls: enough that a common
     * surname does not truncate to a misleading "no more", few enough that a page of two-line
     * options with eager-loaded relations stays one small query per relation. An operator who
     * needs the 26th match needs a narrower search, not a longer list.
     */
    public const LIMIT = 25;

    /**
     * Models whose entire set is loaded into the page rather than searched on the server.
     *
     * The bar is strict: the set must be **bounded by the shape of the business**, not merely
     * small today. A property has floors; a mall has zones; an operator has departments — those
     * cannot grow to a thousand without something else changing first. Tenants, units, leases,
     * invoices and inventory items all can, so none of them are here: they search server-side,
     * which is also the only path that applies the fold.
     *
     * Getting this wrong is not a crash, it is a slow page nobody attributes to a dropdown.
     *
     * @var array<int, class-string>
     */
    public const PRELOAD = [
        Asset::class,
        Area::class,
        Department::class,
        Floor::class,
        Warehouse::class,
    ];

    /**
     * Relations each presenter reaches for, eager-loaded so a page of options costs a constant
     * number of queries instead of one per option.
     *
     * This is the N+1 that hides best: a dropdown renders 25 options, each subtitle touches a
     * relation, and the page still feels fine on demo data with four tenants. It is also why the
     * gate asserts that every relation named here EXISTS on the model — a typo'd eager load
     * silently reintroduces the N+1 while looking exactly like a fix.
     *
     * @var array<class-string, array<int, string>>
     */
    public const EAGER = [
        Tenant::class => ['activeLeases.unit', 'unitOwnerships.unit'],
        Unit::class => ['floor', 'area', 'asset'],
        Lease::class => ['tenant', 'unit'],
        Invoice::class => ['tenant'],
        PurchaseRequest::class => ['vendor'],
        VendorContract::class => ['vendor'],
        Employee::class => ['department'],
        Department::class => ['asset', 'head'],
        Area::class => ['asset'],
        Floor::class => ['asset'],
        Warehouse::class => ['asset'],
        BankAccount::class => ['asset'],
        Equipment::class => ['unit'],
        ServicePlan::class => ['asset'],
        RentableItem::class => ['floor', 'area'],
        UnitOwnership::class => ['owner', 'unit'],
        MarketingPost::class => ['tenant'],
        VendorBill::class => ['vendor'],
        User::class => ['roles'],
    ];

    /**
     * Picker reach for models where property isolation does not answer the question.
     *
     * Only for `#[PortfolioShared]` models that still need narrowing — a shared model is by
     * definition not owned by a property, so `PropertyIsolation` has nothing to say about which
     * of them belong in THIS property's picker.
     *
     * @var array<class-string, string>
     */
    public const PICKER_SCOPES = [
        Tenant::class => 'a retailer belongs to whichever properties they lease or own in — plus the ones affiliated with nothing yet, who must stay pickable or their FIRST lease could never be created',
    ];

    /**
     * Models deliberately left on the derived fallback label, and why.
     *
     * The bar: is there a second fact about this record that would ever disambiguate two of them?
     * If the name IS the whole identity, a subtitle is noise in a list the operator is scanning.
     *
     * @var array<class-string, string>
     */
    public const PLAIN = [
        Role::class => 'a role is picked by name from a fixed catalogue of fourteen; its permission count is the only other fact about it and nobody chooses a role by that.',
    ];

    /** @var array<class-string, Closure(Model): RecordOption>|null */
    private static ?array $presenters = null;

    /** @var array<class-string, array<int, string>>|null */
    private static ?array $relationPaths = null;

    /**
     * Related records whose blob a picker also matches — DERIVED from what each resource already
     * declares for the top search bar, never re-listed here.
     *
     * **The gap this closes.** A lease's `search_text` is a pure function of the lease's OWN
     * columns (`HasSearchText`'s one invariant — reach through a relation and renaming a tenant
     * strands every blob quoting the old name). So the blob holds `LSE-AW-2026-0001` and nothing
     * else, and typing a tenant's name into the LEASE picker found nothing — while typing it into
     * the top search bar found the lease immediately, because `LeaseResource` declares
     * `['search_text', 'tenant.search_text', 'unit.search_text']`.
     *
     * Two surfaces, one question, two different answers. The resources were right; the pickers
     * were the half that never read them. Reading the declaration rather than copying it means a
     * resource that adds a path tomorrow reaches its picker for free — and that the two can no
     * longer disagree, which is the failure that was actually shipping.
     *
     * @return array<int, string> relation paths with the trailing `.search_text` stripped
     */
    public static function searchRelations(string $model): array
    {
        if (self::$relationPaths === null) {
            self::$relationPaths = self::deriveRelationPaths();
        }

        return self::$relationPaths[$model] ?? [];
    }

    /**
     * @return array<class-string, array<int, string>>
     */
    protected static function deriveRelationPaths(): array
    {
        $paths = [];

        try {
            // The ADMIN panel's resources are the authoritative declaration: the portal's are a
            // subset over the same models, and a picker's reach should not shrink because the
            // component happens to be rendered in the tenant portal.
            $resources = Filament::getPanel('admin')->getResources();
        } catch (Throwable) {
            // No panels registered (a bare CLI boot, an early migration). A picker searching only
            // its own blob is the previous behaviour, not a broken one.
            return [];
        }

        foreach ($resources as $resource) {
            if (! method_exists($resource, 'getGloballySearchableAttributes')) {
                continue;
            }

            $relations = [];

            foreach ($resource::getGloballySearchableAttributes() as $attribute) {
                if (! str_ends_with($attribute, '.search_text')) {
                    continue;
                }

                $relations[] = substr($attribute, 0, -strlen('.search_text'));
            }

            if ($relations !== []) {
                $paths[$resource::getModel()] = array_values(array_unique($relations));
            }
        }

        return $paths;
    }

    /**
     * How each model introduces itself.
     *
     * Read this as a list of answers to one question: **standing in front of two of these, what
     * tells them apart?** Not "what do we know about the record" — a picker is not a report, and
     * every token that does not disambiguate is one more thing between the operator and the row.
     *
     * @return array<class-string, Closure(Model): RecordOption>
     */
    protected static function presenters(): array
    {
        return self::$presenters ??= [

            // ---- Leasing & tenancy -------------------------------------------------

            // The complaint that started all of this. A mall runs «Zara», «Zara Home» and «Zara
            // Kids», and the picker showed all three as their name and nothing else. The unit is
            // what an operator actually knows about the one they mean — so the unit is the
            // subtitle, taken from active leases and from ownerships, because a unit OWNER
            // (module 37) holds no lease at all and is otherwise indistinguishable from a
            // tenant with no space.
            Tenant::class => static function (Tenant $record): RecordOption {
                $units = $record->relationLoaded('activeLeases')
                    ? $record->activeLeases->pluck('unit.code')->filter()
                    : collect();

                if ($record->relationLoaded('unitOwnerships')) {
                    $units = $units->merge($record->unitOwnerships->pluck('unit.code')->filter());
                }

                $units = $units->unique()->values();

                return RecordOption::make(
                    title: $record->name,
                    code: $record->code,
                    subtitle: RecordOption::join([
                        self::truncateList($units->all()),
                        $record->phone,
                        // The legal name only earns its place when it differs from the trading
                        // name — repeating «Zara · Zara» wastes the line that exists to separate
                        // this row from the one above it.
                        $record->legal_name !== $record->name ? $record->legal_name : null,
                    ]),
                    badge: self::statusLabel('admin.statuses.tenant', $record->status),
                    tone: self::tone($record->status),
                );
            },

            Unit::class => static fn (Unit $record): RecordOption => RecordOption::make(
                title: $record->code,
                code: null,
                subtitle: RecordOption::join([
                    $record->floor?->code,
                    $record->area?->name,
                    $record->area_sqm ? number_format((float) $record->area_sqm, 0).' m²' : null,
                    $record->asset?->name,
                ]),
                badge: self::statusLabel('admin.statuses.unit', $record->status),
                // A unit picker inverts the usual reading of state: VACANT is the answer you
                // want when you are writing a lease, and OCCUPIED is the one that should stop
                // you. So this model states its own tones rather than taking the shared map,
                // which would have painted the useful rows grey and the blocked ones green.
                tone: match ($record->status) {
                    'vacant' => 'success',
                    'reserved' => 'warning',
                    'maintenance' => 'danger',
                    default => 'gray',
                },
            ),

            // WHO and WHERE lead; the contract code is the tag beside them. An operator raising an
            // invoice is thinking "Cilantro, A-04", not "LSE-AW-2026-0001" — the reference is what
            // they read off a filed contract afterwards, not what they hold in their head at the
            // moment of picking. Yardi's lease lookup leads with the tenant for the same reason.
            // The reference is still shown, still searched, still the thing that identifies the
            // document; it is just not the headline.
            Lease::class => static fn (Lease $record): RecordOption => RecordOption::make(
                title: RecordOption::join([$record->tenant?->name, $record->unit?->code]),
                code: $record->reference,
                subtitle: RecordOption::join([
                    self::dateRange($record->commencement_date, $record->expiry_date),
                    $record->base_rent_monthly !== null
                        ? __('admin.search.option.per_month', ['amount' => self::money($record->base_rent_monthly)])
                        : null,
                ]),
                badge: self::statusLabel('admin.statuses.lease', $record->status),
                tone: self::tone($record->status),
            ),

            UnitOwnership::class => static fn (UnitOwnership $record): RecordOption => RecordOption::make(
                title: $record->reference,
                code: $record->unit?->code,
                subtitle: RecordOption::join([
                    // `owner`, not `tenant` — the relation is named for what the party IS on this
                    // record (module 37: a buyer, not a retailer), and the gate caught the guess.
                    $record->owner?->name,
                    $record->ownership_share_pct !== null
                        ? number_format((float) $record->ownership_share_pct, 2).'%'
                        : null,
                ]),
                badge: self::statusLabel('admin.enums.unit_ownership_status', $record->status),
                tone: self::tone($record->status),
            ),

            // ---- Money -------------------------------------------------------------

            // What separates two invoices is never the number — it is who owes what, and by
            // when. An operator allocating a payment or picking an invoice for a cheque is
            // looking for the outstanding figure, so the outstanding figure is on the line.
            Invoice::class => static fn (Invoice $record): RecordOption => RecordOption::make(
                title: $record->number,
                code: null,
                subtitle: RecordOption::join([
                    $record->tenant?->name,
                    $record->due_date?->format('d M Y'),
                    self::money($record->total),
                    (float) $record->balance > 0.005
                        ? __('admin.search.option.outstanding', ['amount' => self::money($record->balance)])
                        : null,
                ]),
                badge: self::statusLabel('admin.statuses.invoice', $record->status),
                tone: self::tone($record->status),
            ),

            // A purchase request is picked to be BILLED against, so what the operator is checking is
            // "is this the one for that amount?" — hence the value on the line.
            PurchaseRequest::class => static fn (PurchaseRequest $record): RecordOption => RecordOption::make(
                title: $record->reference,
                code: null,
                subtitle: RecordOption::join([
                    $record->vendor?->name,
                    self::money($record->total_value),
                ]),
                badge: self::statusLabel('admin.procurement.statuses', $record->status),
                tone: self::tone($record->status),
            ),

            // The REMAINING commitment, not the contract value — the whole reason a bill is tied to
            // a contract is to see what is left of it, and the picker is where that decision is made.
            VendorContract::class => static fn (VendorContract $record): RecordOption => RecordOption::make(
                title: $record->name ?: $record->reference,
                code: $record->name ? $record->reference : null,
                subtitle: RecordOption::join([
                    $record->vendor?->name,
                    $record->exists
                        ? __('admin.vendors.commitment.remaining_short', ['amount' => number_format($record->remainingValue(), 0)])
                        : null,
                    self::dateRange($record->start_date, $record->end_date),
                ]),
                badge: self::statusLabel('admin.statuses.vendor_contract', $record->status),
                tone: self::tone($record->status),
            ),

            FixedAsset::class => static fn (FixedAsset $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->tag,
                subtitle: RecordOption::join([$record->category, self::money($record->acquisition_cost)]),
                badge: self::statusLabel('admin.fixed_assets.statuses', $record->status),
                tone: self::tone($record->status),
            ),

            VendorBill::class => static fn (VendorBill $record): RecordOption => RecordOption::make(
                title: $record->number,
                code: null,
                subtitle: RecordOption::join([
                    $record->vendor?->name,
                    $record->bill_date?->format('d M Y'),
                    self::money($record->total),
                ]),
                badge: self::statusLabel('admin.statuses.vendor_bill', $record->status),
                tone: self::tone($record->status),
            ),

            // ---- Counterparties ----------------------------------------------------

            Vendor::class => static fn (Vendor $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->code,
                subtitle: RecordOption::join([
                    $record->phone,
                    $record->city,
                    $record->legal_name !== $record->name ? $record->legal_name : null,
                ]),
                badge: self::statusLabel('admin.statuses.vendor', $record->status),
                tone: self::tone($record->status),
            ),

            // ---- Property structure ------------------------------------------------

            Asset::class => static fn (Asset $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->code,
                subtitle: $record->city,
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            Area::class => static fn (Area $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->code,
                subtitle: $record->asset?->name,
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            Floor::class => static fn (Floor $record): RecordOption => RecordOption::make(
                title: $record->name ?: $record->code,
                code: $record->name ? $record->code : null,
                subtitle: $record->asset?->name,
            ),

            RentableItem::class => static fn (RentableItem $record): RecordOption => RecordOption::make(
                title: $record->name ?: $record->code,
                code: $record->name ? $record->code : null,
                subtitle: RecordOption::join([
                    self::statusLabel('admin.enums.rentable_item_type', $record->type),
                    $record->floor?->code,
                    $record->area?->name,
                ]),
                badge: self::statusLabel('admin.enums.rentable_item_status', $record->status),
                tone: self::tone($record->status),
            ),

            // ---- Facility ----------------------------------------------------------

            Equipment::class => static fn (Equipment $record): RecordOption => RecordOption::make(
                title: $record->name_en ?: $record->name_ar,
                code: $record->code,
                subtitle: RecordOption::join([
                    $record->category,
                    $record->unit?->code,
                    $record->location,
                ]),
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            ServicePlan::class => static fn (ServicePlan $record): RecordOption => RecordOption::make(
                title: $record->title,
                code: null,
                subtitle: RecordOption::join([
                    $record->category,
                    $record->asset?->name,
                ]),
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            // ---- Inventory & treasury ----------------------------------------------

            InventoryItem::class => static fn (InventoryItem $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->sku,
                subtitle: RecordOption::join([$record->category, $record->unit]),
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            Warehouse::class => static fn (Warehouse $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->code,
                subtitle: RecordOption::join([$record->asset?->name, $record->category]),
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            // ---- General ledger ----------------------------------------------------

            // The code leads, because that is how an accountant reads a chart — and the type
            // is what stops them posting rent to a liability by picking the wrong «Rent».
            LedgerAccount::class => static fn (LedgerAccount $record): RecordOption => RecordOption::make(
                title: $record->displayName(),
                code: $record->code,
                subtitle: RecordOption::join([
                    self::statusLabel('admin.enums.ledger_account_type', $record->type),
                    $record->is_postable ? null : __('admin.search.option.header_account'),
                ]),
            ),

            BankAccount::class => static fn (BankAccount $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->account_number,
                subtitle: RecordOption::join([
                    $record->bank_name,
                    $record->currency,
                    $record->asset?->name,
                ]),
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            // ---- People ------------------------------------------------------------

            Employee::class => static fn (Employee $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->code,
                subtitle: RecordOption::join([$record->position, $record->department?->name]),
                badge: self::statusLabel('admin.employees.statuses', $record->status),
                tone: self::tone($record->status),
            ),

            // An operator picking someone to assign work to needs to know what that person can
            // DO — the role is the only fact on the row that answers it.
            User::class => static fn (User $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: null,
                subtitle: RecordOption::join([
                    $record->relationLoaded('roles')
                        ? self::truncateList($record->roles->pluck('name')->all())
                        : null,
                    $record->email,
                ]),
            ),

            Department::class => static fn (Department $record): RecordOption => RecordOption::make(
                title: $record->name,
                code: $record->code,
                subtitle: RecordOption::join([$record->asset?->name, $record->head?->name]),
                badge: $record->is_active ? null : __('admin.search.option.inactive'),
                tone: 'gray',
            ),

            // ---- Marketing ---------------------------------------------------------

            MarketingPost::class => static fn (MarketingPost $record): RecordOption => RecordOption::make(
                title: $record->title,
                code: null,
                subtitle: RecordOption::join([
                    $record->tenant?->name,
                    self::dateRange($record->starts_at, $record->ends_at),
                ]),
                badge: self::statusLabel('admin.marketing_posts.statuses', $record->status),
                tone: self::tone($record->status),
            ),
        ];
    }

    /**
     * The option for one record — the ONE entry point every surface goes through.
     */
    public static function for(Model $record): RecordOption
    {
        $presenter = self::presenters()[$record::class] ?? null;

        return $presenter ? $presenter($record) : self::fallback($record);
    }

    /**
     * A record with no presenter, named the way the rest of the codebase already names records.
     *
     * `label()` / `displayName()` first — the project's existing bilingual-name convention, the
     * same one `ActivityVocabulary` reads to turn a foreign key into a sentence. Reusing it means
     * a model that has already answered "what are you called?" for the audit trail does not have
     * to answer it again for the pickers.
     */
    protected static function fallback(Model $record): RecordOption
    {
        foreach (['label', 'displayName'] as $method) {
            if (method_exists($record, $method)) {
                return RecordOption::make((string) $record->{$method}());
            }
        }

        foreach (['name', 'title', 'reference', 'number', 'code'] as $attribute) {
            if (filled($record->getAttribute($attribute))) {
                return RecordOption::make((string) $record->getAttribute($attribute));
            }
        }

        return RecordOption::make('#'.$record->getKey());
    }

    public static function hasPresenter(string $model): bool
    {
        return array_key_exists($model, self::presenters());
    }

    /** @return array<int, class-string> */
    public static function presentedModels(): array
    {
        return array_keys(self::presenters());
    }

    public static function shouldPreload(string $model): bool
    {
        return in_array($model, self::PRELOAD, strict: true);
    }

    // ------------------------------------------------------------------------
    // Querying
    // ------------------------------------------------------------------------

    /**
     * The picker query for a model: eager loads, the property scope, then the call site's own
     * narrowing.
     *
     * **This is the one query every picker path goes through — options, search AND the label of
     * an already-chosen value — and that is load-bearing rather than tidy.** Filament validates a
     * Select by asking it to resolve the submitted value's label: a value it cannot label is
     * rejected with `Rule::in([])` (see `Select::getInValidationRuleValues()`). So the label
     * lookup IS the write-side guard against a posted foreign key from another property. Resolve
     * labels through an unscoped `find()` — which reads as the friendlier choice, since a saved
     * value then always renders — and every entity select in the panel silently starts accepting
     * any id in the table.
     *
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    public static function pickable(string $model, ?Closure $modifyQuery = null, bool $scoped = true): Builder
    {
        $query = $model::query()->with(self::EAGER[$model] ?? []);

        if ($scoped) {
            $query = self::scope($query);
        }

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        // Only if the call site did not order it itself — a form that wants units by floor, or
        // invoices oldest-first for an allocation screen, has said so and must win.
        if ($query->getQuery()->orders === null && ($order = self::order($model)) !== null) {
            $query->orderBy($query->qualifyColumn($order[0]), $order[1]);
        }

        return $query;
    }

    /**
     * Default ordering for a picker: `[column, direction]`, or null when the model has nothing
     * obvious to sort on.
     *
     * Two different answers for two different kinds of record, which is why this is not simply
     * "order by the title":
     *
     *   - **Master data** (a tenant, a unit, a vendor) sorts ALPHABETICALLY. The operator is
     *     hunting a name they already know, and an alphabetical list is one they can predict.
     *   - **Documents** (an invoice, a bill, an offer) sort NEWEST FIRST. Nobody looks for the
     *     oldest invoice; sorting `INV-…-0001` to the top of a picker buries this month's
     *     billing run under the opening balances.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function order(string $model): ?array
    {
        $explicit = [
            Invoice::class => ['issue_date', 'desc'],
            VendorBill::class => ['bill_date', 'desc'],
            MarketingPost::class => ['starts_at', 'desc'],
            LedgerAccount::class => ['code', 'asc'],
            Floor::class => ['level', 'asc'],
        ];

        if (isset($explicit[$model])) {
            return $explicit[$model];
        }

        // Read off `$fillable` rather than the schema: this runs on every picker query and a
        // `Schema::hasColumn()` probe would be a round trip to the database to answer a question
        // the class already knows.
        $fillable = (new $model)->getFillable();

        foreach (['name', 'code', 'title', 'reference', 'number'] as $column) {
            if (in_array($column, $fillable, true)) {
                return [$column, 'asc'];
            }
        }

        return null;
    }

    /**
     * The columns a picker falls back to when the model carries no `search_text` blob.
     *
     * Also what gets handed to `Select::searchable()`, which matters for a reason that is pure
     * Filament plumbing: `Select::hasDynamicSearchResults()` returns FALSE for a relationship
     * select whose search columns are blank, and a select with no dynamic search results never
     * calls the server at all — it silently falls back to filtering the loaded options in the
     * browser. So the array must be non-empty even though the matching is done by our own
     * callback. (`InvoiceForm` had discovered this the hard way and carried a comment about it.)
     *
     * @return array<int, string>
     */
    public static function searchColumns(string $model): array
    {
        if (in_array(HasSearchText::class, class_uses_recursive($model), true)) {
            return ['search_text'];
        }

        $fillable = (new $model)->getFillable();

        $columns = array_values(array_filter(
            ['name', 'code', 'title', 'reference', 'number', 'email'],
            fn (string $column): bool => in_array($column, $fillable, true),
        ));

        return $columns === [] ? [(new $model)->getKeyName()] : $columns;
    }

    /**
     * What the operator may type to find this record — shown as the dropdown's search prompt.
     *
     * The single most useful sentence in a picker, and the one nothing in the panel was saying.
     * An operator who believes a box searches names will only ever type names; the phone number
     * and the tax ID have been searchable all along and stayed undiscovered because the field
     * said "Search…". Per-model where a model has something specific worth naming
     * (`admin.search.prompts.tenant`), generic otherwise.
     */
    public static function searchPrompt(string $model): string
    {
        $key = 'admin.search.prompts.'.str(class_basename($model))->snake()->toString();

        return Lang::has($key) ? __($key) : __('admin.search.prompts.default');
    }

    /**
     * Narrow a picker query to what the current user's property selection may offer.
     *
     * Derived from `PropertyIsolation` rather than re-listed — see the class docblock. Composes
     * with (never replaces) whatever the call site adds: a form that also wants only ACTIVE
     * vendors, or only units in the asset chosen higher up the form, keeps passing its own
     * closure and the two clauses AND together.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function scope(Builder $query): Builder
    {
        $model = $query->getModel()::class;
        $ids = TenantScope::visibleAssetIds();

        // A property that is ITSELF the dimension. Always drop the "All Properties" pseudo-asset:
        // it is a view mode, never a record anything can belong to, and offering it as an option
        // is how `asset_id` came to be stamped with it (see BypassesFilamentTenantAutoScope).
        if ($model === Asset::class) {
            $query->where('code', '!=', Asset::ALL_PROPERTIES_CODE);

            return $ids === null ? $query : $query->whereIn($query->qualifyColumn('id'), $ids);
        }

        // Unrestricted (super_admin, or a single-mall deployment with no assignments at all):
        // nothing to narrow.
        if ($ids === null) {
            return $query;
        }

        if (array_key_exists($model, self::PICKER_SCOPES)) {
            return self::applyPickerScope($query, $model, $ids);
        }

        if (! PropertyIsolation::isOwned($model)) {
            return $query;
        }

        $via = PropertyIsolation::linkageFor($model);

        return $via === null
            ? $query->whereIn($query->qualifyColumn('asset_id'), $ids)
            : $query->whereHas($via, fn (Builder $related) => $related->whereIn('asset_id', $ids));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, int>  $ids
     * @return Builder<Model>
     */
    protected static function applyPickerScope(Builder $query, string $model, array $ids): Builder
    {
        return match ($model) {
            // A retailer reaches a property through a lease, and a unit OWNER reaches it through
            // an ownership — module 37 buyers hold no lease at all, and scoping on leases alone
            // is what made them unpickable on the invoice form while remaining invoiceable by
            // the services.
            //
            // The third branch is the new tenant nobody has leased anything to yet. They must
            // stay pickable in every property or their FIRST lease could never be written. It is
            // not a leak: a party affiliated with nothing belongs to nobody. Note it checks BOTH
            // relations — the old `doesntHave('leases')` offered a tenant who owned a unit in
            // another mall to every property in the portfolio.
            Tenant::class => $query->where(fn (Builder $where) => $where
                ->whereHas('leases.unit', fn (Builder $unit) => $unit->whereIn('asset_id', $ids))
                ->orWhereHas('unitOwnerships.unit', fn (Builder $unit) => $unit->whereIn('asset_id', $ids))
                ->orWhere(fn (Builder $unaffiliated) => $unaffiliated
                    ->whereDoesntHave('leases')
                    ->whereDoesntHave('unitOwnerships'))),

            default => $query,
        };
    }

    /**
     * Search a model's records and return them as `id => [option, record]`.
     *
     * The match itself is `HasSearchText::scopeSearch` — the same folded, word-ANDed blob search
     * the tables and the top search bar use, so «شركه» finds «شركة» and `INV2026` finds
     * `INV-2026` in a dropdown exactly as it does everywhere else. A model without the blob
     * falls back to the columns the call site named, which is what Filament would have done
     * anyway.
     *
     * A query that folds to nothing returns nothing rather than everything: an operator who
     * typed only punctuation has not asked for the first 25 rows of the table.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $columns  fallback columns for a model with no blob
     * @return array<int|string, array{0: RecordOption, 1: Model}>
     */
    public static function search(
        string $model,
        ?string $search,
        ?Closure $modifyQuery = null,
        int $limit = self::LIMIT,
        array $columns = [],
        bool $scoped = true,
    ): array {
        $query = self::pickable($model, $modifyQuery, $scoped);

        $words = SearchText::words($search);

        if ($words === []) {
            return [];
        }

        if (in_array(HasSearchText::class, class_uses_recursive($model), true)) {
            $relations = self::searchRelations($model);

            // Words AND, sources OR — the same semantics as the top search bar and every list, so
            // "cilantro a-04" narrows to the lease that matches BOTH, one word through the tenant's
            // blob and the other through the unit's.
            foreach ($words as $word) {
                $query->where(function (Builder $where) use ($word, $relations): void {
                    $where->where($where->qualifyColumn('search_text'), 'like', '%'.$word.'%');

                    foreach ($relations as $relation) {
                        $where->orWhereHas(
                            $relation,
                            fn (Builder $related) => $related->where(
                                $related->qualifyColumn('search_text'),
                                'like',
                                '%'.$word.'%',
                            ),
                        );
                    }
                });
            }
        } elseif ($columns !== []) {
            // No blob: fall back to the raw columns, still ANDing the words so a two-word query
            // narrows. Unfolded, because the stored values are unfolded — folding one side only
            // is the failure this codebase exists to avoid.
            $raw = trim((string) $search);
            $query->where(function (Builder $where) use ($columns, $raw): void {
                foreach ($columns as $index => $column) {
                    $where->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', '%'.$raw.'%');
                }
            });
        } else {
            return [];
        }

        return self::collect($query->limit($limit));
    }

    /**
     * Every record of a model, paired with its option — the preloaded / whole-set path.
     *
     * @param  class-string<Model>  $model
     * @return array<int|string, array{0: RecordOption, 1: Model}>
     */
    public static function options(string $model, ?Closure $modifyQuery = null, ?int $limit = null, bool $scoped = true): array
    {
        $query = self::pickable($model, $modifyQuery, $scoped);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return self::collect($query);
    }

    /**
     * The option for one already-chosen key — what the closed control shows.
     *
     * Resolved through `pickable()`, i.e. WITH the property scope, and that is deliberate even
     * though the friendlier-looking choice is an unscoped `find()`. Filament turns "can you label
     * this value?" into "is this value allowed?" during validation, so an unscoped lookup here
     * would quietly accept a foreign key from another property on every entity select in the
     * panel. See the note on `pickable()`.
     *
     * The cost of the strict reading is cosmetic and bounded: a value the current scope cannot
     * offer renders as its raw id rather than its name — which is exactly what the scoped
     * relationship selects already did before this, so nothing regresses.
     *
     * @param  class-string<Model>  $model
     */
    public static function label(string $model, int|string|null $key, ?Closure $modifyQuery = null, bool $scoped = true): ?RecordOption
    {
        if ($key === null || $key === '') {
            return null;
        }

        $record = self::pickable($model, $modifyQuery, $scoped)->find($key);

        return $record ? self::for($record) : null;
    }

    /**
     * Options for a set of already-chosen keys — the multi-select equivalent of `label()`.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int|string>  $keys
     * @return array<int|string, array{0: RecordOption, 1: Model}>
     */
    public static function labels(string $model, array $keys, ?Closure $modifyQuery = null, bool $scoped = true): array
    {
        $keys = array_filter($keys, fn ($key): bool => $key !== null && $key !== '');

        if ($keys === []) {
            return [];
        }

        return self::collect(self::pickable($model, $modifyQuery, $scoped)->whereKey(array_values($keys)));
    }

    /**
     * Run a picker query and pair each option with the record it came from.
     *
     * The RECORD travels alongside the option because `EntitySelect::decorateOption()` needs it —
     * a screen adding "⚠ encumbered" to a unit has to ask the unit. Re-deriving it from the key
     * would be a second query per option, and passing only the option would push the decision back
     * into a raw label string where nothing escapes it.
     *
     * @param  Builder<Model>  $query
     * @return array<int|string, array{0: RecordOption, 1: Model}>
     */
    protected static function collect(Builder $query): array
    {
        return $query->get()
            ->mapWithKeys(fn (Model $record): array => [$record->getKey() => [self::for($record), $record]])
            ->all();
    }

    // ------------------------------------------------------------------------
    // Shared formatting
    // ------------------------------------------------------------------------

    /**
     * The word for an enum-ish value, from the catalogue the tables and forms already label from.
     *
     * Takes the GROUP key rather than a group name, because this codebase has three shapes of
     * value catalogue and a picker has to read all of them:
     *
     *   - nested keys      `admin.statuses.invoice.overdue`
     *   - a flat array     `admin.enums.rentable_item_status` → `['available' => 'Available', …]`
     *   - a module's own   `admin.employees.statuses.active`
     *
     * Passing the full group path means a presenter points at the SAME entry the record's own
     * table labels from, which is the property that matters: the status word in the picker and
     * the status word in the list must be the same word, or the operator has to learn two
     * vocabularies for one field. (`ActivityVocabulary` learned this the hard way — picking a
     * group because its keys overlapped gave `expense.category` the retail list.)
     *
     * Falls back to the raw value rather than printing a missing-key path: an unseeded or
     * imported status is a legacy record, and the operator is better served by `written off`
     * than by `admin.statuses.invoice.written_off`.
     */
    public static function statusLabel(string $group, BackedEnum|string|null $value): ?string
    {
        // Backed enums as well as strings: this codebase is mid-migration off DB enums, so a status
        // column is a plain string on most models and a cast enum on the newer ones
        // (`UnitOwnership::$status`). A picker must read both without every presenter remembering
        // which kind it is looking at.
        $value = $value instanceof BackedEnum ? (string) $value->value : $value;

        if (blank($value)) {
            return null;
        }

        if (Lang::has("{$group}.{$value}")) {
            return __("{$group}.{$value}");
        }

        $catalogue = __($group);

        if (is_array($catalogue) && filled($catalogue[$value] ?? null)) {
            return $catalogue[$value];
        }

        return str_replace('_', ' ', $value);
    }

    /**
     * Should this state stop you picking the record?
     *
     * That is the only question a badge colour answers in a picker — not whether the state is
     * good business news. `expired` on a lease is grey rather than red because picking an expired
     * lease to credit against is a perfectly ordinary thing to do; `blacklisted` on a tenant is
     * red because it is a reason to stop.
     *
     * Models where the reading genuinely inverts (a VACANT unit is the one you want) state their
     * own tones in their presenter instead of taking this map.
     */
    public static function tone(BackedEnum|string|null $status): ?string
    {
        $status = $status instanceof BackedEnum ? (string) $status->value : $status;

        return match ($status) {
            'active', 'paid', 'captured', 'reconciled', 'posted', 'approved', 'completed', 'published', 'handed_over' => 'success',
            'draft', 'pending', 'pending_approval', 'partially_paid', 'issued', 'submitted', 'scheduled' => 'warning',
            'overdue', 'blacklisted', 'disputed', 'failed', 'bounced', 'void', 'rejected', 'suspended' => 'danger',
            null, '' => null,
            default => 'gray',
        };
    }

    /** EGP with no decimals — a picker is for recognising a figure, not reconciling it. */
    protected static function money(int|float|string|null $amount): ?string
    {
        return $amount === null ? null : 'EGP '.number_format((float) $amount, 0);
    }

    protected static function dateRange(mixed $from, mixed $to): ?string
    {
        $format = static fn (mixed $date): ?string => $date instanceof \DateTimeInterface
            ? $date->format('M Y')
            : null;

        return RecordOption::join([$format($from), $format($to)]) === null
            ? null
            : trim(($format($from) ?? '…').' – '.($format($to) ?? '…'));
    }

    /**
     * The first few of a list, with a count for the rest.
     *
     * A tenant with eleven units would otherwise push everything else on the line off the right
     * edge — and the eleven codes were never the point, the fact that it is THAT tenant was.
     *
     * @param  array<int, string|null>  $values
     */
    protected static function truncateList(array $values, int $keep = 2): ?string
    {
        $values = array_values(array_filter(array_map(
            fn ($value): string => trim((string) ($value ?? '')),
            $values,
        ), fn (string $value): bool => $value !== ''));

        if ($values === []) {
            return null;
        }

        if (count($values) <= $keep) {
            return implode(', ', $values);
        }

        return implode(', ', array_slice($values, 0, $keep))
            .' '.__('admin.search.option.and_more', ['count' => count($values) - $keep]);
    }

    /**
     * The role catalogue, used by the `Role` fallback documented in `PLAIN`.
     *
     * Kept as a method so the seeder constant is reached in one place if the `PLAIN` decision is
     * ever revisited.
     */
    public static function roleDescription(string $name): ?string
    {
        return RolesPermissionsSeeder::ROLES[$name] ?? null;
    }
}
