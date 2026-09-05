<?php

/**
 * §9.3 gate 3 — EVERY STATUS MUST BE REACHABLE, AND EVERY NON-TERMINAL STATUS LEAVEABLE.
 *
 * The `expired` shape, made permanent. `leases:expire` projected every lease past its term into a
 * status that four doors then refused to act on — LE-04 was reachable for one morning and never
 * again, and nothing was red. And `work_orders.status = 'completed'` lived in fixtures for months
 * while the model said `done`, so an SLA test measured an order the transition matrix considered
 * still open. Both defects are the same question nobody was forced to answer: for each value this
 * column may hold, WHAT WRITES IT, and WHAT LEAVES IT?
 *
 * This gate derives both halves from the code and demands a registered reason for the residue:
 *
 *  - A value is WRITTEN if a token-stripped app/ write (`'status' => X` or `->status = X`, X a
 *    literal or a resolvable `Model::CONST`) attributes to its column — a constant self-attributes
 *    through its class, a literal through the file's model context — or if an editable
 *    `Select::make('status')` on the model's own forms offers it through its lang enum.
 *  - A value is LEFT if a TRANSITIONS matrix names it, or some file MENTIONS it and WRITES a
 *    different value of the same column — following the call graph ONE hop, because the act that
 *    knows the value and the code that writes the next one are routinely different files
 *    (`EditDepositTransaction` gates its cancel button on `recorded`; `DepositService` writes
 *    `cancelled`). A form fallback counts only when that form offers the value ITSELF as well as
 *    another — a form that never offers V cannot be opened on a record in V.
 *
 * **WHAT THE LEAVER TOOTH DOES AND DOES NOT CATCH — measured, not assumed.** Mutation-proved: it
 * goes red for an ORPHANED value (one nothing writes and nothing transitions away from) and for a
 * stale exemption whose act has gone. It does NOT replay the `expired` defect itself: with the
 * holdover conversion's write removed, the gate stayed green, because sibling files still mention
 * `expired` while writing other lease statuses. That defect was "every ACT refuses a record in
 * this state", which is a reachability question about guards, not about writes — `ProjectedState`
 * and `EveryLeaseActionIsReachableFromItsTabTest` are where it is answered. Stating the limit
 * here rather than implying coverage: a gate believed to check more than it does is worse than no
 * gate, and this file's own history is full of that shape.
 *
 * TERMINAL values are exempt from the leaveable tooth BY REGISTRATION WITH A REASON — a value is
 * never terminal by convention, because `expired` looked terminal and was a workflow's entry
 * point. `eta_status` is out of scope: module 16 is FROZEN (`Modules::FROZEN`) and its values are
 * gateway-written; sweeping it would demand exemptions for a module deliberately invisible.
 */

use App\Support\ValueSets;
use Illuminate\Support\Str;

