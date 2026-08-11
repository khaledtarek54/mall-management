<?php

namespace App\Filament\Admin\Resources\ApprovalRules\Tables;

use App\Filament\Admin\Resources\ApprovalRules\ApprovalRuleResource;
use App\Models\ApprovalRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ApprovalRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // No search box: five columns of amounts and a tier, a dozen rows at most. See
            // App\Support\SearchPolicy, where this resource is exempt with that reason.
            ->searchable(false)
            ->columns([
                TextColumn::make('module')
                    ->label(__('admin.fields.approval_module'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.approval_module.{$state}"))
                    ->sortable(),
                TextColumn::make('band')
                    ->label(__('admin.fields.approval_band'))
                    ->getStateUsing(fn (ApprovalRule $record) => $record->label())
                    ->fontFamily('mono'),
                TextColumn::make('required_permission')
                    ->label(__('admin.fields.approval_tier'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.approval_tier.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        ApprovalRule::TIER_3 => 'danger',
                        ApprovalRule::TIER_2 => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('module')
                    ->label(__('admin.fields.approval_module'))
                    ->options(fn () => collect(ApprovalRule::MODULES)
                        ->mapWithKeys(fn (string $m) => [$m => __("admin.enums.approval_module.{$m}")])
                        ->all()),
            ])
            ->recordActions([
                // Read it without opening the write form — less friction, and no write surface for
                // a view-only role. The modal schema is the resource's own form rendered disabled,
                // so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make()->visible(fn () => ApprovalRuleResource::canManage()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => ApprovalRuleResource::canDeleteAny()),
                ]),
            ])
            // The ladder reads as a ladder: module, then ascending amount.
            ->defaultSort('module')
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading(__('admin.empty.approval_rules.heading'))
            ->emptyStateDescription(__('admin.empty.approval_rules.description'));
    }
}
