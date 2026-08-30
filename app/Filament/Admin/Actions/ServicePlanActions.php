<?php

namespace App\Filament\Admin\Actions;

use App\Models\ServicePlan;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a service plan, defined once.**
 *
 * `generatenow` lived inline in `ServicePlansTable`,
 * so the act was reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform this act can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class ServicePlanActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // The per-plan retry, which is what an operator wants when THIS plan is the one
            // failing. Same private path the sweep takes, so a manual generation cannot produce
            // a different work order from the automatic one.
            Action::make('generateNow')
                ->label(__('admin.facility.generate_now'))
                ->icon('heroicon-o-play')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (ServicePlan $record): bool => $record->is_active
                    && (auth()->user()?->can('facility.create') ?? false))
                ->authorize(fn (): bool => auth()->user()?->can('facility.create') ?? false)
                ->action(function (ServicePlan $record): void {
                    abort_unless(auth()->user()?->can('facility.create') ?? false, 403);

                    $service = app(GeneratePreventiveWorkOrdersService::class);
                    $created = $service->runFor($record);

                    self::report($created, $service->failures);
                }),
        ];
    }

    /**
     * One notification for both actions — a generation that raised nothing is a RESULT, not a
     * silence, and a plan that failed must say why on screen rather than only in `last_generation_error`.
     *
     * @param  array<int, string>  $failures
     */
    protected static function report(int $created, array $failures): void
    {
        if ($failures !== []) {
            Notification::make()
                ->danger()
                ->title(__('admin.facility.generate_failed', ['count' => count($failures)]))
                ->body(implode(' · ', array_slice($failures, 0, 3)))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($created > 0
                ? __('admin.facility.generated', ['count' => $created])
                : __('admin.facility.nothing_due'))
            ->send();
    }
}
