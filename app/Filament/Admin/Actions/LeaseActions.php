<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\Unit;
use App\Services\AssignRentableItemService;
use App\Services\BillSecurityDepositService;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\ExerciseLeaseOptionService;
use App\Services\LeaseExtensionService;
use App\Services\LeaseReliefService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseRentChangeService;
use App\Services\LeaseSpaceChangeService;
use App\Services\LeaseTerminationService;
use App\Services\MoveOutStatementService;
use App\Services\SettleMoveOutService;
use App\Settings\BillingSettings;
use App\Support\Filament\EntitySelect;
use App\Support\LeaseTerm;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * **Everything you can DO to a lease, defined once.**
 *
 * The commercial acts on a tenancy — renew, change rent, grant relief, change premises, let and give
 * back parking, convert to holdover, terminate, settle the final account.
 *
 * **Why they moved here.** They lived inline in `LeasesTable`, so the LIST carried nine row actions
 * while the lease's own page carried one: an operator who opened a lease could do almost nothing
 * without going back to the list. That is backwards from the record-hub information architecture
 * this project took from Yardi (`docs/benchmarks/yardi/08`) — **the list finds, the record acts** —
 * and with the definitions living in one surface only the two could never be kept in step. Adding an
 * action in one place silently left the other behind, which is exactly what happened.
 *
 * One definition, both surfaces, composed by name. `Filament\Actions\Action` is a single class for
 * a table row and a page header in v4, which is what makes this possible without a wrapper.
 */
