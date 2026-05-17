<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.activity.page_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('causer')->latest('id'))
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
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.activity.events.{$state}") : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('attribute_changes')
                    ->label(__('admin.activity.changes'))
                    ->formatStateUsing(function (Activity $record): string {
                        $changes = $record->attribute_changes;
                        if (! $changes || ! isset($changes['attributes'])) {
                            return '—';
                        }
                        $lines = [];
                        $old = $changes['old'] ?? [];
                        foreach ($changes['attributes'] as $field => $newValue) {
                            $oldValue = $old[$field] ?? '∅';
                            $lines[] = "{$field}: {$oldValue} → {$newValue}";
                        }
                        return implode(' · ', $lines);
                    })
                    ->wrap()
                    ->size('xs')
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('admin.activity.event'))
                    ->options(fn () => __('admin.activity.events')),
                SelectFilter::make('causer_id')
                    ->label(__('admin.filters.causer'))
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $userId) => $q->where('causer_id', $userId)->where('causer_type', User::class))),
                Filter::make('created_range')
                    ->label(__('admin.activity.when'))
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
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
