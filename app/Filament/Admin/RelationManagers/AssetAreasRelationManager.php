<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Areas\Schemas\AreaForm;
use App\Models\Area;
use App\Models\User;
use App\Support\Filament\EntitySelect;
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
 * **The SUPERVISOR picker is offered here too, since 2026-09-10.** It was not, for a stated reason:
 * the scope reads the property from the register form's own field, which this manager pins rather
 * than shows, so *"who covers them is set on the zone's own screen, one click away"*. That reason
 * was the weaker half of the decision — the `unique` rule on `code` above already scopes itself
 * off `$this->getOwnerRecord()`, and the picker can do exactly the same — and the cost of the
 * omission was not cosmetic: a zone routes (`TenantRequest` and `FacilityWorkOrder` both fan out to
 * its supervisors), so a zone created here and never opened again routed to NOBODY, silently. The
 * write-surface gate registered it as a divergence; this closes it.
 *
 * Yardi's shape, and this repo's own reading of it (`docs/benchmarks/yardi/08`): a sub-screen that
 * CREATES a record collects the same attributes as the register does. One definition of a zone.
 *
 * **Same scope, same guard, one seam.** The picker reads `AreaForm::applySupervisorScope()` with the
 * OWNER's id, so this tab and the register can never offer different rosters; and the post-save
 * re-validation `AreaResource::assertSupervisorsInScope()` runs from the action's `after()`, exactly
 * as `CreateArea`/`EditArea` run it, because a relationship field syncs from component state after
 * the model saves and the option list is a convenience, not a gate.
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
            EntitySelect::make('supervisors')
                ->label(__('admin.areas.fields.supervisors'))
                ->helperText(__('admin.areas.supervisors_hint'))
                ->entity(User::class)
                ->relationship('supervisors')
                // The OWNER is the property — no form field to read and nothing the client can
                // substitute, which is a stronger footing than the register form has.
                ->modifyOptionsQuery(fn ($query) => AreaForm::applySupervisorScope($query, (int) $this->getOwnerRecord()->getKey()))
                ->multiple()
                ->native(false)
                ->columnSpanFull(),
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
                    ->authorize(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    // **Transactional, as the register's CreateRecord page already is.** The
                    // guard below runs AFTER the row commits, and a payload that reaches it — a
                    // SCALAR id slips Filament's array validation — is stripped and 403'd; without
                    // the transaction that 403 left an orphaned zone behind. Found by review.
                    ->databaseTransaction()
                    // The option list is not the gate — the ids still arrive in the payload.
                    ->after(fn (Area $record) => AreaResource::assertSupervisorsInScope($record)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    ->databaseTransaction()
                    ->after(fn (Area $record) => AreaResource::assertSupervisorsInScope($record)),
                // A zone is `#[DeletionAllowed]` configuration, so the project-wide rule applies:
                // delete is super_admin only.
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false),
            ]);
    }
}
