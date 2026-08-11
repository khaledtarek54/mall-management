<?php

namespace App\Filament\Imports;

use App\Models\Tenant;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class TenantImporter extends Importer
{
    protected static ?string $model = Tenant::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label(__('admin.tables.tenant.name'))
                ->requiredMapping()
                ->rules(['required', 'max:200']),

            ImportColumn::make('legal_name')
                ->label(__('admin.fields.legal_name'))
                ->rules(['nullable', 'max:200']),

            ImportColumn::make('type')
                ->label(__('admin.fields.type'))
                // The schema is enum('type',['individual','company']); 'foreign' is not storable and
                // would fail the INSERT on strict MySQL as an opaque failed row. Reject it cleanly.
                ->rules(['nullable', 'in:individual,company']),

            ImportColumn::make('tax_id')
                ->label(__('admin.fields.tax_id'))
                // Same Egyptian-VAT format the admin form enforces — import is the primary roster
                // onboarding path, so a malformed tax_id here is the go-live/ETA risk the module
                // doc calls out. (The Tenant model then normalises it to bare digits on save.)
                ->rules(['nullable', 'max:50', 'regex:/^\d{3}-?\d{3}-?\d{3}$/']),

            ImportColumn::make('email')
                ->label(__('admin.tables.tenant.email'))
                ->rules(['nullable', 'email', 'max:255']),

            ImportColumn::make('phone')
                ->label(__('admin.tables.tenant.phone'))
                ->rules(['nullable', 'max:50']),

            ImportColumn::make('contact_person')
                ->label(__('admin.fields.contact_person'))
                ->rules(['nullable', 'max:200']),

            ImportColumn::make('address')
                ->label(__('admin.fields.address'))
                ->rules(['nullable', 'max:500']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', 'in:active,inactive,blacklisted']),
        ];
    }

    /**
     * Find the tenant this row refers to, or start a new one.
     *
     * **Identity is `tax_id` first, then `email`.** `tenants.email` is nullable and carries no
     * unique index, so keying only on it meant that re-running an import — the normal response to
     * a partial one — created a fresh duplicate of every email-less tenant. Those duplicates then
     * acquire their own leases and invoices, splitting one retailer's AR across two records that
     * can no longer be merged, because `RefusesDeletionWhenReferenced` correctly refuses to delete
     * either once it has history.
     *
     * The Egyptian tax registration is the better key: it is the identifier the operator's own
     * records and the tax authority both use, and one company has exactly one. `email` remains the
     * fallback for a tenant that genuinely has no TRN yet.
     *
     * A row with neither is a row we cannot recognise on a second pass, so it creates a new tenant
     * — the same behaviour as before, now the exception rather than the rule. Both sibling
     * importers key on something genuinely unique: `LeaseImporter` on `reference` or
     * (unit, commencement), `UnitImporter` on the unique (asset_id, code).
     */
    public function resolveRecord(): ?Tenant
    {
        // Normalised through the model's own rule: the column stores bare digits, so looking up
        // the dashed form the CSV carries would match nothing — and this dedup would have created
        // a duplicate of every tenant while appearing to prevent exactly that.
        $taxId = Tenant::normaliseTaxId($this->data['tax_id'] ?? null);

        if (filled($taxId)) {
            return Tenant::firstOrNew(['tax_id' => $taxId]);
        }

        $email = $this->data['email'] ?? null;

        if (filled($email)) {
            return Tenant::firstOrNew(['email' => $email]);
        }

        return new Tenant;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your tenant import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }

    /**
     * Queue the import rather than running it inline.
     *
     * Was a hard-coded `'sync'`, which no configuration could reach — so the cut-over ran inside
     * one HTTP request. `sync` remains the default (config/imports.php), so local work and the
     * suite are unchanged; production sets IMPORT_QUEUE_CONNECTION.
     */
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
