<?php

namespace App\Filament\Admin\Pages;

use App\Support\ActivityLogChangeRenderer;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.activity-log';

    public static function getNavigationLabel(): string
    {
        return __('admin.activity.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.activity.page_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.reports');
    }

    public static function canAccess(): bool
    {
        return \App\Support\Modules::enabled('activity_log');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Support\Modules::enabled('activity_log');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->with('causer')->latest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label(__('admin.activity.who'))
                    ->placeholder(__('admin.activity.system'))
                    ->weight('medium'),
                TextColumn::make('log_name')
                    ->label(__('admin.activity.what'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.activity.subjects.{$state}") : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        'lease' => 'info',
                        'invoice' => 'warning',
                        'payment' => 'success',
                        'tenant' => 'primary',
                        'charge' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('subject_id')
                    ->label(__('admin.activity.record'))
                    ->formatStateUsing(function (Activity $record): string {
                        if (! $record->subject) {
                            return '—';
                        }
                        return match (class_basename($record->subject_type)) {
                            'Lease' => $record->subject->reference,
                            'Invoice' => $record->subject->number,
                            'Payment' => $record->subject->reference,
                            'Tenant' => $record->subject->name,
                            'Charge' => $record->subject->name,
                            default => '#' . $record->subject_id,
                        };
                    })
                    ->fontFamily('mono')
                    ->size('xs'),
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
                TextColumn::make('changes')
                    ->label(__('admin.activity.changes'))
                    ->state(fn (Activity $record): string => app(ActivityLogChangeRenderer::class)->render($record))
                    ->html()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('admin.activity.subject'))
                    ->options(fn () => __('admin.activity.subjects')),
                SelectFilter::make('event')
                    ->label(__('admin.activity.event'))
                    ->options(fn () => __('admin.activity.events')),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
