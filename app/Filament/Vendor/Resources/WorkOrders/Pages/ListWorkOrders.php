<?php

namespace App\Filament\Vendor\Resources\WorkOrders\Pages;

use App\Filament\Actions\EvidenceUpload;
use App\Filament\Vendor\Resources\WorkOrders\WorkOrderResource;
use App\Models\FacilityWorkOrder;
use App\Services\AcceptWorkOrderService;
use App\Services\CommentOnWorkOrderService;
use App\Services\WorkOrderProposalService;
use App\Support\Filament\VendorScope;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                // ── EVIDENCE (step 4). A surface over the `evidence` collection built 2026-08-19,
                // and the reason the portal is worth having for a job already done: the photographs
                // reach the operator from the person who took them, rather than arriving on
                // WhatsApp and being re-uploaded by a coordinator who was not there.
                //
                // Allowed on a job the contractor has ACCEPTED and on one already done — evidence
                // often arrives after the fact, and refusing it then would push it back to WhatsApp,
                // which is the behaviour this replaces. Refused on `cancelled`: there is nothing to
                // evidence.
                Action::make('evidence')
                    ->label(__('vendor.jobs.evidence'))
                    ->icon('heroicon-o-camera')
                    ->color('gray')
                    ->visible(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record)
                        && $record->status !== 'cancelled')
                    ->authorize(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record))
                    ->schema([
                        // APPEND, never replace. A contractor adding a second photograph must not
                        // silently delete the first — the operator's completion gate reads this
                        // collection, and a replace would let a later upload erase the evidence an
                        // earlier decision rested on.
                        //
                        // That promise used to be made by `->appendFiles()` alone, which does not
                        // keep it: it governs the browser widget, while the save runs
                        // `deleteAbandonedFiles()` against a modal state that nothing hydrated.
                        // Measured — a second upload really did delete the first. `EvidenceUpload`
                        // is the one definition, shared with the operator's door on the admin table
                        // so the two cannot drift or erase each other's work.
                        EvidenceUpload::make()
                            ->label(__('vendor.jobs.evidence'))
                            ->helperText(__('vendor.jobs.evidence_helper')),
                    ])
                    ->action(function (FacilityWorkOrder $record): void {
                        // The upload component writes the media; this only refuses an unauthorised
                        // dispatch. 404, not 403 — same rule as accept.
                        VendorScope::assertOwned($record);

                        Notification::make()->success()->title(__('vendor.jobs.evidence_attached'))->send();
                    }),

                // ── UPDATE (step 4). The thread built in step 1, from the contractor's side.
                // A contractor's comment is ALWAYS public — `is_internal` is the operator's tool for
                // writing something the contractor must not read, and offering them the toggle would
                // let them post a note their own client cannot see, which is nonsense on a job the
                // client is paying for.
                Action::make('update')
                    ->label(__('vendor.jobs.update'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('gray')
                    ->visible(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record)
                        && ! $record->isTerminal())
                    ->authorize(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record))
                    ->schema([
                        Textarea::make('body')
                            ->label(__('vendor.jobs.update_body'))
                            ->required()
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->action(function (FacilityWorkOrder $record, array $data): void {
                        VendorScope::assertOwned($record);

                        app(CommentOnWorkOrderService::class)->comment(
                            $record,
                            VendorScope::contact(),
                            (string) $data['body'],
                            // Never internal. See above.
                            isInternal: false,
                        );

                        Notification::make()->success()->title(__('vendor.jobs.update_posted'))->send();
                    }),

                // ── QUOTE (step 5). `WorkOrderProposalService` unchanged except for WHO submitted:
                // an operator keying it on the phone writes `submitted_by_user_id`, a contractor
                // sending it writes `submitted_by_vendor_contact_id`. The approval ladder, the NTE
                // rise and every refusal are the same service the admin side uses — a second path
                // would be a second set of rules about money.
                Action::make('quote')
                    ->label(__('vendor.jobs.quote'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->modalDescription(__('vendor.jobs.quote_confirm'))
                    ->visible(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record)
                        && ! $record->isTerminal())
                    ->authorize(fn (FacilityWorkOrder $record): bool => VendorScope::owns($record))
                    ->schema([
                        Toggle::make('is_supplementary')
                            ->label(__('vendor.jobs.quote_supplementary'))
                            ->helperText(__('vendor.jobs.quote_supplementary_helper'))
                            ->default(false)
                            ->columnSpanFull(),
                        TextInput::make('labour_amount')->label(__('vendor.jobs.quote_labour'))
                            ->numeric()->minValue(0)->default(0)->prefix('EGP'),
                        TextInput::make('material_amount')->label(__('vendor.jobs.quote_material'))
                            ->numeric()->minValue(0)->default(0)->prefix('EGP'),
                        TextInput::make('service_amount')->label(__('vendor.jobs.quote_service'))
                            ->numeric()->minValue(0)->default(0)->prefix('EGP')
                            ->helperText(__('vendor.jobs.quote_amounts_helper')),
                        Textarea::make('scope')
                            ->label(__('vendor.jobs.quote_scope'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->action(function (FacilityWorkOrder $record, array $data): void {
                        VendorScope::assertOwned($record);

                        try {
                            app(WorkOrderProposalService::class)->submit(
                                $record,
                                // `vendor_id` is NOT taken from the form: the service defaults it to
                                // the contractor already on the job, and letting a portal payload
                                // state one would let a contractor file a quote against another
                                // company's name.
                                collect($data)->only([
                                    'is_supplementary', 'labour_amount', 'material_amount',
                                    'service_amount', 'scope',
                                ])->all(),
                                VendorScope::contact(),
                            );
                        } catch (DomainException $e) {
                            // A refusal is a message, not a 500 — "a quote for nothing is not a
                            // quote" is the one a contractor will actually meet.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('vendor.jobs.quote_sent'))->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('scheduled_for', 'asc')
            ->emptyStateHeading(__('vendor.jobs.empty_heading'))
            ->emptyStateDescription(__('vendor.jobs.empty_description'));
    }
}
