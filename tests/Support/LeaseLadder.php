<?php

namespace Tests\Support;

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Lease;
use Livewire\Livewire;

/**
 * The tester's lease and its ladder, as one string — shared by the escalation and the term
 * regression files (`AnEscalationClauseEditRetruesItsLadderTest`, `ALeaseTermEditRedatesItsScheduleTest`).
 *
 * A class rather than file-scope helpers because a parallel worker loads only the files it owns
 * and re-declaring a helper across two files is a fatal redeclaration that exits the suite with
 * no output (`TestHelperUniquenessConformanceTest` names the pair; this is the second call site,
 * which is when a helper is extracted).
 */
final class LeaseLadder
{
    /** The tester's lease, through the real create form: 1,000 rent, 250 service, 10 % yearly, levy 5 %. */
    public static function testersLease(Asset $asset, int $rate = 10, string $commencement = '2026-09-10', string $expiry = '2029-09-09'): Lease
    {
        $unit = makeUnit($asset, ['code' => 'E-'.uniqid(), 'status' => 'vacant']);
        $tenant = makeTenant();

        Livewire::test(CreateLease::class)->fillForm([
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'commencement_date' => $commencement, 'term_months' => 36, 'expiry_date' => $expiry,
            'base_rent_monthly' => 1000, 'service_charge_monthly' => 250,
            'has_marketing_levy' => true, 'marketing_levy_rate' => 5,
            'escalation_type' => 'fixed_percent', 'escalation_rate' => $rate,
            'escalation_interval_months' => null, 'security_deposit_months' => 3,
        ])->call('create')->assertHasNoFormErrors();

        return Lease::where('tenant_id', $tenant->id)->sole();
    }

    /** Save the real edit page with these fields changed. */
    public static function edit(Lease $lease, array $data): void
    {
        Livewire::test(EditLease::class, ['record' => $lease->getKey()])
            ->fillForm($data)->call('save')->assertHasNoFormErrors();
    }

    /** One type's active rows as `amount@Y-m`, so a whole shape is one string. */
    public static function rungs(Lease $lease, string $type): string
    {
        return $lease->charges()->where('type', $type)->where('is_active', true)->orderBy('start_date')->get()
            ->map(fn (Charge $c) => number_format((float) $c->amount, 0, '.', '').'@'.$c->start_date->format('Y-m'))
            ->implode(' ');
    }

    /** The same, to the DAY — for the rows a term edit moves. */
    public static function rungsByDay(Lease $lease, string $type): string
    {
        return $lease->charges()->where('type', $type)->where('is_active', true)->orderBy('start_date')->get()
            ->map(fn (Charge $c) => number_format((float) $c->amount, 0, '.', '').'@'.$c->start_date->toDateString().'..'.($c->end_date?->toDateString() ?? 'open'))
            ->implode(' ');
    }
}
