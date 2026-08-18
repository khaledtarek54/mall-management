<?php

namespace App\Filament\Portal\Resources\Leases\Schemas;

use App\Models\Lease;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The tenant's own lease terms, read-only — what they're paying, for what, until when. Native
 * infolist (no hand-rolled Blade), the money terms grouped so a tenant can actually read them.
 */
class LeaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.portal.lease.terms'))
                ->columns(3)
                ->components([
                    TextEntry::make('reference')->label(__('admin.fields.reference')),
                    TextEntry::make('unit.code')->label(__('admin.resources.unit.singular'))->badge(),
                    TextEntry::make('unit.asset.name')->label(__('admin.fields.property')),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                        ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                    TextEntry::make('commencement_date')->label(__('admin.fields.commencement_date'))->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('expiry_date')->label(__('admin.fields.expiry_date'))->date('d/m/Y')->placeholder('—'),
                    // Only where rent actually starts later than the term — otherwise it is noise
                    // that repeats the commencement date. A tenant in fit-out should be able to see
                    // when they start paying without asking.
                    TextEntry::make('rent_commencement_date')
                        ->label(__('admin.fields.rent_commencement_date'))
                        ->date('d/m/Y')
                        ->visible(fn (Lease $record) => $record->rent_commencement_date !== null),
                ]),

            Section::make(__('admin.portal.lease.charges'))
                ->columns(3)
                ->components([
                    TextEntry::make('base_rent_monthly')->label(__('admin.fields.base_rent_monthly'))->money('EGP'),
                    TextEntry::make('service_charge_monthly')->label(__('admin.fields.service_charge_monthly'))->money('EGP')->placeholder('—'),
                    TextEntry::make('billing_frequency')
                        ->label(__('admin.fields.billing_frequency'))
                        ->formatStateUsing(fn (?string $state) => $state ? __("admin.billing_frequency.{$state}") : '—'),
                    // **What the tenant needs to know about their deposit is three numbers, not one.**
                    // This showed the CONTRACTED figure alone — so a tenant who had paid 150,000 of
                    // an agreed 180,000 saw "180,000" and could not tell whether that was a bill, a
                    // receipt, or a number from their contract. They could not see what they had
                    // paid, what was outstanding, or that anything was outstanding at all.
                    TextEntry::make('security_deposit')
                        ->label(__('admin.portal.deposit.agreed'))
                        ->money('EGP')
                        ->placeholder('—'),
                    TextEntry::make('deposit_held')
                        ->label(__('admin.portal.deposit.paid'))
                        ->getStateUsing(fn (Lease $record) => $record->depositHeld())
                        ->money('EGP')
                        ->color(fn (Lease $record) => $record->depositShortfall() > 0 ? 'warning' : 'success'),
                    TextEntry::make('deposit_outstanding')
                        ->label(__('admin.portal.deposit.outstanding'))
                        ->getStateUsing(fn (Lease $record) => $record->depositShortfall())
                        ->money('EGP')
                        ->weight('bold')
                        ->color(fn (Lease $record) => $record->depositShortfall() > 0 ? 'danger' : 'gray')
                        // The instruction, only when there IS something to act on. A standing "how
                        // to pay" line under a settled deposit is noise; under an unpaid one it is
                        // the whole point, because a deposit is never invoiced — nothing else in
                        // the portal will ever ask them for it.
                        ->helperText(fn (Lease $record) => $record->depositShortfall() > 0
                            ? __('admin.portal.deposit.how_to_pay')
                            : __('admin.portal.deposit.settled')),
                    // Only meaningful when a levy applies — spelled out as a rate the tenant can check.
                    TextEntry::make('marketing_levy_rate')
                        ->label(__('admin.fields.marketing_levy_rate'))
                        ->visible(fn (Lease $record) => (bool) $record->has_marketing_levy)
                        ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),
                    // Honest for every shape the escalation can take. This was keyed on
                    // `escalation_rate > 0`, which is ZERO on a fixed-AMOUNT lease — so a tenant
                    // whose rent steps by EGP 5,000 every year was shown nothing at all about it.
                    // Their own contract, invisible on their own portal.
                    TextEntry::make('escalation_rate')
                        ->label(__('admin.portal.lease.escalation'))
                        ->visible(fn (Lease $record) => $record->escalation_type === 'fixed_amount'
                            ? (float) $record->escalation_amount > 0
                            : (float) $record->escalation_rate > 0)
                        ->formatStateUsing(function (Lease $record): string {
                            $when = $record->next_escalation_date?->format('d/m/Y') ?? '—';

                            if ($record->escalation_type === 'fixed_amount') {
                                return number_format((float) $record->escalation_amount, 2).' EGP · '.$when;
                            }

                            $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.').'%';
                            $line = $pct($record->escalation_rate).' · '.$when;

                            // The collar is part of what the tenant agreed to pay — a cap they
                            // negotiated is worth more to them than the headline rate.
                            $bounds = array_filter([
                                $record->escalation_floor_rate !== null
                                    ? __('admin.portal.lease.escalation_min', ['pct' => $pct($record->escalation_floor_rate)])
                                    : null,
                                $record->escalation_ceiling_rate !== null
                                    ? __('admin.portal.lease.escalation_max', ['pct' => $pct($record->escalation_ceiling_rate)])
                                    : null,
                            ]);

                            return $bounds ? $line.' ('.implode(', ', $bounds).')' : $line;
                        }),
                ]),

            // The bays, storage and signage this lease holds. Without it a tenant sees a
            // "Parking & rentable items" line on the invoice with no way to check WHICH bays they
            // are paying for or at what rate — the most common billing query there is.
            Section::make(__('admin.lease_rentable_items.title'))
                ->visible(fn (Lease $record) => $record->rentableItems()->wherePivotNull('effective_to')->exists())
                ->components([
                    RepeatableEntry::make('rentableItems')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('code')->label(__('admin.fields.item_code')),
                            TextEntry::make('type')
                                ->label(__('admin.fields.type'))
                                ->formatStateUsing(fn (string $state) => __('admin.enums.rentable_item_type')[$state] ?? $state),
                            TextEntry::make('pivot.monthly_rate')
                                ->label(__('admin.fields.item_monthly_rate'))
                                ->money('EGP'),
                        ]),
                ]),

            // Percentage rent is only shown to the tenants it applies to — a plain-language summary
            // of the threshold + rate so they know when overage kicks in.
            Section::make(__('admin.portal.lease.percentage_rent'))
                ->visible(fn (Lease $record) => (bool) $record->has_percentage_rent)
                ->columns(3)
                ->components([
                    TextEntry::make('percentage_rent_rate')
                        ->label(__('admin.portal.lease.pct_rate'))
                        ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),
                    TextEntry::make('percentage_rent_threshold')
                        ->label(__('admin.portal.lease.pct_threshold'))
                        ->money('EGP')
                        ->placeholder('—'),
                    TextEntry::make('percentage_rent_frequency')
                        ->label(__('admin.fields.percentage_rent_frequency'))
                        ->formatStateUsing(fn (?string $state) => __("admin.enums.percentage_rent_frequency.{$state}") !== "admin.enums.percentage_rent_frequency.{$state}"
                            ? __("admin.enums.percentage_rent_frequency.{$state}")
                            : __('admin.enums.percentage_rent_frequency.monthly')),
                ]),
        ]);
    }
}
