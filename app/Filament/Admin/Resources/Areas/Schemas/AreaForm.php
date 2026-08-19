<?php

namespace App\Filament\Admin\Resources\Areas\Schemas;

use App\Models\User;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class AreaForm
{
    /**
     * The selected property, clamped to what the user may actually see.
     *
     * `asset_id` is **client-supplied** (the Select is enabled in All-Properties
     * mode and is `->live()`), so every query keyed on it — the supervisor options
     * and the unique rule — must go through `TenantScope::clampAssetId()`, which
     * returns null for a property the user cannot see. See that method for the two
     * traps (option enumeration + the unique-rule existence oracle) it collapses.
     */
    private static function inScopeAssetId(Get $get): ?int
    {
        return TenantScope::clampAssetId($get('asset_id'));
    }

    /**
     * The zone-supervisor scope: staff who can service a property's zones are those assigned to
     * that property, plus property-less users (super_admin / single-mall back-compat) — never
     * another mall's roster. A null property (none chosen / out of scope) offers nothing.
     *
     * The OR is deliberately grouped: an ungrouped whereHas()->orWhereDoesntHave() would let the OR
     * escape once the relationship's outer scope is applied, handing every property's roster to the
     * picker. Single source of truth for the picker, the post-save re-validation guard
     * (AreaResource::assertSupervisorsInScope) and its regression test.
     */
    public static function applySupervisorScope(Builder $query, ?int $assetId): Builder
    {
        if ($assetId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereHas('assignedAssets', fn (Builder $a) => $a->where('assets.id', $assetId))
            ->orWhereDoesntHave('assignedAssets'));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            PropertyField::make()
                ->label(__('admin.areas.fields.property'))
                ->live(),

            TextInput::make('code')
                ->label(__('admin.areas.fields.code'))
                ->helperText(__('admin.areas.code_hint'))
                ->required()
                ->maxLength(40)
                // Unique per property, matching the DB's areas_asset_code_unique. Clamped
                // through inScopeAssetId() for the same reason as Equipment/Warehouse: a
                // unique rule keyed on the raw client value is a one-bit existence oracle
                // over another property's codes. null (out of scope) collapses it.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', self::inScopeAssetId($get))),

            TextInput::make('name')
                ->label(__('admin.areas.fields.name'))
                ->required()
                ->maxLength(255),

            Toggle::make('is_active')
                ->label(__('admin.areas.fields.active'))
                ->default(true),

            EntitySelect::make('supervisors')
                ->label(__('admin.areas.fields.supervisors'))
                ->helperText(__('admin.areas.supervisors_hint'))
                ->entity(User::class)
                // Scoped to the selected property's staff so a restricted user never sees
                // another mall's roster (mirrors CorrectiveWorkOrderForm::technicianOptions):
                // users assigned to this property, plus property-less users (super_admin /
                // single-mall back-compat). Grouped deliberately — an ungrouped
                // whereHas()->orWhereDoesntHave() would let the OR escape once the outer
                // asset scope is applied, handing every property's roster to the picker.
                ->relationship('supervisors')
                ->modifyOptionsQuery(fn (Builder $query, Get $get) => self::applySupervisorScope($query, self::inScopeAssetId($get)))
                ->multiple()
                ->native(false)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label(__('admin.areas.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
