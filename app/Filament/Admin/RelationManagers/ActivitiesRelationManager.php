<?php

namespace App\Filament\Admin\RelationManagers;

use App\Support\ActivityLogChangeRenderer;
use App\Support\ActivityVocabulary;
use App\Support\Filament\CauserFilter;
use App\Support\Filament\DateRangeFilter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.activity.page_title');
    }

    /** Same batching seam as the standalone page — see ActivityLog::getTableRecords(). */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        app(ActivityVocabulary::class)->preloadReferences($records);

        return $records;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('causer')->latest('id'))
            // An audit trail is read by WHEN and by WHO, both of them filters below. Every column
            // here is derived at read time — the event word, the rendered change set — so there is
            // no stored text a `LIKE` could reach, and a search box would return nothing for every
            // query an operator tried.
            ->searchable(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label(__('admin.activity.who'))
                    ->placeholder(__('admin.activity.system'))
                    ->weight('medium'),
                TextColumn::make('event')
                    ->label(__('admin.activity.event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => app(ActivityVocabulary::class)->event($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted', 'voided' => 'danger',
                        'reversed' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('changes')
                    ->label(__('admin.activity.changes'))
                    ->state(fn (Activity $record): string => app(ActivityLogChangeRenderer::class)->render($record))
                    ->html()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('admin.activity.event'))
                    ->options(fn () => __('admin.activity.events')),
                // The audit trail's most-asked question, defined once — see CauserFilter for why
                // the morph type clause has to travel with it.
                CauserFilter::make(),
                DateRangeFilter::make('created_at', __('admin.activity.when'), name: 'created_range'),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
