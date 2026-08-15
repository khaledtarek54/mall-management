<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Support\TenantScope;

/**
 * Server-side write guard for property isolation.
 *
 * A resource whose form exposes a client-editable `asset_id` (the Select is
 * enabled in "All Properties" mode, where its value is client-supplied) must
 * re-validate the submitted property against the current user's visible set on
 * BOTH create and edit — otherwise a property-restricted user could tamper the
 * id and write into another property's books.
 *
 * `TenantScope::visibleAssetIds()` returns null for portfolio users
 * (super_admin / owners / unconstrained), so the guard is a no-op for them and
 * only bites a restricted user submitting an out-of-scope (or null/consolidated)
 * property.
 *
 * Wire it from the Create/Edit page's mutate hook:
 *
 *     protected function mutateFormDataBeforeCreate(array $data): array
 *     {
 *         XResource::assertAssetInScope($data['asset_id'] ?? null);
 *         return $data;
 *     }
 *
 * The write-guard half of the property-isolation invariant. The read half is
 * `ScopesViaProperty` / `BypassesScopingOnAll`. See docs/PROPERTY-ISOLATION-PLAN.md.
 */
trait GuardsAssetInScope
{
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }

    /**
     * Guard a submitted `lease_id` by the property its unit belongs to. For
     * chain-derived resources (Invoice, TenantSalesDeclaration, CreditNote…)
     * whose property is determined by a client-supplied lease.
     *
     * FAIL-CLOSED CONTRACT: a null (or unknown) lease resolves to a null asset →
     * abort(403) for a property-restricted user, exactly like a consolidated
     * `asset_id`. This is intentional — it stops a restricted user from creating a
     * property-less (portfolio-level) row by nulling the FK. A resource that
     * *legitimately* allows a null FK — a tenant-level CreditNote with no lease —
     * must therefore SKIP the guard for the null case at the call site
     * (`if (! empty($data['lease_id'])) { … }`, as CreditNote's pages do), never
     * relax this method. Same contract for assertUnit/UnitsAssetInScope +
     * assertInvoiceAssetInScope below.
     */
    public static function assertLeaseAssetInScope(mixed $leaseId): void
    {
        static::assertAssetInScope(
            $leaseId === null ? null : Lease::query()->with('unit:id,asset_id')->find($leaseId)?->unit?->asset_id
        );
    }

    /** Guard a submitted `unit_id` by its property (Lease master unit, TenantRequest…). */
    public static function assertUnitAssetInScope(mixed $unitId): void
    {
        static::assertAssetInScope(
            $unitId === null ? null : Unit::query()->find($unitId)?->asset_id
        );
    }

    /** Guard a list of submitted `unit_id`s (e.g. a lease's additional units). */
    public static function assertUnitsAssetInScope(array $unitIds): void
    {
        foreach (array_filter($unitIds) as $unitId) {
            static::assertUnitAssetInScope($unitId);
        }
    }

    /** Guard a submitted `invoice_id` by its OWN property column; used by Payment allocations. */
    public static function assertInvoiceAssetInScope(mixed $invoiceId): void
    {
        // Reads the invoice's column rather than walking lease -> unit. Through the chain an
        // owner assessment resolved to null, and a null asset passes `assertAssetInScope` — so the
        // guard waved through exactly the allocations it exists to stop.
        static::assertAssetInScope(
            $invoiceId === null ? null : Invoice::query()->whereKey($invoiceId)->value('asset_id')
        );
    }
}
