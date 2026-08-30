<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a user, defined once.**
 *
 * `suspend` and `reactivate` lived inline in `UsersTable`,
 * so they were reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform these acts can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class UserActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // Suspend, not delete. Deleting a leaver takes their name off every record they
            // touched and off the activity log with it; suspending ends the login and keeps
            // the history attributable. Gated in visible() AND authorize() — a hidden action
            // is still dispatchable via a crafted Livewire call.
            Action::make('suspend')
                ->label(__('admin.users.actions.suspend'))
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (User $record) => ! $record->isSuspended() && UserResource::canSuspend($record))
                ->authorize(fn (User $record) => UserResource::canSuspend($record))
                ->requiresConfirmation()
                ->modalDescription(__('admin.users.actions.suspend_confirm'))
                ->schema([
                    TextInput::make('reason')
                        ->label(__('admin.users.fields.suspended_reason'))
                        ->helperText(__('admin.users.fields.suspended_reason_help'))
                        ->maxLength(255),
                ])
                ->action(function (User $record, array $data): void {
                    abort_unless(UserResource::canSuspend($record), 403);

                    $record->forceFill([
                        'status' => User::STATUS_SUSPENDED,
                        'suspended_at' => now(),
                        'suspended_reason' => $data['reason'] ?? null,
                    ])->save();

                    Notification::make()
                        ->title(__('admin.users.notices.suspended', ['name' => $record->name]))
                        ->success()
                        ->send();
                }),
            Action::make('reactivate')
                ->label(__('admin.users.actions.reactivate'))
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (User $record) => $record->isSuspended() && UserResource::canSuspend($record))
                ->authorize(fn (User $record) => UserResource::canSuspend($record))
                ->requiresConfirmation()
                ->action(function (User $record): void {
                    abort_unless(UserResource::canSuspend($record), 403);

                    $record->forceFill([
                        'status' => User::STATUS_ACTIVE,
                        'suspended_at' => null,
                        'suspended_reason' => null,
                    ])->save();

                    Notification::make()
                        ->title(__('admin.users.notices.reactivated', ['name' => $record->name]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
