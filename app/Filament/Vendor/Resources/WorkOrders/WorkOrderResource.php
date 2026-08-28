<?php

namespace App\Filament\Vendor\Resources\WorkOrders;

use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Support\Filament\VendorScope;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Icons\Heroicon;

/**
 * **The contractor's jobs** — the only list in the vendor portal, and by design the only one.
 *
 * The portal is "a list of *your* jobs and the four verbs" (§3 of the design). There is no vendor
 * directory, no property pages, no reports: everything a contractor can reach is a dispatch that was
 * made to them.
 *
 * **`getEloquentQuery()` is layer 1 of three**, and only the third is a gate:
 *  1. this query narrows to `vendor_id` + dispatched statuses (`VendorScope::jobs`);
 *  2. the UI shows only what it returns;
 *  3. every action re-checks ownership server-side and 404s — because the Livewire payload still
 *     carries an id, and a narrowed list is not a gate.
 *
 * **No create, no edit, no delete.** A contractor does not raise their own work orders, and marking
 * a job done is explicitly out of scope: a contractor saying "finished" is a *claim*, the operator's
 * completion is a *decision*, and `facility.complete` runs a checklist gate, an evidence gate and
 * the cost object. Keeping them apart is the same reasoning that made the tenant's confirmation a
 * control rather than a courtesy.
 */
class WorkOrderResource extends Resource
{
    protected static ?string $model = FacilityWorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('vendor.jobs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vendor.jobs.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('vendor.jobs.plural');
    }

    /** Layer 1: the contractor's own dispatches, and nothing else. */
    public static function getEloquentQuery(): Builder
    {
        return VendorScope::jobs(parent::getEloquentQuery());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
        ];
    }
}
