<?php

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

use App\Models\VendorContract;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.vendor_contracts');
    }

    /**
     * The notice deadline the operator is about to commit to, shown live as they type the period —
     * so "90 days" is never an abstract number they have to work out on a calendar.
     */
    private static function previewNoticeDeadline(Get $get): ?string
    {
        $end = $get('end_date');
        $days = $get('notice_period_days');

        if (blank($end) || blank($days)) {
            return null;
        }

        return Carbon::parse($end)->subDays((int) $days)->format('Y-m-d');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->components([
                TextInput::make('reference')->label(__('admin.fields.reference'))->maxLength(100),
                TextInput::make('name')->label(__('admin.fields.name') ?: 'Name')->required()->maxLength(200),
                Select::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->options(fn () => __('admin.statuses.vendor_contract'))
                    ->required()
                    ->default('draft')
                    ->native(false),
                Select::make('asset_id')
                    ->label(__('admin.resources.asset.singular'))
                    ->options(fn () => TenantScope::selectableAssetOptions())
                    ->searchable()
                    ->placeholder('—')
                    ->default(fn () => TenantScope::currentAssetId())
                    ->disabled(fn () => TenantScope::currentAssetId() !== null)
                    ->dehydrated()
                    // Server-side property-isolation guard: the field is enabled + dehydrated
                    // in All-Properties mode, so re-validate a chosen property against the
                    // user's visible set (null = portfolio-wide contract, allowed).
                    ->rules([
                        fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                            if ($value === null) {
                                return;
                            }
                            $visible = TenantScope::visibleAssetIds();
                            if ($visible !== null && ! in_array((int) $value, $visible, true)) {
                                abort(403);
                            }
                        },
                    ]),
                DatePicker::make('start_date')->label(__('admin.fields.start_date') ?: 'Start')->required()->native(false),
                DatePicker::make('end_date')
                    ->label(__('admin.fields.end_date') ?: 'End')
                    ->native(false)
                    ->afterOrEqual('start_date')
                    ->live(),
                // The end date is not the date anyone decides on — the NOTICE deadline is.
                // Captured here so `vendors:scan-contract-renewals` can chase it.
                TextInput::make('notice_period_days')
                    ->label(__('admin.vendors.renewal.notice_period_days'))
                    ->helperText(fn (Get $get) => ($deadline = self::previewNoticeDeadline($get)) !== null
                        ? __('admin.vendors.renewal.notice_preview', ['date' => $deadline])
                        : __('admin.vendors.renewal.notice_period_hint'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(730)
                    ->suffix(__('admin.vendors.renewal.days'))
                    ->live(onBlur: true),
                Toggle::make('auto_renews')
                    ->label(__('admin.vendors.renewal.auto_renews'))
                    ->helperText(__('admin.vendors.renewal.auto_renews_hint')),
                TextInput::make('value')
                    ->label(__('admin.fields.amount'))
                    ->prefix('EGP')
                    ->numeric()
                    ->minValue(0),
                Select::make('currency')
                    ->label(__('admin.fields.currency'))
                    ->options(['EGP' => 'EGP', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'SAR' => 'SAR', 'AED' => 'AED'])
                    ->default('EGP')
                    ->required()
                    ->native(false),
                // FR-CM-08 — what this vendor owes when a corrective job misses its SLA.
                // The FRD never says on what basis, so it is agreed per contract rather
                // than guessed in code. `none` (the default) = no penalty negotiated.
                Select::make('sla_penalty_basis')
                    ->label(__('admin.facility.penalty.basis'))
                    ->helperText(__('admin.facility.penalty.basis_hint'))
                    ->options(fn () => __('admin.facility.penalty.bases'))
                    ->default('none')
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('sla_penalty_rate')
                    ->label(__('admin.facility.penalty.rate'))
                    ->helperText(__('admin.facility.penalty.rate_hint'))
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    // A percentage is not money; a flat fee and a per-day rate are.
                    ->prefix(fn (Get $get) => $get('sla_penalty_basis') === 'percent_of_value' ? null : 'EGP')
                    ->suffix(fn (Get $get) => $get('sla_penalty_basis') === 'percent_of_value' ? '%' : null)
                    ->visible(fn (Get $get) => $get('sla_penalty_basis') !== 'none')
                    ->required(fn (Get $get) => $get('sla_penalty_basis') !== 'none'),
            ]),
            Section::make(__('admin.sections.notes'))->collapsed()->components([
                Textarea::make('scope')->label(__('admin.fields.description'))->rows(3)->columnSpanFull(),
                Textarea::make('notes')->label(__('admin.fields.notes'))->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Property isolation: a shared vendor's contracts must not leak across malls —
            // show portfolio-wide (null asset_id) contracts to everyone, property-scoped
            // ones only within the user's visible set (null = super_admin/portfolio).
            ->modifyQueryUsing(fn ($query) => $query->when(
                TenantScope::visibleAssetIds(),
                fn ($q, $ids) => $q->where(fn ($w) => $w->whereNull('asset_id')->orWhereIn('asset_id', $ids)),
            ))
            ->columns([
                TextColumn::make('reference')->label(__('admin.fields.reference'))->fontFamily('mono')->size('xs')->placeholder('—'),
                TextColumn::make('name')->label(__('admin.fields.name'))->weight('bold')->searchable(),
                TextColumn::make('asset.name')->label(__('admin.fields.property'))->placeholder(__('admin.fields.portfolio'))->color('gray'),
                TextColumn::make('start_date')->label(__('admin.fields.start_date'))->date('d/m/Y')->sortable(),
                TextColumn::make('end_date')->label(__('admin.fields.end_date'))->date('d/m/Y')->sortable()->placeholder('—'),
                // The date a contract manager actually works to. Sorted/filtered on a real column
                // (VendorContract::saving keeps it in step with end_date + notice_period_days).
                TextColumn::make('notice_deadline')
                    ->label(__('admin.vendors.renewal.notice_deadline'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (VendorContract $record) => match (true) {
                        ! $record->isNoticeDue() => 'gray',
                        (bool) $record->auto_renews => 'danger',
                        default => 'warning',
                    })
                    // Auto-renewing and fixed-term contracts demand opposite actions at this date.
                    ->description(fn (VendorContract $record) => match (true) {
                        ! $record->isNoticeDue() => null,
                        (bool) $record->auto_renews => __('admin.vendors.renewal.serve_notice_or_renews'),
                        default => __('admin.vendors.renewal.will_simply_end'),
                    }),
                TextColumn::make('value')
                    ->label(__('admin.vendors.commitment.committed'))
                    ->money('EGP')
                    ->alignRight()
                    // Signed value vs the commitment as varied — silent divergence is what made
                    // the over-run flag untrustworthy before change orders existed.
                    ->description(fn (VendorContract $record) => $record->effectiveValue() !== (float) $record->value
                        ? __('admin.vendors.commitment.as_amended', ['amount' => number_format($record->effectiveValue(), 2)])
                        : null),
                // Committed vs actual, side by side — the whole point of recording a contract
                // value. Red the moment the vendor has invoiced past what was agreed.
                TextColumn::make('billed_to_date')
                    ->label(__('admin.vendors.commitment.billed'))
                    ->state(fn (VendorContract $record) => $record->billedToDate())
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('remaining_value')
                    ->label(__('admin.vendors.commitment.remaining'))
                    ->state(fn (VendorContract $record) => $record->remainingValue())
                    ->money('EGP')
                    ->alignRight()
                    ->color(fn (VendorContract $record) => $record->isOverCommitted() ? 'danger' : 'success')
                    ->tooltip(fn (VendorContract $record) => $record->isOverCommitted()
                        ? __('admin.vendors.commitment.over_committed')
                        : null),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.vendor_contract.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'expired', 'terminated' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.vendor_contract')),
                // The decision list, sharing VendorContract::noticeDue() with the nightly scan
                // and the dashboard card so all three agree on what is due.
                Filter::make('notice_due')
                    ->label(__('admin.filters.notice_due'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<VendorContract> $query */
                        return $query->noticeDue();
                    })
                    ->toggle(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.actions.add_contract'))
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false),
            ])
            ->recordActions([
                // "View working" for the commitment: an operator should never have to trust a
                // bare Remaining figure they cannot reconcile. Native infolist entries, per the
                // codebase convention (no View pages, read-only detail lives in an action modal).
                Action::make('commitment')
                    ->label(__('admin.vendors.commitment.view_working'))
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->schema(fn (VendorContract $record) => [
                        TextEntry::make('signed')
                            ->label(__('admin.vendors.commitment.committed'))
                            ->state('EGP '.number_format((float) $record->value, 2)),
                        TextEntry::make('amendments')
                            ->label(__('admin.vendors.amendments.title'))
                            ->state(function () use ($record) {
                                $rows = $record->amendments()->orderBy('effective_on')->get();

                                if ($rows->isEmpty()) {
                                    return __('admin.vendors.amendments.none');
                                }

                                return $rows->map(fn ($a) => sprintf(
                                    '%s · %s EGP %s — %s',
                                    $a->effective_on->format('Y-m-d'),
                                    (float) $a->value_delta < 0 ? '−' : '+',
                                    number_format(abs((float) $a->value_delta), 2),
                                    $a->reason,
                                ))->join("\n");
                            }),
                        TextEntry::make('effective')
                            ->label(__('admin.vendors.commitment.effective'))
                            ->state('EGP '.number_format($record->effectiveValue(), 2)),
                        TextEntry::make('billed')
                            ->label(__('admin.vendors.commitment.billed'))
                            ->state('EGP '.number_format($record->billedToDate(), 2)),
                        TextEntry::make('remaining')
                            ->label(__('admin.vendors.commitment.remaining'))
                            ->state('EGP '.number_format($record->remainingValue(), 2))
                            ->color($record->isOverCommitted() ? 'danger' : 'success')
                            ->helperText($record->isOverCommitted()
                                ? __('admin.vendors.commitment.over_committed')
                                : null),
                    ]),
                // A change order. `visible()` is not a gate in Filament (mountAction never checks
                // it), so the permission is asserted in action() too.
                Action::make('amend')
                    ->label(__('admin.vendors.amendments.add'))
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->schema([
                        TextInput::make('reference')
                            ->label(__('admin.vendors.amendments.reference'))
                            ->maxLength(100),
                        TextInput::make('value_delta')
                            ->label(__('admin.vendors.amendments.value_delta'))
                            // Signed on purpose — descoping is a variation too, and forcing it
                            // positive would make a reduction impossible to record honestly.
                            ->helperText(__('admin.vendors.amendments.value_delta_hint'))
                            ->prefix('EGP')
                            ->numeric()
                            ->required(),
                        DatePicker::make('effective_on')
                            ->label(__('admin.vendors.amendments.effective_on'))
                            ->default(now())
                            ->required()
                            ->native(false),
                        Textarea::make('reason')
                            ->label(__('admin.vendors.amendments.reason'))
                            ->helperText(__('admin.vendors.amendments.reason_hint'))
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (VendorContract $record, array $data): void {
                        abort_unless(auth()->user()?->can('vendors.edit') ?? false, 403);

                        $record->amendments()->create([
                            'reference' => $data['reference'] ?? null,
                            'value_delta' => (float) $data['value_delta'],
                            'effective_on' => $data['effective_on'],
                            'reason' => $data['reason'],
                            'created_by_user_id' => auth()->id(),
                        ]);

                        // Feedback carries the RESULTING state, not just "saved".
                        Notification::make()
                            ->success()
                            ->title(__('admin.vendors.amendments.recorded'))
                            ->body(__('admin.vendors.commitment.helper', [
                                'value' => number_format($record->effectiveValue(), 2),
                                'billed' => number_format($record->billedToDate(), 2),
                                'remaining' => number_format($record->remainingValue(), 2),
                            ]))
                            ->send();
                    }),
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('start_date', 'desc');
    }
}
