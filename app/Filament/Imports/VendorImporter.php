<?php

namespace App\Filament\Imports;

use App\Models\TaxCode;
use App\Models\Vendor;
use App\Support\Filament\CustomFieldsTable;
use App\Support\Pdf\DocumentLocale;
use App\Support\PropertyIsolation;
use App\Support\ValueSets;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

/**
 * Load the operator's existing supplier register at cut-over.
 *
 * Fourth importer, and it follows the three that exist rather than inventing anything: identity on
 * something genuinely unique (`TenantImporter` keys on `tax_id`, `UnitImporter` on the unique
 * (asset, code), `LeaseImporter` on `reference`), enum-backed columns validated against the DB's own
 * set, and the queue connection read from config instead of hard-coded `sync`.
 *
 * **`Vendor` is SHARED, not property-scoped** ({@see PropertyIsolation::sharedModels()}) — a
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
            // See TenantImporter — a supplied supplier code is kept.
            ImportColumn::make('code')
                ->label(__('admin.fields.vendor_code'))
                ->rules(['nullable', 'max:40']),

            ImportColumn::make('name')
                ->label(__('admin.tables.vendor.name'))
                ->requiredMapping()
                ->rules(['required', 'max:200']),

            ImportColumn::make('legal_name')
                ->label(__('admin.fields.legal_name'))
                ->rules(['nullable', 'max:200']),

            // Validated against the exact set the column accepts, READ FROM the registry rather than
            // repeated here: an unlisted value fails the row with a reason on it instead of reaching
            // the INSERT, and widening the set stays a one-line change in one file. These two were
            // DB enums until 2026-08-12 — see App\Support\ValueSets.
            ImportColumn::make('type')
                ->label(__('admin.fields.type'))
                ->rules(['nullable', Rule::in(ValueSets::allowed('vendors', 'type'))]),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', Rule::in(ValueSets::allowed('vendors', 'status'))]),

            ImportColumn::make('tax_id')
                ->label(__('admin.fields.tax_id'))
                // The same Egyptian TRN shape the tenant side enforces. It is also this importer's
                // identity key, so a malformed one does not merely store badly — it splits a
                // supplier across two rows on the second pass.
                ->rules(['nullable', 'max:50', 'regex:/^\d{3}-?\d{3}-?\d{3}$/']),

            // ── Withholding: a CODE, and a separate exemption flag ────────────────────────────
            // Two columns rather than one, because a single value had to carry two meanings: blank
            // for "no agreed nature, use the portfolio default" and an explicit 0 for "this
            // supplier is EXEMPT". Collapsing them would silently start withholding from an exempt
            // vendor the next time the default changed. Splitting them makes the CSV say which it
            // means instead of relying on a magic zero.
            //
            // The code is validated against the catalogue rather than a rate range: a spreadsheet
            // that carries "2" would previously have been accepted and quietly withheld 2%, a rate
            // the operator's own tax sheet does not contain.
            ImportColumn::make('withholding_tax_code')
                ->label(__('admin.vendors.wht.code'))
                ->rules([
                    'nullable',
                    'string',
                    Rule::in(array_keys(TaxCode::options(TaxCode::PURCHASES, families: [TaxCode::FAMILY_WITHHOLDING]))),
                ]),

            ImportColumn::make('withholding_exempt')
                ->label(__('admin.vendors.wht.exempt'))
                ->boolean()
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('email')
                ->label(__('admin.fields.email'))
                ->rules(['nullable', 'email', 'max:255']),

            ImportColumn::make('phone')
                ->label(__('admin.tables.vendor.phone'))
                ->rules(['nullable', 'max:50']),

            // Which language this party's documents are issued in. An operator migrating from
            // another system knows this per record and would otherwise set it by hand afterwards.
            // Narrower than the column deliberately (see CLAUDE.md on re-listing a value set):
            // `Rule::in` over the languages we hold a catalogue for, so a spreadsheet typo is
            // refused at import rather than silently producing English.
            ImportColumn::make('locale')
                ->label(__('admin.fields.locale'))
                ->rules(['nullable', Rule::in(DocumentLocale::supported())]),

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

            // The operator's own fields (D-7), LAST so an existing mapping template's column
            // order is untouched. Optional: a sheet that names none imports as it always did.
            ...CustomFieldsTable::importColumns('vendor'),
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
