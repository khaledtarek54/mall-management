<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Pages;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\Lease;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDepositTransaction extends CreateRecord
{
    protected static string $resource = DepositTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The tenant/asset are derived from the lease in the model's saving hook;
        // re-validate the selected lease's property against the user's visible set so
        // a tampered lease_id can't post this deposit into another property.
        DepositTransactionResource::assertAssetInScope(
            Lease::with('unit')->find($data['lease_id'] ?? null)?->unit?->asset_id
        );

        // A deposit transaction is recorded on create; tenant/asset are derived
        // from the lease in the model's booted() hook.
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }
}
