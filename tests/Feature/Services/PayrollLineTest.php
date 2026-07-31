<?php

use App\Models\Employee;
use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\PayslipPdfService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

function lineEmployee(int $assetId, array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'E-'.uniqid(),
        'name' => 'Mona Adel',
        'hire_date' => '2026-01-01',
        'base_salary' => 8000,
        'payment_method' => 'bank',
    ], $attrs));
}

function draftRun(int $assetId): Payroll
{
    return Payroll::create([
        'asset_id' => $assetId,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);
}

it('derives the run header from the sum of the lines', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500]);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 6000, 'salary_tax' => 400, 'social_insurance' => 300]);

    $run->refresh();
    expect((float) $run->gross_salaries)->toBe(16000.0);
    expect((float) $run->salary_tax)->toBe(1400.0);
    expect((float) $run->social_insurance)->toBe(800.0);
    expect((float) $run->net_paid)->toBe(13800.0); // 16000 − 1400 − 800
});

it('computes a line net = gross − tax − insurance', function () {
    $asset = makeAsset();
    $line = draftRun($asset->id)->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 9000, 'salary_tax' => 800, 'social_insurance' => 600]);

    expect($line->net)->toBe(7600.0);
});

it('breaks gross into basic + allowances and leaves net + basic derived (Phase 4a)', function () {
    $asset = makeAsset();
    $line = draftRun($asset->id)->lines()->create([
        'employee_id' => lineEmployee($asset->id)->id,
        'gross' => 10000, 'allowances' => 2500, 'salary_tax' => 800, 'social_insurance' => 600,
        'employer_social_insurance' => 1875,
    ]);

    expect($line->basic)->toBe(7500.0);   // gross − allowances
    expect($line->net)->toBe(8600.0);     // gross − tax − insurance (employer SI NOT deducted)
});

it('rejects allowances that exceed gross (they are a portion of it)', function () {
    $asset = makeAsset();

    expect(fn () => draftRun($asset->id)->lines()->create([
        'employee_id' => lineEmployee($asset->id)->id, 'gross' => 5000, 'allowances' => 6000,
    ]))->toThrow(DomainException::class);
});

it('deducts ad-hoc/other deductions from net and rolls them to the header (Phase 4c)', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $line = $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id,
        'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500, 'other_deductions' => 300, 'deduction_note' => 'absence 1 day']);

    expect($line->net)->toBe(8200.0);                                  // 10000 − 1000 − 500 − 300
    expect((float) $run->fresh()->other_deductions)->toBe(300.0);
    expect((float) $run->fresh()->net_paid)->toBe(8200.0);
});

it('rejects a line whose other deductions drive net negative (Phase 4c)', function () {
    $asset = makeAsset();

    expect(fn () => draftRun($asset->id)->lines()->create(['employee_id' => lineEmployee($asset->id)->id,
        'gross' => 5000, 'salary_tax' => 1000, 'social_insurance' => 500, 'other_deductions' => 4000])) // < 0
        ->toThrow(DomainException::class);
});

it('rolls employer social insurance up to the header without touching net (Phase 4a)', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500, 'employer_social_insurance' => 1875]);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 6000, 'salary_tax' => 400, 'social_insurance' => 300, 'employer_social_insurance' => 1125]);

    $run->refresh();
    expect((float) $run->employer_social_insurance)->toBe(3000.0);          // Σ employer SI
    expect((float) $run->net_paid)->toBe(13800.0);                          // unchanged: 16000 − 1400 − 800
});

it('recomputes the header down when a line is deleted', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $a = $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500]);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 6000]);

    expect((float) $run->fresh()->gross_salaries)->toBe(16000.0);

    $a->delete();
    expect((float) $run->fresh()->gross_salaries)->toBe(6000.0);
});

it('resets the header to zero when the LAST line is deleted (no stale phantom)', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $line = $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 5000, 'salary_tax' => 300, 'social_insurance' => 200]);
    expect((float) $run->fresh()->gross_salaries)->toBe(5000.0);

    $line->delete(); // removing the last line must not leave a GL-live phantom header

    $run->refresh();
    expect((float) $run->gross_salaries)->toBe(0.0);
    expect((float) $run->salary_tax)->toBe(0.0);
    expect((float) $run->social_insurance)->toBe(0.0);
    expect((float) $run->net_paid)->toBe(0.0);
});

it('keeps a soft-deleted employee resolvable on their payslip line (reproducible after turnover)', function () {
    $asset = makeAsset();
    $employee = lineEmployee($asset->id, ['name' => 'Departed Staff']);
    $line = draftRun($asset->id)->lines()->create(['employee_id' => $employee->id, 'gross' => 9000, 'salary_tax' => 800, 'social_insurance' => 600]);

    trashBypassingDeletionPolicy($employee); // staff turnover after the run

    // The line still resolves the (trashed) employee, so the payslip isn't anonymous.
    expect($line->fresh()->employee)->not->toBeNull();
    expect($line->fresh()->employee->name)->toBe('Departed Staff');
    expect(app(PayslipPdfService::class)->build($line->fresh()))->toContain('%PDF');
});

it('posts the line-driven header to the GL when the run is approved', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500]);
    $run->lines()->create(['employee_id' => lineEmployee($asset->id)->id, 'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500]);
    $run->update(['status' => 'approved']);

    $entry = $this->poster->post($run->fresh());

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('salaries_expense')]->debit)->toEqualWithDelta(20000.0, 0.001); // Σ gross
    expect((float) $byAccount[$this->accounts->id('salary_tax_payable')]->credit)->toEqualWithDelta(2000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('social_insurance_payable')]->credit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('bank')]->credit)->toEqualWithDelta(17000.0, 0.001); // net
});

it('renders a payslip PDF for a line', function () {
    $asset = makeAsset();
    $run = draftRun($asset->id);
    $line = $run->lines()->create(['employee_id' => lineEmployee($asset->id, ['name' => 'Mona Adel'])->id, 'gross' => 9000, 'salary_tax' => 800, 'social_insurance' => 600]);

    $pdf = app(PayslipPdfService::class)->build($line->fresh());

    expect($pdf)->toBeString();
    expect(substr($pdf, 0, 4))->toBe('%PDF');
    expect(app(PayslipPdfService::class)->filename($line))->toContain('payslip-');
});
