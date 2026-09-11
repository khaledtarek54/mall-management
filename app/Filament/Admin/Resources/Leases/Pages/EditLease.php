<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Actions\LeaseActions;
use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Widgets\LeaseSummary;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseAgreementPdfService;
use App\Services\MonthlyBillingService;
use App\Support\BillingRefusal;
use App\Support\BillingWindow;
use App\Support\ChargeEscalation;
use App\Support\Filament\MonthPicker;
use App\Support\Filament\PdfDownloadAction;
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
    /**
     * The lease edit and everything `afterSave()` derives are ONE unit of work.
     *
     * `afterSave()` throws a `DomainException` when additional units are changed on a locked lease,
     * and it runs AFTER `handleRecordUpdate()` — so without this the lease row's edit committed
     * (term, expiry, escalation, deposit months, percentage-rent flags) while `syncUnits()` and
     * `createLevyCharge()` never ran and the operator was told the change was refused. Reachable
     * exactly as that guard's own comment argues: a disabled input's value still arrives in the
     * Livewire payload.
     *
     * `halt()` COMMITS by default, so any halt added here must pass
     * `shouldRollbackDatabaseTransaction: true`. The one at :58 is pre-write and unaffected.
     */
    protected ?bool $hasDatabaseTransactions = true;

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
            $this->halt(shouldRollbackDatabaseTransaction: true);
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

        // The "Which charges step" table (meeting 2026-09-02, point 24): one row per recurring
        // charge TYPE on the schedule, read from the rung in force today — the rent and the levy
        // are never asked (the clause and the rent answer for them). Not a lease column, so it
        // is filled here and written back in `afterSave()` through the one writer.
        $data['charge_escalations'] = self::chargeEscalationRows($this->record);

        return $data;
    }

    /**
     * @return list<array{type: string, escalation_mode: string, escalation_rate: ?float, escalation_amount: ?float}>
     */
    public static function chargeEscalationRows(Lease $lease): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $schedule = app(ChargeScheduleService::class);

        $types = $lease->charges()
            ->where('is_active', true)
            ->where('frequency', '!=', 'one_time')
            ->whereNotIn('type', ChargeEscalation::DERIVED_TYPES)
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return $types
            ->map(function (string $type) use ($lease, $schedule, $today): ?array {
                // COVERING today, or the first rung still to start — never `rowInForce()`'s
                // latest-active fallback, which would offer a rule for a charge the operator
                // ENDED (active, past its end date) that `setEscalation()` then writes to no row.
                $row = $schedule->rowCovering($lease, $type, $today)
                    ?? $lease->charges()->where('type', $type)->where('is_active', true)
                        ->whereNotNull('start_date')->whereDate('start_date', '>', $today->toDateString())
                        ->orderBy('start_date')->first();

                if ($row === null) {
                    return null;
                }

                return [
                    'type' => $type,
                    'escalation_mode' => ChargeEscalation::modeOf($row),
                    'escalation_rate' => $row->escalation_rate === null ? null : (float) $row->escalation_rate,
                    'escalation_amount' => $row->escalation_amount === null ? null : (float) $row->escalation_amount,
                ];
            })
            ->filter()
            ->values()
            ->all();
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
        // `Lease::premisesLockedBecause()` — the field's own predicate, so the form's disabled
        // state and this refusal cannot drift: `live` (the act owns it), `stepped` (a re-priced
        // seed row would disagree with a started rung), `schedule` (an act's or an import's row
        // would be re-priced and relabelled).
        $locked = $this->record->premisesLockedBecause();
        $current = $this->record->units()->pluck('units.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $wanted = collect([$this->record->unit_id, ...$additional])
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($locked !== null && $current !== $wanted) {
            throw new \DomainException($locked === 'live'
                ? __('admin.fields.additional_units_locked')
                : __('admin.fields.additional_units_locked_draft'));
        }

        $this->record->syncUnits(
            [$this->record->unit_id, ...$additional],
            $this->record->unit_id,
        );

        // ── A DRAFT'S RENT FOLLOWS ITS UNITS (2026-09-11, the term-edit review) ─────────────
        // On a rate-priced draft the rent is rate × area, and `syncUnits()` attaches units and
        // nothing else — so a draft whose space changed here kept the rent of the old space, and
        // its seeded row with it. Nothing has happened to a draft, so this is the wizard's own
        // post-attach sequence run again: re-derive the column (`repriceFromPremises()` refuses on
        // its own once invoiced), then re-price the seeded rows and re-true the steps from them.
        // Silent on a flat-priced lease, where a negotiated sum is not a function of area.
        if ($locked === null && $current !== $wanted) {
            $this->record->load('units');
            // Both, unconditionally: the form derives a rate-priced rent live from the units
            // picked, so the column usually arrives already re-priced and `repriceFromPremises()`
            // finds nothing to move — the seeded ROW is what is a save behind, and
            // `repriceSeededRent()` reads the row and no-ops when it already agrees.
            $this->record->repriceFromPremises();
            app(ChargeScheduleService::class)->repriceSeededRent($this->record->fresh());
        }

        // ── EACH CHARGE'S OWN RULE, THROUGH THE ONE WRITER (point 24) ────────────────────────
        //
        // Only the rows the operator CHANGED: `setEscalation()` throws that type's projected
        // rungs away and re-walks them, so writing every row on every save would re-mint the
        // ladder (new ids, an audit trail full of churn) for a lease whose escalation nobody
        // touched. Compared against what the schedule holds now, not against the form's
        // initial state — a save is what the operator meant, whatever the form had shown.
        $carried = collect(self::chargeEscalationRows($this->record))->keyBy('type');
        $schedule = app(ChargeScheduleService::class);

        foreach ($this->data['charge_escalations'] ?? [] as $row) {
            $type = (string) ($row['type'] ?? '');

            if ($type === '' || in_array($type, ChargeEscalation::DERIVED_TYPES, true)) {
                continue;
            }

            $mode = in_array($row['escalation_mode'] ?? null, ChargeEscalation::MODES, true)
                ? $row['escalation_mode']
                : ChargeEscalation::NONE;

            // Normalised BY MODE before comparing, exactly as the model stores it: a hidden figure
            // lingers in the repeater's state after a mode switch (percent → fixed amount keeps
            // the rate box's value), the model clears it on write, and comparing the raw state
            // then re-minted the ladder on every later save (found by review).
            $ruled = [
                'escalation_mode' => $mode,
                'escalation_rate' => $mode === ChargeEscalation::PERCENT && filled($row['escalation_rate'] ?? null)
                    ? round((float) $row['escalation_rate'], 2)
                    : null,
                'escalation_amount' => $mode === ChargeEscalation::FIXED_AMOUNT && filled($row['escalation_amount'] ?? null)
                    ? round((float) $row['escalation_amount'], 2)
                    : null,
            ];

            $standing = $carried->get($type);

            if ($standing !== null
                && $standing['escalation_mode'] === $ruled['escalation_mode']
                && $standing['escalation_rate'] === $ruled['escalation_rate']
                && $standing['escalation_amount'] === $ruled['escalation_amount']) {
                continue;
            }

            $schedule->setEscalation($this->record, $type, $ruled['escalation_mode'], $ruled['escalation_rate'], $ruled['escalation_amount']);
        }

        // The marketing levy is NOT re-synced here any more (2026-09-11). `Lease::updated` does
        // it — base row first, then the projected levy rungs — for every door that writes the
        // levy's two columns, this page included. Doing it here AFTER the hook is what cost the
        // levy its first future step when toggled on (the projection opened the levy from
        // commencement at the stepped amount, and this re-sync then overwrote it with the base),
        // and running it on every save, as it did before, is the door through which the final
        // projected levy rung was overwritten with the base levy on staging (400 → 50).
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
            // The AGREEMENT itself. Ungrouped and beside the acts rather than inside one of the
            // three dropdowns, because it is not a verb on the tenancy — it hands back a file and
            // changes nothing, which is the same distinction `RowActionPolicy` draws when it
            // declines to count a download as a write.
            PdfDownloadAction::make('downloadAgreement')
                ->label(__('admin.pdf.lease_agreement'))
                ->icon('heroicon-o-document-text')
                ->service(LeaseAgreementPdfService::class)
                ->recipient(fn (Lease $record) => $record->tenant),
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
            // BILLABLE_STATUSES, not the literal — two copies of "which leases bill" is exactly
            // how the manual and scheduled paths drifted apart before, and a `future` lease in its
            // commencement month is billed by the batch run at 02:00.
            ->visible(fn (Lease $record) => in_array($record->status, Lease::BILLABLE_STATUSES, true))
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
                    ->default(now()->startOfMonth()->toDateString())
                    // The same window the Billing Run Preview offers. This picker carried no bounds
                    // at all, so one screen refused to PREVIEW a month the other would happily
                    // BILL — a receivable raisable years early, posting revenue into a period that
                    // may not exist and dating an e-invoice into the future. The bounds are the UI
                    // half; the closure below is the gate.
                    ->minDate(BillingWindow::earliest())
                    ->maxDate(BillingWindow::latest()),
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
