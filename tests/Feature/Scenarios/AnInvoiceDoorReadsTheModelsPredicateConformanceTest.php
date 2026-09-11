<?php

use App\Support\Modules;
use App\Support\PhpSource;
use Tests\Support\ActionStrips;

/*
|--------------------------------------------------------------------------
| An invoice door never restates a status rule as a literal (2026-09-12)
|--------------------------------------------------------------------------
| The invoice has more doors than any other record and ONE register for the question that every
| status literal was trying to answer (`App\Support\InvoiceSettlement`, plus the model's own
| `isPayable()`, `isOverdue()`, `stillOwed()`, `creditable()`, `canDisputeLines()`,
| `voidBlockedBecause()`). Measured before this gate: the record page's *Void* button carried a
| status ALLOWLIST beside the service's DENYLIST under a comment saying they could not drift; the
| *Write off* button and the lease tab's *Record payment* each restated `isPayable()`; two credit-
| note pickers and the cheque picker each carried a hand-kept "open invoices" list (the model's
| `stillOwed()` docblock records 23 such copies, with `disputed` missing from every one); both
| invoice tables and the tenant tab coloured or captioned the due date by "past due unless paid /
| cancelled" — the ninth spelling of `isOverdue()`; and the register's own Overdue TAB read the
| stored stamp, which a `partially_paid` invoice can never carry.
|
| The rule: a decision about an INVOICE's status in a Filament file is a call on the model, never
| an array of strings. WHOSE status is read off the file itself, never off a list of files:
|   - `in_array($x->status, ['…'])` — `$x` is an invoice when the nearest closure typing it says
|     `Invoice $x`, when it is named `$invoice`, or when it is `$this->record` under
|     `Resources/Invoices/`;
|   - `whereIn('status', ['…'])` — the query is an invoice's when its nearest ROOT before the call
|     is `Invoice::…`, or, with no root in sight (a relation manager's `$query`, a tab's `$query`),
|     when the file is the invoice resource's or an `*Invoice*RelationManager`.
| The first cut keyed on `use App\Models\Invoice;` and reported the credit-note page's own
| `status` test, because that page imports Invoice for its picker. The review then found it could
| not see a relation manager's `$query` at all, and that its file-level ETA allowance also excused
| the `disputeLine` literal this change had just converted, in the same file.
|
| The one allowance is DERIVED from `Modules::FROZEN`, not listed: a literal on a line behind
| `Modules::enabled('<frozen>')`, or in a widget whose `widgetModule()` is a frozen key, is inert
| until the freeze lifts — and lifting it turns this gate red naming both, which is the unfreeze
| checklist's signal, not a regression.
*/

const INVOICE_STATUS_PREDICATES = '/->(isPayable|isOverdue|stillOwed|creditable|overdue|canDisputeLines|voidBlockedBecause)\(/';

/** Is this file an invoice door by WHERE it lives — the invoice resource, or an invoice tab. */
function fileOwnsInvoices(string $relative): bool
{
    return str_contains($relative, '/Resources/Invoices/')
        || (str_ends_with($relative, 'RelationManager.php') && str_contains(basename($relative), 'Invoice'));
}

/** A frozen module's key on a line, or a widget belonging to one, shields the literal. */
function frozenKeyPattern(): ?string
{
    $keys = array_keys(Modules::FROZEN);

    return $keys === [] ? null : '('.implode('|', array_map('preg_quote', $keys)).')';
}

function fileIsAFrozenWidget(string $raw): bool
{
    $frozen = frozenKeyPattern();

    return $frozen !== null && preg_match('/function widgetModule\(\)[^{]*\{\s*return \''.$frozen.'\';/', $raw) === 1;
}

function lineIsBehindAFrozenSwitch(string $raw, int $at): bool
{
    $frozen = frozenKeyPattern();
    $lineStart = (int) strrpos(substr($raw, 0, $at), "\n") + 1;
    $lineEnd = strpos($raw, "\n", $at) ?: strlen($raw);

    return $frozen !== null && preg_match('/Modules::enabled\(\''.$frozen.'\'\)/', substr($raw, $lineStart, $lineEnd - $lineStart)) === 1;
}

/**
 * Every `in_array($x->status, ['…'])` whose `$x` the file itself says is an invoice.
 *
 * @return array{offenders: list<string>, shielded: int}
 */
