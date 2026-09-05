<?php

namespace App\Filament\Imports;

use App\Models\Tenant;
use App\Support\DataTransferNotice;
use App\Support\Filament\CustomFieldsTable;
use App\Support\Pdf\DocumentLocale;
use App\Support\ValueSets;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

class TenantImporter extends Importer
{
    protected static ?string $model = Tenant::class;

    public static function getColumns(): array
    {
        return [
            // Optional, and kept when supplied: an operator migrating off another system arrives
            // with tenant codes their accountant already uses. Left blank, the model allocates the
            // next one in the series.
            ImportColumn::make('code')
                ->label(__('admin.fields.tenant_code'))
                ->rules(['nullable', 'max:40']),

            ImportColumn::make('name')
                ->label(__('admin.tables.tenant.name'))
                ->requiredMapping()
                ->rules(['required', 'max:'.Tenant::FIELD_MAX['name']]),

            ImportColumn::make('legal_name')
                ->label(__('admin.fields.legal_name'))
                ->rules(['nullable', 'max:'.Tenant::FIELD_MAX['legal_name']]),

            ImportColumn::make('type')
                ->label(__('admin.fields.type'))
                // 'foreign' is not an accepted value; rejecting it here fails the row with a reason
                // rather than letting it reach the model guard as an opaque failed row. Read from
                // the registry, not repeated — the column was an enum until 2026-08-12.
                ->rules(['nullable', Rule::in(ValueSets::allowed('tenants', 'type'))]),

            ImportColumn::make('tax_id')
                ->label(__('admin.fields.tax_id'))
                // Same Egyptian-VAT format the admin form enforces — import is the primary roster
                // onboarding path, so a malformed tax_id here is the go-live/ETA risk the module
                // doc calls out. (The Tenant model then normalises it to bare digits on save.)
                ->rules(['nullable', 'max:50', 'regex:/^\d{3}-?\d{3}-?\d{3}$/']),

            ImportColumn::make('email')
                ->label(__('admin.tables.tenant.email'))
                ->rules(['nullable', 'email', 'max:'.Tenant::FIELD_MAX['email']]),

            ImportColumn::make('phone')
                ->label(__('admin.tables.tenant.phone'))
                ->rules(['nullable', 'max:'.Tenant::FIELD_MAX['phone']]),

            // Which language this tenant's documents are issued in. An operator migrating from
            // another system knows this per retailer and would otherwise have to set it by hand on
            // every record afterwards. Narrower than the column deliberately (see CLAUDE.md on
            // re-listing a value set): `Rule::in` over the languages we hold a catalogue for, so a
            // spreadsheet typo is refused at import rather than silently producing English.
            ImportColumn::make('locale')
                ->label(__('admin.fields.locale'))
                ->rules(['nullable', Rule::in(DocumentLocale::supported())]),

            ImportColumn::make('contact_person')
                ->label(__('admin.fields.contact_person'))
                ->rules(['nullable', 'max:'.Tenant::FIELD_MAX['contact_person']]),

            ImportColumn::make('address')
                ->label(__('admin.fields.address'))
                ->rules(['nullable', 'max:500']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', Rule::in(ValueSets::allowed('tenants', 'status'))]),

            // The operator's own fields (D-7), LAST so an existing mapping template's column
            // order is untouched. Optional: a sheet that names none imports as it always did.
            ...CustomFieldsTable::importColumns('tenant'),
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
        return DataTransferNotice::forImport($import);
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
