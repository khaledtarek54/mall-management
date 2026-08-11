<?php

namespace App\Filament\Imports;

use App\Models\Vendor;
use App\Support\PropertyIsolation;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Load the operator's existing supplier register at cut-over.
 *
 * Fourth importer, and it follows the three that exist rather than inventing anything: identity on
 * something genuinely unique (`TenantImporter` keys on `tax_id`, `UnitImporter` on the unique
 * (asset, code), `LeaseImporter` on `reference`), enum-backed columns validated against the DB's own
 * set, and the queue connection read from config instead of hard-coded `sync`.
 *
 * **`Vendor` is SHARED, not property-scoped** ({@see PropertyIsolation::SHARED}) — a
 * supplier serves the whole portfolio and the per-property engagement lives on `VendorContract` /
 * `VendorBill`. So unlike `LeaseImporter` there is no asset column here and nothing to clamp, which
 * is a real simplification and not an omission.
 */
class VendorImporter extends Importer
{
    protected static ?string $model = Vendor::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label(__('admin.tables.vendor.name'))
                ->requiredMapping()
                ->rules(['required', 'max:200']),

            ImportColumn::make('legal_name')
                ->label(__('admin.fields.legal_name'))
                ->rules(['nullable', 'max:200']),

            // Both are DB enums (`vendors.type`, `vendors.status` — see App\Support\DatabaseEnums).
            // Validated against the exact set the column accepts: an unlisted value would otherwise
            // reach a strict-MySQL INSERT and surface as an opaque failed row with no reason on it.
            ImportColumn::make('type')
                ->label(__('admin.fields.type'))
                ->rules(['nullable', 'in:contractor,supplier,service_provider,consultant,other']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', 'in:active,inactive,blacklisted']),

            ImportColumn::make('tax_id')
                ->label(__('admin.fields.tax_id'))
                // The same Egyptian TRN shape the tenant side enforces. It is also this importer's
                // identity key, so a malformed one does not merely store badly — it splits a
                // supplier across two rows on the second pass.
                ->rules(['nullable', 'max:50', 'regex:/^\d{3}-?\d{3}-?\d{3}$/']),

            // ── The one column where blank ≠ zero ──────────────────────────────────────────────
            // `null` means "no agreed rate, use the portfolio default"; an explicit `0` means "this
            // supplier is EXEMPT from withholding". Collapsing them would silently start withholding
            // from an exempt vendor the next time the default rate changed — the distinction the
            // model's own cast docblock exists to protect. An empty CSV cell must therefore stay
            // null, which is why there is no `->default()` here.
            ImportColumn::make('withholding_tax_rate')
                ->label(__('admin.vendors.wht.rate'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0', 'max:100']),

            ImportColumn::make('email')
                ->label(__('admin.fields.email'))
                ->rules(['nullable', 'email', 'max:255']),

            ImportColumn::make('phone')
                ->label(__('admin.tables.vendor.phone'))
                ->rules(['nullable', 'max:50']),

            ImportColumn::make('address')
                ->label(__('admin.fields.address'))
                ->rules(['nullable', 'max:500']),

            ImportColumn::make('city')
                ->label(__('admin.fields.city'))
                ->rules(['nullable', 'max:100']),

            ImportColumn::make('notes')
                ->label(__('admin.fields.notes'))
                ->rules(['nullable', 'max:2000']),

            // `slug` is deliberately absent. The model derives it from the name and de-duplicates
            // against soft-deleted rows; accepting one from a CSV would let two suppliers collide
            // on a column the model guarantees is unique.
        ];
    }

    /**
     * Find the supplier this row refers to, or start a new one.
     *
     * **`tax_id` first, then `email`** — the same order and the same reasoning as `TenantImporter`.
     * Re-running an import is the normal response to a partial one, so identity has to survive a
     * second pass: keying on `name` would fork "Cairo HVAC Co." and "Cairo HVAC Co" into two
     * suppliers, each accumulating its own bills, contracts and compliance documents. Once either
     * has history, `RefusesDeletionWhenReferenced` correctly refuses to delete it, and the two
     * cannot be merged.
     *
     * A row with neither identifier creates a new supplier. That is the honest outcome — the file
     * gave us nothing to recognise it by — and it is the exception rather than the rule.
     */
    public function resolveRecord(): ?Vendor
    {
        $taxId = trim((string) ($this->data['tax_id'] ?? ''));

        if ($taxId !== '') {
            // Stored as typed, unlike Tenant which normalises to bare digits — so match on both
            // spellings rather than assuming the file uses the same one the operator did.
            $bare = preg_replace('/\D/', '', $taxId) ?? '';

            $existing = Vendor::query()
                ->where('tax_id', $taxId)
                ->orWhereRaw("REPLACE(REPLACE(tax_id, '-', ''), ' ', '') = ?", [$bare])
                ->first();

            return $existing ?? new Vendor(['tax_id' => $taxId]);
        }

        $email = trim((string) ($this->data['email'] ?? ''));

        if ($email !== '') {
            return Vendor::firstOrNew(['email' => $email]);
        }

        return new Vendor;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your vendor import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    /** Queued in production, `sync` locally and in the suite — same as its siblings. */
    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }

    /** A guard rail against a mis-mapped file, not a capacity limit. */
    public function getMaxRows(): ?int
    {
        return (int) config('imports.max_rows', 5000);
    }
}
