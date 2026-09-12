<?php

namespace App\Filament\Admin\Resources\FixedAssets\Schemas;

use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\PaymentMethod;
use App\Models\Vendor;
use App\Services\DepreciationService;
use App\Support\Filament\BankAccountField;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\Modules;
use App\Support\TaxDepreciation;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class FixedAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            PropertyField::make()
                ->label(__('admin.fixed_assets.fields.property')),
            // ── THE CLASS FIRST (meeting 2026-09-02, point 11) ────────────────────────────────
            // A catalogue row now (`FixedAssetCategory`, the seventh `IsCodeCatalogue`), not free
            // text with suggestions: it is what numbers the asset and what proposes its useful
            // life, its memo value and its tax pool — SAP's asset class, and why it comes before
            // the name. Picking one PREFILLS the three below only where they are still blank; a
            // figure the operator typed is never overwritten, and nothing here is read again once
            // the asset is saved (what is on the asset is what depreciates).
            Select::make('category')
                ->label(__('admin.fixed_assets.fields.category'))
                ->options(fn (): array => FixedAssetCategory::options())
                // Required on CREATE — a new asset takes its number from it. A row registered
                // before the catalogue existed may carry none, and a housekeeping edit (a name, a
                // note) must not turn into a classification decision, so on EDIT it is required
                // only where the row already has one; the model's column stays nullable for the
                // same reason.
                ->required(fn (string $operation, ?FixedAsset $record): bool => $operation === 'create' || filled($record?->category))
                ->native(false)
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    $defaults = FixedAssetCategory::defaultsFor($state);

                    if ($defaults === null) {
                        return;
                    }

                    if (blank($get('useful_life_months')) && $defaults['useful_life_months'] !== null) {
                        $set('useful_life_months', $defaults['useful_life_months']);
                        $set('annual_rate_pct', FixedAsset::annualRateFor($defaults['useful_life_months']));
                    }

                    if (blank($get('salvage_value')) && $defaults['salvage_value'] !== null) {
                        $set('salvage_value', $defaults['salvage_value']);
                    }

                    if (blank($get('tax_pool')) && $defaults['tax_pool'] !== null) {
                        $set('tax_pool', $defaults['tax_pool']);
                    }
                })
                // Names the tax pool only while there is a tax pool below to fill in.
                ->helperText(fn (): string => Modules::enabled('tax_depreciation')
                    ? __('admin.fixed_assets.helpers.category')
                    : __('admin.fixed_assets.helpers.category_no_tax_pool')),
            TextInput::make('name')
                ->label(__('admin.fixed_assets.fields.name'))
                ->required()
                ->maxLength(255),
            // Optional since 2026-09-12: left blank, the model allocates the next number in the
            // class's series for this property (`FixedAsset::generateTag()`); typed, it is kept —
            // a migrating register's own numbers win, the counterparty-code rule.
            TextInput::make('tag')
                ->label(__('admin.fixed_assets.fields.tag'))
                // Required once the asset EXISTS: the column is NOT NULL and nothing re-allocates
                // on update, so a cleared tag would reach the database as a raw constraint error.
                ->required(fn (string $operation): bool => $operation === 'edit')
                ->maxLength(40)
                ->placeholder(fn (Get $get): string => ($prefix = FixedAssetCategory::defaultsFor($get('category'))['tag_prefix'] ?? null)
                    ? __('admin.fixed_assets.helpers.tag_allocated_as', ['example' => strtoupper($prefix).'-0001'])
                    : __('admin.fixed_assets.helpers.tag_typed'))
                ->helperText(__('admin.fixed_assets.helpers.tag'))
                // Unique per property (matches the DB composite unique index).
                // Clamped: `asset_id` is client-supplied, and a unique rule keyed on the
                // raw value leaks whether a tag exists in a property the user cannot see
                // (TenantScope::clampAssetId).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', TenantScope::clampAssetId($get('asset_id')))),
            // The Egyptian tax pool, stated rather than inferred — proposed by the class, and
            // separate from `method`, which is the ACCOUNTING basis: an asset routinely has a
            // different rate under each.
            // No form default, deliberately: with `general` filled in from mount the class's own
            // pool never reached the field (`blank()` was never true). The model floors a blank
            // to the statutory default on create, so the row is never left unstated.
            // Offered only while the tax schedule is switched on (`tax_depreciation`, meeting
            // 2026-09-02 point 12): a pool nothing on screen reads is a question the operator
            // cannot answer. Hidden is NOT dehydrated, so an existing asset keeps the pool it has,
            // and a new one takes its CLASS's proposal from the model or stays NULL — unstated,
            // shown back as blank when the switch returns — rather than a default the system
            // invented while nobody could confirm it (`FixedAsset::saving`).
            Select::make('tax_pool')
                ->label(__('admin.fixed_assets.fields.tax_pool'))
                ->options(fn () => collect(TaxDepreciation::pools())
                    ->mapWithKeys(fn (string $p) => [$p => __("admin.tax_depreciation.pools.{$p}")])->all())
                ->native(false)
                ->visible(fn (): bool => Modules::enabled('tax_depreciation'))
                ->helperText(__('admin.fixed_assets.helpers.tax_pool')),
            DatePicker::make('acquisition_date')
                ->label(__('admin.fixed_assets.fields.acquisition_date'))
                ->default(now())
                ->required()
                ->native(false)
                // This IS the acquisition entry's `entry_date`, so moving it moves which period
                // recognised the asset. `ChangeImpact` classifies it DERIVED — legitimate to change,
                // and the posted entry is voided and re-posted to match — but DERIVED's own
                // definition ends *"the operator must be told"*, and this was the one money form in
                // the panel that said nothing at all: `AnnouncesLedgerRestatement` reports the
                // restatement AFTER the save, which is the wrong end of the decision. Not disabled,
                // because the model deliberately permits it (a re-cost is a supported operation, see
                // `DepreciationService::assertRecostValid`) and a form stricter than its model is
                // the divergence `DepositTransactionForm` had in the other direction. Locked once
                // TRANSFERRED (point 18): the legs froze it, and the model refuses the write —
                // a guarded field must LOOK guarded (SW-238).
                ->disabled(fn (?FixedAsset $record): bool => $record?->historyLockedByTransfer() ?? false)
                ->helperText(fn (?FixedAsset $record): string => ($record?->historyLockedByTransfer() ?? false)
                    ? __('admin.fixed_assets.transferred_field_hint')
                    : __('admin.fixed_assets.posted_field_hint')),
            TextInput::make('acquisition_cost')
                ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->prefix('EGP')
                ->disabled(fn (?FixedAsset $record): bool => $record?->historyLockedByTransfer() ?? false)
                // On EDIT, the base (cost − salvage) can't drop below what has already been
                // depreciated — else NBV goes negative and depreciation stops forever (F-86).
                // Inline so the operator sees it before submit; EditFixedAsset re-checks server
                // side. `$get` reads the sibling salvage field so the pair is judged together.
                ->rule(fn (Get $get, ?FixedAsset $record) => function (string $attr, $value, \Closure $fail) use ($get, $record) {
                    if ($record === null) {
                        return; // create: nothing depreciated yet
                    }

                    try {
                        app(DepreciationService::class)->assertRecostValid(
                            $record,
                            (float) $value,
                            (float) ($get('salvage_value') ?? 0),
                        );
                    } catch (\DomainException $e) {
                        $fail($e->getMessage());
                    }
                }),
            // The MEMO value (point 13). No form default any more: the class proposes it on pick
            // (1.00 on every shipped class — SAP's rule, so a fully-depreciated asset stays on the
            // register at one pound rather than at nil), and the model proposes the same for a
            // door with no form. What is typed here is what depreciates.
            TextInput::make('salvage_value')
                ->label(__('admin.fixed_assets.fields.salvage_value'))
                ->numeric()
                ->minValue(0)
                ->prefix('EGP')
                ->helperText(__('admin.fixed_assets.helpers.salvage_value'))
                // Salvage can't exceed cost, else the depreciable base is negative.
                ->lte('acquisition_cost'),
            // ── ONE LIFE, READ TWO WAYS (point 14) ───────────────────────────────────────────
            // Months are the stored truth (`DepreciationService` divides by them); the rate is the
            // accountant's reading of the same number (Law 91 states its rates as percentages),
            // kept in step both ways: type 60 and read 20%, type 25% and read 48 months.
            TextInput::make('useful_life_months')
                ->label(__('admin.fixed_assets.fields.useful_life'))
                ->numeric()
                ->minValue(1)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, $state) => $set('annual_rate_pct', FixedAsset::annualRateFor((int) $state))),
            // No ceiling: a life shorter than a year is a rate above 100% (six months is 200%), and
            // Filament validates a non-dehydrated field by default — a `maxValue(100)` here refused
            // every short-lived asset on create AND locked its Edit page (the review caught it).
            TextInput::make('annual_rate_pct')
                ->label(__('admin.fixed_assets.fields.annual_rate_pct'))
                ->numeric()
                ->minValue(0.01)
                ->step('0.01')
                ->suffix('%')
                ->dehydrated(false)
                ->live(onBlur: true)
                ->afterStateHydrated(fn (Set $set, ?FixedAsset $record) => $set('annual_rate_pct', $record?->annualRatePct()))
                ->afterStateUpdated(function (Set $set, $state): void {
                    $months = filled($state) ? FixedAsset::monthsForAnnualRate((float) $state) : null;

                    // A non-positive rate answers no life; the field's own `minValue` refuses it
                    // on submit, and the months box is left as it was rather than blanked.
                    if ($months !== null || blank($state)) {
                        $set('useful_life_months', $months);
                    }
                })
                ->helperText(__('admin.fixed_assets.helpers.annual_rate_pct')),
            // ── HOW IT WAS PAID FOR, AND THROUGH WHICH BANK (meeting 2026-09-02, point 15) ──────
            // The outbound RAIL catalogue (`payment_methods`), the floor still `cash|bank` so a row
            // written before the catalogue reads as itself — the same picker the expense form
            // carries. Decides which account the acquisition's CREDIT leg hits, so switching it on
            // a posted asset moves real money between accounts in the books (the hint below).
            Select::make('funded_from')
                ->label(__('admin.fixed_assets.fields.funded_from'))
                ->options(fn (): array => PaymentMethod::optionsFor('fixed_assets.funded_from', 'admin.enums.cash_or_bank'))
                // The default comes from the same list as the options (SW-116).
                ->default(fn () => PaymentMethod::defaultFor('fixed_assets.funded_from', 'cash'))
                ->required()
                ->native(false)
                // `->live()` so the bank-account field beside it picks up its requirement as soon
                // as the rail changes; the refusal itself is evaluated at validation either way.
                ->live()
                ->helperText(__('admin.fixed_assets.posted_field_hint')),
            // Which bank account the purchase money left. `for()` takes the document class because
            // the document declares BOTH the purpose its money belongs to and the column naming its
            // rail (`funded_from` here), so the picker defaults to the same account
            // `RecordsBankAccount` would fill in and requires one on exactly the rails the catalogue
            // says carry bank money. Without it a bank-funded asset credited the generic `bank`
            // ROLE — the unattributed state a reconciliation cannot match. It keeps the field's
            // own helper (whether this rail needs one); the re-post consequence is stated on the
            // rail beside it and announced in figures on save.
            BankAccountField::for(FixedAsset::class),
            // WHO sold it — an existing supplier, or a NEW NAME through the picker's own create
            // door, which registers a real `vendors` row (the counterparty the next slice's
            // supplier bill needs) rather than storing a typed string nothing else can join on.
            // Optional: an asset built in-house or bought before the register has no supplier.
            EntitySelect::make('vendor_id')
                ->label(__('admin.fixed_assets.fields.vendor'))
                ->entity(Vendor::class)
                // The relationship is what makes `createOptionForm()` work — see `LeaseForm`'s
                // tenant picker for the 500 a select with neither relationship nor
                // `createOptionUsing()` throws the moment the button is pressed.
                ->relationship('vendor', 'name')
                ->createOptionForm([
                    TextInput::make('name')->label(__('admin.tables.vendor.name'))->required()->maxLength(200),
                    Select::make('type')
                        ->label(__('admin.tables.vendor.type'))
                        ->options(fn () => __('admin.enums.vendor_type'))
                        ->required()
                        ->default('supplier')
                        ->native(false),
                    TextInput::make('phone')->label(__('admin.fields.phone'))->tel()->maxLength(50),
                    TextInput::make('email')->label(__('admin.fields.email'))->email()->maxLength(255),
                ])
                // The "+" is a door onto the VENDOR register and carries that register's own right — the create-option action has no gate of its own (measured: `accounting` holds `fixed_assets.create` and not `vendors.create`, and minted a supplier through it). `authorize()` is the refusal at dispatch through the `AuthorizedAction` binding; `visible()` is the button.
                ->createOptionAction(fn (Action $action): Action => $action
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('vendors.create'))
                    ->visible(fn (): bool => (bool) auth()->user()?->can('vendors.create')))
                ->helperText(__('admin.fixed_assets.helpers.vendor')),
            Textarea::make('notes')
                ->label(__('admin.fixed_assets.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