class LeaseActions
{
    /**
     * Every act, in the order a tenancy meets them.
     *
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            Action::make('renew')
                ->label(__('admin.actions.renew'))
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn ($record) => $record->status === 'active' && LeaseResource::canEdit($record) && auth()->user()?->can('leases.renew'))
                ->authorize(fn () => auth()->user()?->can('leases.renew') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.actions.renew_modal_heading', ['ref' => $record->reference]))
                ->modalDescription(function (Lease $record): string {
                    $base = __('admin.actions.renew_modal_description', ['ends' => $record->expiry_date->format('d/m/Y')]);
                    $terms = app(ExerciseLeaseOptionService::class)->pendingRenewalTerms($record);

                    // Say WHERE the pre-filled numbers came from. A form that silently arrives
                    // with different figures than last time is one an operator stops trusting.
                    return $terms === null ? $base : $base.' '.__('admin.actions.renew_from_option', [
                        'basis' => __("admin.lease_options.rent_bases.{$terms['option']->rent_basis}"),
                    ]);
                })
                // Pre-filled from an EXERCISED option where there is one (story OP-04). The
                // option already knows the contracted term and rent; before this the operator
                // read them off the screen and re-typed them, which is how a five-year renewal
                // at a contracted +10% gets billed at the old rent.
                //
                // A `market` or `cpi` basis yields no rent — a valuation and an index feed are
                // not numbers this system may invent — so those fall back to the current rent
                // for the operator to overwrite, exactly as the escalation sweep refuses CPI.
                ->fillForm(function (Lease $record): array {
                    $terms = app(ExerciseLeaseOptionService::class)->pendingRenewalTerms($record);

                    return [
                        'new_term_months' => $terms['term_months'] ?? $record->term_months,
                        'new_rent' => $terms['rent'] ?? (float) $record->base_rent_monthly,
                        'new_service_charge' => (float) $record->service_charge_monthly,
                        'commencement_date' => ($terms['commencement'] ?? $record->expiry_date->copy()->addDay())
                            ->toDateString(),
                    ];
                })
                ->schema([
                    TextInput::make('new_term_months')
                        ->label(__('admin.fields.new_term_months'))
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->live(onBlur: true),
                    TextInput::make('new_rent')
                        ->label(__('admin.fields.new_rent'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('new_service_charge')
                        ->label(__('admin.fields.new_service_charge'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    DatePicker::make('commencement_date')
                        ->label(__('admin.fields.commencement_date'))
                        ->required()
                        ->live(onBlur: true),

                    // The renewal's end date, shown before it is committed. The modal took a
                    // term and a commencement and never displayed the expiry they produce, so
                    // an operator agreed a new contract without seeing when it ends — and the
                    // service derives it from exactly these two, so there is nothing to type.
                    Placeholder::make('new_expiry_preview')
                        ->label(__('admin.fields.expiry_date'))
                        ->content(fn (Get $get) => ($expiry = LeaseTerm::expiryFrom(
                            $get('commencement_date'),
                            $get('new_term_months'),
                        )) !== null
                            ? CarbonImmutable::parse($expiry)->format('d/m/Y')
                            : '—'),
                ])
                ->action(function (Lease $record, array $data) {
                    try {
                        $renewal = app(LeaseRenewalService::class)->renew($record, $data);
                    } catch (\InvalidArgumentException $e) {
                        // A stale/concurrent modal (the lease changed state since it opened) hits the
                        // service status guard — surface it as a toast, not an uncaught Livewire 500.
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.actions.lease_renewed'))
                        ->body(__('admin.actions.lease_renewed_body', [
                            'ref' => $renewal->reference,
                            'months' => $renewal->term_months,
                            'start' => $renewal->commencement_date->format('d/m/Y'),
                        ]))
                        ->success()
                        ->send();
                }),
            // **Record a deposit movement from the LEASE**, which is where the question is asked.
            //
            // It was register-only, and the relation manager on the lease said why: "a create button
            // here would be a second way to move money, thinner than the first." That reasoning does
            // not survive the facts — every guard is on the MODEL (`GuardsPostingDate`,
            // `AllocatesDocumentNumber`, the ValueSets listener, the GL registry), so an action that
            // creates a `DepositTransaction` inherits all of them and is not thinner at all. It is
            // also the opposite of what this class exists for: the list finds, the RECORD acts
            // (docs/benchmarks/yardi/08), which is why terminate, renew and settle — all of which
            // move money — already live here.
            //
            // Cash at the counter is now the EXCEPTION rather than the rule: bill the deposit and it
            // collects itself through the ordinary rail. This is for the cheque handed to the leasing
            // office, and for the refund and forfeit that close it out.
            Action::make('recordDeposit')
                ->label(__('admin.deposits.record'))
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn (Lease $record) => LeaseResource::canEdit($record)
                    && (auth()->user()?->can('deposit_transactions.create') ?? false))
                ->authorize(fn () => auth()->user()?->can('deposit_transactions.create') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.deposits.record_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.deposits.record_description'))
                ->schema([
                    Placeholder::make('position')
                        ->label(__('admin.deposits.outstanding'))
                        ->content(fn (Lease $record) => __('admin.tables.lease.deposit_held_of', [
                            'held' => number_format($record->depositHeld(), 2),
                            'agreed' => number_format((float) $record->security_deposit, 2),
                        ])),
                    Select::make('type')
                        ->label(__('admin.fields.type'))
                        ->options(fn () => __('admin.enums.deposit_type'))
                        ->default('receipt')
                        ->required()
                        ->live()
                        ->native(false),
                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        ->options(fn () => __('admin.enums.method'))
                        ->default('bank_transfer')
                        // A forfeit moves nothing through a bank — it turns the landlord's liability
                        // into income, so asking "by what method" would be a question with no answer.
                        ->visible(fn (Get $get) => $get('type') !== 'forfeit')
                        ->requiredUnless('type', 'forfeit')
                        ->native(false),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        // Pre-filled with what is missing on a receipt, and with what is HELD on a
                        // refund or forfeit — you cannot give back more than you have.
                        ->default(fn (Lease $record) => $record->depositShortfall() ?: null)
                        ->helperText(fn (Get $get, Lease $record) => in_array($get('type'), ['refund', 'forfeit'], true)
                            ? __('admin.deposits.max_held', ['held' => number_format($record->depositHeld(), 2)])
                            : null),
                    DatePicker::make('transaction_date')
                        ->label(__('admin.fields.transaction_date'))
                        ->required()
                        ->default(fn () => now()),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2),
                ])
                ->action(function (Lease $record, array $data) {
                    abort_unless(auth()->user()?->can('deposit_transactions.create') ?? false, 403);

                    $amount = round((float) $data['amount'], 2);

                    // Giving back more than is held would post a NEGATIVE liability — the landlord
                    // recorded as owing the tenant money it never took.
                    if (in_array($data['type'], ['refund', 'forfeit'], true) && $amount > $record->depositHeld() + 0.005) {
                        Notification::make()
                            ->danger()
                            ->title(__('admin.deposits.exceeds_held', [
                                'held' => 'EGP '.number_format($record->depositHeld(), 2),
                            ]))
                            ->send();

                        return;
                    }

                    $movement = DepositTransaction::create([
                        'lease_id' => $record->id,
                        'tenant_id' => $record->tenant_id,
                        'asset_id' => $record->unit?->asset_id,
                        'type' => $data['type'],
                        'status' => 'recorded',
                        'method' => $data['type'] === 'forfeit' ? null : ($data['method'] ?? null),
                        'amount' => $amount,
                        'transaction_date' => $data['transaction_date'],
                        'notes' => $data['notes'] ?? null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('admin.deposits.recorded'))
                        ->body(__('admin.deposits.recorded_body', [
                            'number' => $movement->number,
                            'held' => 'EGP '.number_format($record->fresh()->depositHeld(), 2),
                        ]))
                        ->send();
                }),
            // Bill the deposit as a CHARGE (Voyager). Visible only while something is outstanding —
            // an action that refuses the moment you press it is a worse answer than one that is not
            // offered, and `depositShortfall()` already knows.
            Action::make('billDeposit')
                ->label(__('admin.deposits.bill'))
                ->icon('heroicon-o-shield-check')
                ->color('primary')
                ->visible(fn (Lease $record) => $record->depositShortfall() > 0
                    && in_array($record->status, ['active', 'pending_approval'], true)
                    && LeaseResource::canEdit($record))
                ->authorize(fn () => auth()->user()?->can('invoices.create') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.deposits.bill_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.deposits.bill_description'))
                ->schema([
                    Placeholder::make('outstanding')
                        ->label(__('admin.deposits.outstanding'))
                        ->content(fn (Lease $record) => 'EGP '.number_format($record->depositShortfall(), 2)
                            .' — '.__('admin.tables.lease.deposit_held_of', [
                                'held' => number_format($record->depositHeld(), 2),
                                'agreed' => number_format((float) $record->security_deposit, 2),
                            ])),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->required()
                        ->default(fn (Lease $record) => $record->depositShortfall())
                        ->helperText(__('admin.deposits.amount_helper')),
                    DatePicker::make('issue_date')
                        ->label(__('admin.fields.issue_date'))
                        ->required()
                        ->default(fn () => now()),
                ])
                ->action(function (Lease $record, array $data) {
                    abort_unless(auth()->user()?->can('invoices.create') ?? false, 403);

                    $invoice = app(BillSecurityDepositService::class)->bill($record, $data);

                    Notification::make()
                        ->title(__('admin.deposits.billed'))
                        ->body(__('admin.deposits.billed_body', [
                            'number' => $invoice->number,
                            'amount' => 'EGP '.number_format((float) $invoice->total, 2),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('changeRent')
                ->label(__('admin.actions.change_rent'))
                ->icon('heroicon-o-currency-dollar')
                ->color('warning')
                ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.actions.change_rent_modal_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.actions.change_rent_modal_description'))
                ->fillForm(fn (Lease $record) => [
                    'base_rent_monthly' => (float) $record->base_rent_monthly,
                    'base_rent_rate_per_sqm_year' => (float) $record->base_rent_rate_per_sqm_year,
                    'service_charge_monthly' => (float) $record->service_charge_monthly,
                ])
                ->schema([
                    // A rate-priced lease (LS-04) is re-RATED, not re-priced: the manager
                    // negotiated a rate, so that is what they type, and the monthly figure
                    // follows. Shown instead of the amount rather than beside it — two editable
                    // fields that derive from each other is how they end up disagreeing.
                    TextInput::make('base_rent_rate_per_sqm_year')
                        ->label(__('admin.fields.base_rent_rate_per_sqm_year'))
                        ->prefix('EGP')
                        ->suffix('/m²/'.__('admin.fields.per_year_suffix'))
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText(fn (Lease $record) => __('admin.helpers.base_rent_rate_per_sqm_year', [
                            'area' => number_format($record->totalAreaSqm(), 2),
                        ]))
                        ->visible(fn (Lease $record) => $record->rent_pricing_basis === Lease::RENT_RATE),
                    TextInput::make('base_rent_monthly')
                        ->label(__('admin.fields.base_rent_monthly'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required(fn (Lease $record) => $record->rent_pricing_basis !== Lease::RENT_RATE)
                        ->visible(fn (Lease $record) => $record->rent_pricing_basis !== Lease::RENT_RATE),
                    TextInput::make('service_charge_monthly')
                        ->label(__('admin.fields.service_charge_monthly'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    // The date the new rent TAKES EFFECT. The schedule has supported this since
                    // phase 1 — nothing exposed it, so every operator change silently landed on
                    // "today" and a rent agreed in advance could not be entered in advance.
                    DatePicker::make('effective_from')
                        ->label(__('admin.actions.change_rent_effective_from'))
                        ->helperText(__('admin.actions.change_rent_effective_from_hint'))
                        ->default(now()->startOfMonth())
                        ->required(),
                    // Required, not optional: this is where story LE-01's "a rent change cannot
                    // happen without a reason" is enforced, because the form is the only path
                    // with a human present to give one.
                    Textarea::make('reason')
                        ->label(__('admin.actions.change_rent_reason'))
                        ->placeholder(__('admin.actions.change_rent_reason_placeholder'))
                        ->rows(2)
                        ->required(),
                    TextInput::make('document_reference')
                        ->label(__('admin.lease_events.document'))
                        ->helperText(__('admin.lease_events.document_hint'))
                        ->maxLength(255),
                ])
                ->action(function (Lease $record, array $data) {
                    try {
                        $updated = app(LeaseRentChangeService::class)->apply($record, $data);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.actions.rent_changed'))
                        ->body(__('admin.actions.rent_changed_body', [
                            'ref' => $updated->reference,
                            'rent' => 'EGP '.number_format((float) $updated->base_rent_monthly, 2),
                            'service' => 'EGP '.number_format((float) $updated->service_charge_monthly, 2),
                        ]))
                        ->success()
                        ->send();
                }),
            // ── Parking, storage and signage (space model) ────────────────────────────────
            // The register and the service existed with no way in: an operator could not let a
            // bay without tinker. Assign writes the dated pivot AND re-derives the lease's one
            // `parking` charge, so the money follows in the same click.
            Action::make('assignRentableItem')
                ->label(__('admin.actions.assign_rentable_item'))
                ->icon('heroicon-o-ticket')
                ->color('gray')
                ->modalHeading(fn (Lease $record) => __('admin.actions.assign_rentable_item').' · '.$record->reference)
                ->modalDescription(__('admin.actions.assign_rentable_item_hint'))
                ->visible(fn (Lease $record): bool => (auth()->user()?->can('rentable_items.edit') ?? false)
                    && in_array($record->status, ['active', 'pending_approval'], true))
                ->authorize(fn (): bool => auth()->user()?->can('rentable_items.edit') ?? false)
                ->schema(fn (Lease $record): array => [
                    Select::make('rentable_item_id')
                        ->label(__('admin.resources.rentable_item.singular'))
                        ->options(fn (): array => self::lettableItemOptions($record))
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->helperText(__('admin.helpers.assign_rentable_item')),
                    DatePicker::make('effective_from')
                        ->label(__('admin.actions.change_rent_effective_from'))
                        ->default(now()->startOfMonth())
                        ->required(),
                    TextInput::make('monthly_rate')
                        ->label(__('admin.fields.item_monthly_rate'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->helperText(__('admin.helpers.assign_rentable_item_rate')),
                ])
                ->action(function (Lease $record, array $data) {
                    abort_unless(auth()->user()?->can('rentable_items.edit') ?? false, 403);

                    $item = RentableItem::findOrFail($data['rentable_item_id']);

                    try {
                        app(AssignRentableItemService::class)->assign($record, $item, $data);
                    } catch (\DomainException|\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('admin.actions.assign_rentable_item_done', ['code' => $item->code]))
                        ->send();
                }),
            Action::make('releaseRentableItem')
                ->label(__('admin.actions.release_rentable_item'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->modalDescription(__('admin.actions.release_rentable_item_hint'))
                ->visible(fn (Lease $record): bool => (auth()->user()?->can('rentable_items.edit') ?? false)
                    && $record->rentableItems()->wherePivotNull('effective_to')->exists())
                ->authorize(fn (): bool => auth()->user()?->can('rentable_items.edit') ?? false)
                ->schema(fn (Lease $record): array => [
                    Select::make('rentable_item_id')
                        ->label(__('admin.resources.rentable_item.singular'))
                        ->options(fn (): array => self::heldItemOptions($record))
                        ->native(false)
                        ->required(),
                    DatePicker::make('effective_to')
                        ->label(__('admin.actions.release_rentable_item_to'))
                        ->default(now()->endOfMonth())
                        ->required()
                        ->helperText(__('admin.actions.release_rentable_item_to_hint')),
                ])
                ->action(function (Lease $record, array $data) {
                    abort_unless(auth()->user()?->can('rentable_items.edit') ?? false, 403);

                    $item = RentableItem::findOrFail($data['rentable_item_id']);

                    try {
                        app(AssignRentableItemService::class)->release($record, $item, $data['effective_to']);
                    } catch (\DomainException|\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('admin.actions.release_rentable_item_done', ['code' => $item->code]))
                        ->send();
                }),
            // Temporary relief (LE-03) is deliberately its own action rather than a checkbox on
            // "Change Rent": a concession and a renegotiation are different deals with
            // different consequences, and the whole point of the story is that the system can
            // finally tell them apart.
            Action::make('grantRelief')
                ->label(__('admin.actions.grant_relief'))
                ->icon('heroicon-o-receipt-percent')
                ->color('warning')
                ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.actions.grant_relief_modal_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.actions.grant_relief_modal_description'))
                ->schema([
                    Select::make('type')
                        ->label(__('admin.fields.type'))
                        ->options([
                            'base_rent' => __('admin.fields.base_rent_monthly'),
                            'service_charge' => __('admin.fields.service_charge_monthly'),
                        ])
                        ->default('base_rent')
                        ->required(),
                    Select::make('basis')
                        ->label(__('admin.actions.relief_basis'))
                        ->options([
                            'percent' => __('admin.actions.relief_basis_percent'),
                            'amount' => __('admin.actions.relief_basis_amount'),
                        ])
                        ->default('percent')
                        ->live()
                        ->required(),
                    TextInput::make('percent_off')
                        ->label(__('admin.actions.relief_percent_off'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(100)
                        ->visible(fn (Get $get) => $get('basis') === 'percent')
                        ->required(fn (Get $get) => $get('basis') === 'percent'),
                    TextInput::make('amount')
                        ->label(__('admin.actions.relief_amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get) => $get('basis') === 'amount')
                        ->required(fn (Get $get) => $get('basis') === 'amount'),
                    DatePicker::make('from')
                        ->label(__('admin.actions.relief_from'))
                        ->default(now()->startOfMonth())
                        ->required(),
                    DatePicker::make('to')
                        ->label(__('admin.actions.relief_to'))
                        ->helperText(__('admin.actions.relief_to_hint'))
                        ->required()
                        ->after('from'),
                    Textarea::make('reason')
                        ->label(__('admin.actions.change_rent_reason'))
                        ->rows(2)
                        ->required(),
                    TextInput::make('document_reference')
                        ->label(__('admin.lease_events.document'))
                        ->helperText(__('admin.lease_events.document_hint'))
                        ->maxLength(255),
                ])
                ->action(function (Lease $record, array $data) {
                    // The basis selector is UI only — the service takes whichever of the two
                    // numbers was filled, so send exactly one and let it validate.
                    $payload = $data;
                    unset($payload['basis']);
                    if (($data['basis'] ?? 'percent') === 'percent') {
                        $payload['amount'] = null;
                    } else {
                        $payload['percent_off'] = null;
                    }

                    try {
                        $result = app(LeaseReliefService::class)->grant($record, $payload);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $reverts = $result['resumed'];

                    Notification::make()
                        ->title(__('admin.actions.relief_granted'))
                        ->body(__('admin.actions.relief_granted_body', [
                            'ref' => $record->reference,
                            'amount' => 'EGP '.number_format((float) ($result['relief'][0]->amount ?? 0), 2),
                            'until' => $result['relief'] ? end($result['relief'])->end_date->format('d/m/Y') : '—',
                            'reverts' => $reverts
                                ? 'EGP '.number_format((float) $reverts->amount, 2)
                                : __('admin.actions.relief_no_reversion'),
                        ]))
                        ->success()
                        ->send();
                }),
            // The move-out final account (MF-03). Native Filament: the statement renders as
            // Placeholders inside the action's own schema, beside the one editable part (the
            // deductions), so the operator reads and settles in one place instead of pricing a
            // refund from three screens.
            Action::make('finalAccount')
                ->label(__('admin.move_out.settle'))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->visible(fn (Lease $record) => in_array($record->status, ['terminated', 'expired'], true)
                    && LeaseResource::canEdit($record))
                ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.move_out.heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.move_out.description'))
                ->modalSubmitActionLabel(__('admin.move_out.settle'))
                ->schema([
                    Placeholder::make('statement')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content(function (Lease $record) {
                            $s = app(MoveOutStatementService::class)->for($record);
                            $money = fn (float $v) => 'EGP '.number_format($v, 2);

                            $rows = [
                                [__('admin.move_out.contractual_deposit'), $money((float) $s['contractual_deposit'])],
                                [__('admin.move_out.deposit_held'), $money((float) $s['deposit_held'])],
                            ];

                            if ((float) $s['deposit_shortfall'] > 0) {
                                $rows[] = [__('admin.move_out.deposit_shortfall'), $money((float) $s['deposit_shortfall'])];
                            }

                            $rows[] = [__('admin.move_out.open_ar'), $money((float) $s['open_ar'])];
                            $rows[] = [__('admin.move_out.tenant_credit'), $money((float) $s['tenant_credit'])];
                            $rows[] = [
                                (float) $s['residual_debt'] > 0
                                    ? __('admin.move_out.residual_debt')
                                    : __('admin.move_out.net_to_tenant'),
                                $money((float) $s['residual_debt'] > 0 ? (float) $s['residual_debt'] : (float) $s['net_to_tenant']),
                            ];

                            $lines = collect($rows)->map(fn (array $r) => "**{$r[0]}:** {$r[1]}")->join("  \n");

                            $pending = collect($s['pending_trueups'])->pluck('detail');
                            $lines .= "\n\n**".__('admin.move_out.pending').'**  '."\n"
                                .($pending->isNotEmpty() ? '- '.$pending->join("\n- ") : __('admin.move_out.pending_none'));

                            $lines .= "\n\n_".__('admin.move_out.ar_note').'_';

                            return str($lines)->markdown()->toHtmlString();
                        }),
                    Repeater::make('deductions')
                        ->label(__('admin.move_out.deductions'))
                        ->addActionLabel(__('admin.move_out.add_deduction'))
                        ->schema([
                            TextInput::make('description')
                                ->label(__('admin.move_out.deduction_description'))
                                ->required(),
                            TextInput::make('amount')
                                ->label(__('admin.move_out.deduction_amount'))
                                ->prefix('EGP')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                        ->columns(2)
                        ->default([])
                        ->columnSpanFull(),
                    DatePicker::make('settlement_date')
                        ->label(__('admin.lease_events.effective'))
                        ->default(now())
                        ->required(),
                    TextInput::make('document_reference')
                        ->label(__('admin.lease_events.document'))
                        ->helperText(__('admin.lease_events.document_hint'))
                        ->maxLength(255),
                ])
                ->action(function (Lease $record, array $data) {
                    try {
                        $result = app(SettleMoveOutService::class)->settle($record, $data);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.move_out.settled'))
                        ->body(__('admin.move_out.settled_body', [
                            'ref' => $record->reference,
                            'refunded' => 'EGP '.number_format((float) ($result['refund']->amount ?? 0), 2),
                            'deducted' => 'EGP '.number_format((float) ($result['forfeit']->amount ?? 0), 2),
                        ]))
                        ->success()
                        ->send();
                }),
            // Premises changes (LE-02). Space and money move on ONE date through one service —
            // the old way was "add a pivot row, then run Change Rent", two undated steps that
            // could disagree about when the deal changed.
            Action::make('changePremises')
                ->label(__('admin.actions.change_premises'))
                ->icon('heroicon-o-squares-plus')
                ->color('info')
                ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.actions.change_premises_modal_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.actions.change_premises_modal_description'))
                ->schema([
                    Select::make('direction')
                        ->label(__('admin.actions.premises_direction'))
                        ->options([
                            'expand' => __('admin.actions.premises_expand'),
                            'contract' => __('admin.actions.premises_contract'),
                        ])
                        ->default('expand')
                        ->live()
                        ->required(),
                    EntitySelect::make('unit_ids')
                        ->label(__('admin.actions.premises_units'))
                        ->entity(Unit::class)
                        ->multiple()
                        ->required()
                        // CONTRACTING picks from the units this lease currently holds; EXPANDING
                        // picks from unlet space in THIS lease's own property. The visible-set half
                        // of the old scope is OptionDisplay's now (`Unit` is `#[PropertyOwned]`).
                        ->modifyOptionsQuery(function ($query, Get $get, Lease $record) {
                            if ($get('direction') === 'contract') {
                                return $query
                                    ->whereIn('id', $record->unitsOn(CarbonImmutable::now())->pluck('id'))
                                    ->where('id', '!=', $record->unit_id);
                            }

                            return $query
                                ->when($record->unit?->asset_id, fn ($q, $id) => $q->where('asset_id', $id))
                                ->whereNotIn('id', $record->units->pluck('id'))
                                // `isActivelyLeased()` reads a relation, so it cannot be a where —
                                // the set is one property's units, which is small enough to reject
                                // over, and this replaces a post-query `reject()` that did the same.
                                ->whereNotIn('id', Unit::query()
                                    ->when($record->unit?->asset_id, fn ($q, $id) => $q->where('asset_id', $id))
                                    ->get()
                                    ->filter(fn (Unit $u) => $u->isActivelyLeased($record->id))
                                    ->pluck('id'));
                        }),
                    DatePicker::make('effective_from')
                        ->label(__('admin.actions.premises_effective_from'))
                        ->helperText(__('admin.actions.premises_effective_from_hint'))
                        ->default(now()->addMonth()->startOfMonth())
                        ->required(),
                    TextInput::make('new_total_rent')
                        ->label(__('admin.actions.premises_new_total_rent'))
                        ->helperText(__('admin.actions.premises_new_total_rent_hint'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0),
                    Textarea::make('reason')
                        ->label(__('admin.actions.change_rent_reason'))
                        ->rows(2)
                        ->required(),
                    TextInput::make('document_reference')
                        ->label(__('admin.lease_events.document'))
                        ->helperText(__('admin.lease_events.document_hint'))
                        ->maxLength(255),
                ])
                ->action(function (Lease $record, array $data) {
                    $service = app(LeaseSpaceChangeService::class);

                    try {
                        $updated = ($data['direction'] ?? 'expand') === 'contract'
                            ? $service->contract($record, $data)
                            : $service->expand($record, $data);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.actions.premises_changed'))
                        ->body(__('admin.actions.premises_changed_body', [
                            'ref' => $updated->reference,
                            'area' => number_format($updated->totalAreaSqmOn(CarbonImmutable::parse($data['effective_from'])), 0),
                            'from' => CarbonImmutable::parse($data['effective_from'])->startOfMonth()->format('d/m/Y'),
                        ]))
                        ->success()
                        ->send();
                }),
            // Holdover conversion (LE-04). Visible only on a lease that has actually expired
            // and has not been converted — this is the action the ActionRequired card exists to
            // prompt, and until it existed the card was the end of the story.
            Action::make('convertToHoldover')
                ->label(__('admin.actions.convert_to_holdover'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (Lease $record) => $record->isHoldover() && ! $record->isConvertedHoldover() && LeaseResource::canEdit($record))
                ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.actions.convert_to_holdover_modal_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.actions.convert_to_holdover_modal_description'))
                ->fillForm(fn (Lease $record) => [
                    'rate_pct' => (float) app(BillingSettings::class)->holdover_default_rate_pct,
                    // visible() already proved the lease expired, so expiry_date is present here.
                    'effective_from' => $record->expiry_date->copy()->addDay()->startOfMonth(),
                ])
                ->schema([
                    TextInput::make('rate_pct')
                        ->label(__('admin.actions.holdover_rate'))
                        ->helperText(__('admin.actions.holdover_rate_hint'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                    DatePicker::make('effective_from')
                        ->label(__('admin.actions.holdover_from'))
                        ->helperText(__('admin.actions.holdover_from_hint'))
                        ->required(),
                    Textarea::make('reason')
                        ->label(__('admin.actions.change_rent_reason'))
                        ->rows(2)
                        ->required(),
                    TextInput::make('document_reference')
                        ->label(__('admin.lease_events.document'))
                        ->helperText(__('admin.lease_events.document_hint'))
                        ->maxLength(255),
                ])
                ->action(function (Lease $record, array $data) {
                    try {
                        $updated = app(ConvertLeaseToHoldoverService::class)->convert($record, $data);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.actions.holdover_converted'))
                        ->body(__('admin.actions.holdover_converted_body', [
                            'ref' => $updated->reference,
                            'rent' => 'EGP '.number_format((float) $updated->base_rent_monthly, 2),
                            'from' => $updated->holdover_from->format('d/m/Y'),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('terminate')
                ->label(__('admin.actions.terminate'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record) && auth()->user()?->can('leases.terminate'))
                ->authorize(fn () => auth()->user()?->can('leases.terminate') ?? false)
                ->modalHeading(fn (Lease $record) => __('admin.actions.terminate_modal_heading', ['ref' => $record->reference]))
                ->modalDescription(fn (Lease $record) => __('admin.actions.terminate_modal_description', ['unit' => $record->unit?->code ?? '—']))
                ->modalSubmitActionLabel(__('admin.actions.terminate_submit'))
                ->fillForm([
                    'termination_date' => now()->toDateString(),
                    'cancel_open_invoices' => true,
                ])
                ->schema([
                    DatePicker::make('termination_date')
                        ->label(__('admin.actions.termination_date'))
                        ->required()
                        // A lease cannot end before it started. The model refuses it either
                        // way (Lease::booted) — this stops the operator picking the date at
                        // all, which is the difference between an inline "not available" and
                        // a submitted form bouncing back. Equal is allowed: a deal that
                        // collapses at handover terminates on its commencement date.
                        ->minDate(fn (Lease $record) => $record->commencement_date)
                        ->native(false),
                    Textarea::make('reason')
                        ->label(__('admin.actions.termination_reason'))
                        ->placeholder(__('admin.actions.termination_reason_placeholder'))
                        ->rows(2),
                    // The service has honoured `credit_unearned` since MF-02 shipped and this modal
                    // never sent it, so it silently defaulted to true — right in almost every case,
                    // and unusable in the one the flag exists for. The credit note posts ON the
                    // termination date, so terminating into a CLOSED period is refused inside a
                    // best-effort job: the operator needs a way through that is not "reopen the
                    // books". A documented escape hatch nobody can reach is not an escape hatch.
                    Toggle::make('credit_unearned')
                        ->label(__('admin.actions.credit_unearned'))
                        // Sorted the way `FieldHelp` sorts everything: the visible line is the
                        // CONSTRAINT (when to switch it off), the reason it exists is one hover away.
                        ->helperText(__('admin.actions.credit_unearned_helper'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.credit_unearned'))
                        ->default(true),
                    Toggle::make('cancel_open_invoices')
                        ->label(__('admin.actions.cancel_open_invoices'))
                        ->helperText(__('admin.actions.cancel_open_invoices_helper'))
                        ->default(true),
                ])
                ->action(function (Lease $record, array $data) {
                    try {
                        $terminated = app(LeaseTerminationService::class)->terminate($record, $data);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.actions.lease_terminated'))
                        ->body(__('admin.actions.lease_terminated_body', [
                            'ref' => $terminated->reference,
                            'date' => $terminated->expiry_date->format('d/m/Y'),
                            'unit' => $terminated->unit?->code ?? '—',
                        ]))
                        ->warning()
                        ->send();
                }),
            // ── Extend the term — the last commercial change with no act behind it ────────────
            // `expiry_date` was free text on the form, so a further term happened by typing a date:
            // no reason, no actor, no event, and nothing able to tell an extension from a typo.
            // `LeaseEvent::TYPE_EXTENSION` had been declared and never written.
            Action::make('extendTerm')
                ->label(__('admin.actions.extend_term'))
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (Lease $record): bool => in_array($record->status, ['active', 'pending_approval'], true)
                    && LeaseResource::canEdit($record))
                ->authorize(fn (Lease $record): bool => LeaseResource::canEdit($record))
                ->modalHeading(fn (Lease $record) => __('admin.actions.extend_term_heading', ['ref' => $record->reference]))
                ->modalDescription(__('admin.actions.extend_term_description'))
                ->schema([
                    DatePicker::make('new_expiry_date')
                        ->label(__('admin.fields.expiry_date'))
                        ->required()
                        ->native(false)
                        // Only forwards. Pulling an expiry backwards ends a tenancy early, which is
                        // a TERMINATION — it settles the deposit and credits unearned billing, and
                        // none of that would happen here.
                        ->after(fn (Lease $record) => $record->expiry_date)
                        ->helperText(fn (Lease $record) => __('admin.helpers.extend_term_current', [
                            'date' => $record->expiry_date?->format('d/m/Y') ?? '—',
                        ])),
                    Textarea::make('reason')
                        ->label(__('admin.actions.change_rent_reason'))
                        ->required()
                        ->rows(2)
                        ->placeholder(__('admin.actions.extend_term_reason_placeholder')),
                    TextInput::make('document_reference')
                        ->label(__('admin.fields.document_reference'))
                        ->maxLength(120),
                ])
                ->action(function (array $data, Lease $record): void {
                    // The real gate — `visible()` is the UI.
                    abort_unless(LeaseResource::canEdit($record), 403);

                    $extended = app(LeaseExtensionService::class)->extend($record, $data);

                    Notification::make()
                        ->title(__('admin.actions.extend_term_done'))
                        ->body(__('admin.actions.extend_term_done_body', [
                            'ref' => $extended->reference,
                            'date' => $extended->expiry_date->format('d/m/Y'),
                            'months' => $extended->term_months,
                        ]))
                        ->success()
                        ->send();
                }),

        ];
    }

    /**
     * Compose a subset BY NAME, preserving the order asked for.
     *
     * @param  array<int, string>  $names
     * @return array<int, Action>
     */
    private static function only(array $names): array
    {
        $byName = [];

        foreach (self::all() as $action) {
            $byName[$action->getName()] = $action;
        }

        return array_values(array_filter(array_map(
            fn (string $name): ?Action => $byName[$name] ?? null,
            $names,
        )));
    }

