<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Schemas;

use App\Models\CamExpensePool;
use App\Support\Vat;
use App\Models\LedgerAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CamExpensePoolForm
{
    /**
     * The recovery basis (expense, estimate, fee %, VAT) is FROZEN once any allocation is billed —
     * changing it would leave billed rows on the old figure while regenerated rows use the new one
     * (the model's updating guard throws). Disabling the fields (with a hint saying why) is the UX
     * side of that guard: the operator sees they must void billed allocations first, instead of hitting
     * a raw exception on save.
     */
    public static function basisFrozen(?CamExpensePool $record): bool
    {
        return $record !== null && $record->allocations()->where('status', '!=', 'pending')->exists();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.cam_pool'))
                ->description(__('admin.sections.cam_pool_description'))
                ->columns(3)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.resources.asset.singular'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    TextInput::make('period_year')
                        ->label(__('admin.fields.period_year'))
                        ->required()
                        ->numeric()
                        ->minValue(2020)
                        ->maxValue(2099)
                        // Clamped: `asset_id` is client-supplied, and a unique rule keyed on
                        // the raw value leaks whether a pool exists for a year in a property
                        // the user cannot see (TenantScope::clampAssetId).
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('asset_id', \App\Support\TenantScope::clampAssetId($get('asset_id'))))
                        ->default(fn () => now()->year),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.cam_pool'))
                        ->default('draft')
                        ->required()
                        ->native(false),
                    // Where each total comes from (RC-01 / RC-05). BOTH default to `stated` on the
                    // COLUMN so no pool that already exists changes basis, while a NEW pool is
                    // created on the derived bases — the same split-default pattern as
                    // Lease::$fit_out_scope, and for the same reason: existing years must keep the
                    // basis they were reconciled against.
                    Select::make('expense_basis')
                        ->label(__('admin.cam.expense_basis'))
                        ->options([
                            CamExpensePool::BASIS_STATED => __('admin.cam.basis_stated'),
                            CamExpensePool::BASIS_LEDGER => __('admin.cam.basis_ledger'),
                        ])
                        ->default(CamExpensePool::BASIS_LEDGER)
                        ->required()
                        ->live()
                        ->native(false)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    Select::make('estimate_basis')
                        ->label(__('admin.cam.estimate_basis'))
                        ->options([
                            CamExpensePool::BASIS_STATED => __('admin.cam.basis_stated'),
                            CamExpensePool::BASIS_BILLED => __('admin.cam.basis_billed'),
                        ])
                        ->default(CamExpensePool::BASIS_BILLED)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    Select::make('ledgerAccounts')
                        ->label(__('admin.cam.ledger_accounts'))
                        ->helperText(__('admin.cam.ledger_accounts_help'))
                        ->relationship('ledgerAccounts', 'name_en', fn ($query) => $query
                            ->where('type', 'expense')
                            ->where('is_postable', true)
                            ->orderBy('code'))
                        ->getOptionLabelFromRecordUsing(fn (LedgerAccount $record) => "{$record->code} · {$record->name_en}")
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->columnSpanFull()
                        ->visible(fn ($get) => $get('expense_basis') === CamExpensePool::BASIS_LEDGER)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    // RC-03. `occupied` recovers 100% of the pool from whoever is trading, which
                    // is what SOME leases say; `gla` leaves the vacancy with the landlord, which is
                    // what many others say. The column default is `occupied`, so every existing
                    // pool keeps the basis it was reconciled on.
                    Select::make('denominator_basis')
                        ->label(__('admin.cam.denominator_basis'))
                        ->helperText(__('admin.cam.denominator_basis_help'))
                        ->options([
                            CamExpensePool::DENOMINATOR_OCCUPIED => __('admin.cam.denominator_occupied'),
                            CamExpensePool::DENOMINATOR_GLA => __('admin.cam.denominator_gla'),
                            CamExpensePool::DENOMINATOR_FIXED => __('admin.cam.denominator_fixed'),
                        ])
                        ->default(CamExpensePool::DENOMINATOR_OCCUPIED)
                        ->required()
                        ->live()
                        ->native(false)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    TextInput::make('denominator_fixed_sqm')
                        ->label(__('admin.cam.denominator_fixed_sqm'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('m²')
                        ->visible(fn ($get) => $get('denominator_basis') === CamExpensePool::DENOMINATOR_FIXED)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    // RC-04. Hidden on `occupied`, where the shares already sum to 100% and
                    // grossing up would bill tenants more than the landlord spent.
                    TextInput::make('gross_up_pct')
                        ->label(__('admin.cam.gross_up_pct'))
                        ->helperText(__('admin.cam.gross_up_pct_help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->visible(fn ($get) => $get('denominator_basis') !== CamExpensePool::DENOMINATOR_OCCUPIED)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    TextInput::make('variable_pct')
                        ->label(__('admin.cam.variable_pct'))
                        ->helperText(__('admin.cam.variable_pct_help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->visible(fn ($get) => $get('denominator_basis') !== CamExpensePool::DENOMINATOR_OCCUPIED
                            && $get('expense_basis') !== CamExpensePool::BASIS_LEDGER)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    TextInput::make('total_actual_expense')
                        ->label(__('admin.fields.total_actual_expense'))
                        ->prefix('EGP')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->helperText(__('admin.helpers.cam_actual_expense'))
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record))
                        ->hintColor('warning')
                        ->hint(fn (?CamExpensePool $record) => self::basisFrozen($record) ? __('admin.helpers.cam_basis_frozen') : null),
                    TextInput::make('total_estimated_collected')
                        ->label(__('admin.fields.total_estimated_collected'))
                        ->prefix('EGP')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->helperText(__('admin.helpers.cam_estimated_collected'))
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record))
                        ->hintColor('warning')
                        ->hint(fn (?CamExpensePool $record) => self::basisFrozen($record) ? __('admin.helpers.cam_basis_frozen') : null),
                    // Admin fee % is stored as a FRACTION (0.10) but operators think in percent (10).
                    // formatStateUsing (×100) runs on hydrate — INCLUDING on the default — so the
                    // default is expressed in the field's raw (pre-format) space, the fraction 0.10,
                    // which formats to "10" for display and dehydrates back to 0.10 on save. (A
                    // default of 10 would format to 1000 and blow maxValue(100).) Blank ⇒ null ⇒ no fee.
                    TextInput::make('admin_fee_pct')
                        ->label(__('admin.fields.cam_admin_fee_pct'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        ->default(0.10)
                        ->helperText(__('admin.helpers.cam_admin_fee_pct'))
                        ->formatStateUsing(fn ($state) => $state === null ? null : round((float) $state * 100, 4))
                        ->dehydrateStateUsing(fn ($state) => ($state === null || $state === '') ? null : round((float) $state / 100, 6))
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record))
                        ->hintColor('warning')
                        ->hint(fn (?CamExpensePool $record) => self::basisFrozen($record) ? __('admin.helpers.cam_basis_frozen') : null),

                    // VAT on the cost recovery (true-up + over-collection credit). Plain percentage
                    // (14, not the fee's 0.10 fraction). Defaults to the standard rate, matching the
                    // monthly CAM estimate; set 0% for a genuinely non-taxable pass-through. Frozen
                    // once an allocation is billed — so a later rate change never moves a pool that
                    // has already billed, which is why this reads the setting only as a NEW pool's
                    // starting point.
                    TextInput::make('recovery_vat_rate')
                        ->label(__('admin.fields.cam_recovery_vat_rate'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        ->default(fn () => Vat::standardRate())
                        ->required()
                        ->helperText(__('admin.helpers.cam_recovery_vat_rate'))
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record))
                        ->hintColor('warning')
                        ->hint(fn (?CamExpensePool $record) => self::basisFrozen($record) ? __('admin.helpers.cam_basis_frozen') : null),
                ]),
            Section::make(__('admin.sections.cam_notes'))
                ->components([
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
