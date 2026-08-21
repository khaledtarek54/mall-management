<?php

namespace App\Filament\Portal\Resources\TenantRequests\Tables;

use App\Enums\TenantRequestType;
use App\Models\TenantRequest;
use App\Models\TenantRequestSubcategory;
use App\Services\TenantRequestService;
use App\Support\Portal;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
    /**
     * Named once so `visible()` and `abort_unless()` cannot drift — the double-gate rule.
     *
     * Read-only portal users may not write anything (`Portal::isAdmin()`), and only a `resolved`
     * request is open to a decision.
     */
    private static function tenantMayConfirm(TenantRequest $record): bool
    {
        return Portal::isAdmin()
            && in_array($record->status, TenantRequestService::CONFIRMABLE, true);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('unit'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.requests.reference'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('title')
                    ->label(__('admin.tables.requests.title'))
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.requests.unit'))
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
                    ->formatStateUsing(fn (?string $state, $record) => $state
                        ? TenantRequestSubcategory::labelFor($state, $record->request_type instanceof TenantRequestType ? $record->request_type : null)
                        : null),
                TextColumn::make('priority')
                    ->label(__('admin.tables.requests.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.work_priority.{$state}"))
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
                    ->label(__('admin.tables.requests.submitted'))
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
                // **The control ServiceChannel §6 calls out: the doer must not be the one who
                // closes the job.** Offered only while the request is `resolved` — confirming is a
                // control BEFORE closure, and there is nothing left to control once it is shut.
                //
                // ⚠️ visible() styles the page; the abort_unless() in action() is the gate.
                Action::make('confirmResolution')
                    ->label(__('admin.tenant_requests.confirm_resolution'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (TenantRequest $record) => __('admin.tenant_requests.confirm_modal_heading', ['ref' => $record->reference]))
                    // What was actually done, so nobody confirms a resolution they have not read.
                    ->modalDescription(fn (TenantRequest $record) => $record->resolution_notes
                        ?: __('admin.tenant_requests.confirm_no_notes'))
                    ->visible(fn (TenantRequest $record) => self::tenantMayConfirm($record))
                    ->action(function (TenantRequest $record) {
                        abort_unless(self::tenantMayConfirm($record), 403);

                        app(TenantRequestService::class)->confirmResolution($record, Portal::user());

                        Notification::make()->title(__('admin.tenant_requests.confirmed'))->success()->send();
                    }),

                Action::make('disputeResolution')
                    ->label(__('admin.tenant_requests.dispute_resolution'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->modalHeading(fn (TenantRequest $record) => __('admin.tenant_requests.dispute_modal_heading', ['ref' => $record->reference]))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.tenant_requests.dispute_reason'))
                            ->required()
                            ->rows(3)
                            ->maxLength(1000)
                            // Required for a reason: "not fixed" alone sends an engineer back
                            // knowing no more than the first time.
                            ->helperText(__('admin.tenant_requests.dispute_reason_hint')),
                    ])
                    ->visible(fn (TenantRequest $record) => self::tenantMayConfirm($record))
                    ->action(function (TenantRequest $record, array $data) {
                        abort_unless(self::tenantMayConfirm($record), 403);

                        app(TenantRequestService::class)
                            ->disputeResolution($record, Portal::user(), $data['reason']);

                        Notification::make()->title(__('admin.tenant_requests.disputed'))->warning()->send();
                    }),

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
            ->defaultSort('submitted_at', 'desc')
            ->emptyStateIcon('heroicon-o-wrench')
            ->emptyStateHeading(__('admin.empty.portal_tenant_requests.heading'))
            ->emptyStateDescription(__('admin.empty.portal_tenant_requests.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.portal_tenant_requests.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
