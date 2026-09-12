<?php

namespace App\Support\Filament;

use App\Models\Lease;
use App\Support\ChargeEscalation;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The three fields that state an annual-increase rule — the mode, and the one figure the mode
 * reads — built ONCE for every screen that asks (2026-09-12).
 *
 * Point 24 put the trio on the lease form's "Which charges step" table and on the schedule tab's
 * Add charge modal, written by hand in both; the per-item step for bays added five more askers —
 * the create form's items repeater, the quick-lease wizard, the assign modal, and a row action on
 * each of the two tabs — and seven copies of one control is how the visibility rule on the rate
 * box comes to differ between the screen an operator ruled on and the one they check it on. So:
 * one builder, the `EntitySelect`/`PropertyField` reasoning. The options NAME what a follows-lease
 * row would inherit (`ChargeEscalation::options()`), read off the lease — or, on a form whose
 * clause is still being typed, off the form's own state through a closure.
 *
 * What is written is never read off these fields raw: every writer passes them through
 * `ChargeEscalation::normalise()`, so a rate lingering in the state after a switch to a fixed
 * amount is not a term (found by review of point 24).
 */
final class EscalationRuleFields
{
    /**
     * @param  Lease|Closure(Get): Lease|null  $lease  what a follows-lease option inherits; null offers the bare wording
     * @param  Closure(Get): bool|null  $applies  when false the fields hide (a one-time charge has no anniversary)
     * @param  bool  $inTable  cells of a table repeater carry no label of their own
     * @return array{0: Select, 1: TextInput, 2: TextInput}
     */
    public static function make(Lease|Closure|null $lease, ?Closure $applies = null, bool $inTable = false): array
    {
        $applies ??= fn (): bool => true;
        $resolve = fn (Get $get): ?Lease => $lease instanceof Closure ? $lease($get) : $lease;

        $mode = Select::make('escalation_mode')
            ->options(fn (Get $get): array => ChargeEscalation::options($resolve($get)))
            // The follows-lease option NAMES the clause's figure, a fact from outside this select.
            // Filament re-fetches a non-native select's option list when it is opened and never
            // re-reads the label it DISPLAYS (`select.blade.php` keys the widget on disabled and
            // reorderable only, under `wire:ignore`), so on a form whose clause is typed live the
            // label stood at "(no percentage to follow)" beside a "By" cell already reading "the
            // index" (Trello cdng18sM). Keyed on the clause, the widget is re-mounted the round
            // trip the clause changes and reads its label afresh; on a modal over a saved lease
            // the key is constant and this changes nothing.
            ->key(fn (Get $get): string => 'escalation_mode:'.md5(ChargeEscalation::clauseFingerprint($resolve($get))))
            ->default(ChargeEscalation::NONE)
            ->native(false)
            ->selectablePlaceholder(false)
            ->live()
            ->visible(fn (Get $get): bool => $applies($get))
            ->required(fn (Get $get): bool => $applies($get));

        // "% / yr" over a clause that steps every three months is the wrong unit: the figure is
        // per STEP, and how often a step comes is the lease's interval (Trello O26YHJWG).
        $perStep = fn (Get $get): string => ($clause = $resolve($get)) === null
            ? '/ '.__('admin.fields.per_year_suffix')
            : ChargeEscalation::cadence($clause);

        $rate = TextInput::make('escalation_rate')
            ->suffix(fn (Get $get): string => '% '.$perStep($get))
            ->numeric()
            ->minValue(0.01)
            ->maxValue(100)
            ->step('0.01')
            ->helperText(__('admin.helpers.charge_escalation_rate'))
            ->visible(fn (Get $get): bool => $applies($get) && $get('escalation_mode') === ChargeEscalation::PERCENT)
            ->required(fn (Get $get): bool => $applies($get) && $get('escalation_mode') === ChargeEscalation::PERCENT);

        $amount = TextInput::make('escalation_amount')
            ->prefix('EGP')
            ->suffix($perStep)
            ->numeric()
            ->minValue(0.01)
            ->visible(fn (Get $get): bool => $applies($get) && $get('escalation_mode') === ChargeEscalation::FIXED_AMOUNT)
            ->required(fn (Get $get): bool => $applies($get) && $get('escalation_mode') === ChargeEscalation::FIXED_AMOUNT);

        if ($inTable) {
            $mode->hiddenLabel();
            $rate->hiddenLabel();
            $amount->hiddenLabel();
        } else {
            $mode->label(__('admin.fields.escalation_mode'))->helperText(__('admin.helpers.escalation_mode'));
            $rate->label(__('admin.fields.escalation_rate'));
            $amount->label(__('admin.fields.escalation_amount'));
        }

        return [$mode, $rate, $amount];
    }
}
