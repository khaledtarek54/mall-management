<?php

namespace App\Filament\Actions;

use App\Services\Accounting\SetPostMonthService;
use App\Support\PostMonth;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * "Post to month" — drop-in for any GL source document (story MF-05).
 *
 * **A factory rather than an action copied per resource.** The post month is one behaviour shared by
 * 24 posting sources; written out per table it would drift — a different label here, a missing
 * authorisation there, a reason field that is optional on one screen and required on the next. This
 * is the UI counterpart of the single override the whole story rests on.
 *
 * Usage: `PostMonthAction::make('vendor_bills.edit')` in a table's `recordActions()`.
 *
 * Gated in BOTH `visible()` and `action()` — the house rule. `visible()` is the UI; the
 * `abort_unless` inside `action()` is the gate.
 */
class PostMonthAction
{
    public static function make(string $permission): Action
    {
        $allowed = fn (): bool => auth()->user()?->can($permission) ?? false;

        return Action::make('postToMonth')
            ->label(__('admin.actions.post_to_month'))
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->modalDescription(__('admin.actions.post_to_month_hint'))
            ->visible($allowed)
            ->authorize($allowed)
            ->fillForm(fn (Model $record): array => [
                'post_month' => PostMonth::forSource($record)?->toDateString(),
            ])
            ->schema([
                DatePicker::make('post_month')
                    ->label(__('admin.actions.post_to_month_field'))
                    ->native(false)
                    // The month is the decision; the day comes from the document.
                    ->displayFormat('m/Y')
                    ->required()
                    ->helperText(__('admin.actions.post_to_month_field_hint')),
                Textarea::make('reason')
                    ->label(__('admin.actions.post_to_month_reason'))
                    ->placeholder(__('admin.actions.post_to_month_reason_placeholder'))
                    ->rows(2)
                    ->required(),
            ])
            ->extraModalFooterActions([
                Action::make('clearPostMonth')
                    ->label(__('admin.actions.post_to_month_clear'))
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Model $record): bool => PostMonth::isOverridden($record))
                    ->authorize($allowed)
                    ->action(function (Model $record) use ($permission) {
                        abort_unless(auth()->user()?->can($permission) ?? false, 403);

                        try {
                            app(SetPostMonthService::class)->clear($record);
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.actions.post_to_month_cleared'))->send();
                    })
                    ->cancelParentActions(),
            ])
            ->action(function (Model $record, array $data) use ($permission) {
                abort_unless(auth()->user()?->can($permission) ?? false, 403);

                try {
                    app(SetPostMonthService::class)->set($record, $data['post_month'], $data['reason']);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin.actions.post_to_month_set'))->send();
            });
    }
}
