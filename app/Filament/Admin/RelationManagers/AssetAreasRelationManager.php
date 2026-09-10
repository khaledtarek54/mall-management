<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\Area;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The property's ZONES — how a mall is divided up, set up where the mall is.
 *
 * Asked for by the tester: *"Add a Zones setting in Property edit/create to divide the property into
 * zones."* Zones already existed — `Area` (module 30) has carried `asset_id`, a per-property unique
 * code and a set of supervisors since it shipped — and were reachable ONLY from their own register
 * under Setup. So nothing new is invented here: what was missing is the door, on the record the
 * zones belong to.
 *
 * It was also already claimed. `AssetFloorsRelationManager`'s docblock states in writing that
 * *"units and zones are already managed this way"* — a comment describing a screen nobody had built,
 * which is exactly how an absence stays invisible until somebody goes looking for the feature.
 *
 * **What a zone is FOR, and why it is not another way of slicing area.** A zone routes: supervisors
 * attached here are who an incoming request or work order in that part of the mall goes to, and
 * `units.area_id` says which zone a shop stands in. It is deliberately NOT a share of the GLA —
 * floors already carry the area arithmetic (`Floor::areaFigures()`), and a second thing that looks
 * like it apportions space would be a second answer to a question CAM already settles.
 *
 * The SUPERVISOR picker is not offered here. It is scoped to the property's own roster through
 * `AreaForm::applySupervisorScope()`, which reads the property from the FORM's own field — a field
 * this manager pins rather than shows — so wiring it correctly belongs with that form. Zones are
 * created and named here; who covers them is set on the zone's own screen, one click away.
 */
class AssetAreasRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'areas';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        // The key the AREAS REGISTER labels itself from, not a second one for the same noun. The
        // tab shipped pointing at `admin.navigation.areas`, which exists in neither catalogue, so
        // it rendered the RAW KEY as its title in both languages — the failure `Lang::has()` cannot
        // see and `TranslationKeyConformanceTest` exists to catch.
        return __('admin.areas.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.areas.fields.code'))
                ->helperText(__('admin.areas.code_hint'))
                ->required()
                ->maxLength(40)
                // Unique per PROPERTY, matching the DB's `areas_asset_code_unique` — every mall has
                // a food court. Keyed on the owner record rather than on anything the client sent,
                // so it cannot be used as an existence oracle over another property's codes.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('asset_id', $this->getOwnerRecord()->getKey()),
                ),
            TextInput::make('name')
                ->label(__('admin.areas.fields.name'))
                ->required()
                ->maxLength(255),
            Toggle::make('is_active')
                ->label(__('admin.areas.fields.active'))
                ->helperText(__('admin.areas.active_hint'))
                ->default(true),
            Textarea::make('notes')
                ->label(__('admin.areas.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->defaultSort('code')
            // A mall has a handful of zones, all visible at once — and `Area`'s blob is not reachable
            // through this relation's own query, so a box here could only ever answer nothing.
            ->searchable(false)
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.areas.fields.code'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.areas.fields.name'))
                    ->sortable(),
                TextColumn::make('units_count')
                    ->label(__('admin.resources.unit.plural'))
                    ->counts('units')
                    ->badge(),
                TextColumn::make('supervisors_count')
                    ->label(__('admin.areas.fields.supervisor_count'))
                    ->counts('supervisors')
                    ->badge()
                    ->color('info'),
                IconColumn::make('is_active')
                    ->label(__('admin.areas.fields.active'))
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.areas.actions.add'))
                    ->modalHeading(__('admin.areas.actions.add'))
                    ->visible(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can('assets.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can('assets.edit') ?? false),
                // A zone is `#[DeletionAllowed]` configuration, so the project-wide rule applies:
                // delete is super_admin only.
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false),
            ]);
    }
}
