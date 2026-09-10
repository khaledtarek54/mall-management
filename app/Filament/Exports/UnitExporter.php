<?php

namespace App\Filament\Exports;

use App\Models\Unit;
use App\Support\DataTransferNotice;
use App\Support\Filament\CustomFieldsTable;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class UnitExporter extends Exporter
{
    protected static ?string $model = Unit::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')->label(__('admin.tables.unit.code')),
            ExportColumn::make('asset.name')->label(__('admin.filters.asset')),
            // The property CODE as well as its name, because `UnitImporter::asset_code` is
            // `requiredMapping()` and resolves a code — `resolveVisibleAsset('Atriom Walk')` is
            // NULL, only 'AW' resolves. Without this the export was a ONE-WAY DOOR at its one
            // required column: the mapping modal cannot be submitted with `asset_code` blank, and
            // an operator picking the only plausible header ("Asset") fails every row. Labelled
            // from the key the importer guesses on, so the mapping is automatic. Same reasoning as
            // the vendor and property exporters, which lead with `code` for exactly this.
            ExportColumn::make('asset.code')->label(__('admin.tables.asset.code')),
            // `floor.code`, never `floor` — the bare relation name has no dot, so Filament skips
            // its relationship resolution entirely and `data_get()` hands back the Floor MODEL,
            // which the CSV writer stringifies through `Model::__toString()` into a JSON blob of
            // the whole row. The operator's floor column read
            // `{"id":2,"asset_id":2,"code":"G",...}`. `code` is what the units table itself shows
            // and what a re-import would join on.
            ExportColumn::make('floor.code')->label(__('admin.pdf.floor')),
            // The floor's NAME as well as its code, the same pairing as the property above: an
            // operator reading the spreadsheet wants *Ground*, and a re-import needs *G*.
            //
            // WHICH ONE CARRIES THE PLAIN "Floor" LABEL IS NOT COSMETIC. Filament maps an import
            // column by LABEL (`ImportColumn::getGuesses()` unshifts its own, and an export's
            // header row IS its labels), and `UnitImporter::floor` is labelled *Floor* and resolves
            // a CODE. So the code keeps that label and the name is explicitly *Floor name*: label
            // them the other way round and the importer auto-guesses onto the NAME column, where
            // every row then fails with "No floor with the code 'Ground' exists". That is the one
            // asymmetry with the asset pair, and it is the importer's join key that decides it.
            ExportColumn::make('floor.name')->label(__('admin.tables.unit.floor_name')),
            ExportColumn::make('category')->label(__('admin.tables.unit.category')),
            ExportColumn::make('area_sqm')->label(__('admin.tables.unit.area')),
            ExportColumn::make('activeLease.tenant.name')->label(__('admin.tables.unit.tenant')),
            ExportColumn::make('activeLease.base_rent_monthly')->label(__('admin.tables.unit.rent')),
            ExportColumn::make('activeLease.expiry_date')->label(__('admin.widgets.top_tenants.lease_ends')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),

            // The operator's own fields (D-7), LAST so the shipped column positions a
            // colleague's import template depends on never move.
            ...CustomFieldsTable::exportColumns('unit'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return DataTransferNotice::forExport($export);
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