    /**
     * The lease page's header: dropdowns grouped by the question being asked.
     *
     * Nine loose buttons is the complaint that started this — a wall of equally-weighted verbs with
     * no shape to scan. Grouped, an operator reads three words and opens the one matching their
     * intention, which is how a record hub is meant to read.
     *
     * @return array<int, ActionGroup>
     */
    /**
     * Every group's contents, as one map — so `grouped()` and the completeness gate read the SAME
     * list rather than two that drift.
     *
     * @return array<string, array<int, string>>
     */
    public const GROUPS = [
        'money' => ['changeRent', 'grantRelief', 'billDeposit', 'recordDeposit'],
        'premises' => ['changePremises', 'assignRentableItem', 'releaseRentableItem'],
        'lifecycle' => ['renew', 'extendTerm', 'convertToHoldover', 'terminate', 'finalAccount'],
    ];

    /**
     * The header dropdowns, composed BY NAME from {@see GROUPS}.
     *
     * **An action missing from a group is defined and rendered nowhere** — it passes every
     * visibility and authorisation check and simply never appears. That is what happened to the two
     * deposit actions the day they were added (2026-08-18): both `isVisible()`, neither on screen.
     * `LeaseActionTopologyTest` now asserts every action in `all()` belongs to exactly one group, so
     * the next one cannot be added and forgotten.
     */
    public static function grouped(): array
    {
        return [
            ActionGroup::make(self::only(self::GROUPS['money']))
                ->label(__('admin.actions.groups.money'))
                ->icon('heroicon-o-banknotes')
                ->button(),
            ActionGroup::make(self::only(self::GROUPS['premises']))
                ->label(__('admin.actions.groups.premises'))
                ->icon('heroicon-o-building-storefront')
                ->button(),
            ActionGroup::make(self::only(self::GROUPS['lifecycle']))
                ->label(__('admin.actions.groups.lifecycle'))
                ->icon('heroicon-o-arrow-path')
                ->button(),
        ];
    }

