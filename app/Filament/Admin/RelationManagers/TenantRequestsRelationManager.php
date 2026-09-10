<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Models\TenantRequest;
use App\Support\Filament\PropertyLink;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenantRequests';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.requests');
    }

    public function table(Table $table): Table
    {
        return $table
            // Property isolation: only requests on units in the user's visible properties.
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['unit', 'assignee'])
                ->latest('submitted_at')
                ->when(
                    TenantScope::visibleAssetIds(),
                    fn ($q, $ids) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $ids)),
                ))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.requests.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('title')
                    ->label(__('admin.tables.requests.title'))
                    ->limit(40),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.requests.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('priority')
                    ->label(__('admin.tables.requests.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.work_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted', 'in_progress' => 'info',
                        'acknowledged', 'awaiting_tenant' => 'warning',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.requests.submitted'))
                    ->date('d/m/Y'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant_request')),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.view'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // The property comes from the ROW, and on THIS tab that is belt and braces: it
                    // narrows with `TenantScope::visibleAssetIds()`, which answers the SELECTED
                    // property for any real tenant — super_admin included — so a row from another
                    // mall cannot reach the screen today. It is written the same way as the
                    // violations and sales tabs, which genuinely do span malls, because the answer
                    // to "which mall is this row in" should not depend on a scoping decision made
                    // in a different file, and because the gate requires it rather than keeping an
                    // exemption list of the tabs that happen to be narrow this week.
                    ->url(fn (TenantRequest $record): ?string => PropertyLink::to(TenantRequestResource::class, $record))
                    // A ROW WITH NO PROPERTY GETS NO BUTTON, and one in a mall this operator cannot
                    // enter gets none either — `PropertyLink::to()` answers null for both, and an
                    // *Open* that goes nowhere is worse than no *Open*.
                    ->visible(fn (TenantRequest $record): bool => PropertyLink::to(TenantRequestResource::class, $record) !== null),
            ])
            ->toolbarActions([])
            ->defaultSort('submitted_at', 'desc');
    }
}
