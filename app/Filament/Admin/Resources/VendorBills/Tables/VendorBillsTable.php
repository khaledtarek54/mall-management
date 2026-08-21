<?php

namespace App\Filament\Admin\Resources\VendorBills\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\PostMonthAction;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Models\ExpenseCategory;
use App\Models\Vendor;
use App\Support\Filament\EntitySelectFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class VendorBillsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.vendor_bill.number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('vendor.name')
                    ->label(__('admin.fields.vendor'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->placeholder(__('admin.fields.property_consolidated'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('category')
                    ->label(__('admin.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => ExpenseCategory::labelFor($state))
                    ->color('gray'),
                TextColumn::make('bill_date')
                    ->label(__('admin.fields.bill_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('admin.fields.total'))
                    ->money('EGP')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('paid_amount')
                    ->label(__('admin.fields.paid_amount'))
                    ->money('EGP')
                    ->color('info')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('balance')
                    ->label(__('admin.fields.balance'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.vendor_bill.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'info',
                        'cancelled' => 'gray',
                        'approved' => 'primary',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.vendor_bill')),
                SelectFilter::make('category')
                    ->label(__('admin.fields.category'))
                    ->options(fn () => ExpenseCategory::filterOptions()),
                EntitySelectFilter::make('vendor_id')
                    ->label(__('admin.fields.vendor'))
                    ->relationship('vendor')
                    ->entity(Vendor::class),
                TrashedFilter::make(),
            ])
            // "What do we owe this vendor across all its bills" — the AP conversation.
            ->groups([
                Group::make('vendor.name')->label(__('admin.fields.vendor'))->collapsible(),
            ])
            ->recordActions([
                // The post month, for the case it exists for: a bill that arrives after its own
                // month has closed (MF-05).
                PostMonthAction::make('vendor_bills.edit'),
                LedgerEntryAction::make(),
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => VendorBillResource::canView($record))
                    ->authorize(fn ($record) => VendorBillResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => VendorBillResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => VendorBillResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('bill_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.empty.vendor_bills.heading'))
            ->emptyStateDescription(__('admin.empty.vendor_bills.description'));
    }
}
