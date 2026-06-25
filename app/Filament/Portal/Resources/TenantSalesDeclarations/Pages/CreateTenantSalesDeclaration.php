<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Tenant;
use App\Notifications\SalesDeclarationSubmittedNotification;
use App\Services\AssetStaffRecipients;
use App\Services\PercentageRentCalculationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class CreateTenantSalesDeclaration extends CreateRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['declared_at'] ??= now();
        $data['declared_by_type'] = Tenant::class;
        $data['declared_by_id'] = Auth::guard('portal')->id();
        $data['status'] = 'submitted';

        return $data;
    }

    protected function afterCreate(): void
    {
        $declaration = $this->record;

        app(PercentageRentCalculationService::class)->recalculate($declaration);

        // Operator-side bell: managers + leasings assigned to the
        // lease's asset, plus every super_admin, get a database notification so
        // the new declaration surfaces in their triage queue. Mail skipped —
        // high frequency at scale; the admin Sales Declarations nav badge
        // already counts submitted rows.
        $recipients = app(AssetStaffRecipients::class)->for(
            $declaration->lease?->unit?->asset_id,
            ['manager', 'leasing'],
        );

        try {
            Notification::send(
                $recipients,
                new SalesDeclarationSubmittedNotification($declaration->fresh())
            );
        } catch (\Throwable $e) {
            \Log::warning('Portal sales declaration notification fan-out failed', [
                'declaration_id' => $declaration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
