<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a employee, defined once.**
 *
 * `terminate` lived inline in `EmployeesTable`,
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
class EmployeeActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            Action::make('terminate')
                ->label(__('admin.employees.actions.terminate'))
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Employee $record) => $record->status === 'active' && EmployeeResource::canEdit($record))
                ->authorize(fn (Employee $record) => EmployeeResource::canEdit($record))
                ->schema([
                    DatePicker::make('terminated_on')
                        ->label(__('admin.employees.fields.terminated_on'))
                        ->default(now())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data, Employee $record): void {
                    // Server-side re-check (authz can't see form tampering of a terminal flip).
                    abort_unless(EmployeeResource::canEdit($record) && $record->status === 'active', 403);
                    $record->update(['status' => 'terminated', 'terminated_on' => $data['terminated_on']]);
                    Notification::make()->title(__('admin.employees.terminated'))->success()->send();
                }),
        ];
    }
}
