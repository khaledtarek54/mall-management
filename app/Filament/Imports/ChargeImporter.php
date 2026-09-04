<?php

namespace App\Filament\Imports;

use App\Contracts\BillableAgreement;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\UnitOwnership;
use App\Services\ChargeScheduleService;
use App\Support\TenantScope;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

/**
 * Load the recurring charges a lease bills every month — service charge, marketing, chiller, signage.
 *
 * **This importer writes through `ChargeScheduleService`, never straight to the table**, and that is
 * the whole design rather than a stylistic preference. A lease's charges are a SCHEDULE: dated rows
 * that must butt up against each other exactly. Two rows overlapping a month make it ambiguous which
 * amount applies, and the billing run — which refuses rather than guesses — bills **nothing at all**
 * for that lease. `atriom:audit-charge-schedules` exists because that has already happened to legacy
 * rows, and an importer inserting rows directly is the fastest way to recreate it a hundred times in
 * one upload.
 *
 * `setAmount()` is the one path that closes the outgoing row before opening the next, so a file that
 * lists two rates for the same charge produces a correct two-rung schedule instead of a lease that
 * silently stops billing.
 *
 * **`vat_rate` is an override and blank is the normal state.** The catalogue answers at billing
 * time, resolved for each invoice's own date — see `Charge::resolvedVatRate()`. A file column that
 * defaulted to today's standard rate would freeze it onto every imported lease and undo exactly the
 * fix that made a future rate change reach recurring rent.
 *
 * Scoped by the AGREEMENT, which carries its own property, so there is no `asset_code` column to
 * clamp: the resolvers below only find records on a property the importer can see.
 *
 * **A charge hangs off a lease OR a unit ownership**, so the file names one or the other
 * (2026-08-19). Module 37's assessments are `charges` rows exactly as a lease's service charge is,
 * and until now this importer resolved a `lease_reference` only — so a migrating operator who
 * loaded a portfolio of sold units got ownerships that `billing:run-assessments` could never bill,
 * silently, every month. That is the same shape as the missing schedule screen (pre-staging QA
 * F-01) arriving through the import door instead.
 */
class ChargeImporter extends Importer
{
    protected static ?string $model = Charge::class;

    /**
     * Every column here is an INPUT to `ChargeScheduleService`, never a direct write.
     *
     * They all carry a no-op `fillRecordUsing`, which looks odd until you see what the alternative
     * does: `resolveRecord()` has already asked the service to place this amount on the schedule —
     * which may have CLOSED the row in force and opened a new one — and Filament would then fill
     * `amount` straight onto whichever row came back, overwriting the rung the service just decided.
     * `lease_reference` is not a column on `charges` at all.
     *
     * @return array<int, ImportColumn>
     */
    public static function getColumns(): array
    {
        $inputOnly = fn (ImportColumn $column): ImportColumn => $column->fillRecordUsing(fn (): null => null);

        return [
            // Exactly ONE of these two identifies the agreement. Neither is `requiredMapping()`,
            // because a file of lease charges has no ownership column and vice versa; the
            // either-or is enforced in `resolveRecord()`, where both values are in hand.
            $inputOnly(ImportColumn::make('lease_reference')
                ->label(__('admin.imports.columns.lease_reference'))
                ->rules(['nullable', 'string'])),

            $inputOnly(ImportColumn::make('ownership_reference')
                ->label(__('admin.imports.columns.ownership_reference'))
                ->rules(['nullable', 'string'])),

            $inputOnly(ImportColumn::make('type')
                ->label(__('admin.imports.columns.charge_type'))
                ->requiredMapping()
                ->rules(['required', 'string', 'max:64'])),

            $inputOnly(ImportColumn::make('name')
                ->label(__('admin.imports.columns.line_description'))
                ->rules(['nullable', 'string', 'max:255'])),

            $inputOnly(ImportColumn::make('amount')
                ->label(__('admin.imports.columns.amount_per_cycle'))
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'min:0'])),

            $inputOnly(ImportColumn::make('frequency')
                ->rules(['nullable', Rule::in(ValueSets::allowed('charges', 'frequency'))])),

            // Settable in the UI and not in the importer is the gap a migrating operator falls
            // into: they arrive with a spreadsheet of charges, half of which their previous system
            // billed in arrears, and can express none of it. Blank = advance, which is what every
            // row without the column means.
            $inputOnly(ImportColumn::make('billing_timing')
                ->label(__('admin.fields.billing_timing'))
                ->rules(['nullable', Rule::in(ValueSets::allowed('charges', 'billing_timing'))])),

            // Yardi's per-charge prorate flag. A migrating operator arrives with a spreadsheet of
            // charges, some of which their previous system never prorated — settable in the UI and
            // not here is 200 rows keyed in by hand.
            $inputOnly(ImportColumn::make('prorate')
                ->label(__('admin.imports.columns.prorate'))
                ->boolean()
                ->rules(['nullable', 'boolean'])),

            $inputOnly(ImportColumn::make('effective_from')
                ->label(__('admin.imports.columns.effective_from'))
                ->requiredMapping()
                ->rules(['required', 'date'])),

