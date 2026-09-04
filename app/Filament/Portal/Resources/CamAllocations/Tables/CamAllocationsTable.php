<?php

namespace App\Filament\Portal\Resources\CamAllocations\Tables;

use App\Models\CamAllocation;
use App\Services\CamStatementPdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CamAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // No search box: CamAllocation carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('pool.period_year')
                    ->label(__('admin.fields.period_year'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('pool.asset.name')
                    ->label(__('admin.fields.asset'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pro_rata_share_pct')
                    ->label(__('admin.fields.your_share'))
                    ->suffix(' %')
                    ->numeric(2)
                    ->alignRight(),
                TextColumn::make('allocated_amount')
                    ->label(__('admin.fields.allocated_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('estimated_paid')
                    ->label(__('admin.fields.estimated_paid'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('true_up_amount')
                    ->label(__('admin.fields.true_up_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : ((float) $state < 0 ? 'success' : 'gray'))
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_allocation.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'billed' => 'success',
                        'pending' => 'warning',
                        'disputed' => 'danger',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.cam_allocation')),
            ])
            ->recordActions([
                ViewAction::make(),
                // The tenant's own copy (RC-06). The portal is where a service-charge audit right
                // is actually exercised — a statement only the operator can print is a statement
                // the tenant has to ask for.
                //
                // No authz check here beyond the resource's own tenant scope: this table already
                // shows only the signed-in tenant's allocations, and the statement contains nothing
                // the row itself does not.
                PdfDownloadAction::make('statement')
                    ->label(__('admin.cam_statement.download'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->service(CamStatementPdfService::class)
                    // `unitOwnership->tenant` is not a relation — the ownership calls it `owner` —
                    // so this answered null for every unit owner and the download modal lost his
                    // language tier. One seam: `CamAllocation::counterparty()`.
                    ->recipient(fn (CamAllocation $record) => $record->counterparty()),
            ])
            // The most recent service-charge year first. `cam_expense_pool_id` sorted by the
            // order the pools were created in, which is close to year order until the operator
            // back-fills an old one — and this is the tenant's own screen, where "which year am
            // I looking at" is the first question.
            ->defaultSort('pool.period_year', 'desc')
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateHeading(__('admin.empty.portal_cam_allocations.heading'))
            ->emptyStateDescription(__('admin.empty.portal_cam_allocations.description'));
    }
}
