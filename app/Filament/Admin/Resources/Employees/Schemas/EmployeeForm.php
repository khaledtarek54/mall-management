<?php

namespace App\Filament\Admin\Resources\Employees\Schemas;

use App\Models\Department;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\Pdf\DocumentLocale;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            PropertyField::make()
                ->label(__('admin.employees.fields.property')),
            EntitySelect::make('department_id')
                ->label(__('admin.employees.fields.department'))
                // Global + visible-property departments only.
                ->entity(Department::class)
                ->searchable()
                ->native(false),
            TextInput::make('code')
                ->label(__('admin.employees.fields.code'))
                ->required()
                ->maxLength(40)
                // Unique per property (matches the DB composite unique index).
                // Clamped: `asset_id` is client-supplied, and a unique rule keyed on the raw
                // value leaks whether an employee code exists in a property the user cannot
                // see — the most sensitive instance of this class (TenantScope::clampAssetId).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', TenantScope::clampAssetId($get('asset_id')))),
            TextInput::make('name')
                ->label(__('admin.employees.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('national_id')
                ->label(__('admin.employees.fields.national_id'))
                ->maxLength(20),
            TextInput::make('position')
                ->label(__('admin.employees.fields.position'))
                ->maxLength(255),
            DatePicker::make('hire_date')
                ->label(__('admin.employees.fields.hire_date'))
                ->default(now())
                ->required()
                ->native(false),
            TextInput::make('base_salary')
                ->label(__('admin.employees.fields.base_salary'))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->prefix('EGP'),
            Select::make('payment_method')
                ->label(__('admin.employees.fields.payment_method'))
                ->options(['bank' => __('admin.employees.methods.bank'), 'cash' => __('admin.employees.methods.cash')])
                ->default('bank')
                ->required()
                ->native(false),
            TextInput::make('phone')
                ->label(__('admin.employees.fields.phone'))
                ->tel()
                ->maxLength(30),
            // Which language this employee's payslip is written in. Blank means nobody has asked,
            // and the payslip then follows whoever generates it.
            Select::make('locale')
                ->label(__('admin.fields.locale'))
                ->helperText(__('admin.helpers.employee_locale'))
                ->options(DocumentLocale::options())
                ->placeholder(__('admin.fields.locale_unset'))
                ->native(false),
            Textarea::make('notes')
                ->label(__('admin.employees.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