            $inputOnly(ImportColumn::make('vat_rate')
                ->label(__('admin.imports.columns.vat_override'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0', 'max:100'])),
        ];
    }

    /**
     * The whole row is applied here rather than through column fills.
     *
     * `setAmount()` decides whether this is the schedule's first rung, a correction to a row that
     * has not started billing, or a new rung that closes the one in force — and it needs the amount
     * and the date together to make that call. Filling a `Charge` model column by column would
     * bypass it and put a bare row in the table.
     */
    public function resolveRecord(): ?Charge
    {
        // A model-level refusal is a sentence written FOR A PERSON — the schedule-overlap guard,
        // the unknown charge code, and (2026-09-03) a frequency the agreement's billing run cannot
        // invoice. Filament logs a bare `Throwable` as a failed row with **no message at all**
        // (`ImportCsv::logFailedRow($row)` — the message argument is only passed for
        // `RowImportFailedException` and `ValidationException`), so every one of those sentences
        // was dropped and the operator's failure CSV said only that the row failed. Re-thrown as
        // the one shape that carries it through.
        try {
            return $this->place();
        } catch (\DomainException $e) {
            throw new RowImportFailedException($e->getMessage());
        }
    }

    /** The schedule write itself — see {@see resolveRecord()} for why it is wrapped. */
    private function place(): ?Charge
    {
        $agreement = $this->resolveAgreement();

        $type = trim((string) ($this->data['type'] ?? ''));
        $rawRate = $this->data['vat_rate'] ?? null;

        return app(ChargeScheduleService::class)->setAmount(
            $agreement,
            $type,
            (float) ($this->data['amount'] ?? 0),
            CarbonImmutable::parse((string) $this->data['effective_from']),
            array_filter([
                'name' => trim((string) ($this->data['name'] ?? '')) ?: null,
                'frequency' => trim((string) ($this->data['frequency'] ?? '')) ?: 'monthly',
                // Null, not 'advance', when the column is absent or blank — null IS advance, and
                // writing the word would claim the operator ruled on a row they never mentioned.
                'billing_timing' => trim((string) ($this->data['billing_timing'] ?? '')) ?: null,
                // Blank stays NULL — the lease's own proration method stands, which is what every
                // charge did before the flag existed. Only an explicit falsehood in the file says
                // this row bills whole months. `array_filter` below drops nulls, so a `true` here
                // would be indistinguishable from silence and is deliberately not written.
                'prorate' => array_key_exists('prorate', $this->data) && $this->data['prorate'] !== null && $this->data['prorate'] !== ''
                    ? (bool) $this->data['prorate']
                    : null,
                // Blank stays NULL so the catalogue answers per invoice. An explicit 0 is the
                // operator saying this charge is not taxed, which is a different statement.
                'vat_rate' => ($rawRate === null || $rawRate === '') ? null : (float) $rawRate,
                // A charge imported as effective from September is not owed from the lease's
                // commencement; without this the first row back-dates and the next billing run
                // invoices every month since.
                'first_row_from_effective' => true,
            ], fn ($v): bool => $v !== null),
            Charge::ORIGIN_MANUAL,
        );
    }

    /**
     * The lease or the ownership this row's charge belongs to — exactly one of them.
     *
     * A row naming BOTH is refused rather than resolved by precedence: the file is stating two
     * different debtors for one charge, and picking one silently would bill somebody nobody chose.
     */
    private function resolveAgreement(): BillableAgreement
    {
        $leaseReference = trim((string) ($this->data['lease_reference'] ?? ''));
        $ownershipReference = trim((string) ($this->data['ownership_reference'] ?? ''));

        if ($leaseReference !== '' && $ownershipReference !== '') {
            throw new \RuntimeException(
                'This row names both a lease ['.$leaseReference.'] and an ownership ['
                .$ownershipReference.']. A charge belongs to one agreement — give the row one reference.'
            );
        }

        if ($leaseReference === '' && $ownershipReference === '') {
            throw new \RuntimeException('This row names neither a lease reference nor an ownership reference.');
        }

        // `visibleAssetIds()` returning NULL means unrestricted (super_admin), not "no properties" —
        // passing it straight to whereIn() is a TypeError, and defaulting it to [] would silently
        // refuse every row for the one user allowed to import anything.
        $visible = TenantScope::visibleAssetIds();

        if ($ownershipReference !== '') {
            $ownership = UnitOwnership::query()
                ->where('reference', $ownershipReference)
                ->when($visible !== null, fn ($q) => $q->whereIn('asset_id', $visible))
                ->first();

            return $ownership ?? throw new \RuntimeException(
                'Unknown or out-of-scope ownership reference ['.$ownershipReference.'].'
            );
        }

        $lease = Lease::query()
            ->where('reference', $leaseReference)
            ->when($visible !== null, fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $visible)))
            ->first();

        return $lease ?? throw new \RuntimeException(
            'Unknown or out-of-scope lease reference ['.$leaseReference.'].'
        );
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your charge-schedule import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body.' Run `php artisan atriom:audit-charge-schedules` to confirm no lease was left with an overlapping or gapped schedule.';
    }
}
