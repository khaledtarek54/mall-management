<?php

namespace App\Filament\Admin\Resources\Holidays\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Holidays\HolidayResource;
use App\Support\AssignedAssets;
use App\Support\OpsLog;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHoliday extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** Filament stamps `asset_id` on create but never on update, so the edit path guards too. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assertMayWriteHoliday(isset($data['asset_id']) ? (int) $data['asset_id'] : null);

        return $data;
    }

    /**
     * A national holiday is a row for every mall, so only somebody who can SEE every mall may write
     * one.
     *
     * `mall_admin` inherits `holidays.create` from the blanket manager grant, and a null `asset_id`
     * would otherwise let an admin pinned to one property change SLA deadlines — and, once the
     * working clock is switched on, penalty amounts — at malls they cannot open. CLAUDE.md's rule
     * is that you cannot grant access you do not hold; the analogue is that you cannot write a row
     * for properties you cannot see.
     *
     * Measured against `AssignedAssets`, NOT `TenantScope::visibleAssetIds()`, for the reason
     * `UserResource::enforceGrantableAssetsRule()` gives: the latter collapses to the SELECTED
     * property, which would refuse a super_admin who happens to be working inside one mall.
     */
    private function assertMayWriteHoliday(?int $assetId): void
    {
        if ($assetId !== null) {
            $this->assertAssetInScope($assetId);

            return;
        }

        if (AssignedAssets::idsForCurrentUser() === null) {
            return;
        }

        OpsLog::warning('A property-restricted user tried to write a portfolio-wide holiday', [
            'user_id' => auth()->id(),
            'assigned_assets' => AssignedAssets::idsForCurrentUser(),
        ]);

        abort(403);
    }
}
