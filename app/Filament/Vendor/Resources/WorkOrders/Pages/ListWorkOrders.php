<?php

namespace App\Filament\Vendor\Resources\WorkOrders\Pages;

use App\Filament\Vendor\Resources\WorkOrders\WorkOrderResource;
use App\Models\FacilityWorkOrder;
use App\Services\AcceptWorkOrderService;
use App\Support\Filament\VendorScope;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The contractor's job list, and the **accept** verb.
 *
 * Accept is the highest-value action in the portal: `acknowledged_at` is what the response SLA is
 * measured to, and until now it was stamped when a coordinator moved the job to `in_progress` — so
 * the response time this system reports has been *when staff updated a column*, not when the
 * contractor agreed.
 */
class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('vendor.jobs.reference'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('title')
                    ->label(__('vendor.jobs.title'))
                    ->wrap()
                    ->limit(80),
                // The PROPERTY, not a property filter. A contractor works across malls, so the
                // question they need answered is "where am I going", never "which mall am I in" —
                // `docs/PROPERTY-ISOLATION.md` deliberately does not apply here.
                TextColumn::make('asset.name')
                    ->label(__('vendor.jobs.property')),
                TextColumn::make('priority')
                    ->label(__('vendor.jobs.priority'))
                    ->badge(),
                TextColumn::make('scheduled_for')
                    ->label(__('vendor.jobs.scheduled_for'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('acknowledged_at')
                    ->label(__('vendor.jobs.accepted_at'))
                    ->dateTime('d/m/Y H:i')
                    // A job nobody has accepted yet is the one thing this screen exists to surface,
                    // so it says so rather than showing an empty cell that reads as missing data.
                    ->placeholder(__('vendor.jobs.not_accepted'))
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->label(__('vendor.jobs.status'))
                    ->badge(),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label(__('vendor.jobs.accept'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('vendor.jobs.accept_confirm'))
                    // Shown only where it means something: a job already accepted, or one that has
                    // reached a terminal state, has nothing left to agree to.
                    ->visible(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record)
                        && $record->acknowledged_at === null
                        && ! $record->isTerminal())
                    // Declared, not just enforced. `assertOwned()` aborts inside a helper, which is a
                    // real gate but an invisible one — `ActionAuthzConformanceTest` reads the chain,
                    // and so does a reviewer. Stating the intent here is what stops the next person
                    // assuming `visible()` was the whole of it.
                    ->authorize(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record))
                    ->action(function (FacilityWorkOrder $record): void {
                        // **Layer 3 — the gate.** The list above is narrowed and the button is
                        // hidden, and neither is a gate: the Livewire payload still carries an id.
                        // 404, never 403 — a 403 confirms the job exists.
                        VendorScope::assertOwned($record);

                        app(AcceptWorkOrderService::class)->accept($record, VendorScope::contact());

                        Notification::make()
                            ->success()
                            ->title(__('vendor.jobs.accepted'))
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('scheduled_for', 'asc')
            ->emptyStateHeading(__('vendor.jobs.empty_heading'))
            ->emptyStateDescription(__('vendor.jobs.empty_description'));
    }
}
