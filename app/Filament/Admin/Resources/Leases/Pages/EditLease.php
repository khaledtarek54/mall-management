<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Actions\LeaseActions;
use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Widgets\LeaseSummary;
use App\Models\Lease;
use App\Services\MarketingLevyService;
use App\Services\MonthlyBillingService;
use App\Support\BillingRefusal;
use App\Support\BillingWindow;
use App\Support\Filament\MonthPicker;
use App\Support\Filament\RefreshesRecordState;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLease extends EditRecord
{
    use FillsCustomFields;
    use RefreshesRecordState;

    protected static string $resource = LeaseResource::class;

    /**
     * The lease columns the commercial actions rewrite. This page IS the record hub — renew,
     * change rent, extend, convert to holdover and terminate all run from its own header and
     * all land on fields rendered a few centimetres below the button. Without this the operator
     * raises the rent, is told it worked, and goes on reading the old rent.
     *
     * `notes` is deliberately absent even though termination appends to it: it is a field the
     * operator types, and refilling it would discard an edit in progress.
     */
    protected function derivedStatePaths(): array
    {
        return [
            'status', 'base_rent_monthly', 'base_rent_rate_per_sqm_year', 'service_charge_monthly',
            'expiry_date', 'term_months', 'security_deposit', 'escalation_rate', 'escalation_amount',
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Terminal leases are immutable — halt with a notice rather than letting the model's
        // updating guard throw a raw 500 (the model guard is the real backstop for crafted saves).
        if ($this->record->isTerminal()) {
            Notification::make()
                ->title(__('admin.validation.lease_terminal_immutable'))
                ->danger()
                ->send();
            $this->halt();
        }

        // Block re-homing the lease (or attaching out-of-scope additional units).
        LeaseResource::assertUnitAssetInScope($data['unit_id'] ?? $this->record->unit_id);
        LeaseResource::assertUnitsAssetInScope($this->data['additional_unit_ids'] ?? []);

        return $data;
    }

    /** Pre-fill the additional-units selector from the lease's pivot (non-master units). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['additional_unit_ids'] = $this->record->units()
            ->wherePivot('is_master', false)
            ->pluck('units.id')
            ->all();

        return $data;
    }

    /** Sync the full unit set (master = unit_id) after saving the lease. */
    protected function afterSave(): void
    {
        $additional = $this->data['additional_unit_ids'] ?? [];

        // ── THE DISABLED FIELD IS A UI TRUTH, NOT A GATE (2026-08-28) ────────────────────────
        //
        // A disabled input's value still arrives in the Livewire payload — the rule this codebase
        // states for every pinned field — so the refusal has to live here. `syncUnits()` attaches
        // the units and nothing else: on a rate-priced lease that leaves the rent behind, and a
        // 110 m² lease at 4,800/m² went to 200 m² still billing 44,000 where 80,000 was due.
        //
        // Re-deriving here is not the fix. Re-rating needs an EFFECTIVE DATE and a form save has
        // none, so it could only restate the rent from the start of the lease — rewriting months
        // already billed. `LeaseSpaceChangeService` takes that date, re-derives at it, and closes
        // and reopens the charge row; this refuses and names it.
        $live = in_array($this->record->status, ['active', 'pending_approval'], true);
        $current = $this->record->units()->pluck('units.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $wanted = collect([$this->record->unit_id, ...$additional])
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($live && $current !== $wanted) {
            throw new \DomainException(__('admin.fields.additional_units_locked'));
        }

        $this->record->syncUnits(
            [$this->record->unit_id, ...$additional],
            $this->record->unit_id,
        );

        // Re-sync the marketing levy charge so a toggle/rate change on the form takes effect
        // (activates/deactivates + re-rates the `marketing` charge for the next monthly run).
        app(MarketingLevyService::class)->createLevyCharge($this->record);
    }

    /**
     * The tenancy at a glance, above the tabs — UX-01's Summary.
     *
     * A header widget rather than a separate View page: the lease page already IS the record hub,
     * and a second surface showing the same facts is one that drifts from it. Same reasoning that
     * put the actions in one registry.
     */
    protected function getHeaderWidgets(): array
    {
        return [LeaseSummary::class];
    }

    protected function getHeaderActions(): array
    {
        // The record hub: everything you can DO to this tenancy lives here, grouped by the question
        // being asked. The leases LIST used to carry nine commercial actions and this page one, so
        // an operator who opened a lease had to go back to the list to act on it — backwards from
        // the record-hub architecture this project took from Yardi. See
        // App\Filament\Admin\Actions\LeaseActions, which is now the single definition both
        // surfaces compose from, so they cannot drift the way they already had.
        return [
            $this->generateInvoiceAction(),
            ...LeaseActions::grouped(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function generateInvoiceAction(): Action
    {
        return Action::make('generateInvoice')
            ->label(__('admin.actions.generate_invoice'))
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->visible(fn (Lease $record) => $record->status === 'active')
            // Generating an invoice is a distinct, billing-sensitive permission —
            // gate it server-side (visible() only hides the button; authorize() enforces).
            ->authorize(fn () => auth()->user()?->can('leases.generate_invoice') ?? false)
            ->modalHeading(fn (Lease $record) => __('admin.actions.generate_invoice_for', ['ref' => $record->reference]))
            ->modalDescription(__('admin.actions.generate_invoice_description'))
            ->modalSubmitActionLabel(__('admin.actions.generate'))
            ->schema([
                // The billing period IS a month — `format('Y-m-01')` said so already, by forcing
                // whatever day was clicked back to the first.
                MonthPicker::make('period')
                    ->label(__('admin.actions.billing_period'))
                    ->helperText(__('admin.actions.billing_period_helper'))
                    ->required()
                    ->monthsBack(24)
                    ->monthsAhead(1)
                    ->default(now()->startOfMonth()->toDateString())
                    // The same window the Billing Run Preview offers. This picker carried no bounds
                    // at all, so one screen refused to PREVIEW a month the other would happily
                    // BILL — a receivable raisable years early, posting revenue into a period that
                    // may not exist and dating an e-invoice into the future. The bounds are the UI
                    // half; the closure below is the gate.
                    ->minDate(BillingWindow::earliest())
                    ->maxDate(BillingWindow::latest())
                    ->native(false),
                Toggle::make('prorate')
                    ->label(__('admin.actions.prorate_first_period'))
                    ->helperText(__('admin.actions.prorate_helper'))
                    ->default(true),
            ])
            ->action(function (array $data, Lease $record): void {
                $period = CarbonImmutable::parse($data['period'])->startOfMonth();

                // The real gate — `minDate`/`maxDate` bound the picker, and a picker is not a guard.
                if (! BillingWindow::allows($period)) {
                    Notification::make()
                        ->title(__('admin.actions.outside_billing_window_title'))
                        ->body(__('admin.actions.outside_billing_window_body', [
                            'from' => BillingWindow::earliest()->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                            'to' => BillingWindow::latest()->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                        ]))
                        ->warning()
                        ->send();

                    return;
                }

                $result = app(MonthlyBillingService::class)
                    ->generateForLease($record, $period, (bool) ($data['prorate'] ?? false));

                if ($result['status'] === 'created') {
                    Notification::make()
                        ->title(__('admin.actions.invoice_created'))
                        ->body(__('admin.actions.invoice_created_body', [
                            'number' => $result['invoice']->number,
                            'total' => 'EGP '.number_format((float) $result['invoice']->total, 2),
                        ]))
                        ->success()
                        ->send();

                    return;
                }

                // A refusal, not a failure. The wording lives in App\Support\BillingRefusal
                // because the Billing forecast tab raises invoices through the same service and
                // was rendering the raw reason CODE — so the same refusal was a paragraph of
                // advice here and an untranslated key one tab away.
                $refusal = BillingRefusal::explain($record, $period, $result);

                Notification::make()
                    ->title($refusal['title'])
                    ->body($refusal['body'])
                    ->status($refusal['danger'] ? 'danger' : 'warning')
                    ->send();
            });
    }
}
