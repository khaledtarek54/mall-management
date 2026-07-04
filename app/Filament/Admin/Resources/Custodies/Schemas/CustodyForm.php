<?php

namespace App\Filament\Admin\Resources\Custodies\Schemas;

use App\Models\Custody;
use App\Models\Employee;
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
                ->options(fn () => self::employeeOptions())
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

    /** @return array<int, string> */
    private static function employeeOptions(): array
    {
        $query = Employee::query()->active();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
