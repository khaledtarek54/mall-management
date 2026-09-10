<?php

namespace App\Support\Filament;

use Filament\Actions\ExportBulkAction;

/**
 * Filament's `ExportBulkAction`, refusing an export that could not say which rows it is about.
 *
 * Bound in `AppServiceProvider` so `ExportBulkAction::make()` returns this at all thirteen call sites
 * and the fourteenth before anyone remembers it — the argument that put the CRUD authorization in
 * the container. See {@see IdentifiedExport} for the rule, and for why it refuses rather than
 * silently switching the column back on.
 */
class IdentifiedExportBulkAction extends ExportBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mutateFormDataUsing(function (array $data): array {
            IdentifiedExport::assertIdentified($this->getExporter(), $data['columnMap'] ?? []);

            return $data;
        });
    }
}
