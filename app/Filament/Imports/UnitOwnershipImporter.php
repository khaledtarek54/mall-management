<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Support\DataTransferNotice;
use App\Support\ValueSets;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

/**
 * Load the sold units at cut-over — the owners who pay صيانة rather than rent.
 *
 * Module 37's whole population arrived one form at a time, and a mall that sold floors has
 * hundreds. That matters more than the typing: `BillUnitOwnershipsService` is SCHEDULED, so every
 * ownership missing from the register is an owner nobody bills, month after month, reported as an
 * unremarkable `skipped`. A register nobody can load is a register nobody has.
 *
 * **An owner IS a `tenants` row**, which is the thing to understand before reading the columns:
 * the counterparty a mall bills is one register whether the money is rent or a maintenance
 * assessment, so this importer matches an existing tenant by e-mail and REFUSES an unknown one
 * rather than creating a second party record for somebody already on file.
 *
 * **It does NOT import the assessment schedule**, deliberately — that is `ChargeImporter`'s job,
 * exactly as a lease's rent ladder is. An ownership with no charges bills nothing, which is why
 * `BillableAgreementIsConfigurableConformanceTest` requires both roads to exist for every billable
 * agreement, and why the operator is told so on completion rather than discovering it at month-end.
 *
 * Property-scoped through `ResolvesVisibleAssetByCode`, like every other importer here.
 */
class UnitOwnershipImporter extends Importer
{
    use ResolvesVisibleAssetByCode;

    protected static ?string $model = UnitOwnership::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('asset_code')
                ->label(__('admin.resources.asset.singular'))
                ->requiredMapping()
                ->rules(['required', 'string', static::assetInScopeRule()])
                ->fillRecordUsing(function (UnitOwnership $record, string $state): void {
                    $record->asset_id = static::resolveVisibleAsset($state)?->id;
                }),

            // The unit sold. Scoped to the row's OWN property: a unit code is unique per mall, so
            // an unscoped lookup would hand this owner the identically-coded shop next door.
            ImportColumn::make('unit_code')
                ->label(__('admin.resources.unit.singular'))
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->fillRecordUsing(function (UnitOwnership $record, string $state): void {
                    $record->unit_id = Unit::query()
                        ->where('asset_id', $record->asset_id)
                        ->where('code', $state)
                        ->value('id');
                }),

            // The owner, matched on a party already on file — see the class docblock.
            ImportColumn::make('owner_email')
                ->label(__('admin.fields.email'))
                ->requiredMapping()
                ->rules(['required', 'email', Rule::exists('tenants', 'email')->whereNull('deleted_at')])
                ->fillRecordUsing(function (UnitOwnership $record, string $state): void {
                    $record->tenant_id = Tenant::query()->where('email', $state)->value('id');
                }),

            ImportColumn::make('tenure_type')
                ->label(__('admin.fields.tenure_type'))
                ->requiredMapping()
                ->rules(['required', Rule::in(ValueSets::allowed('unit_ownerships', 'tenure_type'))]),

            ImportColumn::make('status')
                ->label(__('admin.fields.status'))
                ->requiredMapping()
                ->rules(['required', Rule::in(ValueSets::allowed('unit_ownerships', 'status'))]),

            ImportColumn::make('management_mode')
                ->label(__('admin.fields.management_mode'))
                ->requiredMapping()
                ->rules(['required', Rule::in(ValueSets::allowed('unit_ownerships', 'management_mode'))]),

            // WHAT THE ASSESSMENT IS CHARGED ON. Required because it decides the money: `area`
            // apportions by square metres, `participation` by the deed's share of the building,
            // `purchase_value` by what they paid, `stated` by a figure somebody typed. A default
            // here would be this system choosing a basis on the operator's behalf.
            ImportColumn::make('assessment_basis')
                ->label(__('admin.fields.assessment_basis'))
                ->requiredMapping()
                ->rules(['required', Rule::in(ValueSets::allowed('unit_ownerships', 'assessment_basis'))]),

            // A CO-OWNED unit is why this is a column and not an assumption: two owners at 50 each
            // is the ordinary Egyptian shape, and SW-220 is what happens when the share is ignored.
            ImportColumn::make('ownership_share_pct')
                ->label(__('admin.fields.ownership_share_pct'))
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'min:0.01', 'max:100']),

            ImportColumn::make('participation_pct')
                ->label(__('admin.fields.participation_pct'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0', 'max:100']),

            ImportColumn::make('purchase_contract_number')
                ->label(__('admin.fields.purchase_contract_number'))
                ->rules(['nullable', 'max:100']),

            ImportColumn::make('purchase_date')
                ->label(__('admin.fields.purchase_date'))
                ->rules(['nullable', 'date']),

            ImportColumn::make('purchase_price')
                ->label(__('admin.fields.purchase_price'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            // POSSESSION, which is not the sale: an owner pays the service assessment from the day
            // they are handed the keys, and `everHadPossession()` reads this to decide whether a
            // tenure counts toward a recovery year at all.
            ImportColumn::make('handover_date')
                ->label(__('admin.fields.handover_date'))
                ->rules(['nullable', 'date']),

            ImportColumn::make('started_at')
                ->label(__('admin.fields.started_at'))
                ->rules(['nullable', 'date']),

            ImportColumn::make('ended_at')
                ->label(__('admin.fields.ended_at'))
                ->rules(['nullable', 'date', 'after_or_equal:started_at']),

            ImportColumn::make('payment_terms_days')
                ->label(__('admin.fields.payment_terms_days'))
                ->numeric()
                ->rules(['nullable', 'integer', 'min:0', 'max:365']),

            ImportColumn::make('notes')
                ->label(__('admin.fields.notes'))
                ->rules(['nullable', 'max:2000']),
        ];
    }

    public function resolveRecord(): ?UnitOwnership
    {
        $asset = static::resolveVisibleAsset($this->data['asset_code'] ?? null);

        if (! $asset) {
            return null;
        }

        $unitId = Unit::query()
            ->where('asset_id', $asset->id)
            ->where('code', $this->data['unit_code'] ?? '')
            ->value('id');

        $tenantId = Tenant::query()->where('email', $this->data['owner_email'] ?? '')->value('id');

        // Keyed on (unit, owner) — one tenure per party per unit, which is the identity the
        // register uses. A second pass CORRECTS rather than duplicating, and a migrating operator
        // re-uploads a corrected file more often than a clean one.
        //
        // A RESALE is deliberately NOT expressible here: the seller's tenure has to be closed and
        // the buyer's opened as one act, which is `TransferUnitOwnershipService` — it dates both
        // ends, carries the deed share across and keeps the CAM year apportioned between them. An
        // importer that let a file do it by hand would produce two tenures nobody had reconciled.
        $existing = $unitId && $tenantId
            ? UnitOwnership::query()->where('unit_id', $unitId)->where('tenant_id', $tenantId)->first()
            : null;

        return $existing ?? new UnitOwnership([
            'asset_id' => $asset->id,
            'currency' => 'EGP',
            'payment_terms_days' => 15,
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        // The register alone bills NOTHING — the assessment schedule is `ChargeImporter`'s file,
        // exactly as a lease's rent ladder is. Said here because the alternative is finding out at
        // month-end, when the run reports every one of these owners as an unremarkable `skipped`.
        return DataTransferNotice::forImport($import)
            .' '.__('admin.unit_ownerships.import_charges_next');
    }

    /** Queued in production, `sync` locally and in the suite — same as its siblings. */
    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }
}
