<?php

namespace App\Filament\Admin\Resources\SlaPolicies;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
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
 * Part of module 26, so it rides the `facility` flag + permissions.
 */
class SlaPolicyResource extends Resource
{
    /**
     * Deliberately absent from global search — the reason is stated in
     * App\Support\SearchPolicy::GLOBAL_SEARCH_EXEMPT, which the conformance
     * gate reads. Do not flip this without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). ScopesToProperty
    // turns that hook off AND scopes reads from the model's own #[PropertyOwned]; the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;

    protected static ?string $model = SlaPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $recordTitleAttribute = 'priority';

    protected static function permissionModule(): string
    {
        return 'facility';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.sla.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.sla.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.sla.plural');
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
