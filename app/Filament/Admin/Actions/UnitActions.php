<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Unit;
use App\Services\RemeasureUnitService;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * **Everything you can DO to a unit, defined once.**
 *
 * Re-measuring is the only act a unit has, and it lived inline in `UnitsTable` — so it was
 * reachable from the LIST and not from the unit's own page, which is backwards from the record-hub
 * architecture this project took from Yardi: **the list finds, the record acts**. It is now a
 * header action on `EditUnit`, defined here so the two surfaces can never drift.
 *
 * Safe to move, and that was checked rather than assumed: the act gates on `UnitResource::canEdit`,
 * which is the same permission the Edit page itself is reached through, so no role that can
 * re-measure today loses the ability to. Four other resources failed that check and kept their
 * verbs on the row — see {@see RowActionPolicy}.
 */
class UnitActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // Change a unit's measured area — the ONLY path that may, because it is the only
            // one that dates the change. `RemeasureUnitService` shipped with the versioning
            // feature and had no caller anywhere in app/: the register existed, nothing could
            // add to it, and the only reachable way to change an area was the Edit form's
            // plain `area_sqm` field, which bypassed versioning entirely (validation sweep,
            // 2026-08-11). Closing that bypass without this action would have left operators
            // unable to record a re-survey at all.
            Action::make('remeasure')
                ->label(__('admin.actions.remeasure_unit'))
                ->icon('heroicon-o-variable')
                ->color('gray')
                ->visible(fn ($record) => UnitResource::canEdit($record))
                ->authorize(fn ($record) => UnitResource::canEdit($record))
                ->modalHeading(fn (Unit $record) => __('admin.actions.remeasure_unit_heading', ['unit' => $record->code]))
                // The current figure goes in the description, so the operator is told what they
                // are changing FROM before they type what it is changing to.
                ->modalDescription(fn (Unit $record) => __('admin.actions.remeasure_unit_description', [
                    'current' => number_format((float) $record->area_sqm, 2),
                ]))
                ->schema([
                    TextInput::make('area_sqm')
                        ->label(__('admin.actions.remeasure_new_area'))
                        ->numeric()
                        ->minValue(0.01)
                        ->suffix('m²')
                        ->required(),
                    DatePicker::make('effective_from')
                        ->label(__('admin.actions.remeasure_effective_from'))
                        ->default(now())
                        ->native(false)
                        ->required()
                        ->helperText(__('admin.helpers.remeasure_effective_from'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.remeasure_effective_from')),
                    Textarea::make('reason')
                        ->label(__('admin.fields.reason'))
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(function (Unit $record, array $data): void {
                    // action() is the real gate; visible() is the UI.
                    abort_unless(UnitResource::canEdit($record), 403);

                    try {
                        app(RemeasureUnitService::class)->record($record, (float) $data['area_sqm'], [
                            'effective_from' => $data['effective_from'] ?? null,
                            'reason' => $data['reason'] ?? null,
                        ]);
                    } catch (\DomainException $e) {
                        // e.g. a date at or before the row it would close — a toast, not a 500.
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('admin.actions.remeasure_unit_done', [
                            'unit' => $record->code,
                            'area' => number_format((float) $record->fresh()->area_sqm, 2),
                        ]))
                        ->send();
                }),
        ];
    }
}
