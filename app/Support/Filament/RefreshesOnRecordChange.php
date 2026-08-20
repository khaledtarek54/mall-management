<?php

namespace App\Support\Filament;

use Livewire\Attributes\On;

/**
 * A widget that describes a record (or a population of them) re-renders when something on the
 * same screen changes it.
 *
 * `Filament\Widgets\Widget` is a plain Livewire component with no listeners of its own, and the
 * widgets blade mounts each one with a STABLE key:
 *
 *     @livewire($widgetClass, [...], key("{$widgetClass}-{$widgetKey}"))
 *
 * A stable key is exactly what tells Livewire 3 to leave the child alone when the parent
 * re-renders. So a stats widget above a table, or above an Edit form, computes its figures once
 * on page load and then never again — every action on that page leaves it describing the state
 * before the action. The deposit-holdings summary kept reporting the old liability after a
 * refund was recorded three feet below it; the lease summary kept reporting the old outstanding
 * after the invoice that changed it was raised from the same page.
 *
 * An empty listener body is the whole implementation: Livewire re-renders a component whenever
 * one of its listeners fires, and a widget's figures are computed in `getStats()` at render
 * time. There is nothing to invalidate — only a render to ask for.
 *
 * @see RecordChanged for who announces the change
 */
trait RefreshesOnRecordChange
{
    /**
     * Deliberately empty. Handling the event IS the refresh — Livewire re-renders the component
     * for any listener call, and the widget recomputes from the database on render.
     */
    #[On(RecordChanged::EVENT)]
    public function refreshOnRecordChange(): void {}
}