/** value => reason. A terminal value is one the record correctly never leaves. */
const STATUS_TERMINAL = [
    'invoices.status' => [
        'cancelled' => 'void is the end of a document; corrections issue a new one',
        'credited' => 'fully credited — the credit note is the live document now',
        'written_off' => 'left only by WriteOffInvoiceService::reverse(), which restores the prior status — detected as a leaver below, so this row is belt-and-braces for the derivation',
        'paid' => 'left only by a payment reversal restoring balance; recomputeTotals moves it, detected as a writer of partially_paid/issued',
    ],
    'payments.status' => [
        'voided' => 'a keying error undone; the receipt never happened',
        'failed' => 'a gateway outcome; retrying initiates a new payment',

    ],
    'leases.status' => [
        'terminated' => 'the final account settles it; a new tenancy is a new lease',
        'cancelled' => 'never commenced',
        'renewed' => 'superseded by its renewal lease',
    ],
    'credit_notes.status' => [
        'void' => 'issued in error; the reversal is final',
        'applied' => 'fully consumed — un-applying restores issued and is detected as a writer',
    ],
    'journal_entries.status' => ['void' => 'a voided entry is evidence of the correction'],
    'vendor_bills.status' => [
        'cancelled' => 'refused before commitment',
        'paid' => 'left only by voiding a payment against it, which recomputes and is detected',
    ],
    'expenses.status' => ['cancelled' => 'the correction path for a recorded cost'],
    'deposit_transactions.status' => ['cancelled' => 'the correction path for a deposit movement'],
    'payrolls.status' => ['cancelled' => 'the documented correction path for an approved run'],
    'post_dated_cheques.status' => [
        'cleared' => 'the cheque became money; the receipt is the live document',
        'cancelled' => 'returned to the tenant unbanked',
        'bounced' => 're-presenting re-deposits it — detected as a leaver (deposit() accepts a bounced cheque)',
    ],
    'purchase_requests.status' => [
        'rejected' => 'the request died; a new need is a new request',
        'received' => 'goods arrived — procurement complete',
        'cancelled' => 'withdrawn before ordering',
    ],
    'tenant_requests.status' => ['cancelled' => 'withdrawn by the tenant or operator', 'closed' => 'auto-closed after resolution; a new complaint is a new request'],
    'owner_requests.status' => ['cancelled' => 'withdrawn', 'closed' => 'responded and closed'],
    'tenant_sales_declarations.status' => ['locked' => 'locking freezes the base a fine was billed on; voidLocked is the act that leaves it and is detected as a leaver'],
    'unit_ownerships.status' => ['transferred' => 'the resale closed this tenure; the buyer holds a new row'],
    'work_permits.status' => ['closed' => 'the work ended and the area was made safe', 'cancelled' => 'never started'],
    'work_order_proposals.status' => ['rejected' => 'declined; a revised offer is a new proposal', 'withdrawn' => 'the contractor took it back', 'approved' => 'the quote became the job\'s commercial basis'],
    'sla_penalties.status' => ['applied' => 'the penalty reached the bill', 'waived' => 'the operator forgave it'],
    'disbursements.status' => ['cancelled' => 'not paid after all', 'paid' => 'the owner has the money'],
    'owner_statements.status' => ['superseded' => 'a revision replaced it'],
    'owner_statement_runs.status' => ['superseded' => 'a revision replaced it'],
    'marketing_posts.status' => ['rejected' => 'declined by the operator; a fresh submission is a new post', 'archived' => 'its window closed'],
    'announcements.status' => ['sent' => 'delivered; it cannot be unsent'],
    'violations.status' => ['resolved' => 'the matter closed; a repeat is a new violation'],
    'fixed_assets.status' => ['disposed' => 'sold or scrapped; the ledger holds the outcome'],
    'employees.status' => ['terminated' => 'rehiring opens a new employment record'],
    'vendor_contracts.status' => ['terminated' => 'ended early by decision', 'expired' => 'ran its term; renewal mints a new contract — the scan that stamps it is one-way by design'],
    'cam_allocations.status' => ['closed' => 'the year true-up finished with it'],
    'cam_expense_pools.status' => ['closed' => 'the recovery year is done'],
    'fiscal_years.status' => ['closed' => 'the year-end close posted; reopening is a migration-grade act'],
];

/**
 * value => [reason, file whose source must still contain the proof token, proof token].
 *
 * Every row here is a value written through a VARIABLE — `$payload = ['status' => $next]` — where
 * the value itself is chosen by the CALLER and no static read can resolve it. The proof token is
 * checked (tooth 4), so an exemption cannot outlive the act it names: delete the caller that
 * passes `'done'` and this gate goes red, which is exactly the `expired` failure it exists for.
 */
