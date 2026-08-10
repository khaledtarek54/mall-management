<?php

namespace App\Filament\Admin\Resources\AccountMappings\Tables;

use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Models\AccountMapping;
use App\Support\PostingRoles;
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

                TextColumn::make('account.name_ar')
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
            ]);
    }
}
