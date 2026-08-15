<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Pages;

use App\Support\MorphMap;
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
        // Clamp the client-supplied lease_id to the signed-in tenant's OWN. The form's options()
        // scope only the RENDERING; a crafted Livewire submit can post any lease_id — and without this
        // a portal user could plant a declaration on ANOTHER retailer's lease: it would occupy that
        // lease's (lease_id, period_start) unique slot (DoS'ing the victim's own reporting) and surface
        // a fabricated report on that mall's admin queue → potential misbilling. The mobile API's
        // CreateSalesDeclarationAction already enforces this; the portal page must too.
        $data['lease_id'] = \App\Support\Portal::clampLeaseId($data['lease_id'] ?? null);
        abort_if($data['lease_id'] === null, 403);

        $data['declared_at'] ??= now();
        $data['declared_by_type'] = MorphMap::alias(Tenant::class);
        $data['declared_by_id'] = \App\Support\Portal::tenantId();
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
