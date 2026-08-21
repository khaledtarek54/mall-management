<?php

/*
|--------------------------------------------------------------------------
| Nothing resolves a property by walking `lease -> unit -> asset`
|--------------------------------------------------------------------------
| `invoices.lease_id` became NULLABLE with module 37: a unit OWNER pays a صيانة assessment, and
| `UnitOwnership::invoiceLinkAttributes()` returns `lease_id => null` with
| `Invoice::assertBelongsToExactlyOneAgreement()` ENFORCING it. So for an assessment the chain
| `$invoice->lease?->unit?->asset_id` is null BY CONSTRUCTION, not by accident.
|
| It was fixed four times, one call site at a time, and each fix announced a sweep:
|   * the migration's own docblock enumerated "FOUR places" and fixed those four
|   * `c6fb8ecb` fixed `InvoicePdfService` and claimed "resolved in one place"
|   * `7711e54a` swept "two more PDF peers" — and stopped at PDFs
| Five live sites were still standing after all of that, and the worst had no symptom anyone could
| see: `ApplyTenantCreditService` refused with a `DomainException`, which `Invoice::saved()` catches
| as "the ordinary case — most invoices have no credit" WITHOUT a log line. With
| `auto_apply_tenant_credit` shipping true, no unit owner's credit had ever been drawn down and the
| monthly run re-billed them in full.
|
| A grep takes two seconds. Nobody ran it, four times running. So it is a gate now.
|
| The rule this enforces is narrow and mechanical: no code under `app/` may reach an ASSET through a
| subject's `lease` relation — `$x->lease?->unit?->asset`. A call site whose subject genuinely cannot
| exist without a lease exempts itself WITH A REASON. That is the right way round: an exemption is
| read by a human, a missing sweep is read by nobody.
|
| ## What this gate does NOT catch, stated rather than implied
|
| It matches the explicit relation hop `->lease?->unit?->asset`. It does NOT match the same walk
| written through a local variable:
|
|     $lease = $allocation->lease;      // nullable for an ownership allocation
|     $asset = $lease?->unit?->asset;   // invisible to this gate
|
| That is deliberate, not an oversight. Matching the local form flags eight services that take a
| `Lease` as their SUBJECT — move-out, space change, straight-line rent, cheque coverage, deposits —
| where the chain is correct and can never be null. Eight exemptions to catch one more site is how a
| gate becomes something people add a line to without reading. `CamStatementPdfService` was that one
| site; it now branches on the concrete agreement. If a second one appears, tighten this then.
*/

use Illuminate\Support\Facades\File;

/**
 * Subjects whose `lease` is genuinely never null, so the chain is safe there.
 *
 * Each entry must say WHY the subject cannot exist without a lease. "It has always worked" is not a
 * reason; the four sites above had always worked too.
 */
const LEASE_BOUND_SUBJECTS = [
    'app/Filament/Portal/Resources/TenantSalesDeclarations/Pages/CreateTenantSalesDeclaration.php' => 'A sales declaration exists to compute PERCENTAGE RENT, which is a lease clause. `tenant_sales_declarations.lease_id` is NOT NULL, and a unit owner declares no turnover.',
    'app/Console/Commands/ScanLeaseOptionWindowsCommand.php' => 'A lease OPTION is a clause of a lease by definition — `lease_options.lease_id` is NOT NULL, so the subject cannot exist without one.',
];

it('never resolves a property by walking the lease chain, except where the subject is lease-bound', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $body = $file->getContents();

        // Strip comments before matching: this very test's neighbours DESCRIBE the chain in prose
        // to explain why they no longer use it, and a gate that fires on its own documentation
        // teaches people to delete the documentation.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $body) ?? $body;

        if (! preg_match('~->lease\??->unit\??->asset~', $code)) {
            continue;
        }

        if (array_key_exists($relative, LEASE_BOUND_SUBJECTS)) {
            continue;
        }

        $offenders[] = $relative;
    }

    expect($offenders)->toBe([], implode("\n", [
        'These resolve a property by walking `lease -> unit -> asset`, which is NULL for a unit-owner',
        'assessment (invoices.lease_id / cam_allocations.lease_id are nullable by design):',
        '  '.implode("\n  ", $offenders),
        '',
        'Use the subject\'s OWN `asset_id` (invoices and cam_allocations both carry one, NOT NULL and',
        'enforced on save), or branch on the concrete agreement. If the subject genuinely cannot exist',
        'without a lease, add it to LEASE_BOUND_SUBJECTS with the reason.',
    ]));
});

it('has no stale exemption', function () {
    // An exemption for a file that no longer contains the chain reads as coverage and is not.
    $stale = [];

    foreach (array_keys(LEASE_BOUND_SUBJECTS) as $relative) {
        $path = base_path($relative);

        if (! file_exists($path)) {
            $stale[] = "{$relative} (file is gone)";

            continue;
        }

        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents($path)) ?? '';

        if (! preg_match('~->lease\??->unit\??->asset~', $code)) {
            $stale[] = "{$relative} (no longer walks the chain)";
        }
    }

    expect($stale)->toBe([], 'Remove these from LEASE_BOUND_SUBJECTS: '.implode(', ', $stale));
});

it('gives every exemption a reason a reviewer can weigh', function () {
    foreach (LEASE_BOUND_SUBJECTS as $relative => $reason) {
        // The four missed sweeps all had a one-line justification that turned out to be an
        // assumption. A reason has to name the column or the model rule that makes it true.
        expect(strlen($reason))->toBeGreaterThan(60, "The exemption for {$relative} does not say why the lease can never be null.");
    }
});

it('proves the sweep can actually find the chain', function () {
    // The gate above passes trivially if the pattern never matches anything. This project has
    // shipped a conformance test that swept ZERO models and stayed green for a year.
    $probe = <<<'PHP'
    <?php
    $assetId = $invoice->lease?->unit?->asset_id;
    PHP;

    $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $probe) ?? $probe;

    expect(preg_match('~->lease\??->unit\??->asset~', $code))->toBe(1)
        ->and(count(LEASE_BOUND_SUBJECTS))->toBeGreaterThan(0);
});
