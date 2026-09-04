<?php

namespace App\Filament\Admin\Resources\Custodies\Schemas;

use App\Models\Custody;
use App\Models\Employee;
use App\Support\EquipmentPicker;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustodyForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once a custody has settlements, its grant terms are settled — lock them
        // (editing the amount/custodian would misstate the outstanding). Notes stay open.
        $granted = fn (?Custody $record) => $record !== null && $record->transactions()->exists();

        return $schema->columns(2)->components([
            Select::make('employee_id')
                ->label(__('admin.custodies.fields.custodian'))
                // Active employees of the user's visible properties (the custody's
                // property is denormalised from the chosen employee). The custodian is
                // fixed at grant — locked on edit so the books dimension can't drift.
                //
                // The RECORD's own custodian is passed in and always offered back — see
                // employeeOptions(). Without it the edit page cannot LABEL the stored value, and
                // Filament then refuses the save on a field the operator cannot even touch.
                ->options(fn (?Custody $record) => self::employeeOptions($record?->employee_id))
                ->required()
                ->searchable()
                ->native(false)
                ->disabled(fn (?Custody $record) => $record !== null),
            TextInput::make('reference')
                ->label(__('admin.custodies.fields.reference'))
                ->maxLength(255),
            TextInput::make('amount')
                ->label(__('admin.custodies.fields.amount'))
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->prefix('EGP')
                ->disabled($granted),
            DatePicker::make('custody_date')
                ->label(__('admin.custodies.fields.custody_date'))
                // UX only — GrantCustodyService is the gate. The settlement picker on
                // CustodyTransactionsRelationManager has carried this bound since F-93; the grant,
                // which is the half that hands the cash over, carried no bound at all.
                ->maxDate(now())
                ->default(now())
                ->required()
                ->native(false)
                ->disabled($granted),
            Select::make('paid_from')
                ->label(__('admin.custodies.fields.paid_from'))
                ->options(['cash' => __('admin.employees.methods.cash'), 'bank' => __('admin.employees.methods.bank')])
                ->default('cash')
                ->required()
                ->native(false)
                ->disabled($granted),
            Textarea::make('purpose')
                ->label(__('admin.custodies.fields.purpose'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Active employees of the visible properties — **plus this custody's own custodian**, even
     * after they have left the payroll.
     *
     * Not cosmetic. Filament derives an `in:` rule from a Select's options and validates the
     * CURRENT value against it. Measured at HEAD against filament/filament v4.11.8:
     * `Select::getInValidationRuleValues()` (vendor/filament/forms/src/Components/Select.php:1788)
     * returns `[]` the moment `getOptionLabel(withDefault: false)` comes back blank, which makes
     * `Rule::in([])` — a rule nothing satisfies; and a DISABLED field is still VALIDATED, because
     * `HasState::isNeitherDehydratedNorValidated()` (HasState.php:800) short-circuits on
     * `isValidatedWhenNotDehydrated`, which defaults TRUE (HasState.php:59). So narrowing to
     * `->active()` alone did not merely blank the custodian on the edit page: it made the whole
     * record unsavable — the purpose and the reference included, which `Custody::saving()`
     * deliberately leaves editable so "an operator must be able to record what it turned out to be
     * for". The lockout arrives the day the person holding the custody leaves, which is exactly
     * when an outstanding one has to be chased.
     *
     * TWO reachable states drop the stored custodian and one mechanism covers both: the employee
     * is TERMINATED (`->active()`), or their `asset_id` has since moved to another mall while the
     * custody keeps the property it was granted under. A soft-deleted employee is NOT a third —
     * `Employee` is `#[DeletableWhenUnused(blockedBy: [..., 'custodies'])]`, so one holding a
     * custody cannot be deleted at all; nothing here reaches for `withTrashed()` on a state the
     * deletion policy already refuses.
     *
     * Same defect, same shape and same reasoning as {@see EquipmentPicker} and the
     * area picker on `UnitForm`. Kept private rather than extracted: one call site.
     *
     * CREATE is untouched — `$currentId` is null there — so a NEW custody still cannot be granted
     * to somebody who has left.
     *
     * @return array<int, string>
     */
    private static function employeeOptions(?int $currentId = null): array
    {
        $query = Employee::query()->active();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        $options = $query->orderBy('name')->pluck('name', 'id')->all();

        if ($currentId !== null && ! array_key_exists($currentId, $options)) {
            $name = Employee::query()->whereKey($currentId)->value('name');

            if ($name !== null) {
                $options[$currentId] = $name;
            }
        }

        return $options;
    }
}
