<?php

namespace App\Filament\Admin\Pages;

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
                    ->state(fn (Activity $record): string => $this->renderChanges($record))
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

    /**
     * Format an activity row's `attribute_changes` payload as a readable
     * HTML fragment — one line per field, with the old value struck through
     * and the new value highlighted. Used by the table column above; kept
     * as a method so HTML escaping stays in one place.
     */
    protected function renderChanges(Activity $record): string
    {
        $changes = $record->attribute_changes;
        if (! $changes || ! isset($changes['attributes'])) {
            return '<span class="fi-color-gray">—</span>';
        }

        $lines = [];
        $old = $changes['old'] ?? [];
        $emptyMarker = '<em class="opacity-60">' . __('admin.activity.empty_value') . '</em>';

        foreach ($changes['attributes'] as $field => $newValue) {
            $hadOld = array_key_exists($field, $old) && $old[$field] !== null && $old[$field] !== '';
            $isCreated = ! $hadOld;

            $fieldLabel = '<strong class="text-gray-900 dark:text-gray-100">'
                . e($this->humaniseField($field)) . '</strong>';

            $newDisplay = ($newValue === null || $newValue === '')
                ? $emptyMarker
                : '<span class="text-success-600 dark:text-success-400">'
                  . e($this->formatValue($newValue)) . '</span>';

            if ($isCreated) {
                $lines[] = $fieldLabel . ' ' . $newDisplay;
            } else {
                $oldDisplay = '<span class="line-through opacity-60">'
                    . e($this->formatValue($old[$field])) . '</span>';
                $lines[] = $fieldLabel . ' ' . $oldDisplay . ' → ' . $newDisplay;
            }
        }

        return '<div class="flex flex-col gap-1 text-xs">' . implode('', array_map(
            fn (string $line) => '<div>' . $line . '</div>',
            $lines,
        )) . '</div>';
    }

    /**
     * Turn snake_case column names into something readable for non-engineers
     * (e.g. `paid_amount` → `Paid amount`, `eta_status` → `ETA status`).
     */
    protected function humaniseField(string $field): string
    {
        // Common acronyms that look wrong in lowercase.
        $acronyms = ['eta' => 'ETA', 'vat' => 'VAT', 'id' => 'ID'];
        $words = explode(' ', str_replace('_', ' ', $field));
        $words[0] = ucfirst($words[0]);
        return implode(' ', array_map(
            fn (string $w): string => $acronyms[strtolower($w)] ?? $w,
            $words,
        ));
    }

    /**
     * Compact numeric / date / boolean / nested representations.
     */
    protected function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('admin.activity.bool_true') : __('admin.activity.bool_false');
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}