const STATUS_EXEMPT = [
    // The gateway callback maps provider states through a variable.
    'payments.status.initiated' => ['PaymobPaymentInitiator seeds the pending receipt', 'app/Services/Paymob/PaymobPaymentInitiator.php', 'Payment::create'],
    'payments.status.authorized' => ['the gateway callback maps provider states', 'app/Http/Controllers/Paymob/CallbackController.php', 'status'],

    // ── VOCABULARY WITH NO LIVE WRITER, ON PURPOSE ────────────────────────────────────────────
    //
    // Found by this gate once its form escape stopped answering with a superset. Nothing in `app/`
    // writes any of these and no picker offers them; they stay in `ValueSets` and both lang files
    // because legacy and IMPORTED rows carry them and `RECEIVED_STATUSES` must go on counting a
    // `reconciled` receipt as money in. `PaymentForm` states the reasoning in full: bank
    // reconciliation writes a `BankMatch` row and never touches this column, so offering
    // `reconciled` would let an operator claim a reconciliation that did not happen. `voided` is
    // the live reversal status — `refunded` and `bounced` are what `VoidPaymentService` wrote
    // before 2026-08-28, when a mis-keyed receipt was recorded as money returned to somebody who
    // never received any.
    //
    // Registered rather than deleted, and NOT marked terminal: terminal would say a record reaches
    // this state and stays: these say a record never reaches it at all any more.
    'payments.status.reconciled' => ['deliberately not offered and not written; kept for legacy/imported rows', 'app/Filament/Admin/Resources/Payments/Schemas/PaymentForm.php', 'are NOT offered'],
    'payments.status.settled' => ['deliberately not offered and not written; kept for legacy/imported rows', 'app/Filament/Admin/Resources/Payments/Schemas/PaymentForm.php', 'are NOT offered'],
    'payments.status.refunded' => ['a reversal is `voided` since 2026-08-28; this value survives only on rows written before that', 'app/Models/Payment.php', 'REVERSED_STATUSES'],
    'payments.status.bounced' => ['written on the CHEQUE, never on the receipt; the payment is voided', 'app/Models/Payment.php', 'REVERSED_STATUSES'],

    // An OUTCOME the picker does not offer and `recomputeTotals()` explicitly declines to set
    // ("NOT 'credited': that is the terminal…"), retained so an imported row still classifies.
    'invoices.status.credited' => ['an outcome, never a pick; no writer by design', 'app/Support/InvoiceSettlement.php', 'credited'],

    // Offered by LeaseForm's status Select, whose options are the lang group with two values
    // rejected — a multi-line closure this file's exact-shape reader deliberately cannot parse
    // (reading it loosely is what made the escape a superset).
    'leases.status.pending_approval' => ['offered by the lease form\'s status Select', 'app/Filament/Admin/Resources/Leases/Schemas/LeaseForm.php', 'admin.statuses.lease'],

    // ── AMBIGUOUS BY FILE, WRITTEN IN FACT ────────────────────────────────────────────────────
    //
    // One file, several models that all allow the value, and a literal that carries no type — so
    // the sweep declines to guess (see the write loop). Each is named here with the file that
    // really writes it. `PeriodService` closes BOTH a period and its year and mentions both
    // models, which is precisely the shape no static read can resolve.
    'accounting_periods.status.closed' => ['PeriodService::close()', 'app/Services/Accounting/PeriodService.php', "'status' => 'closed'"],
    'accounting_periods.status.open' => ['a period is born open (column default) and reopened by PeriodService', 'app/Services/Accounting/PeriodService.php', 'reopen'],
    'fiscal_years.status.closed' => ['PeriodService closes the year with its periods', 'app/Services/Accounting/PeriodService.php', "periods()->update(['status' => 'closed'])"],
    'fiscal_years.status.open' => ['a year is born open; the year-end close is the only other writer', 'app/Services/Accounting/PeriodService.php', 'fiscalYear'],
    'cam_allocations.status.closed' => ['the CAM true-up closes an allocation once the year is settled', 'app/Services/CamReconciliationService.php', 'closed'],
    'credit_notes.status.issued' => ['CreditNoteService — issue, and un-apply restoring it', 'app/Services/CreditNoteService.php', "\$note->status = 'issued'"],

    // ── THE ORPHAN THIS GATE EXISTS TO FIND ──────────────────────────────────────────────────
    //
    // `invoices.status = 'disputed'` has NO writer and no picker. SW-238 took it off the header
    // form on 2026-09-05: it put the invoice in `NOT_CHASEABLE` — skipped by the overdue scan, the
    // dunning sweep and the late-fee sweep — with no reason and no audit event, and
    // `DisputeInvoiceItemService` had already said the header is the wrong tool because an invoice
    // is rarely disputed in full. The per-LINE act, which requires a reason, is the door.
    //
    // Registered rather than deleted for the reason SW-238 gives: `NOT_CHASEABLE` must go on
    // honouring a legacy row that carries it. This entry is the standing record that the value is
    // deliberately unreachable — and the gate's first version credited it to a service that writes
    // a SALES DECLARATION, which is why the attribution rule is now one-column-or-none.
    'invoices.status.disputed' => ['deliberately unreachable since SW-238; the per-line dispute act is the door', 'app/Support/InvoiceSettlement.php', 'disputed'],

    // A transition matrix: the service writes `$next`, the ACT names the value.
    'facility_work_orders.status.done' => ['FacilityWorkOrderService::transition($record, \'done\') from the work-order table', 'app/Filament/Admin/Resources/FacilityWorkOrders/Tables/FacilityWorkOrdersTable.php', "transition(\$record, 'done')"],
    'lease_options.status.waived' => ['ExerciseLeaseOptionService::resolveWithout($record, \'waived\') from the options tab', 'app/Filament/Admin/RelationManagers/LeaseOptionsRelationManager.php', "resolveWithout(\$record, 'waived')"],

    // OwnerRequestService::reply()/respond() write `$status` chosen on the reply modal's Select,
    // whose options come from OwnerRequest::STATUSES — every value, by construction.
    'owner_requests.status.in_progress' => ['the reply modal\'s status Select (options = OwnerRequest::STATUSES) through OwnerRequestService::reply()', 'app/Filament/Admin/Resources/OwnerRequests/Tables/OwnerRequestsTable.php', 'OwnerRequest::STATUSES'],
    'owner_requests.status.cancelled' => ['same reply-modal Select — the operator picks the value', 'app/Filament/Admin/Resources/OwnerRequests/Tables/OwnerRequestsTable.php', 'OwnerRequest::STATUSES'],

    // A status Select on the record's own form whose options are built from the model's constant
    // list — every value offered, none of them a literal anywhere.
    'violations.status.resolved' => ['the violation form\'s status Select (options = Violation::STATUSES)', 'app/Filament/Admin/Resources/Violations/Schemas/ViolationForm.php', 'Violation::STATUSES'],
    'unit_ownerships.status.reserved' => ['the ownership form\'s status Select (options = UnitOwnershipStatus::options())', 'app/Filament/Admin/Resources/UnitOwnerships/Schemas/UnitOwnershipForm.php', 'UnitOwnershipStatus::options()'],

    // CAM: a tenant disputes their share off the allocation, and the true-up reads the flag.
    'cam_allocations.status.disputed' => ['CamReconciliationService reads it and the allocation table sets it from the operator\'s pick; no literal write exists', 'app/Filament/Admin/RelationManagers/CamAllocationsRelationManager.php', 'disputed'],
];

