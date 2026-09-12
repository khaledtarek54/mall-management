<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Unit;
use App\Services\RemeasureUnitService;
use App\Support\RowActionPolicy;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
                        ->required()
                        // A RE-MEASUREMENT HAS TO MEASURE SOMETHING DIFFERENT. Reported by the
                        // tester: the modal accepted the area the unit already has, and the
                        // operator was told "re-measured to 1,000.00 m²" while nothing was
                        // recorded — `RemeasureUnitService` returns the row in force unchanged
                        // rather than opening a second one saying the same thing on the same day,
                        // which is deliberate and tested (a retry or a re-run must be safe).
                        //
                        // So the refusal belongs HERE, not in the service: idempotent for a caller,
                        // and a clear "that is what it already measures" for a person, instead of a
                        // success message about a change that did not happen.
                        ->rules([
                            fn (Unit $record, Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                                $on = $get('effective_from');
                                // Compared on the CHOSEN date, which is what the service compares:
                                // re-stating today's area is a no-op, but re-stating it as of a
                                // date when a DIFFERENT area was in force is a real correction.
                                $current = filled($on)
                                    ? $record->areaOn(CarbonImmutable::parse($on))
                                    : (float) $record->area_sqm;

                                // …unless the NET moved (point 20): a survey that confirms the
                                // gross and corrects the net is a change, and the service writes it
                                // on its no-change branch. Without this carve-out the only door for
                                // a net-only correction was the Edit form, and nothing said so.
                                $netMoved = round((float) ($get('net_area_sqm') ?? 0), 2) !== round((float) ($record->net_area_sqm ?? 0), 2);

                                if ($current !== null && ! $netMoved && round((float) $value, 2) === round((float) $current, 2)) {
                                    $fail(__('admin.refusals.remeasure_no_change', [
                                        'area' => number_format((float) $current, 2),
                                    ]));
                                }
                            },
                        ]),
                    // The net area travels with the survey (point 20): a gross re-measured below
                    // the stated net would be refused by the model, so the modal asks for both,
                    // defaulting to what the unit carries so an unchanged net simply carries over.
                    // The net is UNDATED and lands today, so it is judged against the gross in
                    // force on the day it lands: the survey's own for a survey effective today or
                    // earlier, TODAY's for one dated ahead — the same comparison the model makes
                    // on the save, so the refusal arrives here as a field message and not as a
                    // toast quoting a figure the operator did not type (found by the review).
                    TextInput::make('net_area_sqm')
                        ->label(__('admin.tables.unit.net_area'))
                        ->numeric()
                        ->minValue(0.01)
                        ->suffix('m²')
                        ->default(fn (Unit $record) => $record->net_area_sqm)
                        ->helperText(__('admin.helpers.remeasure_net_area'))
                        ->rules([
                            fn (Unit $record, Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                                $on = $get('effective_from');
                                $gross = filled($on) && CarbonImmutable::parse($on)->startOfDay()->isFuture()
                                    ? $record->areaOn()
                                    : $get('area_sqm');

                                if (Unit::netAreaExceedsGross($value, $gross)) {
                                    $fail(Unit::netAreaRefusal($value, $gross));
                                }
                            },
                        ]),
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
                            'net_area_sqm' => filled($data['net_area_sqm'] ?? null) ? (float) $data['net_area_sqm'] : null,
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
