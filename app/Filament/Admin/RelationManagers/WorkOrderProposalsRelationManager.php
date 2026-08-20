<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\ApprovalRule;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\WorkOrderProposal;
use App\Services\WorkOrderProposalService;
use App\Support\ApprovalPolicy;
use App\Support\Modules;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * عروض الأسعار — quotes for work that will exceed the job's not-to-exceed amount.
 *
 * **The before-the-money control** (ServiceChannel §3). Approving one raises the NTE and sets the
 * job's estimate from the quote's own buckets, so the cost object's planned-vs-actual becomes
 * "did the contractor deliver what they quoted?".
 *
 * The contractor does not submit it themselves — that portal is gap O2 — so a quote is recorded by
 * the operator on their behalf, exactly as a vendor bill is.
 */
class WorkOrderProposalsRelationManager extends RelationManager
{
    protected static string $relationship = 'proposals';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.facility.proposal.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('facility') && (auth()->user()?->can('facility.view') ?? false);
    }

    private function order(): FacilityWorkOrder
    {
        return $this->getOwnerRecord();
    }

    /** Recording a contractor's quote is ordinary coordination — the same right as editing the job. */
    private function canRecord(): bool
    {
        return (auth()->user()?->can('facility.edit') ?? false) && ! $this->order()->isTerminal();
    }

    /**
     * **Deciding is a spending decision, and it goes through the same ladder every other one does.**
     *
     * Approving a quote commits the operator to a price, so who may approve depends on the AMOUNT —
     * exactly as it does for a purchase request. Without this a coordinator could authorise EGP
     * 200,000 of work that the same person could not have raised a purchase order for, which would
     * make the approval ladder a rule about paperwork rather than about money.
     */
    private function canDecide(WorkOrderProposal $proposal): bool
    {
        return (auth()->user()?->can('facility.edit') ?? false)
            && ApprovalPolicy::canApprove(auth()->user(), ApprovalRule::MODULE_PURCHASE_REQUEST, (float) $proposal->total_amount);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vendor_id')
                ->label(__('admin.facility.fields.vendor'))
                ->options(fn () => Vendor::assignableOptions($this->order()->vendor_id, $this->order()->trade_id))
                ->default(fn () => $this->order()->vendor_id)
                ->native(false)
                ->searchable()
                ->helperText(__('admin.facility.help.proposal_vendor')),

            // The cost object's own three buckets. Net of tax, like every other cost figure here.
            TextInput::make('labour_amount')->label(__('admin.facility.fields.est_labour_cost'))
                ->numeric()->minValue(0)->default(0)->prefix('EGP'),
            TextInput::make('material_amount')->label(__('admin.facility.fields.est_material_cost'))
                ->numeric()->minValue(0)->default(0)->prefix('EGP'),
            TextInput::make('service_amount')->label(__('admin.facility.fields.est_service_cost'))
                ->numeric()->minValue(0)->default(0)->prefix('EGP')
                ->helperText(__('admin.facility.help.proposal_amounts')),

            Textarea::make('scope')
                ->label(__('admin.facility.fields.proposal_scope'))
                ->rows(3)
                ->columnSpanFull()
                ->helperText(__('admin.facility.help.proposal_scope')),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['vendor', 'decidedBy']))
            ->columns([
                TextColumn::make('total_amount')
                    ->label(__('admin.facility.fields.proposal_total'))
                    ->money('EGP')
                    ->weight('bold')
                    ->description(fn (WorkOrderProposal $r): string => __('admin.facility.proposal.breakdown', [
                        'labour' => number_format((float) $r->labour_amount, 0),
                        'material' => number_format((float) $r->material_amount, 0),
                        'service' => number_format((float) $r->service_amount, 0),
                    ])),

                TextColumn::make('vendor.name')->label(__('admin.facility.fields.vendor'))->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.proposal.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        WorkOrderProposal::STATUS_APPROVED => 'success',
                        WorkOrderProposal::STATUS_REJECTED => 'danger',
                        WorkOrderProposal::STATUS_WITHDRAWN => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('decided_at')
                    ->label(__('admin.facility.fields.decided'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.facility.proposal.awaiting'))
                    ->description(fn (WorkOrderProposal $r): ?string => $r->decidedBy?->name),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.facility.proposal.record'))
                    ->visible(fn (): bool => $this->canRecord())
                    ->authorize(fn (): bool => $this->canRecord())
                    ->using(fn (array $data) => app(WorkOrderProposalService::class)->submit($this->order(), $data)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('admin.facility.proposal.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    // The operator reads what they are committing to before they commit.
                    ->modalDescription(fn (WorkOrderProposal $r) => __('admin.facility.proposal.approve_hint', [
                        'amount' => number_format((float) $r->total_amount, 2),
                        'nte' => number_format((float) $this->order()->nte_amount, 2),
                    ]))
                    ->visible(fn (WorkOrderProposal $r): bool => ! $r->isDecided() && $this->canDecide($r))
                    ->authorize(fn (WorkOrderProposal $r): bool => $this->canDecide($r))
                    ->action(function (WorkOrderProposal $record): void {
                        abort_unless($this->canDecide($record), 403);
                        $this->run(fn () => app(WorkOrderProposalService::class)->approve($record),
                            __('admin.facility.proposal.approved'));
                    }),

                Action::make('reject')
                    ->label(__('admin.facility.proposal.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.facility.fields.proposal_reason'))
                            ->required()
                            ->rows(2)
                            ->helperText(__('admin.facility.help.proposal_reason')),
                    ])
                    ->visible(fn (WorkOrderProposal $r): bool => ! $r->isDecided() && $this->canDecide($r))
                    ->authorize(fn (WorkOrderProposal $r): bool => $this->canDecide($r))
                    ->action(function (WorkOrderProposal $record, array $data): void {
                        abort_unless($this->canDecide($record), 403);
                        $this->run(fn () => app(WorkOrderProposalService::class)->reject($record, $data['reason']),
                            __('admin.facility.proposal.rejected'));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('admin.facility.proposal.empty'))
            ->emptyStateDescription(__('admin.facility.proposal.empty_hint'));
    }

    /** A refusal is a message that says what to do next, never a 500. */
    private function run(callable $act, string $success): void
    {
        try {
            $act();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
