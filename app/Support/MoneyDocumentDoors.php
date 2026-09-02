<?php

namespace App\Support;

use App\Models\Concerns\RecordsBankAccount;
use App\Support\Filament\BankAccountField;

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
        'app/Filament/Admin/Actions/LeaseActions.php::is_opening_balance' => 'A CUTOVER flag: it marks a deposit the operator already held before this system existed, so the register (where migrated balances are keyed in) asks and the day-to-day modal does not. Offering it here would invite an ordinary receipt to be booked as an opening balance, which is the one thing that would make the pot disagree with the books.',
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
