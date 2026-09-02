<?php

namespace App\Support;

use App\Models\Concerns\RecordsBankAccount;
use App\Models\PaymentMethod;
use App\Support\Filament\BankAccountField;
use Illuminate\Support\Facades\Schema;

/**
 * Every screen through which an operator records money that moves through a bank — and the rule
 * that they all ask the same question.
 *
 * ## Why this exists
 *
 * A money document has more than one DOOR. A security-deposit movement is recorded from the deposit
 * register **and** from the lease's own Security deposit tab; a supplier payment from the bill's Edit
 * page; an owner payout from the statement run. Adding a field to the register's form reaches one of
 * them, and the others go on quietly recording a document with that field empty — with nothing red,
 * because each file is correct on its own and no test drives the door nobody thought about.
 *
 * That is exactly what happened when `bank_account_id` was made a real question on 2026-09-02: six
 * forms got it and `LeaseActions::recordDeposit()` did not, so every deposit taken from the lease
 * page — which is where an operator actually takes one — recorded no bank account at all. It was
 * reported from the panel, not by the suite. CLAUDE.md already states the rule this broke, about
 * this very pot: *enumerate the doors onto a pot by grepping the pot, never from the diff that fixed
 * one of them.* A sentence is not a gate.
 *
 * ## What counts as a door, and why it is DERIVED
 *
 * A door is a Filament schema that collects the document's **rail** as a form field — `method` or
 * `paid_from`, whichever that document calls it. That is the observable signal that this screen is
 * recording money movement rather than merely listing it: a table column or a filter reads the rail,
 * a FORM FIELD asks for it.
 *
 * Nothing is listed by hand. The documents come from the models that use
 * {@see RecordsBankAccount}, each rail from that model's own `bankAccountRailColumn()`, and the
 * doors from disk. A registry of doors would go stale the moment somebody adds a screen — which is
 * the failure this is built to catch, so it must not depend on the same person remembering.
 *
 * ## Attribution, and why it is not a list of exceptions
 *
 * Several screens collect a rail for a document that has NO bank account: petty cash (`Custody`),
 * employee advances, marketing spend. Those are the flows EG-12 deliberately left resolving through
 * the rail. They are told apart by whether the file NAMES one of the bank-account documents — not by
 * an exemption list, so a new petty-cash screen is correct by being what it is, and a new
 * bank-account screen is caught by being what it is.
 */
final class MoneyDocumentDoors
{
    /**
     * Filament form components that ASK for a value, as opposed to displaying one.
     *
     * `TextColumn`, `SelectFilter` and `ExportColumn` all take the same `::make('method')` shape and
     * none of them records anything — matching on the column name alone would flag every list that
     * shows which rail a payment used.
     */
    private const ASKING_COMPONENTS = ['Select', 'Radio', 'ToggleButtons', 'TextInput'];

    /**
     * Doors that legitimately collect a rail and offer no bank account, with the reason.
     *
     * Empty, and that is the intended state. A door belongs here only when the money genuinely
     * cannot have moved through a bank account — not when somebody has not got round to it, which is
     * the thing this gate exists to notice.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [];

    /**
     * A door that legitimately does NOT ask one of the money questions its sibling doors ask,
     * because it already knows the answer — keyed `path.php::field`, valued with why.
     *
     * A derivation is not a gap. The lease page's deposit modal is opened FROM a lease, so asking
     * which lease would be asking the operator to re-state what they clicked on. What the registry
     * buys is that the claim is written down and reviewable, rather than being the silent difference
     * between two screens that nobody compared.
     *
     * @var array<string, string>
     */
    public const DOOR_DERIVES = [
        'app/Filament/Admin/Actions/LeaseActions.php::lease_id' => 'The modal is opened FROM the lease, and the action writes `$record->id`. Asking would be asking the operator to re-state what they clicked on — and offering a picker would let them file a deposit against a different lease than the page they are on.',
        'app/Filament/Admin/Actions/LeaseActions.php::is_opening_balance' => 'A CUTOVER flag, and offering it here would be a money defect rather than a convenience. `DepositTransactionJournalizer` returns NO PAYLOAD for an opening balance — the cash arrived in the previous system and the liability is already inside the opening trial balance — so a receipt ticked on this modal would show in the lease\'s deposit pot and never move `deposits_held`. The register is where migrated balances are keyed in and is the only place that question belongs; `DepositTransaction::booted()` already refuses the flag on a refund or forfeit for the mirror-image reason.',
    ];

