<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\EditRecord;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    // No Delete header action. A department that routed a request or held a member is referenced
    // by rows an auditor reads; deactivating is the retirement path here as everywhere else.

    /**
     * Both ends of the move, not just the submitted value.
     *
     * A null `asset_id` is an operator-wide department that every mall routes requests to. Guarding
     * only what was submitted let a restricted admin re-home a global department onto their own
     * property — which takes it away from every other mall, and misroutes their tenant requests —
     * while passing the check cleanly. Same defect and same fix as the holiday register; the
     * reasoning is in {@see GuardsPortfolioWideRows}.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        DepartmentResource::assertMayWriteAcrossPortfolio(
            isset($data['asset_id']) ? (int) $data['asset_id'] : null,
            $this->record->getOriginal('asset_id') === null ? null : (int) $this->record->getOriginal('asset_id'),
            'admin.errors.department_needs_every_property',
        );

        return $data;
    }
}
