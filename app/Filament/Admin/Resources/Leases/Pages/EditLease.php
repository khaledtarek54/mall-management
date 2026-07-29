<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLease extends EditRecord
{
    protected static string $resource = LeaseResource::class;

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
        $this->record->syncUnits(
            [$this->record->unit_id, ...$additional],
            $this->record->unit_id,
        );

        // Re-sync the marketing levy charge so a toggle/rate change on the form takes effect
        // (activates/deactivates + re-rates the `marketing` charge for the next monthly run).
        app(\App\Services\MarketingLevyService::class)->createLevyCharge($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->generateInvoiceAction(),
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
                DatePicker::make('period')
                    ->label(__('admin.actions.billing_period'))
                    ->helperText(__('admin.actions.billing_period_helper'))
                    ->displayFormat('F Y')
                    ->format('Y-m-01')
                    ->required()
                    ->default(now()->startOfMonth()->toDateString())
                    ->native(false),
                Toggle::make('prorate')
                    ->label(__('admin.actions.prorate_first_period'))
                    ->helperText(__('admin.actions.prorate_helper'))
                    ->default(true),
            ])
            ->action(function (array $data, Lease $record): void {
                $period = CarbonImmutable::parse($data['period'])->startOfMonth();
                $result = app(MonthlyBillingService::class)
                    ->generateForLease($record, $period, (bool) ($data['prorate'] ?? false));

                if ($result['status'] === 'created') {
                    Notification::make()
                        ->title(__('admin.actions.invoice_created'))
                        ->body(__('admin.actions.invoice_created_body', [
                            'number' => $result['invoice']->number,
                            'total' => 'EGP ' . number_format((float) $result['invoice']->total, 2),
                        ]))
                        ->success()
                        ->send();

                    return;
                }

                // A refusal, not a failure — say which of the three it is, because "this lease
                // cannot be billed" is useless without telling the operator whether to change the
                // status, wait for commencement, or stop billing an ended lease.
                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'lease_not_billable') {
                    $why = match (true) {
                        $record->status !== 'active' => __('admin.actions.not_billable_status', [
                            'status' => __('admin.statuses.lease.'.$record->status, [], $record->status),
                        ]),
                        $record->expiry_date && $period->greaterThan(\Carbon\CarbonImmutable::instance($record->expiry_date)->endOfMonth())
                            => __('admin.actions.not_billable_expired', ['date' => $record->expiry_date->format('d/m/Y')]),
                        default => __('admin.actions.not_billable_not_started', [
                            'date' => $record->commencement_date?->format('d/m/Y') ?? '—',
                        ]),
                    };

                    Notification::make()
                        ->title(__('admin.actions.not_billable_title', ['period' => $period->format('F Y')]))
                        ->body($why)
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'already_billed') {
                    Notification::make()
                        ->title(__('admin.actions.already_billed_title'))
                        ->body(__('admin.actions.already_billed_body', ['period' => $period->format('F Y')]))
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'no_applicable_charges') {
                    Notification::make()
                        ->title(__('admin.actions.no_charges_title'))
                        ->body(__('admin.actions.no_charges_body'))
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'fit_out') {
                    Notification::make()
                        ->title(__('admin.actions.fit_out_title'))
                        ->body(__('admin.actions.fit_out_body', ['period' => $period->format('F Y')]))
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'off_cycle') {
                    Notification::make()
                        ->title(__('admin.actions.off_cycle_title'))
                        ->body(__('admin.actions.off_cycle_body', [
                            'period' => $period->format('F Y'),
                            'frequency' => __('admin.billing_frequency.' . $record->billing_frequency),
                        ]))
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'run_in_progress') {
                    Notification::make()
                        ->title(__('admin.actions.run_in_progress_title'))
                        ->body(__('admin.actions.run_in_progress_body'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.actions.generation_failed'))
                    ->body(__('admin.actions.generation_failed_body'))
                    ->danger()
                    ->send();
            });
    }
}
