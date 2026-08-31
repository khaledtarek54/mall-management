<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\LeaseCamTerm;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-lease CAM cap terms — the ceiling a tenant's CAM cost share can't exceed. Effective-dated:
 * the row with the greatest effective_year ≤ a reconciliation year governs that year. Consumed by
 * CamReconciliationService (caps the true-up + admin-fee base only; allocated stays uncapped).
 *
 * Write access is gated on `leases.edit` (both visible() and authorize(), per the authz
 * invariant — a hidden action is still dispatchable, so authorize() is the real gate); delete is
 * super_admin-only, matching the project-wide delete policy.
 */
class LeaseCamTermsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'camTerms';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.tables.cam.cap_terms');
    }

    private static function canWrite(): bool
    {
        return (bool) auth()->user()?->can('leases.edit');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('effective_year')
                ->label(__('admin.fields.cam_effective_year'))
                ->required()
                ->numeric()
                ->minValue(2020)
                ->maxValue(2099)
                ->default(fn () => now()->year)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('lease_id', $this->getOwnerRecord()->getKey()))
                ->helperText(__('admin.helpers.cam_effective_year')),
            // NOT required. A term may state a SHARE and no ceiling — the percentage the parties
            // simply agreed — which is what the column was made nullable for on 2026-08-23; the
            // form kept requiring it, so the row the table exists to hold was unreachable from the
            // panel and an operator recording "your share is 8%" had to INVENT a cap that then
            // really bit. Null is also how a cap is ENDED: a later term stating none supersedes an
            // earlier ceiling from its own year on, which deleting the old term cannot express.
            //
            // The placeholder carries that meaning. Filament's own "Select an option" would read as
            // an unanswered question rather than as an answer.
            Select::make('cap_type')
                ->label(__('admin.fields.cam_cap_type'))
                ->helperText(__('admin.lease_cam_terms.help.cap_type'))
                ->options(fn () => __('admin.enums.cam_cap_type'))
                ->placeholder(__('admin.lease_cam_terms.no_cap'))
                ->native(false)
                ->live()
                ->default('absolute'),
            // RC-07: what the cap actually bites on, and whether it accumulates. Both default to
            // the legacy behaviour so an existing term keeps capping exactly what it capped.
            Select::make('cap_scope')
                ->label(__('admin.fields.cam_cap_scope'))
                ->helperText(__('admin.helpers.cam_cap_scope'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.cam_cap_scope'))
                ->options([
                    LeaseCamTerm::SCOPE_TOTAL => __('admin.enums.cam_cap_scope.total'),
                    LeaseCamTerm::SCOPE_CONTROLLABLE => __('admin.enums.cam_cap_scope.controllable'),
                ])
                ->default(LeaseCamTerm::SCOPE_TOTAL)
                ->required()
                ->native(false)
                // `filled()`, not `!== 'none'`: 'none' was never a value in `cam_cap_type` — the
                // enum is absolute|yoy|both — so both of these read TRUE for every term ever
                // written and hid nothing. Scope and carry-forward describe how a CEILING bites, so
                // on a share-only term they are two controls answering a question nobody asked.
                ->visible(fn (Get $get) => filled($get('cap_type'))),
            Toggle::make('cap_carry_forward')
                ->label(__('admin.fields.cam_cap_carry_forward'))
                ->helperText(__('admin.helpers.cam_cap_carry_forward'))
                ->visible(fn (Get $get) => filled($get('cap_type'))),
            TextInput::make('stated_share_pct')
                ->label(__('admin.fields.cam_stated_share_pct'))
                ->helperText(__('admin.helpers.cam_stated_share_pct'))
                ->suffix('%')
                ->numeric()
                ->minValue(0)
                ->maxValue(100),
            TextInput::make('cap_absolute_amount')
                ->label(__('admin.fields.cam_cap_absolute_amount'))
                ->helperText(__('admin.lease_cam_terms.help.cap_absolute_amount'))
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->visible(fn (Get $get) => in_array($get('cap_type'), ['absolute', 'both'], true))
                ->required(fn (Get $get) => in_array($get('cap_type'), ['absolute', 'both'], true)),
            TextInput::make('base_year')
                ->label(__('admin.fields.cam_base_year'))
                ->helperText(__('admin.lease_cam_terms.help.base_year'))
                ->numeric()
                ->minValue(2000)
                ->maxValue(2099)
                ->visible(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true))
                ->required(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true)),
            TextInput::make('base_year_amount')
                ->label(__('admin.fields.cam_base_year_amount'))
                ->helperText(__('admin.lease_cam_terms.help.base_year_amount'))
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->visible(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true))
                ->required(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true)),
            // Operators enter percent (5); stored as a fraction (0.05).
            TextInput::make('yoy_pct')
                ->label(__('admin.fields.cam_yoy_pct'))
                ->helperText(__('admin.lease_cam_terms.help.yoy_pct'))
                ->suffix('%')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step('0.01')
                ->visible(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true))
                ->required(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true))
                ->formatStateUsing(fn ($state) => $state === null ? null : round((float) $state * 100, 4))
                ->dehydrateStateUsing(fn ($state) => ($state === null || $state === '') ? null : round((float) $state / 100, 6)),
            Toggle::make('compounding')
                ->label(__('admin.fields.cam_compounding'))
                ->helperText(__('admin.lease_cam_terms.help.compounding'))
                ->default(true)
                ->visible(fn (Get $get) => in_array($get('cap_type'), ['yoy', 'both'], true)),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->helperText(__('admin.lease_cam_terms.help.cam_notes'))
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: LeaseCamTerm carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('effective_year')
                    ->label(__('admin.fields.cam_effective_year'))
                    ->sortable(),
                // A term with no ceiling SUPERSEDES an earlier one from its own year on, so the
                // cell must read as a decision rather than as a gap. Filament skips
                // formatStateUsing for a null state and renders the placeholder instead.
                TextColumn::make('cap_type')
                    ->label(__('admin.fields.cam_cap_type'))
                    ->badge()
                    ->placeholder(__('admin.lease_cam_terms.no_cap'))
                    ->formatStateUsing(fn (string $state) => __("admin.enums.cam_cap_type.{$state}")),
                TextColumn::make('cap_absolute_amount')
                    ->label(__('admin.fields.cam_cap_absolute_amount'))
                    ->money('EGP', divideBy: 1)
                    ->placeholder('—'),
                TextColumn::make('yoy_pct')
                    ->label(__('admin.fields.cam_yoy_pct'))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : round((float) $state * 100, 2).'%'),
            ])
            ->defaultSort('effective_year', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_cam_term'))
                    ->modalHeading(__('admin.actions.add_cam_term'))
                    ->visible(fn () => self::canWrite())
                    ->authorize(fn () => self::canWrite()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => self::canWrite())
                    ->authorize(fn () => self::canWrite()),
                DeleteAction::make()
                    ->visible(fn () => (bool) auth()->user()?->hasRole('super_admin'))
                    ->authorize(fn () => (bool) auth()->user()?->hasRole('super_admin')),
            ]);
    }
}
