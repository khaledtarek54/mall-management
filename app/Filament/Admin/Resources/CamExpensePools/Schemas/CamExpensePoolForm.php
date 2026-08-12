<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Schemas;

use App\Enums\InvoiceItemType;
use App\Models\Area;
use App\Models\CamExpensePool;
use App\Models\ChargeCode;
use App\Models\LedgerAccount;
use App\Support\TenantScope;
use App\Support\Vat;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

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

    /** The result of a reconciliation, never an estimate paid into one. */
    private const NOT_AN_ESTIMATE = ['cam_recovery', 'cam_admin_fee'];

    /**
     * The charge codes an operator may nominate as a pool's estimate.
     *
     * `cam_recovery` and `cam_admin_fee` are excluded: they are the RESULT of a reconciliation, so
     * counting them would let last year's true-up inflate this year's estimate and the pool would
     * chase its own tail. The rule is stated on `CamExpensePool::ESTIMATE_ITEM_TYPES` too, but a
     * select the operator can see is where the wrong choice would actually be made.
     *
     * **`InvoiceItemType` is the FLOOR, the catalogue supplies the names.** Reading only
     * `charge_codes` would leave this select empty on a fresh install before `ChargeCodeSeeder`
     * runs — and an empty select with a required field is a form nobody can submit. Same
     * floor-and-overlay shape as `Vat::EXEMPT_TYPES`: the engine's own list can always answer, and
     * the accountant's catalogue refines it. A conformance test already pins that every enum case
     * has a catalogue row, so the two cannot drift apart.
     *
     * @return array<string, string>
     */
    private static function estimateCodeOptions(): array
    {
        $isArabic = app()->getLocale() === 'ar';

        $options = collect(InvoiceItemType::options())
            ->except(self::NOT_AN_ESTIMATE);

        ChargeCode::query()
            ->where('is_active', true)
            ->whereNotIn('code', self::NOT_AN_ESTIMATE)
            ->orderBy('sort_order')
            ->get()
            ->each(function (ChargeCode $c) use (&$options, $isArabic): void {
                $options[$c->code] = ($isArabic ? $c->name_ar : $c->name_en) ?: $c->code;
            });

        return $options->all();
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
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
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
                        // Keyed on asset + year + POOL CODE since RC-02: a property runs several
                        // pools in a year, so uniqueness on (asset, year) alone would refuse the
                        // second one. Clamped because `asset_id` is client-supplied, and a unique
                        // rule keyed on the raw value leaks whether a pool exists for a year in a
                        // property the user cannot see (TenantScope::clampAssetId).
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                            ->where('asset_id', TenantScope::clampAssetId($get('asset_id')))
                            ->where('pool_code', $get('pool_code') ?: CamExpensePool::CODE_CAM))
                        ->live(onBlur: true)
                        ->default(fn () => now()->year),
                    // ── Which pool this is (RC-02) ────────────────────────────────────────────
                    // A property runs several: CAM, real-estate tax, insurance, a food-court pool.
                    // The CODE is the key; the name is what an operator reads.
                    TextInput::make('pool_code')
                        ->label(__('admin.fields.pool_code'))
                        ->required()
                        ->maxLength(32)
                        ->default(CamExpensePool::CODE_CAM)
                        ->live(onBlur: true)
                        // Moving off `cam` moves off CAM's assumptions. The billed basis and the
                        // service-charge default are right for the property's CAM pool and wrong
                        // for a tax or insurance pool, where the same defaults quietly subtract the
                        // tenant's whole year of service charge. Reset rather than carry over: the
                        // operator states what the new pool bills, or reconciles on a stated figure.
                        ->afterStateUpdated(function ($state, $old, callable $set) {
                            if ($state === $old || $state === CamExpensePool::CODE_CAM) {
                                return;
                            }

                            $set('estimate_basis', CamExpensePool::BASIS_STATED);
                            $set('estimate_charge_codes', []);
                        })
                        ->helperText(__('admin.helpers.pool_code'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.pool_code'))
                        // The code is part of the identity of the pool and of every allocation
                        // beneath it — renaming it after a reconciliation would silently re-key
                        // the year's history.
                        ->disabled(fn (?CamExpensePool $record): bool => $record?->allocations()->exists() ?? false),
                    TextInput::make('name')
                        ->label(__('admin.fields.pool_name'))
                        ->maxLength(255)
                        ->placeholder(__('admin.helpers.pool_name_placeholder')),
                    Select::make('participant_scope')
                        ->label(__('admin.fields.participant_scope'))
                        ->options(fn () => __('admin.enums.participant_scope'))
                        ->default(CamExpensePool::PARTICIPANTS_ALL)
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText(__('admin.helpers.participant_scope'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.participant_scope')),
                    Select::make('participant_area_id')
                        ->label(__('admin.fields.participant_area'))
                        // Scoped to the pool's own property, like every cross-model select here.
                        ->options(fn (Get $get) => Area::query()
                            ->when(TenantScope::clampAssetId($get('asset_id')), fn ($q, $id) => $q->where('asset_id', $id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false)
                        ->searchable()
                        ->required(fn ($get): bool => $get('participant_scope') === CamExpensePool::PARTICIPANTS_AREA)
                        ->visible(fn ($get): bool => $get('participant_scope') === CamExpensePool::PARTICIPANTS_AREA)
                        ->helperText(__('admin.helpers.participant_area'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.participant_area')),
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
                        ->live()
                        ->native(false)
                        ->disabled(fn (?CamExpensePool $record) => self::basisFrozen($record)),
                    // Which billed codes ARE this pool's estimate. Required on the billed basis,
                    // because that basis is a claim about what was billed and a pool that cannot
                    // say what it bills has no business making it — the alternative, a global
                    // `service_charge` for every pool, had a `tax` pool subtract the tenant's whole
                    // year of service charge and credit-note the difference.
                    Select::make('estimate_charge_codes')
                        ->label(__('admin.cam.estimate_charge_codes'))
                        ->helperText(__('admin.cam.estimate_charge_codes_help'))
                        ->options(fn () => self::estimateCodeOptions())
                        ->multiple()
                        ->native(false)
                        ->searchable()
                        ->columnSpanFull()
                        ->default([CamExpensePool::ESTIMATE_ITEM_TYPES[0]])
                        ->required(fn ($get): bool => $get('estimate_basis') === CamExpensePool::BASIS_BILLED)
                        ->visible(fn ($get): bool => $get('estimate_basis') === CamExpensePool::BASIS_BILLED)
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
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.cam_estimated_collected'))
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
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.cam_admin_fee_pct'))
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
                        // The `cam_recovery` code's rate, not the bare standard rate: a pool that
                        // recovers an exempt supply should open at 0 rather than make the operator
                        // notice and clear it.
                        ->default(fn () => Vat::rateForType('cam_recovery'))
                        ->required()
                        ->helperText(__('admin.helpers.cam_recovery_vat_rate'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.cam_recovery_vat_rate'))
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
