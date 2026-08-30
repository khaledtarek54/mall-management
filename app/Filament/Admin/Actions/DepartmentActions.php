<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentMessageService;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * **Everything you can DO to a department, defined once.**
 *
 * `message` lived inline in `DepartmentsTable`,
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
class DepartmentActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // Inter-department messaging (FR DEPT-2): notify this
            // department's members via the bell.
            Action::make('message')
                ->label(__('admin.actions.message'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('info')
                ->modalHeading(fn (Department $record) => __('admin.actions.message_heading', ['dept' => $record->name]))
                ->schema([
                    Textarea::make('body')
                        ->label(__('admin.actions.message'))
                        ->required()
                        ->rows(4),
                ])
                ->visible(fn ($record) => DepartmentResource::canEdit($record))
                // Fans a notification out to every member of the department — gate it, don't
                // merely hide the button.
                ->authorize(fn ($record) => DepartmentResource::canEdit($record))
                ->action(function (Department $record, array $data) {
                    $count = app(DepartmentMessageService::class)->send($record, Auth::user(), $data['body']);

                    Notification::make()
                        ->title(__('admin.actions.message_sent', ['count' => $count]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
