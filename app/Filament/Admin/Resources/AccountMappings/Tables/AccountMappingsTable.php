<?php

namespace App\Filament\Admin\Resources\AccountMappings\Tables;

use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Models\AccountMapping;
use App\Support\PostingRoles;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AccountMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')
                    ->label(__('admin.fields.posting_role'))
                    ->formatStateUsing(fn (string $state) => PostingRoles::label($state))
                    ->description(fn (AccountMapping $r) => ($g = PostingRoles::group($r->key))
                        ? PostingRoles::groupLabel($g)
                        : __('admin.posting_map.unknown_role'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account.code')
                    ->label(__('admin.fields.account_code'))
                    ->searchable()
                    ->sortable(),

                // The chart is bilingual and this column read `name_ar` for EVERY reader, so an
                // English session read the posting map in Arabic — the same defect this screen's
                // own account picker was corrected for (AccountMappingForm, `modifyOptionsQuery`
                // comment). `LedgerAccount::displayName()` is the one seam that answers for the
                // reader's locale, and it falls back to the other name so a half-translated
                // imported chart still prints something.
                //
                // The column KEEPS its `account.name_ar` name deliberately: Filament derives both
                // the eager load (`Column::applyEagerLoading()`, off the relationship in the name)
                // and a saved column layout's key from it, so renaming would drop the eager load
                // and orphan every stored layout. Only the STATE moves.
                //
                // Measured on the dev database 2026-09-04: 167 accounts, every one carrying both
                // names, so the only symptom today is the language — not a blank cell.
                TextColumn::make('account.name_ar')
                    ->state(fn (AccountMapping $r) => $r->account?->displayName())
                    ->label(__('admin.fields.ledger_account'))
                    ->wrap(),

                // Advisory, not a rule. The seeded chart types every role the way you would expect,
                // so a mismatch is nearly always a mis-pick — but it is shown rather than refused,
                // because a real chart legitimately disagrees in places (`deferred_rent` swings
                // between an asset and a liability with the sign of the straight-line adjustment).
                TextColumn::make('account.type')
                    ->label(__('admin.fields.account_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.ledger_account_type.{$state}") : '—')
                    ->color(fn (?string $state, AccountMapping $r) => $state !== null && $state === PostingRoles::group($r->key)
                        ? 'gray'
                        : 'warning')
                    ->tooltip(fn (?string $state, AccountMapping $r) => $state !== null && $state === PostingRoles::group($r->key)
                        ? null
                        : __('admin.helpers.posting_map_type_mismatch')),

                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->placeholder(__('admin.posting_map.global'))
                    ->badge()
                    ->color(fn (?string $state) => $state === null ? 'gray' : 'info')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label(__('admin.fields.updated_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label(__('admin.filters.posting_role_group'))
                    ->options(fn () => collect([
                        PostingRoles::GROUP_ASSET,
                        PostingRoles::GROUP_LIABILITY,
                        PostingRoles::GROUP_EQUITY,
                        PostingRoles::GROUP_REVENUE,
                        PostingRoles::GROUP_EXPENSE,
                    ])->mapWithKeys(fn (string $g) => [$g => PostingRoles::groupLabel($g)])->all())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('key', PostingRoles::keysIn($data['value']))
                        : $query),

                TernaryFilter::make('is_override')
                    ->label(__('admin.filters.posting_map_scope'))
                    ->placeholder(__('admin.filters.posting_map_scope_all'))
                    ->trueLabel(__('admin.filters.posting_map_scope_override'))
                    ->falseLabel(__('admin.filters.posting_map_scope_global'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('asset_id'),
                        false: fn ($query) => $query->whereNull('asset_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                // Read the row without opening a write surface — the schema is the resource's own
                // form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make()
                    ->visible(fn (AccountMapping $record) => AccountMappingResource::canView($record)),
                EditAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-link')
            ->emptyStateHeading(__('admin.empty.account_mappings.heading'))
            ->emptyStateDescription(__('admin.empty.account_mappings.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.account_mappings.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
