<?php

namespace App\Filament\Admin\Resources\Violations;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Violations\Pages\CreateViolation;
use App\Filament\Admin\Resources\Violations\Pages\EditViolation;
use App\Filament\Admin\Resources\Violations\Pages\ListViolations;
use App\Filament\Admin\Resources\Violations\Schemas\ViolationForm;
use App\Filament\Admin\Resources\Violations\Tables\ViolationTable;
use App\Models\Violation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant-violations register (module 31) — FR-REQ-15/16/17. Property-scoped
 * (direct asset_id, like Area / Equipment); gated by the `violations.*`
 * permissions; lives in the Operations navigation group.
 *
 * FR-REQ-15 records the fine (`fine_amount`) — it is NOT billed here. FR-REQ-16
 * is the property-scoped table + record view for authorized staff. FR-REQ-17 is
 * the "Send notice" record action (ViolationTable), gated on `violations.notify`.
 */
class ViolationResource extends Resource
{
    use BypassesScopingOnAll;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Violation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'violations';
    }

    /**
     * Whether the current user may send the tenant notice (FR-REQ-17). Named once
     * so the table action gates the SAME predicate in visible() (the UI) and
     * action()/authorize() (the real dispatch gate) — they can't drift.
     */
    public static function canNotify(Model $record): bool
    {
        return static::hasPermission('notify');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.violations.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.violations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.violations.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return ViolationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ViolationTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListViolations::route('/'),
            'create' => CreateViolation::route('/create'),
            'edit' => EditViolation::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['description'];
    }
}
