<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Models\Employee;
use App\Services\GrantCustodyService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustody extends CreateRecord
{
    protected static string $resource = CustodyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $employee = Employee::findOrFail($data['employee_id']);
        // The custodian's property must be within the user's visible set (tamper guard).
        CustodyResource::assertAssetInScope($employee->asset_id);

        // The service denormalises asset_id + guards (active custodian, amount > 0).
        return app(GrantCustodyService::class)->grant($employee, $data);
    }
}
