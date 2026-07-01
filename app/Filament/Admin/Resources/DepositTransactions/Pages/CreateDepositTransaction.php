<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Pages;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDepositTransaction extends CreateRecord
{
    protected static string $resource = DepositTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // A deposit transaction is recorded on create; tenant/asset are derived
        // from the lease in the model's booted() hook.
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }
}
