<?php

namespace App\Filament\Admin\Resources\SlaPolicies;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\SlaPolicies\Pages\CreateSlaPolicy;
use App\Filament\Admin\Resources\SlaPolicies\Pages\EditSlaPolicy;
use App\Filament\Admin\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Admin\Resources\SlaPolicies\Schemas\SlaPolicyForm;
use App\Filament\Admin\Resources\SlaPolicies\Tables\SlaPoliciesTable;
use App\Models\SlaPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Per-property SLA durations for corrective maintenance (FR-CM-05) — "set once per mall".
 * Part of module 26, so it rides the `preventive_maintenance` flag + permissions.
 */
class SlaPolicyResource extends Resource
{
    use BypassesScopingOnAll;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = SlaPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 49;

    protected static ?string $recordTitleAttribute = 'priority';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'preventive_maintenance';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.preventive_maintenance.sla.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.preventive_maintenance.sla.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.preventive_maintenance.sla.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.preventive_maintenance.group');
    }

    public static function form(Schema $schema): Schema
    {
        return SlaPolicyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SlaPoliciesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSlaPolicies::route('/'),
            'create' => CreateSlaPolicy::route('/create'),
            'edit' => EditSlaPolicy::route('/{record}/edit'),
        ];
    }
}
