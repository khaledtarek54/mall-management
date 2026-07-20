<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Exports\TenantExporter;
use App\Models\Tenant;
use App\Services\TenantStatementPdfService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.tenant.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('active_leases_count')
                    ->label(__('admin.widgets.account_balance.active_leases'))
                    ->counts('activeLeases')
                    ->badge()
                    ->color('success'),
                TextColumn::make('phone')
                    ->label(__('admin.tables.tenant.phone'))
                    ->searchable()
                    ->icon('heroicon-m-phone'),
                TextColumn::make('whatsapp')
                    ->label(__('admin.fields.whatsapp'))
                    ->icon('heroicon-m-chat-bubble-left-right')
                    ->color('success')
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('admin.tables.tenant.email'))
                    ->searchable()
                    ->icon('heroicon-m-envelope')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('contact_person')
                    ->label(__('admin.fields.contact_person'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'blacklisted' => 'danger',
                        default => 'gray',
                    }),
                // Delinquency = at least one invoice with balance > 0 past
                // its due_date (Tenant::isDelinquent). Surfaces the tested
                // model method in the table so operators can spot defaulters
                // at a glance (audit M02 F-11 / D-9).
                TextColumn::make('is_delinquent')
                    ->label(__('admin.tables.tenant.delinquent'))
                    ->badge()
                    // Scope to visible properties — a shared tenant's mall-B overdue must not colour
                    // the badge for a mall-A-only operator (cross-property AR leak).
                    ->state(fn (Tenant $record): string => $record->isDelinquent(\App\Support\TenantScope::visibleAssetIds()) ? 'delinquent' : 'current')
                    ->color(fn (string $state): string => $state === 'delinquent' ? 'danger' : 'success')
                    ->icon(fn (string $state): string => $state === 'delinquent' ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                    ->formatStateUsing(fn (string $state) => __("admin.tables.tenant.delinquency_state.{$state}"))
                    ->toggleable(),
                // Credit on account = money paid but not yet applied to an invoice (an overpayment /
                // on-account remainder booked to Unearned Revenue). Property-scoped like delinquency.
                TextColumn::make('credit_on_account')
                    ->label(__('admin.tables.tenant.credit_on_account'))
                    ->badge()
                    ->state(fn (Tenant $record): float => $record->creditBalance(\App\Support\TenantScope::visibleAssetIds()))
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? 'EGP '.number_format((float) $state, 2) : '—')
                    ->color(fn ($state): string => (float) $state > 0 ? 'success' : 'gray')
                    ->icon(fn ($state): ?string => (float) $state > 0 ? 'heroicon-m-gift' : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant')),
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(fn () => [
                        'individual' => __('admin.fields.individual'),
                        'company' => __('admin.fields.company'),
                    ]),
                TernaryFilter::make('has_active_lease')
                    ->label(__('admin.widgets.account_balance.active_leases'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('activeLeases'),
                        false: fn (Builder $query) => $query->whereDoesntHave('activeLeases'),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('is_delinquent')
                    ->label(__('admin.tables.tenant.delinquent'))
                    ->queries(
                        // Scoped to visible properties — same reason as the badge above.
                        true: fn (Builder $query) => $query->whereHas('invoices', fn (Builder $q) => $q
                            ->where('balance', '>', 0)
                            ->where('due_date', '<', now())
                            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                            ->when(\App\Support\TenantScope::visibleAssetIds(), fn (Builder $i, $ids) => $i->whereHas('lease.unit', fn (Builder $u) => $u->whereIn('asset_id', $ids)))),
                        false: fn (Builder $query) => $query->whereDoesntHave('invoices', fn (Builder $q) => $q
                            ->where('balance', '>', 0)
                            ->where('due_date', '<', now())
                            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                            ->when(\App\Support\TenantScope::visibleAssetIds(), fn (Builder $i, $ids) => $i->whereHas('lease.unit', fn (Builder $u) => $u->whereIn('asset_id', $ids)))),
                        blank: fn (Builder $query) => $query,
                    ),
                Filter::make('created_range')
                    ->label(__('admin.users.created'))
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('admin.filters.created_from'))
                            ->native(false),
                        DatePicker::make('created_until')
                            ->label(__('admin.filters.created_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = __('admin.filters.created_from') . ': ' . \Carbon\Carbon::parse($data['created_from'])->format('d/m/Y');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = __('admin.filters.created_until') . ': ' . \Carbon\Carbon::parse($data['created_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(TenantExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray'),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => TenantResource::canEdit($record)),
                Action::make('statement')
                    ->label(__('admin.statement.action_label'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    // Statement is tenant financial data — gate server-side (was ungated).
                    ->authorize(fn () => auth()->user()?->can('tenants.view') ?? false)
                    ->action(function (Tenant $record) {
                        $svc = app(TenantStatementPdfService::class);
                        // Admin surface: scope to visible properties so a restricted operator's
                        // statement of a shared tenant excludes malls they can't see.
                        $pdf = $svc->build($record, \App\Support\TenantScope::visibleAssetIds());
                        return response()->streamDownload(
                            fn () => print($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(TenantExporter::class)
                        ->label(__('admin.actions.export')),
                    DeleteBulkAction::make()
                        ->visible(fn () => TenantResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => TenantResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => TenantResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading(__('admin.empty.tenants.heading'))
            ->emptyStateDescription(__('admin.empty.tenants.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.tenants.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
