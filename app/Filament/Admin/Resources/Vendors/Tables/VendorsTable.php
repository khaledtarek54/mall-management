<?php

namespace App\Filament\Admin\Resources\Vendors\Tables;

use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorDocumentType;
use App\Support\Filament\CustomFieldsTable;
use App\Support\TenantScope;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The compliance badge reads every document per row — eager load or the
            // vendors list fires a query per vendor.
            ->modifyQueryUsing(fn ($query) => $query->with('documents'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.vendor_code'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label(__('admin.tables.vendor.name'))
                    ->searchable()
                    // The dashboard's COI card links here with `sort=name:asc`; a
                    // non-sortable column makes Filament drop that sort silently.
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label(__('admin.tables.vendor.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.vendor_type.{$state}"))
                    ->sortable()
                    ->color('gray'),
                TextColumn::make('phone')
                    ->label(__('admin.tables.vendor.phone'))
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('active_contracts_count')
                    ->label(__('admin.tables.vendor.contracts'))
                    ->badge()
                    ->sortable()
                    ->color('info'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.vendor.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'blacklisted' => 'danger',
                        default => 'gray',
                    }),
                // A compliance SUMMARY, not one date: a vendor file is several documents expiring on
                // their own clocks, and only a lapsed blocking one (insurance) actually stops work.
                // Worst state wins, and the badge says the consequence rather than making the
                // operator open the record to work it out.
                TextColumn::make('compliance')
                    ->label(__('admin.vendors.compliance.coi_status'))
                    ->badge()
                    ->state(fn (Vendor $record) => match (true) {
                        $record->documents->isEmpty() => __('admin.vendors.compliance.none'),
                        $record->documents->contains(fn (VendorDocument $d) => $d->isBlocking() && $d->hasExpired()) => __('admin.vendors.compliance.blocked'),
                        $record->documents->contains(fn (VendorDocument $d) => $d->hasExpired()) => __('admin.vendors.compliance.expired'),
                        $record->documents->contains(fn (VendorDocument $d) => $d->alertStage() !== null) => __('admin.vendors.compliance.expiring'),
                        default => __('admin.vendors.compliance.ok'),
                    })
                    ->color(fn (Vendor $record) => match (true) {
                        $record->documents->isEmpty() => 'gray',
                        $record->documents->contains(fn (VendorDocument $d) => $d->hasExpired()) => 'danger',
                        $record->documents->contains(fn (VendorDocument $d) => $d->alertStage() !== null) => 'warning',
                        default => 'success',
                    })
                    // Name the offending documents so the operator knows what to chase.
                    ->description(fn (Vendor $record) => $record->documents
                        ->filter(fn (VendorDocument $d) => $d->alertStage() !== null)
                        ->map(fn (VendorDocument $d) => VendorDocumentType::labelFor($d->type))
                        ->join(', ') ?: null),

                // The operator's own fields (D-7). Hidden until asked for, so a list
                // nobody customised is unchanged.
                ...CustomFieldsTable::columns('vendor'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.tables.vendor.type'))
                    ->options(fn () => __('admin.enums.vendor_type')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.vendor')),
                // The chase list: documents lapsed or lapsing inside the alert window. Shares
                // Vendor::documentsNeedAttention() with the nightly scan and the dashboard card,
                // so all three can never disagree about who needs chasing.
                Filter::make('document_attention')
                    ->label(__('admin.filters.document_attention'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<Vendor> $query */
                        return $query->documentsNeedAttention();
                    })
                    ->toggle(),
                // Vendors with a contract at or past its NOTICE deadline — the date a
                // renew-or-exit decision is actually due. The dashboard card counted these and
                // then linked to the bare vendor list, leaving the operator to find them by
                // opening records one at a time. Reuses VendorContract::scopeNoticeDue() so the
                // card's count and this list are the same question asked once.
                Filter::make('contract_notice_due')
                    ->label(__('admin.filters.contract_notice_due'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'contracts',
                        fn ($q) => $q->noticeDue()->when(
                            TenantScope::visibleAssetIds(),
                            fn ($c, $ids) => $c->whereIn('asset_id', $ids),
                        ),
                    ))
                    ->toggle(),
                TrashedFilter::make(),

                ...CustomFieldsTable::filters('vendor'),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => VendorResource::canView($record))
                    ->authorize(fn ($record) => VendorResource::canView($record)),
                EditAction::make()->visible(fn ($record) => VendorResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => VendorResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateHeading(__('admin.empty.vendors.heading'))
            ->emptyStateDescription(__('admin.empty.vendors.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.vendors.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
