<?php

namespace App\Filament\Admin\Resources\FixedAssets\Tables;

use App\Filament\Actions\ReverseDocumentAction;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Support\CategorySuggestions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
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
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.reports.totals'))
                            ->money('EGP')
                            ->using(fn (Builder $query): float => (float) $query->sum('accumulated'))
                    ),
                TextColumn::make('net_book_value')
                    ->label(__('admin.fixed_assets.fields.net_book_value'))
                    ->state(fn (FixedAsset $record) => round((float) $record->acquisition_cost - (float) ($record->accumulated ?? 0), 2))
                    ->money('EGP')
                    ->weight('bold')
                    ->color('success')
                    // Total NBV = the figure that has to agree with the balance sheet's
                    // fixed-asset line, so it belongs under the column, not only in the CSV.
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.fixed_assets.fields.total_net_book_value'))
                            ->money('EGP')
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
                // Correlated subquery, not HAVING on the `accumulated` alias: without a
                // GROUP BY, HAVING collapses the result to one group and filters nothing.
                Filter::make('fully_depreciated')
                    ->label(__('admin.fixed_assets.filters.fully_depreciated'))
                    ->query(fn ($query) => $query->where(
                        'fixed_assets.acquisition_cost',
                        '<=',
                        DepreciationEntry::query()
                            ->selectRaw('coalesce(sum(amount), 0)')
                            ->whereColumn('fixed_asset_id', 'fixed_assets.id')
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
                Action::make('dispose')
                    ->label(__('admin.fixed_assets.actions.dispose'))
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    // Only active assets, and only if the user may edit.
                    ->visible(fn (FixedAsset $record) => $record->status === 'active' && FixedAssetResource::canEdit($record))
                    ->authorize(fn (FixedAsset $record) => FixedAssetResource::canEdit($record))
                    ->schema([
                        DatePicker::make('disposed_on')
                            ->label(__('admin.fixed_assets.fields.disposed_on'))
                            ->default(now())
                            ->required()
                            ->native(false),
                        TextInput::make('proceeds')
                            ->label(__('admin.fixed_assets.fields.proceeds'))
                            ->helperText(__('admin.fixed_assets.proceeds_hint'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('EGP'),
                        Select::make('proceeds_account')
                            ->label(__('admin.fixed_assets.fields.proceeds_account'))
                            ->options(fn () => __('admin.enums.cash_or_bank'))
                            ->default('cash')
                            ->native(false)
                            // Only matters when money actually came in.
                            ->visible(fn (Get $get) => (float) $get('proceeds') > 0),
                        Textarea::make('notes')
                            ->label(__('admin.fixed_assets.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, FixedAsset $record): void {
                        // Server-side re-check (authorize can't see form tampering of a terminal record).
                        abort_unless(FixedAssetResource::canEdit($record) && $record->status === 'active', 403);
                        app(DisposeFixedAssetService::class)->dispose($record, $data);
                        Notification::make()->title(__('admin.fixed_assets.disposed'))->success()->send();
                    }),
                // Disposed assets are terminal — read-only (no edit).
                EditAction::make()->visible(fn (FixedAsset $record) => FixedAssetResource::canEdit($record) && $record->status === 'active'),
                // **Recorded in error**, which is a different act from DISPOSAL directly above.
                // Disposing books proceeds and a gain or loss because the company sold something;
                // reversing says the acquisition should never have been on the books at all, and
                // the sweep voids the asset's whole GL footprint. Offered only while the asset is
                // still ACTIVE — once disposed, the disposal is the document that speaks for it and
                // reversing underneath it would strand the disposal entry.
                ReverseDocumentAction::make(
                    can: fn (FixedAsset $record) => FixedAssetResource::canEdit($record),
                    label: 'admin.actions.reverse_acquisition',
                    confirm: 'admin.actions.reverse_acquisition_confirm',
                    done: 'admin.notifications.acquisition_reversed',
                    when: fn (FixedAsset $record) => $record->status === 'active',
                ),
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
