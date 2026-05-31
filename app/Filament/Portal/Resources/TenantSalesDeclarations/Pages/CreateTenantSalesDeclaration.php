<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Tenant;
use App\Services\PercentageRentCalculationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

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

        // Operator-side bell: managers + leasing_managers assigned to the
        // lease's asset get a database notification so the new declaration
        // surfaces in their triage queue. Mail skipped — high frequency at
        // scale; the admin Sales Declarations nav badge already counts
        // submitted rows.
        $assetId = $declaration->lease?->unit?->asset_id;
        if ($assetId) {
            $recipients = \App\Models\User::query()
                ->role(['manager', 'leasing_manager', 'super_admin'])
                ->whereHas('assignedAssets', fn ($q) => $q->where('assets.id', $assetId))
                ->get();

            if ($recipients->isEmpty()) {
                $recipients = \App\Models\User::query()->role('super_admin')->get();
            }

            try {
                \Illuminate\Support\Facades\Notification::send(
                    $recipients,
                    new \App\Notifications\SalesDeclarationSubmittedNotification($declaration->fresh())
                );
            } catch (\Throwable $e) {
                \Log::warning('Portal sales declaration notification fan-out failed', [
                    'declaration_id' => $declaration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
