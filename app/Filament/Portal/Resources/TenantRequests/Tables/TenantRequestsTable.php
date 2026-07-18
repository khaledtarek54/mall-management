<?php

namespace App\Filament\Portal\Resources\TenantRequests\Tables;

use App\Enums\TenantRequestType;
use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use App\Support\Portal;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('unit'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.maintenance.reference'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('title')
                    ->label(__('admin.tables.maintenance.title'))
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.maintenance.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('request_type')
                    ->label(__('admin.fields.request_type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => ($state instanceof TenantRequestType ? $state : TenantRequestType::from((string) $state))->label()),
                TextColumn::make('category')
                    ->label(__('admin.fields.subcategory'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.tenant_request_subcategory.{$state}") : null),
                TextColumn::make('priority')
                    ->label(__('admin.tables.maintenance.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'info',
                        'acknowledged' => 'warning',
                        'in_progress' => 'primary',
                        'awaiting_tenant' => 'warning',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.maintenance.submitted'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant_request')),
                Filter::make('open_only')
                    ->label(__('admin.filters.open_only'))
                    ->query(fn (Builder $query) => $query->whereIn('status', TenantRequest::OPEN_STATUSES))
                    ->default(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('rate')
                    ->label(__('admin.actions.rate_request'))
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    // Tenant-admins only (read-only portal users can't write), and
                    // only once the request is resolved/closed. Re-opening the
                    // form lets them update an earlier rating.
                    //
                    // ⚠️ visible() hides the button; it does NOT stop the action being
                    // dispatched — Filament's mountAction() checks isDisabled(), never
                    // isVisible(). The abort_unless() in ->action() is the actual gate.
                    ->visible(fn (TenantRequest $record) => Portal::isAdmin()
                        && in_array($record->status, TenantRequestService::RATEABLE, true))
                    ->fillForm(fn (TenantRequest $record) => [
                        'csat_rating' => $record->csat_rating,
                        'csat_comment' => $record->csat_comment,
                    ])
                    ->schema([
                        Select::make('csat_rating')
                            ->label(__('admin.fields.csat_rating'))
                            ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★'])
                            ->required()
                            ->native(false),
                        Textarea::make('csat_comment')
                            ->label(__('admin.fields.csat_comment'))
                            ->rows(3)
                            ->maxLength(1000),
                    ])
                    ->action(function (TenantRequest $record, array $data) {
                        abort_unless(
                            Portal::isAdmin() && in_array($record->status, TenantRequestService::RATEABLE, true),
                            403,
                        );

                        app(TenantRequestService::class)
                            ->rate($record, (int) $data['csat_rating'], $data['csat_comment'] ?? null);

                        Notification::make()->title(__('admin.actions.rated'))->success()->send();
                    }),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}
