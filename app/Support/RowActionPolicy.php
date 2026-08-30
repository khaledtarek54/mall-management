<?php

namespace App\Support;

/**
 * **Where the verbs live: the list FINDS, the record ACTS.**
 *
 * ## The question this answers
 *
 * A row carried up to nine things you could DO to a record — work orders had eight, invoices nine,
 * owner statement runs eight, purchase requests seven — while the record's own page carried Delete
 * and nothing else. An operator who opened a work order to read it had to go back to the list to
 * act on it, which is backwards from the record-hub information architecture this project took
 * from Yardi (`docs/benchmarks/yardi/08`), and a row of eight equally-weighted verbs reads as
 * noise rather than as choices.
 *
 * `LeaseActions` fixed it for leases first and `SalesDeclarationActions` followed: every act
 * defined ONCE in a class of its own, composed onto the record page by name. `Filament\Actions\
 * Action` is a single class for a row and a page header in v4, which is what makes one definition
 * serve either surface. This registry is the rule those two were instances of.
 *
 * ## What is a verb
 *
 * DERIVED, not listed: a row action is a write verb when its chain calls `->action(`. That is the
 * same seam {@see ActionAuthz} uses, and it separates the acts from the affordances without anyone
 * maintaining a vocabulary — `ViewAction`, a PDF download, a ledger-entry peek and an `open` link
 * all read or navigate, and all of them belong in the row.
 *
 * ## What legitimately stays
 *
 * **Every relation manager**, derived rather than listed. A relation manager is ALREADY on the
 * record page; its row IS the child record. Moving `unapply` or `endCharge` into a child's edit
 * modal would mean opening a modal to press a button. Yardi's sub-grids act in place for the same
 * reason.
 *
 * **A queue screen with no record page**, and the portals. Named in {@see IN_ROW_EXCEPTIONS} with
 * a reason each, because "this one is different" is not reviewable without one.
 */
final class RowActionPolicy
{
    /** The default: write verbs live on the record's own page, defined once in an Actions class. */
    public const RECORD_HUB = 'record_hub';

    /**
     * Tables whose write verbs stay in the row, and why.
     *
     * @var array<string, string> path under app/Filament/ => why
     */
    public const IN_ROW_EXCEPTIONS = [
        // ── Moving these would REMOVE the act from the role that performs it ──────────────────
        //
        // A record page is reached through `canEdit()`, i.e. `{module}.edit`. Four acts are held
        // by a role that deliberately does NOT hold that permission, so an act living only on the
        // record page would be unreachable by exactly the people whose job it is. Measured, not
        // reasoned: `leasing` opening the declaration Edit page answers 403.
        'Admin/Resources/TenantSalesDeclarations/Tables/TenantSalesDeclarationsTable' => 'leasing holds tenant_sales.lock and tenant_sales.dispute and NOT tenant_sales.edit, so '
            .'it is refused the record page — and a LOCKED declaration is un-editable for everyone, '
            .'so voidLocked could never be reached there by anyone at all.',
        'Admin/Resources/FacilityWorkOrders/Tables/FacilityWorkOrdersTable' => 'technician holds facility.complete and NOT facility.edit. The admin panel IS the '
            .'technician tool (no technician mobile app was built, by decision), so completing a job '
            .'from the list is the role core function; splitting the eight acts across two surfaces '
            .'would be worse than either surface alone.',
        'Admin/Resources/TenantRequests/Tables/TenantRequestsTable' => 'technician holds requests.change_status and NOT requests.edit — the same role and the '
            .'same reason as the work-order board it sits beside.',
        'Admin/Resources/Violations/Tables/ViolationTable' => 'billFine gates on invoices.create, which accounting holds while NOT holding '
            .'violations.edit: the role that raises AR can bill a fine today and would be refused '
            .'the violation record page. Cross-module gating, so it is invisible to any check that '
            .'compares a module against itself.',

        // ── No record page to move them to ───────────────────────────────────────────────────
        'Admin/Resources/AccountingPeriods/Tables/AccountingPeriodsTable' => 'A period has no record page — it is not an editable document — and closing one is a '
            .'monthly ritual performed from the list of periods. There is nowhere else to put it.',
        'Admin/Resources/Disbursements/Tables/DisbursementsTable' => 'An approval queue with no record page. Approve/pay/cancel are worked down the list, '
            .'which is the flow Yardi and Voyager both keep in place.',
        'Admin/Resources/OwnerRequests/Tables/OwnerRequestsTable' => 'Replying IS the whole job of this screen and there is no record page to move it to.',
        'Admin/Pages/ReportHub' => 'A page, not a resource: the rows are saved views, which have no record page of their own.',
        'Portal/Resources/Invoices/Tables/InvoicesTable' => 'The tenant portal. Pay now sits on the invoice row because that is where every billing '
            .'portal puts it, and a tenant has no edit page for a document they cannot edit.',
        'Portal/Resources/TenantRequests/Tables/TenantRequestsTable' => 'The tenant portal. Confirm/dispute/rate answer a resolution the tenant is reading in '
            .'that row; they are the reply, not a separate act on a record.',
        'Portal/Resources/MarketingPosts/Tables/MarketingPostsTable' => 'The tenant portal. Submit and withdraw are the two states of the row itself, on a '
            .'list a tenant opens to do exactly that.',
        'Portal/Resources/Leases/Tables/LeasesTable' => 'The tenant portal. Downloading the signed lease is the only act available and the '
            .'reason a tenant opens the list.',
        'Vendor/Resources/WorkOrders/Pages/ListWorkOrders' => 'The contractor portal — four verbs on a job list with no record page, and the whole '
            .'panel exists to work that list. See docs/modules/12b.',
    ];