function invoiceStatusListTestsIn(string $raw, string $blanked, string $relative): array
{
    preg_match_all('/in_array\(\$([\w>-]+)->status,\s*\[\s*\'/', $blanked, $tests, PREG_OFFSET_CAPTURE);

    $offenders = [];
    $shielded = 0;

    foreach ($tests[1] as [$expr, $at]) {
        $name = explode('->', $expr)[0];

        if ($expr === 'this->record') {
            $isInvoice = fileOwnsInvoices($relative);
        } else {
            // The NEAREST typed declaration of that variable before the use is the closure binding
            // it — a file may type `$record` as Lease in one closure and Invoice in the next.
            preg_match_all('/\(\s*\??(\w+)\s+\$'.preg_quote($name, '/').'\b/', substr($raw, 0, $at), $typed);
            $type = $typed[1] === [] ? null : end($typed[1]);
            $isInvoice = $type === 'Invoice' || ($type === null && $name === 'invoice');
        }

        if (! $isInvoice) {
            continue;
        }

        if (fileIsAFrozenWidget($raw) || lineIsBehindAFrozenSwitch($raw, $at)) {
            $shielded++;

            continue;
        }

        $offenders[] = $expr;
    }

    return ['offenders' => $offenders, 'shielded' => $shielded];
}

/**
 * Every `whereIn('status', ['…'])` on an INVOICE query — attributed by the nearest query root.
 *
 * @return array{offenders: list<string>, shielded: int}
 */