    /**
     * Do the doors onto one document ask the same money questions?
     *
     * **Compared to EACH OTHER, never to a spec.** The alternative — every door must collect every
     * field `ChangeImpact` classifies as reaching the ledger — was measured and rejected: it makes
     * `lease_id`, `tenant_id`, `asset_id`, `status` and the document number a finding on every door
     * that legitimately derives them, about forty entries, which is a list exempted into
     * meaninglessness. Comparing doors to each other asks the question that actually failed: a field
     * one screen asks for and another does not.
     *
     * The union is narrowed to columns `ChangeImpact` says are DERIVED or REFUSED — the ones that
     * decide what the document IS or where it posts — so a `notes` box on one screen and not the
     * other is not reported as a defect.
     *
     * Silent for a document with ONE door, and that is correct rather than a hole: there is nothing
     * to disagree with. It arms itself the moment somebody adds the second, which is exactly when
     * the failure this exists for becomes possible.
     *
     * @return array<int, string> one sentence per door that is missing a question its siblings ask
     */
    public static function disagreements(): array
    {
        $byModel = [];

        foreach (self::doors() as $path => $door) {
            $byModel[$door['model']][$path] = self::fieldsAskedIn((string) file_get_contents(base_path($path)));
        }

        $found = [];

        foreach ($byModel as $model => $doors) {
            if (count($doors) < 2) {
                continue;
            }

            $policy = ChangeImpact::POLICY[$model] ?? [];
            $material = array_merge(
                array_keys($policy[ChangeImpact::DERIVED] ?? []),
                array_keys($policy[ChangeImpact::REFUSED] ?? []),
            );

            $union = [];

            foreach ($doors as $asked) {
                $union = array_merge($union, array_intersect($asked, $material));
            }

            $union = array_unique($union);

            foreach ($doors as $path => $asked) {
                foreach (array_diff($union, $asked) as $missing) {
                    if (array_key_exists("{$path}::{$missing}", self::DOOR_DERIVES)) {
                        continue;
                    }

                    $found[] = "{$path} records a ".class_basename($model)." and never asks `{$missing}`, "
                        .'which another door onto the same document does';
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Every column a file ASKS for — the same asking-vs-displaying distinction {@see asks()} draws,
     * widened from the rail to the whole schema.
     *
     * @return array<int, string>
     */
    private static function fieldsAskedIn(string $source): array
    {
        $names = [];

        foreach (array_merge(self::ASKING_COMPONENTS, ['DatePicker', 'DateTimePicker', 'Textarea', 'Toggle', 'Checkbox', 'MonthPicker', 'EntitySelect']) as $component) {
            if (preg_match_all('/'.$component."::make\('([a-z0-9_]+)'\)/", $source, $m)) {
                $names = array_merge($names, $m[1]);
            }
        }

        if (preg_match('/BankAccountField::(for|make)\(/', $source)) {
            $names[] = 'bank_account_id';
        }

        return array_values(array_unique($names));
    }

    /**
     * The money documents that record which bank account they moved through.
     *
     * @return array<class-string, array{rail: string, purpose: string}>
     */
    public static function documents(): array
    {
        $documents = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            if (! str_contains((string) file_get_contents($file), 'use RecordsBankAccount;')) {
                continue;
            }

            /** @var class-string $model */
            $model = 'App\\Models\\'.basename($file, '.php');

            $documents[$model] = [
                'rail' => $model::bankAccountRailColumn(),
                'purpose' => $model::bankAccountPurpose(),
            ];
        }

        ksort($documents);

        return $documents;
    }

    /**
     * The documents that can HAVE a door — the ones that ask a rail on a screen somewhere.
     *
     * A door is derived from a schema collecting the document's rail, so a document with no rail
     * COLUMN can have no door by construction and reporting it as undoored would be reporting on the
     * shape of the model rather than on a gap. `PostDatedCheque` is the case: it carries a bank
     * account because a cheque is LODGED with one, and its rail is the paper itself — there is no
     * `method` on the register, and the `cheque` rail appears on the `Payment` clearing mints.
     *
     * Asked of the SCHEMA rather than kept as a list, so the next such document is classified by
     * being what it is.
     *
     * @return array<class-string, array{rail: string, purpose: string}>
     */
    public static function documentsWithARail(): array
    {
        return array_filter(
            self::documents(),
            fn (array $meta, string $model) => Schema::hasColumn((new $model)->getTable(), $meta['rail']),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The documents that cannot work their own property out, so a creator OUTSIDE the panel has to
     * state the bank account.
     *
     * `RecordsBankAccount::bankAccountAssetOf()` answers
     * `asset_id ?? bill?->asset_id ?? TenantScope::currentAssetId()`. The first two are facts the
     * ROW carries; the third is the mall the operator happens to be looking at, and there is no such
     * mall on an API request, a gateway callback, a console command, a queue worker or a seeder. So
     * a document with neither an `asset_id` column nor a `bill` gets **no default at all** off the
     * panel — it falls to the generic `bank` POSTING ROLE, which is where money nobody attributed
     * lands, and `MatchBankStatementLineService::candidatesFor()` then offers a named bank's
     * postings alongside it. That is the state the register exists to end.
     *
     * Today the answer is `Payment` alone, and it is DERIVED rather than named: a document that
     * grows an `asset_id` drops out of this list by having one.
     *
     * **This is the half {@see doors()} is structurally blind to.** A door is a Filament schema, so
     * the gate built for this invariant cannot see `PaymobPaymentInitiator` or
     * `RecordDemoPaymentAction` — which between them are the whole online card channel, the highest
     * volume of inbound receipts on a live install (SW-228).
     *
     * @return array<class-string, array{rail: string, purpose: string}>
     */
    public static function documentsThatCannotSelfDefault(): array
    {
        return array_filter(
            self::documentsWithARail(),
            fn (string $model): bool => ! Schema::hasColumn((new $model)->getTable(), 'asset_id'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Files outside `app/Filament` that build one of those documents inline, and whether the array
     * they build names `bank_account_id`.
     *
     * Read off the source the same way {@see doors()} reads a schema: a creator is one BY calling
     * `Model::create([`, so the next one is classified by being what it is rather than by being
     * remembered.
     *
     * @return array<string, array{path: string, model: class-string, selfDefaults: bool, passesColumn: bool}>
     */
    public static function offPanelCreators(): array
    {
        $creators = [];

        foreach (self::documentsWithARail() as $model => $meta) {
            $short = class_basename($model);
            $selfDefaults = ! isset(self::documentsThatCannotSelfDefault()[$model]);

            foreach (self::offPanelFiles() as $path => $source) {

                // A WORD boundary, because the shorter name is a substring of the longer one —
                // the same trap {@see names()} records for the basename match, and it attributed
                // the vendor-bill service's creator to the receipt.
                //
                // And matched against the source with its COMMENTS BLANKED. An earlier draft of
                // this very comment spelled the literal out, and the sweep then reported this file
                // as a creator of its own — a gate that fires on prose is one that gets weakened
                // rather than fixed, the finding already recorded for two of the PDF gates. No file
                // needs the blanking today; it is here so that explaining the rule cannot break it.
                // Offsets are preserved, so the array literal is still sliced from the real source.
                if (preg_match_all(self::createPattern($short), self::withoutComments($source), $m, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($m[0] as [$match, $at]) {
                    $array = self::arrayLiteralAt($source, $at + strlen($match) - 1);

                    // **TWO ways to be compliant, and which apply depends on the document.**
                    // Naming the account outright always does. Naming the PROPERTY does too, for a
                    // document that carries an `asset_id` — the model then defaults from it through
                    // `RecordsBankAccount`, which is the ordinary and correct pattern
                    // (`SettleMoveOutService` sets `asset_id` from the lease's unit and relies on
                    // exactly that). Only the two documents with no such column are left with one
                    // option.
                    $names = fn (string $key): bool => str_contains($array, "'".$key."'")
                        || str_contains($array, '"'.$key.'"');

                    // **A rail that needs no account is compliant by naming the rail.** The array
                    // states it (`'method' => 'cash'`), and `PaymentMethod::requiresBankAccount()`
                    // is the same question `RecordsBankAccount` asks before defaulting — so the gate
                    // and the model cannot disagree about whether a cash receipt is missing
                    // anything. Only a LITERAL counts; a rail held in a variable is unreadable from
                    // here and falls through to needing the account named.
                    $railIsExempt = preg_match(
                        '/[\'"]'.preg_quote($meta['rail'], '/').'[\'"]\s*=>\s*[\'"]([a-z0-9_]+)[\'"]/i',
                        $array,
                        $rail,
                    ) === 1 && ! PaymentMethod::requiresBankAccount($rail[1]);

                    // **Keyed by file AND document.** Keyed by file alone, a seeder that builds
                    // several money documents carried one document's verdict into the next — the
                    // running `?? true` is per key — and the sweep reported a compliant payroll as
                    // missing because a different document in the same file was.
                    $key = $path.' → '.$short;

                    $creators[$key] = [
                        'path' => $path,
                        'model' => $model,
                        'selfDefaults' => $selfDefaults,
                        // Every creator of THIS document in the file must pass, so one that does
                        // cannot vouch for a sibling beside it that does not.
                        'passesColumn' => ($creators[$key]['passesColumn'] ?? true)
                            && ($railIsExempt
                                || $names('bank_account_id')
                                || ($selfDefaults && $names('asset_id'))),
                    ];
                }
            }
        }

        return $creators;
    }

    /** `Foo::create([`, never `BarFoo::create([`. */
    private static function createPattern(string $short): string
    {
        return '/(?<![\\w\\\\])'.preg_quote($short, '/').'::create\\(\\[/';
    }

    /** The same source with every comment replaced by spaces — offsets, and string literals, intact. */
    private static function withoutComments(string $source): string
    {
        $out = $source;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $at = strpos($out, $token[1]);

            if ($at !== false) {
                $out = substr_replace($out, str_repeat(' ', strlen($token[1])), $at, strlen($token[1]));
            }
        }

        return $out;
    }

    /**
     * The balanced `[...]` starting at `$from`, counted over TOKENS.
     *
     * Character counting was the first version and it fails OPEN, which is the worst direction for a
     * gate: a `[` inside a STRING inside the array — `'cheque [ref missing'`, and
     * `PostDatedChequeService` really does put interpolated free text in one — never closes, so the
     * slice runs to end of file and picks up a `'bank_account_id'` from an unrelated method further
     * down. The creator is then reported as compliant when it is not.
     *
     * The token stream cannot be fooled that way: a `[` inside a string is part of the string token,
     * never a bracket of its own.
     */
    private static function arrayLiteralAt(string $source, int $from): string
    {
        $depth = 0;
        // A cursor, because `token_get_all()` reports a line for each token but no byte offset.
        $cursor = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $start = $cursor;
            $cursor += strlen($text);

            if ($start < $from) {
                continue;
            }

            if ($text === '[') {
                $depth++;
            } elseif ($text === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $from, $cursor - $from);
                }
            }
        }

        return substr($source, $from);
    }

    /**
     * Every door on disk: `relative/path.php => ['model' => …, 'rail' => …, 'offersField' => bool,
     * 'writesColumn' => ?bool]`.
     *
     * `writesColumn` is null when the file does not build the row itself — most doors hand their
     * data to a resource's own save or to a service, and only a file that calls `Model::create([…])`
     * inline can be asked whether it passed the value through.
     *
     * @return array<string, array{model: class-string, rail: string, offersField: bool, writesColumn: ?bool}>
     */
    public static function doors(): array
    {
        $documents = self::documents();
        $doors = [];

        foreach (self::filamentFiles() as $path => $source) {
            foreach ($documents as $model => $meta) {
                if (! self::names($source, $model)) {
                    continue;
                }

                if (! self::asks($source, $meta['rail'])) {
                    continue;
                }

                $doors[$path] = [
                    'model' => $model,
                    'rail' => $meta['rail'],
                    'offersField' => str_contains($source, class_basename(BankAccountField::class).'::'),
                    'writesColumn' => self::buildsRowInline($source, $model)
                        ? str_contains($source, "'bank_account_id' =>")
                        : null,
                ];

                break;
            }
        }

        ksort($doors);

        return $doors;
    }

    /**
     * Every PHP file that can build a money document with NO operator in the room.
     *
     * `app/` minus `app/Filament` — a Filament file is a door, and {@see doors()} covers those —
     * plus `database/seeders`, which are off-panel in exactly the same way and are how this whole
     * finding surfaced: the ledger teaching set put its receipt on the generic posting role because
     * a seeder has no selected mall either.
     *
     * @return array<string, string> relative path => source
     */
    private static function offPanelFiles(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $files = [];

        foreach ([app_path(), base_path('database/seeders')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files += self::phpFilesUnder($root);
        }

        return $memo = array_filter(
            $files,
            fn (string $path): bool => ! str_starts_with($path, 'app/Filament/'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string, string> relative path => source */
    private static function phpFilesUnder(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /** @return array<string, string> relative path => source */
    private static function filamentFiles(): array
    {
        $files = [];
        $root = app_path('Filament');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /**
     * Does this file name the model — as an import, or fully qualified?
     *
     * Deliberately not a bare word match on the class basename: `Payment` appears inside
     * `VendorBillPayment`, `PaymentMethod` and `payment_date`, so a substring test would attribute
     * half the panel to the wrong document.
     */
    private static function names(string $source, string $model): bool
    {
        $base = class_basename($model);

        return str_contains($source, 'use '.$model.';')
            || str_contains($source, '\\'.$model)
            || (bool) preg_match('/(?<![A-Za-z_\\\\])'.$base.'(?![A-Za-z_])/', $source);
    }

    /** Does it ASK for the rail, rather than display it? */
    private static function asks(string $source, string $rail): bool
    {
        foreach (self::ASKING_COMPONENTS as $component) {
            if (str_contains($source, $component."::make('".$rail."')")) {
                return true;
            }
        }

        return false;
    }

    /** Does this file build the row itself, so that "did it pass the value through?" is answerable? */
    private static function buildsRowInline(string $source, string $model): bool
    {
        return str_contains($source, class_basename($model).'::create([');
    }
}