function statusGateSources(): array
{
    static $files;
    if ($files === null) {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $src = file_get_contents($f->getPathname());
                $out = $src;
                foreach (token_get_all($src) as $t) {
                    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $at = strpos($out, $t[1]);
                        if ($at !== false) {
                            $out = substr_replace($out, str_repeat(' ', strlen($t[1])), $at, strlen($t[1]));
                        }
                    }
                }
                $files[ltrim(str_replace(base_path().'/', '', $f->getPathname()), '/')] = $out;
            }
        }
    }

    return $files;
}

/** The status columns in scope: every `<table>.status` ValueSets registers, minus frozen ETA. */
function statusGateColumns(): array
{
    return collect(array_keys(ValueSets::SETS))
        ->filter(fn ($k) => str_ends_with($k, '.status'))
        ->mapWithKeys(function ($k) {
            $table = substr($k, 0, -7);
            $model = 'App\\Models\\'.Str::studly(Str::singular($table));

            return [$table => class_exists($model) ? $model : null];
        })
        ->filter()
        ->all();
}

/** Constants on the model whose value is an allowed status — the write vocabulary. */
function statusGateConstants(string $model, array $allowed): array
{
    $out = [];
    foreach ((new ReflectionClass($model))->getConstants() as $name => $value) {
        if (is_string($value) && in_array($value, $allowed, true)) {
            $out[$name] = $value;
        }
    }

    return $out;
}