function invoiceStatusListQueriesIn(string $raw, string $blanked, string $relative): array
{
    // The blanked copy keeps every byte's offset, so the raw copy is read at the same positions.
    preg_match_all('/whereIn\(\s*\'\s*\',\s*\[\s*\'/', $blanked, $calls, PREG_OFFSET_CAPTURE);

    $offenders = [];
    $shielded = 0;

    foreach ($calls[0] as [, $at]) {
        if (! preg_match("/whereIn\(\s*'status',\s*\[([^\]]*)\]/", substr($raw, $at, 400), $m)) {
            continue;
        }

        // The nearest `Model::query(` / `Model::where(` before the call is the query being
        // narrowed; a `$query` with no root in sight is the file's own relationship.
        preg_match_all('/\b([A-Z]\w+)::(?:query|where|whereIn|whereNotIn|whereHas|with|withCount|withSum|select)\(/', substr($raw, 0, $at), $roots);
        $root = $roots[1] === [] ? null : end($roots[1]);

        $isInvoice = $root === null ? fileOwnsInvoices($relative) : $root === 'Invoice';

        if (! $isInvoice) {
            continue;
        }

        if (fileIsAFrozenWidget($raw) || lineIsBehindAFrozenSwitch($raw, $at)) {
            $shielded++;

            continue;
        }

        $offenders[] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    return ['offenders' => $offenders, 'shielded' => $shielded];
}

it('decides an invoice status through the model, never through a literal list', function () {
    $offenders = [];
    $doors = 0;
    $predicateReads = 0;
    $shielded = 0;

    foreach (ActionStrips::sources() as $file) {
        $raw = PhpSource::fileWithoutComments($file);
        $blanked = PhpSource::withoutCommentsOrStrings((string) file_get_contents($file));
        $relative = str_replace(base_path().'/', '', $file);

        // A door names the model, or lives in its resource — `ListInvoices` imports only `Builder`
        // and writes its tabs as `fn (Builder $query) => $query->overdue()`.
        if (! preg_match('/^use App\\\\Models\\\\Invoice;/m', $raw) && ! fileOwnsInvoices($relative)) {
            continue;
        }

        $doors++;
        $predicateReads += preg_match_all(INVOICE_STATUS_PREDICATES, $raw);

        $tests = invoiceStatusListTestsIn($raw, $blanked, $relative);
        $queries = invoiceStatusListQueriesIn($raw, $blanked, $relative);
        $shielded += $tests['shielded'] + $queries['shielded'];

        foreach ($tests['offenders'] as $expr) {
            $offenders[] = "{$relative} tests \${$expr}->status against a literal list — read the model's predicate (isPayable / canDisputeLines / voidBlockedBecause)";
        }

        foreach ($queries['offenders'] as $list) {
            $offenders[] = "{$relative} narrows invoices by whereIn('status', [{$list}]) — use stillOwed() / creditable() / overdue()";
        }
    }

    // Premises: the invoice has doors, and they read the predicates (measured 29 files and 23
    // predicate reads on the day this was written).
    expect($doors)->toBeGreaterThanOrEqual(12, 'Almost no Filament file imports Invoice — the sweep is reading the wrong tree.');
    expect($predicateReads)->toBeGreaterThanOrEqual(12, 'The invoice doors read almost none of the model predicates — the seam is not in use.');

    // The frozen-module shield is derived, so prove it excused something while a module IS frozen
    // (two ETA literals on the day this was written) and nothing once none is.
    if (Modules::FROZEN === []) {
        expect($shielded)->toBe(0);
    } else {
        expect($shielded)->toBeGreaterThan(0, 'A module is frozen and the shield excused no literal — either the ETA literals were converted (drop this clause) or the derivation stopped reading them.');
    }

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

it('attributes a status literal to the invoice by what the file says, not by what it imports', function () {
    $blank = fn (string $php) => PhpSource::withoutCommentsOrStrings($php);

    // The credit-note page's own `status` — the false positive the first cut of this gate reported.
    $ownStatus = "<?php\nuse App\\Models\\Invoice;\nclass X { function a() { return in_array(\$this->record->status, ['draft', 'issued']); } }";
    expect(invoiceStatusListTestsIn($ownStatus, $blank($ownStatus), 'app/Filament/Admin/Resources/CreditNotes/Pages/EditCreditNote.php')['offenders'])->toBe([]);
    expect(invoiceStatusListTestsIn($ownStatus, $blank($ownStatus), 'app/Filament/Admin/Resources/Invoices/Pages/EditInvoice.php')['offenders'])->toBe(['this->record']);

    // A closure the file types `Invoice $record`, wherever the file lives — and the same variable
    // typed as a Lease in the closure beside it.
    $typed = "<?php\n\$a = fn (Invoice \$record) => in_array(\$record->status, ['issued']);\n\$b = fn (Lease \$record) => in_array(\$record->status, ['active']);";
    expect(invoiceStatusListTestsIn($typed, $blank($typed), 'app/Filament/Admin/Actions/LeaseActions.php')['offenders'])->toBe(['record']);

    // A literal behind the frozen module's switch is shielded; the literal on the next act in the
    // same file is not — the file-level allowance the review found would have excused both.
    $frozenKey = array_key_first(Modules::FROZEN);
    $twoActs = "<?php\n\$a = fn (Invoice \$record) => Modules::enabled('{$frozenKey}') && in_array(\$record->status, ['issued']);\n\$b = fn (Invoice \$record) => ! in_array(\$record->status, ['cancelled', 'written_off']);";
    expect(invoiceStatusListTestsIn($twoActs, $blank($twoActs), 'app/Filament/Admin/Actions/InvoiceActions.php'))->toBe(['offenders' => ['record'], 'shielded' => 1]);

    // A literal list on an invoice query, the same list on a lease query beside it, a constant
    // (not a literal), and a relation manager's rootless `$query` — attributed by the file.
    $queries = "<?php\n\$x = Invoice::query()->where('a', 1)\n  ->whereIn('status', ['issued', 'paid'])->count();\n\$y = Lease::query()->whereIn('status', ['active'])->count();\n\$z = Invoice::query()->whereIn('status', Invoice::OPEN)->count();";
    expect(invoiceStatusListQueriesIn($queries, $blank($queries), 'app/Filament/Admin/Widgets/X.php')['offenders'])->toBe(["'issued', 'paid'"]);

    $tab = "<?php\nclass T { function table() { return \$t->filters([Filter::make('x')->query(fn (\$query) => \$query->whereIn('status', ['issued', 'overdue']))]); } }";
    expect(invoiceStatusListQueriesIn($tab, $blank($tab), 'app/Filament/Admin/RelationManagers/TenantInvoicesRelationManager.php')['offenders'])->toBe(["'issued', 'overdue'"]);
    expect(invoiceStatusListQueriesIn($tab, $blank($tab), 'app/Filament/Admin/RelationManagers/TenantLeasesRelationManager.php')['offenders'])->toBe([]);

    // A widget belonging to the frozen module is shielded whole.
    $widget = "<?php\nclass W { protected static function widgetModule(): ?string { return '{$frozenKey}'; } function s() { \$b = Invoice::query()->whereIn('status', ['issued']); } }";
    expect(invoiceStatusListQueriesIn($widget, $blank($widget), 'app/Filament/Admin/Widgets/W.php'))->toBe(['offenders' => [], 'shielded' => 1]);
});
