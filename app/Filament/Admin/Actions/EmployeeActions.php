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

            // **The way back, which did not exist (SW-097).** `terminate` was the only act that
            // touched the status and the form carries no status field, so a mis-clicked termination
            // — the wrong row on a list — was permanent: the person drops out of payroll, the org
            // chart and every active-only picker, with nothing on any screen offering a correction.
            //
            // A dead-end status is the shape this codebase has just had to fix twice (a draft
            // invoice with no way out, a cheque that could only ever go to `bounced`). The rule it
            // follows is `RefusesDeletionOfCommittedRecords`': correct a record through a workflow
            // that leaves a trail, rather than by editing a column or by having no answer at all.
            //
            // `terminated_on` is cleared with it: leaving the date behind would say the person left
            // on a day they are still employed, and it is what every "was this person here then"
            // read looks at. The change is audited — `employees` is one of the 85 models
            // `ActivityLogging::for()` covers — so who reinstated whom, and when, is in the trail
            // without a second bespoke record.
            Action::make('reinstate')
                ->label(__('admin.employees.actions.reinstate'))
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('admin.employees.actions.reinstate_confirm'))
                ->visible(fn (Employee $record) => $record->status === 'terminated' && EmployeeResource::canEdit($record))
                ->authorize(fn (Employee $record) => EmployeeResource::canEdit($record))
                ->action(function (Employee $record): void {
                    // Server-side re-check, exactly as its twin does: `visible()` is a UI decision
                    // and the payload still arrives.
                    abort_unless(EmployeeResource::canEdit($record) && $record->status === 'terminated', 403);

                    $record->update(['status' => 'active', 'terminated_on' => null]);

                    Notification::make()->title(__('admin.employees.reinstated'))->success()->send();
                }),
        ];
    }
}
