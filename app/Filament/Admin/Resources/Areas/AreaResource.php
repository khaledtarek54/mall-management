<?php

namespace App\Filament\Admin\Resources\Areas;

use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\Areas\Pages\EditArea;
use App\Filament\Admin\Resources\Areas\Pages\ListAreas;
use App\Filament\Admin\Resources\Areas\Schemas\AreaForm;
use App\Filament\Admin\Resources\Areas\Tables\AreaTable;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Area;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The facility-zone register (module 30) — scoped to the current property
 * (direct asset_id, like Unit / Warehouse / Equipment). Gated by the
 * `areas.*` permissions and lives in the Operations navigation group with the
 * other facility modules.
 */
class AreaResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and
    // that Select is enabled in All-Properties mode). Filament's ownership `creating` hook would
    // force asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset,
    // silently clobbering the chosen mall (the "Announcements tenancy trap").
    // ScopesToProperty turns that hook off AND scopes reads from the model's own
    // #[PropertyOwned]; the submitted asset_id is re-validated by assertAssetInScope().
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'areas';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.areas.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.areas.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.areas.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return AreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AreaTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAreas::route('/'),
            'create' => CreateArea::route('/create'),
            'edit' => EditArea::route('/{record}/edit'),
        ];
    }

    /**
     * Searched through the fold-normalized blob, never a raw column.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
        ];
    }

    /**
     * Server-side re-validation of the zone's supervisors (a review follow-up).
     *
     * The supervisors Select is a Filament *relationship* field — it syncs from the component's
     * own state AFTER the model saves, so it never passes through the page's mutate hooks. A
     * crafted Livewire request can therefore attach a staff member the property-scoped picker
     * would never have offered (another mall's roster). So we re-validate AFTER the sync, from the
     * Create/Edit page's afterCreate/afterSave, against the same predicate the picker uses
     * (AreaForm::applySupervisorScope): assigned to this property, or property-less.
     *
     * Out-of-scope attaches are stripped (the DB is left clean) and the write is rejected with a
     * 403 — a restricted user must not attach staff who can't service the zone. Never a silent 500.
     */
    public static function assertSupervisorsInScope(Area $area): void
    {
        $attached = $area->supervisors()->pluck('users.id')->all();

        if ($attached === []) {
            return;
        }

        $inScope = AreaForm::applySupervisorScope(User::query()->whereKey($attached), $area->asset_id)
            ->pluck('id')
            ->all();

        $outOfScope = array_values(array_diff($attached, $inScope));

        if ($outOfScope !== []) {
            $area->supervisors()->detach($outOfScope);
            abort(403);
        }
    }
}