/**
 * Per column: ['written' => value => [files], 'mentioned' => value => [files]].
 *
 * A write is `'status' => X` or `->status = X` (assignment, not comparison), X a literal or a
 * `Class::CONST` resolved against App\Models. A constant self-attributes through its class; a
 * literal attributes through the file's model context (class basename, table name, or path).
 */
function statusGateDerive(): array
{
    static $result;
    if ($result !== null) {
        return $result;
    }

    $columns = statusGateColumns();
    $result = [];
    foreach ($columns as $table => $model) {
        $result[$table] = ['written' => [], 'mentioned' => []];

        // The column's own DEFAULT is a writer: a create that omits `status` births the record in
        // it (draft, open, recorded, active — the born-state family).
        try {
            foreach (Illuminate\Support\Facades\Schema::getColumns($table) as $col) {
                if ($col['name'] === 'status' && is_string($col['default'] ?? null)) {
                    $default = trim($col['default'], "'\"");
                    if (in_array($default, ValueSets::allowed($table, 'status'), true)) {
                        $result[$table]['written'][$default][] = '(column default)';
                    }
                }
            }
        } catch (Throwable) {
            // a table absent from the test schema simply contributes no default
        }
    }

    foreach (statusGateSources() as $rel => $src) {
        // Each write SITE opens a WINDOW: `'status' => <…>` or `->status = <…>` followed by up to
        // 800 characters (comments are blanked to spaces, so documented writes need reach), inside which every value token counts — that is what sees a ternary and
        // an inline match arm, which a token-adjacent regex missed on the first run.
        preg_match_all("/(?:'status'\s*=>|->status\s*=(?!=)|\['status'\]\s*=(?!=))/", $src, $sites, PREG_OFFSET_CAPTURE);

        foreach ($sites[0] as [$m, $at]) {
            $window = substr($src, $at, strlen($m) + 800);

            // Literals in the window.
            preg_match_all("/'([a-z_]+)'/", $window, $lits);
            foreach (array_unique($lits[1]) as $lit) {
                // ONE column, or none. A literal write carries no type, so the only evidence of
                // WHICH status column it sets is the file's model context — and when a file names
                // several models that all allow the value, that evidence is worthless.
                //
                // Multi-attribution was the first version and it answered WRONGLY on the exact
                // defect this gate is named for: `PercentageRentCalculationService` writes
                // `'status' => 'disputed'` on a SALES DECLARATION, mentions `Invoice`, and was
                // therefore credited as the writer of `invoices.status = 'disputed'` — a value
                // SW-238 had recorded the day before as having no writer at all, its dropdown
                // being the only door. A gate that reports the orphan it exists to find as covered
                // is worse than no gate. Ambiguous now means UNATTRIBUTED, which pushes the value
                // into the registry where a human has to say what writes it.
                $candidates = [];

                foreach (statusGateColumns() as $table => $model) {
                    $hint = class_basename($model);
                    if ((str_contains($src, $hint) || str_contains($rel, $hint) || str_contains($src, "'$table'"))
                        && in_array($lit, ValueSets::allowed($table, 'status'), true)) {
                        $candidates[] = $table;
                    }
                }

                if (count($candidates) === 1) {
                    $result[$candidates[0]]['written'][$lit][] = $rel;
                }
            }

            // Constants in the window — self-attributing through their class. `self::`/`static::`
            // resolve against the file's own class via the PSR-4 path (app/Models writers assign
            // their own constants that way).
            // PHP enum cases written as `X::Case->value` (UnitOwnershipStatus lives in App\Support).
            preg_match_all("/([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z][A-Za-z0-9_]*)->value/", $window, $cases, PREG_SET_ORDER);
            foreach ($cases as $c) {
                foreach (['App\\Enums\\', 'App\\Support\\', 'App\\Models\\'] as $ns) {
                    $enum = $ns.$c[1];
                    if (enum_exists($enum) && defined("$enum::{$c[2]}")) {
                        $value = constant("$enum::{$c[2]}")->value;
                        foreach (statusGateColumns() as $table => $model) {
                            $hint = class_basename($model);
                            if ((str_contains($src, $hint) || str_contains($rel, $hint))
                                && in_array($value, ValueSets::allowed($table, 'status'), true)) {
                                $result[$table]['written'][$value][] = $rel;
                            }
                        }
                        break;
                    }
                }
            }

            preg_match_all("/([A-Za-z_][A-Za-z0-9_]*|self|static)::([A-Z][A-Z0-9_]*)/", $window, $consts, PREG_SET_ORDER);
            foreach ($consts as $c) {
                $class = in_array($c[1], ['self', 'static'], true)
                    ? 'App\\'.str_replace('/', '\\', substr($rel, 4, -4))
                    : 'App\\Models\\'.$c[1];
                if (! class_exists($class) || ! defined("$class::{$c[2]}")) {
                    continue;
                }
                $value = constant("$class::{$c[2]}");
                if (! is_string($value) || ! is_subclass_of($class, Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }
                $table = (new $class)->getTable();
                if (isset($result[$table]) && in_array($value, ValueSets::allowed($table, 'status'), true)) {
                    $result[$table]['written'][$value][] = $rel;
                }
            }
        }

        // Mentions: the literal, or any status constant of the column's model.
        foreach (statusGateColumns() as $table => $model) {
            $allowed = ValueSets::allowed($table, 'status');
            $constants = statusGateConstants($model, $allowed);
            foreach ($allowed as $v) {
                $hit = str_contains($src, "'$v'");
                if (! $hit) {
                    foreach ($constants as $name => $value) {
                        if ($value === $v && str_contains($src, class_basename($model)."::$name")) {
                            $hit = true;
                            break;
                        }
                    }
                }
                if ($hit) {
                    $result[$table]['mentioned'][$v][] = $rel;
                }
            }
        }
    }

    return $result;
}

/**
 * Values an editable status Select on the model's own forms offers — EXACTLY, or nothing.
 *
 * A first version read every `admin.enums.*` / `admin.statuses.*` key mentioned in the file and
 * took every key of those arrays. That is a SUPERSET of what the form offers and it made both
 * teeth inert for 18 of 41 columns: `invoices` reported 61 "offered" values against an allowed set
 * of 9, and `payments` reported `reconciled` and `settled` as offered by a form whose own source
 * says in writing that it does not offer them because nothing produces them. A gate that answers
 * "covered" for the value it was built to catch is worse than no gate.
 *
 * So only two shapes count, both exact: options built from the model's own constant list
 * (`Violation::STATUSES`) or from its enum (`UnitOwnershipStatus::options()`). Both genuinely
 * offer every allowed value. Anything else — a hand-written array, a lang group, a conditional
 * `unset()` — is unreadable from here and returns nothing, which pushes the value into the
 * registry where a human states why.
 */
function statusGateFormOffered(string $table, string $model): array
{
    $hint = class_basename($model);
    $enum = Illuminate\Support\Str::studly(Illuminate\Support\Str::singular($table)).'Status';
    $offered = [];

    foreach (statusGateSources() as $rel => $src) {
        if (! str_contains($src, "Select::make('status')") && ! str_contains($src, "ToggleButtons::make('status')")) {
            continue;
        }
        if (! str_contains($src, $hint) && ! str_contains($rel, $hint)) {
            continue;
        }
        if (preg_match("/(?:$hint::STATUSES|$enum::options\\(\\))/", $src)) {
            return ValueSets::allowed($table, 'status');
        }

        // A Select whose options ARE a lang group verbatim — `->options(fn () => __('admin.
        // statuses.tenant'))`, a single-expression closure with nothing else in it. That group's
        // keys are exactly what the operator is offered.
        //
        // The single-expression shape is the whole distinction, and it is what tells this apart
        // from `PaymentForm`, which builds a RESTRICTED set in code and documents at length why
        // `reconciled` and `settled` are deliberately not among it. Matching the lang group
        // wherever it is merely MENTIONED is what made this escape a superset.
        if (preg_match_all(
            "/->options\\(fn \\(\\)(?:\\s*:\\s*array)?\\s*=>\\s*__\\('(admin\\.(?:enums|statuses)\\.[a-z_.]+)'\\)\\)/",
            $src, $matches
        )) {
            foreach ($matches[1] as $key) {
                $options = __($key);

                if (is_array($options)) {
                    $offered = array_merge($offered, array_keys($options));
                }
            }
        }
    }

    // Intersected with what the column may actually hold: a lang group can carry vocabulary for
    // more than one column, and an offered value outside the set is not offered at all.
    return array_values(array_intersect(
        array_unique($offered),
        ValueSets::allowed($table, 'status'),
    ));
}

it('derives writers for most of the vocabulary — the sweep collects something', function () {
    $written = 0;
    foreach (statusGateDerive() as $per) {
        $written += count($per['written']);
    }
    // 40 columns, ~150 values: a healthy derivation sees writers for well over half. A gate that
    // silently stops collecting reports on a set it no longer sees (three recorded instances).
    expect($written)->toBeGreaterThan(60);
});

it('every status value has a writer, a form that offers it, or a registered reason', function () {
    $derived = statusGateDerive();
    $problems = [];

    foreach (statusGateColumns() as $table => $model) {
        $formOffered = null; // lazy — only computed when a value has no derived writer
        foreach (ValueSets::allowed($table, 'status') as $v) {
            if (isset($derived[$table]['written'][$v])) {
                continue;
            }
            if (isset(STATUS_EXEMPT["$table.status.$v"])) {
                continue;
            }
            $formOffered ??= statusGateFormOffered($table, $model);
            if (in_array($v, $formOffered, true)) {
                continue;
            }
            $problems[] = "$table.status = '$v' — nothing writes it, no form offers it, no reason registered";
        }
    }

    expect($problems)->toBe([]);
});

it('every non-terminal value has a leaver — a file that mentions it and writes another value', function () {
    $derived = statusGateDerive();
    $problems = [];

    foreach (statusGateColumns() as $table => $model) {
        $terminal = STATUS_TERMINAL["$table.status"] ?? [];
        $formOffered = null;
        foreach (ValueSets::allowed($table, 'status') as $v) {
            if (isset($terminal[$v]) || isset(STATUS_EXEMPT["$table.status.$v"])) {
                continue;
            }

            // Files that write SOME OTHER value of this column — the far end of a transition.
            $writersOfOthers = [];
            foreach ($derived[$table]['written'] as $w => $writerFiles) {
                if ($w !== $v) {
                    $writersOfOthers = array_merge($writersOfOthers, $writerFiles);
                }
            }

            // A TRANSITIONS matrix is the strongest statement there is: `'open' => ['in_progress',
            // 'done', 'cancelled']` says in one line which values leave `open`. Both workflow
            // services here (facility, tenant requests) declare one, and they write through a
            // `$next` variable no static read can resolve — so without this the most explicitly
            // documented transitions in the codebase would read as dead ends.
            $matrix = false;
            foreach (statusGateSources() as $rel => $src) {
                if (! str_contains($src, 'TRANSITIONS = [')) {
                    continue;
                }
                $hint = class_basename($model);
                if (! str_contains($src, $hint) && ! str_contains($rel, str_replace('WorkOrder', '', $hint))) {
                    continue;
                }
                if (preg_match("/'".preg_quote($v, '/')."'\s*=>\s*\[\s*'/", $src)) {
                    $matrix = true;
                    break;
                }
            }
            if ($matrix) {
                continue;
            }

            $leavers = [];
            foreach ($derived[$table]['mentioned'][$v] ?? [] as $rel) {
                if (in_array($rel, $writersOfOthers, true)) {
                    $leavers[] = $rel;
                    continue;
                }

                // ONE HOP through the call graph, the way `LeaseEventNarrative`'s gate follows it:
                // the act that KNOWS about this value and the code that WRITES the next one are
                // routinely different files — `EditDepositTransaction` gates its cancel button on
                // `status === 'recorded'` and `DepositService::cancel()` writes `cancelled`.
                // Without the hop, every service-mediated transition reads as a dead end.
                if (preg_match_all("/([A-Z][A-Za-z0-9_]*)::class/", statusGateSources()[$rel] ?? '', $calls)) {
                    foreach (array_unique($calls[1]) as $called) {
                        foreach ($writersOfOthers as $writerFile) {
                            if (str_ends_with($writerFile, "/$called.php")) {
                                $leavers[] = $rel;
                                break 2;
                            }
                        }
                    }
                }
            }

            if ($leavers === []) {
                // An editable Select on the model's own form leaves V — but ONLY if that form can
                // represent a record already in V (it offers V) *and* offers something else to
                // move to. A form that never offers V cannot be opened on such a record without
                // Filament refusing the value it cannot label, so it vouches for nothing.
                $formOffered ??= statusGateFormOffered($table, $model);
                if (in_array($v, $formOffered, true) && array_diff($formOffered, [$v]) !== []) {
                    continue;
                }
                $problems[] = "$table.status = '$v' — no act leaves it and it is not registered terminal (the `expired` shape)";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('keeps the registries honest — no terminal or exempt row for a value that left the set', function () {
    $columns = statusGateColumns();
    $stale = [];

    foreach (STATUS_TERMINAL as $key => $values) {
        $table = substr($key, 0, -7);
        $allowed = isset($columns[$table]) ? ValueSets::allowed($table, 'status') : [];
        foreach (array_keys($values) as $v) {
            if (! in_array($v, $allowed, true)) {
                $stale[] = "STATUS_TERMINAL: $key => $v";
            }
        }
    }

    foreach (STATUS_EXEMPT as $key => [$reason, $file, $proof]) {
        $table = substr($key, 0, strpos($key, '.status.'));
        $v = substr($key, strpos($key, '.status.') + 8);
        $allowed = isset($columns[$table]) ? ValueSets::allowed($table, 'status') : [];
        if (! in_array($v, $allowed, true)) {
            $stale[] = "STATUS_EXEMPT: $key — value no longer in the set";
            continue;
        }

        // An exemption must not outlive the act it names. This is the tooth that makes a
        // variable-written value safe to exempt: delete the caller and the gate goes red.
        if (! file_exists(base_path($file))) {
            $stale[] = "STATUS_EXEMPT: $key — named file is gone ($file)";
        } elseif (! str_contains(file_get_contents(base_path($file)), $proof)) {
            $stale[] = "STATUS_EXEMPT: $key — $file no longer contains '$proof'; the act that writes this value may be gone";
        }
    }

    expect($stale)->toBe([]);
});
