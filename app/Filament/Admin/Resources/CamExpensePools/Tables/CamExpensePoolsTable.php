<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Tables;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Models\CamExpensePool;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CamExpensePoolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('asset'))
            ->columns([
                TextColumn::make('asset.name')
                    ->label(__('admin.resources.asset.singular'))
                    ->searchable()
                    ->weight('medium'),
                // Which pool this row is (RC-02) — a property runs several in a year, so without
                // this the list reads as duplicates.
                TextColumn::make('pool_code')
                    ->label(__('admin.fields.pool_code'))
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state, CamExpensePool $record): string => $record->label())
                    ->description(fn (CamExpensePool $record): ?string => $record->participant_scope === CamExpensePool::PARTICIPANTS_AREA
                        ? $record->participantArea?->name
                        : null)
                    ->searchable(),
                TextColumn::make('period_year')
                    ->label(__('admin.fields.period_year'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('total_actual_expense')
                    ->label(__('admin.tables.cam.actual'))
                    ->money('EGP', divideBy: 1)
                    ->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                // The warning rides the FIGURES, not a corner of the row. A derived total that was
                // never sourced is not missing — it is present, wrong, and subtracted anyway: a pool
                // on `estimate_basis = billed` read 0 collected / 500,000 variance while its tenants
                // had been invoiced 346,000, and the true variance was 154,000. Nothing said so.
                TextColumn::make('total_estimated_collected')
                    ->label(__('admin.tables.cam.estimated'))
                    ->money('EGP', divideBy: 1)
                    ->icon(fn (CamExpensePool $record) => $record->needsSourcing() ? Heroicon::OutlinedExclamationTriangle : null)
                    ->color(fn (CamExpensePool $record) => $record->needsSourcing() ? 'danger' : null)
                    ->tooltip(fn (CamExpensePool $record) => $record->needsSourcing() ? __('admin.cam.never_sourced') : null)
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('variance')
                    ->label(__('admin.tables.cam.variance'))
                    ->getStateUsing(fn (CamExpensePool $record) => $record->variance())
                    ->money('EGP', divideBy: 1)
                    ->tooltip(fn (CamExpensePool $record) => $record->needsSourcing() ? __('admin.cam.never_sourced') : null)
                    ->color(fn ($state, CamExpensePool $record) => $record->needsSourcing()
                        ? 'danger'
                        : ($state > 0 ? 'warning' : ($state < 0 ? 'success' : 'gray'))),
                TextColumn::make('expense_synced_at')
                    ->label(__('admin.tables.cam.sourced_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.cam.never'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_pool.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'reconciling' => 'warning',
                        'reconciled' => 'success',
                        'closed' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('allocations_count')
                    ->label(__('admin.tables.cam.allocations'))
                    ->counts('allocations')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('reconciled_at')
                    ->label(__('admin.tables.cam.reconciled_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.cam_pool')),
            ])
            ->defaultSort('period_year', 'desc')
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => CamExpensePoolResource::canView($record))
                    ->authorize(fn ($record) => CamExpensePoolResource::canView($record)),

                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\CamExpensePoolActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateHeading(__('admin.empty.cam.heading'))
            ->emptyStateDescription(__('admin.empty.cam.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.cam.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
