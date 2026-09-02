<?php

use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Services\Accounting\Journalizers\CreditNoteJournalizer;
use App\Services\CreditNotePdfService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Symfony\Component\Finder\Finder;

/**
 * "IS THIS NOTE ON THE BOOKS?" IS ONE QUESTION, AND IT WAS ASKED FOUR WAYS.
 *
 * `CreditNote::NOT_ON_THE_BOOKS` is the register — `draft` (never issued, nothing posted, the tenant
 * has never seen it) and `void` (reversed) — with a reason written against each. Three other places
 * re-answered it, and each got it right only by coincidence:
 *
 *  - **`CreditNoteJournalizer`** allowed `['issued', 'applied']`. That is the complement of the
 *    register *today*. A fifth status would be COUNTED by every documents-side read (they EXCLUDE
 *    the two that are off the books) and SKIPPED by the GL, silently, in the direction where the
 *    books and the documents disagree.
 *  - **`CreditNote::hasBalance()`** re-listed the same pair, so a fifth status would have been
 *    spendable while the GL ignored it.
 *  - **`BooksReconciliationService`** matched a backing note by ID with NO status filter at all, so
 *    a CAM allocation still `billed` whose credit note had been VOIDED passed `$hasCredit` and the
 *    reconciler reported clean. **A check that cannot fail** — the family this project gates for.
 *
 * And the PDF watermarked `void` alone, so a DRAFT note downloaded as a clean, numbered tax
 * document. `credit_notes.status` DEFAULTS to draft at the column, so that is the ordinary state of
 * a note somebody is still composing — and a tenant handed one files it with their own accountant
 * and claims a reduction that was never issued.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
});

function noteWithStatus(string $status): CreditNote
{
    return CreditNote::create([
        'number' => 'CN-'.uniqid(),
        'tenant_id' => test()->tenant->id,
        'asset_id' => test()->asset->id,
        'status' => $status,
        'issue_date' => now(),
        'reason' => 'adjustment',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'applied_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);
}

it('posts an issued note and skips one that is off the books', function () {
    // The control first: a live note posts.
    expect(app(CreditNoteJournalizer::class)->payload(noteWithStatus('issued')))->not->toBeNull();

    foreach (array_keys(CreditNote::NOT_ON_THE_BOOKS) as $status) {
        expect(app(CreditNoteJournalizer::class)->payload(noteWithStatus($status)))
            ->toBeNull("a `{$status}` note reached the GL");
    }
});

it('derives the GL gate from the register rather than re-listing it', function () {
    // The property that matters is not "these two statuses are skipped" — it is that the GL and the
    // documents read ONE list. A hand-rolled allowlist agrees with the register today and diverges
    // the day a fifth status ships, which is precisely when nobody is looking.
    $source = file_get_contents(base_path('app/Services/Accounting/Journalizers/CreditNoteJournalizer.php'));

    // The gate above is the general one; this pins the two specific call sites that were wrong, so
    // a failure names them rather than only naming a pattern.
    expect($source)->toContain('isOnTheBooks()');

    $model = file_get_contents(base_path('app/Models/CreditNote.php'));

    // …and `hasBalance()` was the third copy.
    expect($model)->toContain('$this->isOnTheBooks()');
});

it('lets no other file re-list the pair', function () {
    // The sweep, because two copies were found by reading and a third only by grepping. Anything
    // that needs the judgement asks `isOnTheBooks()` / `onTheBooks()`.
    $offenders = [];
    $examined = 0;

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        // **CODE ONLY.** The first version swept raw source and reported three files whose only
        // mention of the pair is a DOCBLOCK explaining this very defect. A gate that fires on a
        // sentence is one that gets weakened rather than fixed — this project has recorded that
        // twice — and here it would have buried the two REAL copies the sweep did find.
        $source = collect(token_get_all($file->getContents()))
            ->reject(fn ($token) => is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        if (! str_contains($source, 'CreditNote')) {
            continue;
        }

        $examined++;

        if (preg_match("/'issued'\s*,\s*'applied'/", $source) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    // It found two the reading had missed — `CreateCreditNote`'s closed-period guard and
    // `ReportService`'s credit-note total — which is the whole argument for sweeping rather than
    // reading.
    expect($offenders)->toBe([], 'These re-list the on-the-books statuses instead of asking '
        .'CreditNote::isOnTheBooks(), so a fifth status would divide the books from the documents: '
        .implode(', ', $offenders))
        // What the sweep EXAMINED — a matcher that stopped matching would report zero of zero.
        ->and($examined)->toBeGreaterThan(15);
});

it('watermarks a DRAFT note, not only a void one', function () {
    // A draft downloaded as a clean numbered tax document is the worst of the three, because the
    // column defaults to draft and neither download action gates on status.
    $service = file_get_contents(base_path('app/Services/CreditNotePdfService.php'));

    expect($service)->toContain('isOnTheBooks()')
        ->and($service)->not->toContain("\$note->status === 'void'");

    // And it renders — a watermark closure that threw would be a 500 on the download.
    foreach (['draft', 'void', 'issued'] as $status) {
        expect(app(CreditNotePdfService::class)->build(noteWithStatus($status)))
            ->toBeString()
            ->and(substr(app(CreditNotePdfService::class)->build(noteWithStatus($status)), 0, 5))
            ->toBe('%PDF-');
    }
});

it('makes the reconciler able to fail on a VOIDED backing note', function () {
    // It matched by ID with no status filter, so the one sweep that exists to notice said nothing.
    $source = file_get_contents(base_path('app/Services/Reconciliation/BooksReconciliationService.php'));

    expect($source)->toContain('->onTheBooks()->pluck(\'id\')');
});
