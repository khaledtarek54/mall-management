<?php

namespace App\Filament\Admin\Resources\Leases\Tables;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Exports\LeaseExporter;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseTerminationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['unit', 'tenant']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.lease.unit'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.lease.tenant'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.tables.lease.rent'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('commencement_date')
                    ->label(__('admin.tables.lease.start'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('expiry_date')
                    ->label(__('admin.tables.lease.ends'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(function ($record) {
                        $days = (int) now()->diffInDays($record->expiry_date, false);
                        if ($days < 30) {
                            return 'danger';
                        }
                        if ($days < 90) {
                            return 'warning';
                        }
                        return null;
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_approval' => 'warning',
                        'renewed' => 'info',
                        'terminated', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.lease'))->except('cancelled')->all()),
                SelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->relationship('unit', 'code')
                    ->searchable()
                    ->preload(),
                Filter::make('commencement_range')
                    ->label(__('admin.tables.lease.start'))
                    ->schema([
                        DatePicker::make('commencement_from')
                            ->label(__('admin.filters.commencement_from'))
                            ->native(false),
                        DatePicker::make('commencement_until')
                            ->label(__('admin.filters.commencement_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['commencement_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('commencement_date', '>=', $date))
                        ->when($data['commencement_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('commencement_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['commencement_from'] ?? null) {
                            $indicators[] = __('admin.filters.commencement_from') . ': ' . \Carbon\Carbon::parse($data['commencement_from'])->format('d/m/Y');
                        }
                        if ($data['commencement_until'] ?? null) {
                            $indicators[] = __('admin.filters.commencement_until') . ': ' . \Carbon\Carbon::parse($data['commencement_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
                Filter::make('expiry_range')
                    ->label(__('admin.tables.lease.ends'))
                    ->schema([
                        DatePicker::make('expiry_from')
                            ->label(__('admin.filters.expiry_from'))
                            ->native(false),
                        DatePicker::make('expiry_until')
                            ->label(__('admin.filters.expiry_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['expiry_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('expiry_date', '>=', $date))
                        ->when($data['expiry_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('expiry_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['expiry_from'] ?? null) {
                            $indicators[] = __('admin.filters.expiry_from') . ': ' . \Carbon\Carbon::parse($data['expiry_from'])->format('d/m/Y');
                        }
                        if ($data['expiry_until'] ?? null) {
                            $indicators[] = __('admin.filters.expiry_until') . ': ' . \Carbon\Carbon::parse($data['expiry_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
                Filter::make('expiring_soon')
                    ->label(__('admin.filters.expiring_soon'))
                    ->query(fn (Builder $query) => $query->where('status', 'active')->whereBetween('expiry_date', [now(), now()->addDays(90)])),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(LeaseExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray'),
                Action::make('quickLease')
                    ->label(__('admin.actions.quick_new_lease'))
                    ->icon('heroicon-o-bolt')
                    ->color('primary')
                    ->visible(fn () => LeaseResource::canCreate())
                    ->modalHeading(__('admin.actions.quick_new_lease_modal_heading'))
                    ->modalSubmitActionLabel(__('admin.actions.quick_new_lease_submit'))
                    ->modalWidth('4xl')
                    ->fillForm([
                        'tenant_mode' => 'new',
                        'tenant' => ['type' => 'company'],
                        'lease' => [
                            'commencement_date' => now()->toDateString(),
                            'term_months' => 36,
                            'service_charge_monthly' => 0,
                            'payment_terms_days' => 7,
                            'escalation_rate' => 7,
                        ],
                    ])
                    ->schema([
                        Wizard::make([
                            Step::make(__('admin.resources.tenant.singular'))
                                ->icon('heroicon-o-user')
                                ->columns(2)
                                ->schema([
                                    Select::make('tenant_mode')
                                        ->label(__('admin.fields.tenant_mode'))
                                        ->options([
                                            'new' => __('admin.fields.tenant_mode_new'),
                                            'existing' => __('admin.fields.tenant_mode_existing'),
                                        ])
                                        ->default('new')
                                        ->required()
                                        ->live()
                                        ->columnSpanFull(),
                                    Select::make('tenant_id')
                                        ->label(__('admin.fields.pick_existing_tenant'))
                                        ->options(fn () => \App\Support\TenantScope::selectableTenantOptions())
                                        ->searchable()
                                        ->required()
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'existing')
                                        ->columnSpanFull(),
                                    TextInput::make('tenant.name')
                                        ->label(__('admin.fields.brand_name'))
                                        ->required()
                                        ->maxLength(100)
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.legal_name')
                                        ->label(__('admin.fields.legal_name'))
                                        ->maxLength(150)
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    Select::make('tenant.type')
                                        ->label(__('admin.fields.type'))
                                        ->options([
                                            'individual' => __('admin.fields.individual'),
                                            'company' => __('admin.fields.company'),
                                        ])
                                        ->default('company')
                                        ->required()
                                        ->native(false)
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.email')
                                        ->label(__('admin.fields.email'))
                                        ->email()
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.phone')
                                        ->label(__('admin.fields.phone'))
                                        ->tel()
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.contact_person')
                                        ->label(__('admin.fields.contact_person'))
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                ]),
                            Step::make(__('admin.resources.lease.singular'))
                                ->icon('heroicon-o-document-text')
                                ->columns(2)
                                ->schema([
                                    Toggle::make('lease.show_occupied_units')
                                        ->label(__('admin.fields.show_occupied_units'))
                                        ->helperText(__('admin.helpers.show_occupied_units'))
                                        ->live()
                                        ->dehydrated(false)
                                        ->default(false)
                                        ->columnSpanFull(),
                                    Select::make('lease.unit_id')
                                        ->label(__('admin.fields.unit_label'))
                                        ->options(fn (Get $get) => Unit::with('asset')
                                            ->when(
                                                ! $get('lease.show_occupied_units'),
                                                fn ($q) => $q->where('status', 'vacant'),
                                            )
                                            ->get()
                                            ->mapWithKeys(fn (Unit $u) => [$u->id => sprintf(
                                                '%s · %s · %s',
                                                $u->fullName(),
                                                __("admin.enums.category.{$u->category}"),
                                                __("admin.statuses.unit.{$u->status}"),
                                            )]))
                                        ->searchable()
                                        ->required()
                                        ->columnSpanFull()
                                        ->helperText(fn (Get $get): string => $get('lease.show_occupied_units')
                                            ? __('admin.helpers.unit_showing_all')
                                            : __('admin.helpers.only_available_units')),
                                    DatePicker::make('lease.commencement_date')
                                        ->label(__('admin.fields.commencement_date'))
                                        ->required()
                                        ->native(false),
                                    TextInput::make('lease.term_months')
                                        ->label(__('admin.fields.term_months'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->required()
                                        ->suffix(__('admin.fields.months')),
                                    TextInput::make('lease.base_rent_monthly')
                                        ->label(__('admin.fields.base_rent_monthly'))
                                        ->prefix('EGP')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required(),
                                    TextInput::make('lease.service_charge_monthly')
                                        ->label(__('admin.fields.service_charge_monthly'))
                                        ->prefix('EGP')
                                        ->numeric()
                                        ->minValue(0)
                                        ->helperText(__('admin.fields.service_charge_helper')),
                                    TextInput::make('lease.security_deposit')
                                        ->label(__('admin.fields.security_deposit'))
                                        ->prefix('EGP')
                                        ->numeric()
                                        ->minValue(0)
                                        ->helperText(__('admin.fields.security_deposit_helper')),
                                    TextInput::make('lease.payment_terms_days')
                                        ->label(__('admin.fields.payment_terms_days'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix(__('admin.fields.days')),
                                ]),
                        ])
                            ->skippable(false)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data) {
                        $lease = app(LeaseCreationService::class)->create($data);

                        Notification::make()
                            ->title(__('admin.actions.lease_created'))
                            ->body(__('admin.actions.lease_created_body', [
                                'ref' => $lease->reference,
                                'tenant' => $lease->tenant->name,
                                'unit' => $lease->unit->code,
                                'rent' => number_format((float) $lease->base_rent_monthly, 2),
                            ]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => LeaseResource::canEdit($record)),
                Action::make('renew')
                    ->label(__('admin.actions.renew'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'active' && LeaseResource::canEdit($record))
                    ->modalHeading(fn (Lease $record) => __('admin.actions.renew_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(fn (Lease $record) => __('admin.actions.renew_modal_description', ['ends' => $record->expiry_date->format('d/m/Y')]))
                    ->fillForm(fn (Lease $record) => [
                        'new_term_months' => $record->term_months,
                        'new_rent' => (float) $record->base_rent_monthly,
                        'new_service_charge' => (float) $record->service_charge_monthly,
                        'commencement_date' => $record->expiry_date->copy()->addDay()->toDateString(),
                    ])
                    ->schema([
                        TextInput::make('new_term_months')
                            ->label(__('admin.fields.new_term_months'))
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('new_rent')
                            ->label(__('admin.fields.new_rent'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('new_service_charge')
                            ->label(__('admin.fields.new_service_charge'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        DatePicker::make('commencement_date')
                            ->label(__('admin.fields.commencement_date'))
                            ->required(),
                    ])
                    ->action(function (Lease $record, array $data) {
                        $renewal = app(LeaseRenewalService::class)->renew($record, $data);

                        Notification::make()
                            ->title(__('admin.actions.lease_renewed'))
                            ->body(__('admin.actions.lease_renewed_body', [
                                'ref' => $renewal->reference,
                                'months' => $renewal->term_months,
                                'start' => $renewal->commencement_date->format('d/m/Y'),
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('changeRent')
                    ->label(__('admin.actions.change_rent'))
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                    ->modalHeading(fn (Lease $record) => __('admin.actions.change_rent_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(__('admin.actions.change_rent_modal_description'))
                    ->fillForm(fn (Lease $record) => [
                        'base_rent_monthly' => (float) $record->base_rent_monthly,
                        'service_charge_monthly' => (float) $record->service_charge_monthly,
                    ])
                    ->schema([
                        TextInput::make('base_rent_monthly')
                            ->label(__('admin.fields.base_rent_monthly'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('service_charge_monthly')
                            ->label(__('admin.fields.service_charge_monthly'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->placeholder(__('admin.actions.change_rent_reason_placeholder'))
                            ->rows(2),
                    ])
                    ->action(function (Lease $record, array $data) {
                        $updated = app(\App\Services\LeaseRentChangeService::class)->apply($record, $data);

                        Notification::make()
                            ->title(__('admin.actions.rent_changed'))
                            ->body(__('admin.actions.rent_changed_body', [
                                'ref' => $updated->reference,
                                'rent' => 'EGP ' . number_format((float) $updated->base_rent_monthly, 2),
                                'service' => 'EGP ' . number_format((float) $updated->service_charge_monthly, 2),
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('terminate')
                    ->label(__('admin.actions.terminate'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                    ->modalHeading(fn (Lease $record) => __('admin.actions.terminate_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(fn (Lease $record) => __('admin.actions.terminate_modal_description', ['unit' => $record->unit?->code ?? '—']))
                    ->modalSubmitActionLabel(__('admin.actions.terminate_submit'))
                    ->fillForm([
                        'termination_date' => now()->toDateString(),
                        'cancel_open_invoices' => true,
                    ])
                    ->schema([
                        DatePicker::make('termination_date')
                            ->label(__('admin.actions.termination_date'))
                            ->required()
                            ->native(false),
                        Textarea::make('reason')
                            ->label(__('admin.actions.termination_reason'))
                            ->placeholder(__('admin.actions.termination_reason_placeholder'))
                            ->rows(2),
                        Toggle::make('cancel_open_invoices')
                            ->label(__('admin.actions.cancel_open_invoices'))
                            ->helperText(__('admin.actions.cancel_open_invoices_helper'))
                            ->default(true),
                    ])
                    ->action(function (Lease $record, array $data) {
                        $terminated = app(LeaseTerminationService::class)->terminate($record, $data);

                        Notification::make()
                            ->title(__('admin.actions.lease_terminated'))
                            ->body(__('admin.actions.lease_terminated_body', [
                                'ref' => $terminated->reference,
                                'date' => $terminated->expiry_date->format('d/m/Y'),
                                'unit' => $terminated->unit?->code ?? '—',
                            ]))
                            ->warning()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(LeaseExporter::class)
                        ->label(__('admin.actions.export')),
                    DeleteBulkAction::make()
                        ->visible(fn () => LeaseResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => LeaseResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => LeaseResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('commencement_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.empty.leases.heading'))
            ->emptyStateDescription(__('admin.empty.leases.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.leases.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