    /** Is this file exempt because it is a relation manager — already ON a record page? */
    public static function isRelationManager(string $path): bool
    {
        return str_ends_with(self::relative($path), 'RelationManager');
    }

    /** Does this table keep its write verbs in the row deliberately? */
    public static function keepsVerbsInRow(string $path): bool
    {
        return array_key_exists(self::relative($path), self::IN_ROW_EXCEPTIONS);
    }

    /**
     * The row actions this source declares, split into write verbs and read/navigate affordances.
     *
     * @return array{verbs: list<string>, reads: list<string>}
     */
    public static function rowActionsIn(string $source): array
    {
        $verbs = [];
        $reads = [];

        foreach (self::segments($source) as $segment) {
            if (! preg_match('/([A-Za-z]*)Action::make\(\s*(?:\'([a-zA-Z0-9_]+)\')?/', $segment, $m)) {
                continue;
            }

            $name = ($m[2] ?? '') !== '' ? $m[2] : ($m[1] !== '' ? $m[1] : 'Action');

            if (str_contains($segment, '->action(') && ! self::handsBackAFile($segment)) {
                $verbs[] = $name;
            } else {
                $reads[] = $name;
            }
        }

        return ['verbs' => $verbs, 'reads' => $reads];
    }

    /**
     * Does this action hand the operator a FILE rather than change anything?
     *
     * A download has an `->action()` closure like any other act, and it is still a read: it takes
     * a copy of the row away and leaves the row alone. `RecordChanged::announceAfterAction()`
     * already draws the same line, by testing the RETURN VALUE for a `Response` — and for the same
     * stated reason, that a download is self-identifying and a list of action names is a thing to
     * keep up to date. Detected here from the source because this gate reads files rather than
     * running them; the shapes are the three this app uses to stream one.
     *
     * The payroll register (`canView`, streams a CSV) and the owner-statement pack are both this,
     * and both belong beside the row they are a copy of.
     */
    private static function handsBackAFile(string $segment): bool
    {
        return (bool) preg_match('/ReportCsv::stream|response\(\)->download|streamDownload|->download\(/', $segment);
    }

    /**
     * The top-level entries of `->recordActions([...])`, with any `ActionGroup` flattened.
     *
     * Tokenised rather than matched, for two reasons that both produced wrong answers when this
     * was a regex:
     *
     *  - An apostrophe inside a `//` COMMENT reads as an opening quote and swallows the rest of
     *    the block, so a table reported one action where it declares nine.
     *  - Braces must NOT be counted. `"{$record->status}"` emits `T_CURLY_OPEN` for the brace and
     *    a PLAIN `}` for its close, so a brace-counting walk goes negative mid-string and stops
     *    early — the same tokenizer trap CLAUDE.md records for the test-helper gate. Braces never
     *    delimit this array, so they are simply not counted.
     *
     * A `...SomeActions::all()` spread contributes nothing here, which is correct: the verbs are
     * defined in that class and the table is only composing them.
     *
     * @return list<string>
     */
    public static function segments(string $source): array
    {
        $tokens = token_get_all($source);
        $open = null;

        for ($i = 0; $i < count($tokens) - 2; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_OBJECT_OPERATOR
                && is_array($tokens[$i + 1]) && $tokens[$i + 1][1] === 'recordActions') {
                $open = $i + 2;
                break;
            }
        }

        if ($open === null) {
            return [];
        }

        while ($open < count($tokens) && $tokens[$open] !== '[') {
            $open++;
        }

        return $open < count($tokens) ? self::splitArrayAt($tokens, $open) : [];
    }

    /**
     * Split the array literal whose opening `[` is at $open into its top-level entries,
     * flattening a nested `ActionGroup::make([...])` because its children are still row actions.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     * @return list<string>
     */
    private static function splitArrayAt(array $tokens, int $open): array
    {
        $depth = 0;
        $segments = [];
        $current = '';
        $groupOpens = [];

        for ($k = $open; $k < count($tokens); $k++) {
            $token = $tokens[$k];

            if (is_string($token)) {
                if ($token === '[' || $token === '(') {
                    $depth++;

                    // Remember where a group's own array starts so it can be split in turn.
                    //
                    // It must be the bracket IMMEDIATELY after `ActionGroup::make(` — `$current`
                    // accumulates for the whole segment, so a `str_contains` test is true for
                    // every nested `[` after it and the last one wins. That pointed the recursion
                    // at an inner array (a `__()` replacement list) and the whole group's actions
                    // vanished from the sweep while it reported a tidy result.
                    if ($token === '[' && preg_match('/ActionGroup::make\(\s*$/', $current)) {
                        $groupOpens[count($segments)] = $k;
                    }

                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($token === ']' || $token === ')') {
                    $depth--;

                    if ($depth === 0) {
                        if (trim($current) !== '') {
                            $segments[] = $current;
                        }

                        break;
                    }
                } elseif ($token === ',' && $depth === 1) {
                    if (trim($current) !== '') {
                        $segments[] = $current;
                    }

                    $current = '';

                    continue;
                }

                $current .= $token;

                continue;
            }

            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $current .= $token[1];
            }
        }

        $flattened = [];

        foreach ($segments as $index => $segment) {
            if (isset($groupOpens[$index])) {
                $flattened = [...$flattened, ...self::splitArrayAt($tokens, $groupOpens[$index])];

                continue;
            }

            $flattened[] = $segment;
        }

        return $flattened;
    }

    /** `app/Filament/Foo/Bar.php` (absolute or relative) => `Foo/Bar`. Idempotent. */
    public static function relative(string $path): string
    {
        return TableSortPolicy::relative($path);
    }
}