    /**
     * The named acts, bound to a lease — for a TAB to carry the actions that change its own data.
     *
     * A relation manager's header actions are not handed the owner record, so composing from this
     * registry needs the binding done once here rather than at each call site. Without it a tab
     * either shows nothing or re-implements the act, which is what
     * `LeaseRentableItemsRelationManager` had done: its own `assign` form beside this one, already
     * drifted to a plain `Select` where the registry uses an `EntitySelect` (2026-08-18).
     *
     * @param  array<int, string>  $names
     * @return array<int, Action>
     */
    public static function forOwner(Lease $lease, array $names): array
    {
        return array_map(fn (Action $a): Action => $a->record($lease), self::only($names));
    }

    /**
     * Every custom action's name — what the parity gate checks the table against.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(fn (Action $a): string => $a->getName(), self::all());
    }

    /**
     * Items this lease could take: same property, free on the day, not withdrawn.
     *
     * @return array<int, string>
     */
    private static function lettableItemOptions(Lease $record): array
    {
        $assetId = $record->unit?->asset_id;

        if (! $assetId) {
            return [];
        }

        return RentableItem::query()
            ->where('asset_id', $assetId)
            ->where('status', '!=', RentableItem::STATUS_OUT_OF_SERVICE)
            ->orderBy('code')
            ->get()
            // Filtered in PHP rather than SQL: "held on a date" is a date-ranged predicate over the
            // pivot that the model already owns, and duplicating it as a subquery is how the two
            // drift apart.
            ->reject(fn (RentableItem $i) => $i->isHeldOn(null, ignoreLeaseId: $record->id))
            ->mapWithKeys(fn (RentableItem $i) => [
                $i->id => $i->label().' · EGP '.number_format((float) $i->monthly_rate, 2),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private static function heldItemOptions(Lease $record): array
    {
        // The negotiated rate comes from the pivot table directly: the relation carries no declared
        // pivot type to read through, and this is one query either way.
        $rates = DB::table('lease_rentable_item')
            ->where('lease_id', $record->id)
            ->whereNull('effective_to')
            ->pluck('monthly_rate', 'rentable_item_id');

        return $record->rentableItems()
            ->wherePivotNull('effective_to')
            ->get()
            ->mapWithKeys(fn (RentableItem $i) => [
                $i->id => $i->label().' · EGP '.number_format((float) ($rates[$i->id] ?? 0), 2),
            ])
            ->all();
    }
}
