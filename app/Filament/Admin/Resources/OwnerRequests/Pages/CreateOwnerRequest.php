<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Pages;

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Services\OwnerRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateOwnerRequest extends CreateRecord
{
    protected static string $resource = OwnerRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(OwnerRequestService::class)->create($data, Auth::user());
    }
}
