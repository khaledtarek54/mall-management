<?php

namespace App\Filament\Admin\Resources\Custodies\Tables;

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Employee;
use App\Support\Filament\EntitySelectFilter;
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

class CustodiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'asset']))
            ->columns([
                TextColumn::make('custody_date')
                    ->label(__('admin.custodies.fields.custody_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('employee.name')
                    ->label(__('admin.custodies.fields.custodian'))
                    ->description(fn (Custody $record) => $record->reference)
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.custodies.fields.property'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label(__('admin.custodies.fields.amount'))
                    ->money('EGP')
                    ->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('settled_sum')
                    ->label(__('admin.custodies.fields.settled'))
                    ->money('EGP')
                    ->default(0)
                    ->color('success')
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.reports.totals'))
                            ->money('EGP')
                            // settled_sum is a withSum alias rather than a real column, so the
                            // default Sum summarizer can't select it. Filament hands `using` a
                            // DERIVED table (the resource query wrapped as `custodies`), which
                            // already carries the alias — so summing it here is exactly the
                            // number the column shows, soft-deleted settlements excluded.
                            ->using(fn (Builder $query): float => (float) $query->sum('settled_sum'))
                    ),
                TextColumn::make('outstanding')
                    ->label(__('admin.custodies.fields.outstanding'))
                    // amount − settled (derived from the withSum alias — no N+1).
                    ->state(fn (Custody $record) => round(max(0, (float) $record->amount - (float) ($record->settled_sum ?? 0)), 2))
                    ->money('EGP')
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray')
                    // The عهدة number that matters: how much cash is still out with staff.
                    // Clamped per row (like the column), not on the total — an over-settled
                    // custody must not net off someone else's outstanding balance. CASE
                    // rather than GREATEST so it runs on MySQL and the sqlite test DB alike.
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.custodies.fields.total_outstanding'))
                            ->money('EGP')
                            ->using(fn (Builder $query): float => round((float) $query->sum(DB::raw(
                                'case when amount - coalesce(settled_sum, 0) > 0 then amount - coalesce(settled_sum, 0) else 0 end'
                            )), 2))
                    ),
            ])
            ->filters([
                // The collections worklist — whose عهدة is still open. Built off the
                // CustodyTransaction query so the SoftDeletes scope applies: a cascaded
                // (soft-deleted) settlement must not count as settled.
                Filter::make('outstanding_only')
                    ->label(__('admin.custodies.filters.outstanding_only'))
                    ->query(fn ($query) => $query->where(
                        'custodies.amount',
                        '>',
                        CustodyTransaction::query()
                            ->selectRaw('coalesce(sum(amount), 0)')
                            ->whereColumn('custody_id', 'custodies.id')
                    )),
                EntitySelectFilter::make('employee_id')
                    ->label(__('admin.custodies.fields.custodian'))
                    ->relationship('employee')
                    ->entity(Employee::class),
                SelectFilter::make('paid_from')
                    ->label(__('admin.custodies.fields.paid_from'))
                    ->options(fn (): array => [
                        'cash' => __('admin.employees.methods.cash'),
                        'bank' => __('admin.employees.methods.bank'),
                    ]),
                Filter::make('custody_date')
                    ->label(__('admin.custodies.fields.custody_date'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                        DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('custody_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('custody_date', '<=', $d))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => CustodyResource::canView($record))
                    ->authorize(fn ($record) => CustodyResource::canView($record)),
                EditAction::make()->visible(fn (Custody $record) => CustodyResource::canEdit($record)),
            ])
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.empty.custodies.heading'))
            ->emptyStateDescription(__('admin.empty.custodies.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.custodies.cta'))
                    ->icon('heroicon-o-plus'),
            ])
            ->defaultSort('custody_date', 'desc');
    }
}
