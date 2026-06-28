<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Tables;

use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantSalesDeclarationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lease.tenant', 'lease.unit']))
            ->columns([
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.tenant_sales.tenant'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.tenant_sales.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.tenant_sales.period'))
                    ->formatStateUsing(fn ($state) => $state->isoFormat('MMM YYYY'))
                    ->sortable(),
                TextColumn::make('declared_sales')
                    ->label(__('admin.tables.tenant_sales.declared_sales'))
                    ->money('EGP', divideBy: 1)
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('calculated_percentage_rent')
                    ->label(__('admin.tables.tenant_sales.percentage_rent'))
                    ->money('EGP', divideBy: 1)
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_sales.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'warning',
                        'locked' => 'success',
                        'disputed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('declared_at')
                    ->label(__('admin.tables.tenant_sales.declared_at'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('locked_at')
                    ->label(__('admin.tables.tenant_sales.locked_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant_sales')),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                Action::make('lock')
                    ->label(__('admin.actions.lock_declaration'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.actions.lock_declaration_confirm'))
                    ->schema([
                        Textarea::make('audit_notes')
                            ->label(__('admin.fields.audit_notes'))
                            ->rows(3),
                    ])
                    ->visible(fn (TenantSalesDeclaration $record) => $record->status === 'submitted' && auth()->user()?->can('tenant_sales.lock'))
                    ->action(function (TenantSalesDeclaration $record, array $data): void {
                        app(PercentageRentCalculationService::class)->lock(
                            $record,
                            auth()->user(),
                            $data['audit_notes'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.declaration_locked'))
                            ->body(__('admin.notifications.declaration_locked_body', [
                                'amount' => number_format((float) $record->fresh()->calculated_percentage_rent, 2),
                            ]))
                            ->send();
                    }),
                Action::make('dispute')
                    ->label(__('admin.actions.dispute_declaration'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('audit_notes')
                            ->label(__('admin.fields.audit_notes'))
                            ->required()
                            ->rows(3),
                    ])
                    ->visible(fn (TenantSalesDeclaration $record) => $record->status === 'submitted' && auth()->user()?->can('tenant_sales.dispute'))
                    ->action(function (TenantSalesDeclaration $record, array $data): void {
                        $record->update([
                            'status' => 'disputed',
                            'audit_notes' => $data['audit_notes'],
                        ]);
                        Notification::make()->warning()->title(__('admin.notifications.declaration_disputed'))->send();
                    }),
                // Void a previously-locked declaration if it turns out to be
                // wrong post-lock. Deactivates the percentage_rent Charge so
                // the next monthly billing run skips it; sets status to
                // disputed; stamps audit_notes with the reason + operator
                // (audit M12 F-48 / D-36).
                Action::make('voidLocked')
                    ->label(__('admin.actions.void_locked_declaration'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.actions.void_locked_modal_heading'))
                    ->modalDescription(__('admin.actions.void_locked_modal_description'))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.fields.void_reason'))
                            ->required()
                            ->rows(3)
                            ->placeholder(__('admin.actions.void_locked_reason_placeholder')),
                    ])
                    ->visible(fn (TenantSalesDeclaration $record) => $record->status === 'locked' && auth()->user()?->can('tenant_sales.lock'))
                    ->action(function (TenantSalesDeclaration $record, array $data): void {
                        app(\App\Services\PercentageRentCalculationService::class)
                            ->voidLocked($record, auth()->user(), $data['reason']);

                        Notification::make()
                            ->warning()
                            ->title(__('admin.notifications.declaration_voided'))
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-presentation-chart-line')
            ->emptyStateHeading(__('admin.empty.tenant_sales.heading'))
            ->emptyStateDescription(__('admin.empty.tenant_sales.description'));
    }
}
