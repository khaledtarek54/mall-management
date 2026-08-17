<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSalesDeclaration>
 */
class TenantSalesDeclarationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable turnover-sales declaration. The
     * (lease_id, period_start) pair is uniquely constrained, so callers that
     * create several declarations for the same lease must vary the period (use
     * the ->forPeriod()/->forMonth() helpers). By default the period is a whole
     * calendar month and the declaration is freshly 'submitted' (unlocked), so
     * the nullable lock/audit columns stay null — mirroring how a tenant's
     * just-filed declaration looks before the operator reviews it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Anchor to the first day of a recent month and run to the last day,
        // so period_start is a clean monthly boundary that satisfies the
        // (lease_id, period_start) unique key when varied per call.
        $periodStart = CarbonImmutable::parse(fake()->dateTimeBetween('-18 months', '-1 month'))
            ->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();

        // Declared turnover for the period; percentage rent is a slice of it
        // (a few percent), well under the sales figure — never an invariant
        // violation. States/callers that need an exact lease-derived figure
        // can override calculated_percentage_rent.
        $declaredSales = fake()->randomFloat(2, 50_000, 1_500_000);
        $calculatedPercentageRent = round($declaredSales * fake()->randomFloat(4, 0.03, 0.10), 2);

        return [
            // Required FK — Lease has its own factory (builds Unit/Tenant/Asset).
            'lease_id' => Lease::factory(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'declared_sales' => $declaredSales,
            'calculated_percentage_rent' => $calculatedPercentageRent,
            'declared_at' => $periodEnd->addDays(fake()->numberBetween(1, 10)),
            // Nullable morph: who filed it. Default to the tenant's portal user
            // (the usual self-service path); locked_at/locked_by/audit_notes stay
            // null until an operator locks or disputes the row.
            'declared_by_type' => null,
            'declared_by_id' => null,
            'status' => 'submitted',
            'locked_at' => null,
            'locked_by_user_id' => null,
            'audit_notes' => null,
        ];
    }

    /**
     * Force the declaration onto a specific monthly period for a given (or the
     * default) lease — the precise pair the unique key cares about.
     */
    public function forPeriod(\DateTimeInterface|string $periodStart): static
    {
        $start = CarbonImmutable::parse($periodStart)->startOfMonth();

        return $this->state(fn (array $attributes) => [
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'declared_at' => $start->endOfMonth()->addDays(3),
        ]);
    }

    /**
     * Stamp the declaration as filed by a tenant's portal user (the self-service
     * path). Creates a TenantUser when none is supplied.
     */
    public function declaredByTenantUser(?TenantUser $tenantUser = null): static
    {
        return $this->state(fn (array $attributes) => [
            'declared_by_type' => (new TenantUser)->getMorphClass(),
            'declared_by_id' => $tenantUser?->getKey() ?? TenantUser::factory(),
        ]);
    }

    /**
     * A locked declaration — operator has reviewed and frozen the figure.
     * Locking requires a locking user and a lock timestamp.
     */
    public function locked(?User $lockedBy = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'locked',
            'locked_at' => CarbonImmutable::parse($attributes['declared_at'] ?? now())->addDays(2),
            'locked_by_user_id' => $lockedBy?->getKey() ?? User::factory(),
        ]);
    }

    /**
     * A disputed declaration — flagged by the operator, with audit notes.
     */
    public function disputed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disputed',
            'audit_notes' => fake()->sentence(),
        ]);
    }
}
