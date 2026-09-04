<?php

namespace App\Filament\Admin\Resources\FixedAssets\Tables;

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Support\CategorySuggestions;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FixedAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fixed_assets.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('tag')
                    ->label(__('admin.fixed_assets.fields.tag'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.fixed_assets.fields.property'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('category')
                    ->label(__('admin.fixed_assets.fields.category'))
                    // Translated for the values we seed, raw for one the operator invented.
                    ->formatStateUsing(fn (?string $state) => CategorySuggestions::label('fixed_asset', $state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('acquisition_date')
                    ->label(__('admin.fixed_assets.fields.acquisition_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('acquisition_cost')
                    ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                    ->money('EGP')
                    ->sortable()
                    // ── ON THE BOOKS, not "every row on screen" ────────────────────────────────
                    // Measured on `mall_management_qa` (2026-09-04): the GL carries Furniture &
                    // Equipment net 2,250,000.00 and Accumulated Depreciation 338,166.64, i.e.
                    // 1,911,833.36 of net fixed assets — the disposal has already taken the sold
                    // floor scrubber off both. This footer read cost 2,325,000.00, accumulated
                    // 362,333.21 and TOTAL NET BOOK VALUE 1,962,666.79, overstating the balance
                    // sheet by the scrubber's 50,833.43 of residual book value.
                    //
                    // All THREE totals narrow, never only the net one: leaving cost and accumulated
                    // wide would break the footer's own arithmetic (cost − accumulated = NBV) and
                    // read as a fault in the subtraction rather than as the tie-out it is. The rows
                    // are untouched — the disposed asset stays listed, which is the audit trail and
                    // the reason the status filter is deliberately not defaulted below.
                    ->summarize(
                        Sum::make('total')
                            ->label(__('admin.fixed_assets.fields.total_on_books'))
                            ->money('EGP')
                            ->query(fn (Builder $query) => $query->whereIn('status', FixedAsset::ON_BOOKS_STATUSES))
                    ),
                TextColumn::make('monthly')
                    ->label(__('admin.fixed_assets.fields.monthly'))
                    // Pure calc (cost − salvage) ÷ life — no query.
                    ->state(fn (FixedAsset $record) => app(DepreciationService::class)->monthlyAmount($record))
                    ->money('EGP')
                    ->toggleable(),
                TextColumn::make('accumulated')
                    ->label(__('admin.fixed_assets.fields.accumulated'))
                    // Derived SUM(entries) from the resource's withSum subquery.
                    ->money('EGP')
                    ->default(0)
                    ->color('warning')
                    // withSum alias, not a real column — sum it off the derived table
                    // Filament hands `using` (same pattern as CustodiesTable).
                    // On the books only — see the cost column above; a disposed asset's
                    // accumulated depreciation was debited back out by its own write-off entry.
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.fixed_assets.fields.total_on_books'))
                            ->money('EGP')
                            ->query(fn (Builder $query) => $query->whereIn('status', FixedAsset::ON_BOOKS_STATUSES))
                            ->using(fn (Builder $query): float => (float) $query->sum('accumulated'))
                    ),
                TextColumn::make('net_book_value')
                    ->label(__('admin.fixed_assets.fields.net_book_value'))
                    ->state(fn (FixedAsset $record) => round((float) $record->acquisition_cost - (float) ($record->accumulated ?? 0), 2))
                    ->money('EGP')
                    ->weight('bold')
                    ->color('success')
                    // Total NBV = the figure that has to agree with the balance sheet's
                    // fixed-asset line, so it belongs under the column, not only in the CSV — and
                    // it can only agree if it counts what is still ON the balance sheet. See the
                    // cost column above for the measurement.
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.fixed_assets.fields.total_on_books'))
                            ->money('EGP')
                            ->query(fn (Builder $query) => $query->whereIn('status', FixedAsset::ON_BOOKS_STATUSES))
                            ->using(fn (Builder $query): float => round((float) $query->sum(
                                DB::raw('acquisition_cost - coalesce(accumulated, 0)')
                            ), 2))
                    ),
                TextColumn::make('status')
                    ->label(__('admin.fixed_assets.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.fixed_assets.statuses.$state"))
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                // Deliberately NOT defaulted to 'active'. Disposed assets stay in the
                // register for the audit trail, and a default that silently hides rows
                // makes "where did that chiller go?" a support ticket — and would have
                // made the terminal-record test below unable to see its own row.
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn (): array => __('admin.fixed_assets.statuses')),
                // Free-text on the form (with a "create" affordance), so offer what's in use.
                SelectFilter::make('category')
                    ->label(__('admin.fixed_assets.fields.category'))
                    ->options(fn (): array => CategorySuggestions::options(
                        'fixed_asset',
                        [],   // only what is actually in use — a filter for zero rows is noise
                        FixedAsset::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
                    )),
                Filter::make('acquisition_date')
                    ->label(__('admin.fixed_assets.fields.acquisition_date'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                        DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('acquisition_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('acquisition_date', '<=', $d))),
                // Fully-depreciated assets still on the books — the write-off worklist.
                //
                // An asset is fully depreciated when accumulated has reached the DEPRECIABLE BASE
                // — cost less salvage — and `accumulated` includes what was already written off
                // before Atriom existed. This compared the GROSS cost against the entries alone, so
                // it missed both: `DepreciationService::run()` clamps the last charge at
                // `cost − salvage`, which means for ANY asset carrying a salvage value the old
                // predicate could never be true. Measured 2026-09-04 — all 6 rows on
                // `mall_management_qa` carry a salvage value, so this worklist was empty by
                // construction, for ever, on the one screen whose job is to find retirable assets.
                //
                // `whereRaw` against `FixedAsset::accumulatedDepreciationSql()`, the same
                // expression the register's own `accumulated` column is built from — a second
                // hand-written sum here is how the two drifted in the first place, and a select
                // ALIAS is not referenceable from a WHERE, which is why this cannot just read
                // `accumulated`. Still the WHERE and never HAVING on that alias: with no GROUP BY,
                // HAVING collapses the result to one group and filters nothing.
                //
                // No `GREATEST` clamp on the base: SQLite has no such function, and an asset
                // salvaged above its cost has nothing left to depreciate, so a negative base
                // answering "fully depreciated" is the right answer anyway.
                Filter::make('fully_depreciated')
                    ->label(__('admin.fixed_assets.filters.fully_depreciated'))
                    ->query(fn ($query) => $query->whereRaw(
                        '(fixed_assets.acquisition_cost - COALESCE(fixed_assets.salvage_value, 0)) <= ('
                        .FixedAsset::accumulatedDepreciationSql().')'
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => FixedAssetResource::canView($record))
                    ->authorize(fn ($record) => FixedAssetResource::canView($record)),

                // Disposed assets are terminal — read-only (no edit).
                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\FixedAssetActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()->visible(fn (FixedAsset $record) => FixedAssetResource::canEdit($record) && $record->status === 'active'),
                // `reverse_acquisition` used to sit here, under the comment above saying the record
                // acts — a factory hides its `->action()` in its own file, so `RowActionPolicy` read
                // this table as carrying no write verb at all. It is in `FixedAssetActions` now,
                // beside `dispose`, on the page an operator is already on when they decide.
            ])
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateHeading(__('admin.empty.fixed_assets.heading'))
            ->emptyStateDescription(__('admin.empty.fixed_assets.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.fixed_assets.cta'))
                    ->icon('heroicon-o-plus'),
            ])
            ->defaultSort('name');
    }
}
