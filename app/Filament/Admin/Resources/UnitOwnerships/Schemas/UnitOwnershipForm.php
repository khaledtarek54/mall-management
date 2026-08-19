<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Schemas;

use App\Enums\AssessmentBasis;
use App\Enums\ManagementFeeBasis;
use App\Enums\PartyType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recording a unit sale — who bought which unit, on what terms.
 *
 * The four value-set Selects read their options straight off the backed enums the model casts to, so
 * the screen and the guard can never offer different sets.
 */
class UnitOwnershipForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.unit_ownerships.sections.the_sale'))
                ->columns(2)
                ->schema([
                    PropertyField::make()
                        ->live(),

                    Select::make('unit_id')
                        ->label(__('admin.fields.unit_id'))
                        ->options(fn (callable $get): array => self::unitOptions($get('asset_id')))
                        ->searchable()
                        ->required()
                        // A unit can be sold, then resold — so the picker cannot exclude units that
                        // already carry an ownership. It is the tenure that must not overlap, and
                        // that is the service's job, not the picker's.
                        ->helperText(__('admin.unit_ownerships.help.unit')),

                    EntitySelect::make('tenant_id')
                        ->label(__('admin.unit_ownerships.fields.owner'))
                        ->entity(Tenant::class)
                        // Buyers only — a retailer cannot hold a unit (module 37, `party_type`).
                        ->modifyOptionsQuery(fn ($query) => $query->unitOwners())
                        ->required()
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.unit_owner_party')),

                    Select::make('tenure_type')
                        ->label(__('admin.fields.tenure_type'))
                        ->options(UnitTenureType::options())
                        ->default(UnitTenureType::default()->value)
                        ->required()
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tenure_type')),

                    TextInput::make('purchase_contract_number')
                        ->label(__('admin.unit_ownerships.fields.purchase_contract_number'))
                        ->maxLength(100),

                    TextInput::make('purchase_price')
                        ->label(__('admin.unit_ownerships.fields.purchase_price'))
                        ->numeric()
                        ->minValue(0)
                        ->prefix('EGP'),

                    DatePicker::make('purchase_date')
                        ->label(__('admin.unit_ownerships.fields.purchase_date'))
                        ->native(false),

                    DatePicker::make('handover_date')
                        ->label(__('admin.unit_ownerships.fields.handover_date'))
                        ->native(false)
                        ->helperText(__('admin.unit_ownerships.help.handover_date')),
                ]),

            Section::make(__('admin.unit_ownerships.sections.the_holding'))
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label(__('admin.fields.status'))
                        ->options(UnitOwnershipStatus::options())
                        ->default(UnitOwnershipStatus::default()->value)
                        ->required()
                        ->helperText(__('admin.unit_ownerships.help.status')),

                    Select::make('management_mode')
                        ->label(__('admin.fields.management_mode'))
                        ->options(UnitManagementMode::options())
                        ->default(UnitManagementMode::default()->value)
                        ->required()
                        ->live()
                        ->helperText(__('admin.unit_ownerships.help.management_mode')),

                    DatePicker::make('started_at')
                        ->label(__('admin.fields.started_at'))
                        ->native(false)
                        ->required(),

                    DatePicker::make('ended_at')
                        ->label(__('admin.fields.ended_at'))
                        ->native(false)
                        ->helperText(__('admin.unit_ownerships.help.ended_at')),

                    TextInput::make('ownership_share_pct')
                        ->label(__('admin.fields.ownership_share_pct'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(100)
                        ->suffix('%')
                        ->required()
                        ->helperText(__('admin.unit_ownerships.help.ownership_share_pct')),

                    TextInput::make('payment_terms_days')
                        ->label(__('admin.fields.payment_terms_days'))
                        ->numeric()
                        ->minValue(0)
                        ->default(7)
                        ->required(),
                ]),

            Section::make(__('admin.unit_ownerships.sections.what_they_pay'))
                ->columns(2)
                ->schema([
                    Select::make('assessment_basis')
                        ->label(__('admin.fields.assessment_basis'))
                        ->options(AssessmentBasis::options())
                        ->default(AssessmentBasis::default()->value)
                        ->required()
                        ->live()
                        ->helperText(__('admin.unit_ownerships.help.assessment_basis')),

                    TextInput::make('participation_pct')
                        ->label(__('admin.fields.participation_pct'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        // Required exactly when the chosen basis reads it — asked of the enum so a
                        // fifth basis cannot be added without answering the question.
                        ->required(fn (callable $get): bool => self::basisNeedsParticipation($get('assessment_basis')))
                        ->visible(fn (callable $get): bool => self::basisNeedsParticipation($get('assessment_basis')))
                        ->helperText(__('admin.unit_ownerships.help.participation_pct')),

                    TextInput::make('management_fee_pct')
                        ->label(__('admin.fields.management_fee_pct'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->visible(fn (callable $get): bool => $get('management_mode') === UnitManagementMode::OperatorManaged->value)
                        ->helperText(__('admin.unit_ownerships.help.management_fee_pct')),

                    Select::make('fee_basis')
                        ->label(__('admin.fields.fee_basis'))
                        ->options(ManagementFeeBasis::options())
                        ->default(ManagementFeeBasis::default()->value)
                        ->visible(fn (callable $get): bool => $get('management_mode') === UnitManagementMode::OperatorManaged->value)
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.fee_basis')),

                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Does the chosen basis read `participation_pct`? Answered by the enum, never by a literal. */
    private static function basisNeedsParticipation(?string $basis): bool
    {
        return $basis !== null
            && AssessmentBasis::tryFrom($basis)?->requiredColumn() === 'participation_pct';
    }

    /**
     * Units in the chosen property.
     *
     * Scoped through the FORM's asset_id rather than the panel's current property, because in
     * All-Properties mode the Select is enabled and the operator picks the mall — reading
     * `currentAssetId()` alone would offer nothing (it is null there) or, worse, the wrong mall's
     * units. The submitted asset_id is re-validated server-side by `assertAssetInScope()`.
     *
     * @return array<int, string>
     */
    private static function unitOptions(mixed $assetId): array
    {
        if (blank($assetId)) {
            return [];
        }

        return Unit::query()
            ->where('asset_id', $assetId)
            ->when(
                ($visible = TenantScope::visibleAssetIds()) !== null,
                fn (Builder $q) => $q->whereIn('asset_id', $visible),
            )
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    /** @return array<int, string> */
    public static function partyTypeOptions(): array
    {
        return PartyType::options();
    }
}
