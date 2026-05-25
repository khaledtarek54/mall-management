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
                    ->action(function (Tenant $record) {
                        $svc = app(TenantStatementPdfService::class);
                        $pdf = $svc->build($record);
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
