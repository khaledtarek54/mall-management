<?php

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Every DOOR through which a record is written — and the rule that changing one makes you look at
 * the others.
 *
 * ## Why this exists
 *
 * A record type has more than one write surface. A zone is created from the Areas register **and**
 * from the property's own Zones tab; a charge from the schedule tab on a lease; an invoice is
 * rendered by an admin form, a portal twin and a mobile API resource. Changing one reaches one of
 * them, and the rest go on doing what they did — with nothing red, because each file is correct on
 * its own and no test drives the door nobody thought about.
 *
 * `CLAUDE.md` and `/safe-change` step 2 both already state the rule — *enumerate the doors onto the
 * thing by grepping the thing, never from the diff you just wrote* — and `MoneyDocumentDoors` states
 * the corollary that makes this file necessary: **a sentence is not a gate.** This is that sentence
 * as a command (`atriom:doors`) and as a check that reads the actual diff.
 *
 * ## Why this is NOT a parity gate, which was the obvious design and was measured and rejected
 *
 * The first cut compared every form-bearing Filament file against every other one for the same model
 * and reported **435 findings** — the "exempted into meaninglessness" failure `MoneyDocumentDoors`
 * already records. Three separate reasons, each worth keeping in mind before widening anything here:
 *
 * 1. **An action modal is a different ACT, not a lesser door.** *Bulk lodge cheques* asks `count` and
 *    `first_cheque_date`; the register's form asks neither. Comparing them reports a defect in both
 *    directions.
 * 2. **A twin in another panel legitimately differs by AUDIENCE.** Measured on the three
 *    admin/portal form twins: the operator's tenant-request form carries 20 components the tenant's
 *    does not — 18 of them real columns, among which `status`, `assigned_to`, `resolution_notes` and
 *    `target_resolution_at` are ones a tenant must *not* be able to set. Parity would demand the leak.
 * 3. **An ATTACH relation manager's `form()` writes the PIVOT, not the related model.**
 *    `AssetStaffRelationManager` asks `title`, `assigned_at`, `ended_at`, `notes` — columns of
 *    `asset_user`. Read as a door onto `User` it looks like it is missing `roles` and `password`; it
 *    is missing nothing. Measured: of the three managers the naive rule flagged, two were exactly
 *    this misreading and only one was a real divergence.
 *
 * So parity is only a meaningful question between doors of the **same kind and the same audience**,
 * which is one pair today ({@see comparablePairs()}). The general guard is the co-edit check: derive
 * the siblings, and when a change touches one, NAME the others. That works whether or not the doors
 * are supposed to be identical, which is the half a parity rule can never get right.
 *
 * ## Fail loud
 *
 * A door that cannot be attributed to a record appears in {@see unclassifiable()} rather than being
 * skipped — the `DeletionPolicy` idiom, and for the reason this codebase keeps rediscovering: a
 * sweep that silently stops collecting reports a clean run over a set it never looked at. A file
 * that genuinely renders no record of ours is registered in {@see NOT_A_RECORD} with the reason.
 *
 * ## Known limits, stated rather than implied
 *
 * - An ACTION door is found by `Model::create([`, so an action that hands its data to a SERVICE is
 *   attributed to that service instead of to the screen. `MoneyDocumentDoors` has the same limit and
 *   states it too.
 * - A relation manager is attributed to what it SHOWS, not to its parent, so the parent-screen half
 *   of a co-edit is not covered.
 * - `fieldsAskedIn()` is file-wide, so a repeater's child fields are hoisted into the parent's set.
 *   Harmless at one comparable pair; a false-positive engine if that ever grows.
 * - This is the third spelling of *what is a field* in this application, beside
 *   `MoneyDocumentDoors` and `ModalFieldReach`. That is
 *   a debt: the repo's own rule says extract on the second call site, and doing it means touching
 *   two gates whose own tests would have to be re-proved.
 */
final class WriteSurfaces
{
    /**
     * `Foo::create([`, and never `BarFoo::create([`.
     *
     * ONE definition because it was written twice and the second copy was WRONG: in a PHP
     * single-quoted string `\\` is one literal backslash, so `[\w\\]` collapsed to `[\w\]`, a
     * variable-length lookbehind PCRE refuses to compile — and `preg_match_all` then returns false
     * with a warning, so the sweep found nothing and reported a clean run. Four backslashes.
     */
    private const CREATES = '/(?<![\w\\\\])([A-Z][A-Za-z]+)::create\(\[/';

    /** A Filament schema Filament itself saves onto the record — a resource's create/edit form. */
    public const RESOURCE_FORM = 'resource_form';

    /** A relation manager's own write surface. Writes the related model, or the PIVOT — see `writes`. */
    public const RELATION_MANAGER = 'relation_manager';

    /** A Filament importer: the door a migrating operator's spreadsheet comes through. */
    public const IMPORTER = 'importer';

    /** A Filament exporter: not a write, but the round trip's other half — see the CSV gates. */
    public const EXPORTER = 'exporter';

    /** An API resource — the mobile app's rendering of the same record. */
    public const API_RESOURCE = 'api_resource';

    /**
     * A Filament ACTION that builds a record — a header act, a row act, a page's own modal.
     *
     * **This was missing, and the file it was missing for is the one every docblock here cites.**
     * `LeaseActions::recordDeposit()` is the motivating example of the whole idea — the deposit
     * modal that never got the bank field its six sibling doors got — and `siblingsOf()` answered
     * ZERO for it. `MoneyDocumentDoors` catches it because a door there is any schema asking the
     * rail; this registry could not see it at all, so the generalisation was narrower than its own
     * prior art on precisely the case it was built from.
     *
     * It matters most for the CONTRACTOR panel, whose entire write surface is four acts and which
     * therefore contributed no doors whatever until this existed.
     */
    public const ACTION = 'action';

    /** Anything outside `app/Filament` that builds the row itself: a service, a job, a listener. */
    public const OFF_PANEL_CREATOR = 'off_panel_creator';

    /**
     * Files that look like a door and render no record of ours, with why.
     *
     * The `DeletionPolicy` idiom: fail loud, and classify by writing the reason down. Both entries
     * are API resources over something that is not an `App\Models` record at all, so guessing a
     * model for them would put a door on the wrong register.
     *
     * @var array<string, string>
     */
    public const NOT_A_RECORD = [
        'app/Http/Resources/Api/V1/NotificationResource.php' => "Renders Laravel's own `DatabaseNotification`, not a record of ours — its own @mixin says so. The bell rows have no model in `app/Models` and no form, importer or exporter to be out of step with.",
        'app/Http/Resources/Api/V1/PaymobSessionResource.php' => 'Renders a payment-gateway SESSION — a handle returned by Paymob for a checkout in flight, not a row. The record the session settles is the `Payment`, which has its own doors.',
    ];

    /**
     * Divergences between two doors of the same kind and audience that are DELIBERATE, with why.
     *
     * Keyed `path.php::field`. The point of it is not the exemption — it is that the claim is
     * written down and reviewable rather than being the silent difference between two screens
     * nobody compared.
     *
     * @var array<string, string>
     */
    public const PARITY_DIVERGES = [
        // Empty, and that is the intended state. The one entry this ever held —
        // `AssetAreasRelationManager::supervisors`, a zone tab that created zones nobody was
        // routed to — was closed on 2026-09-10 by giving the tab the picker, which is the outcome
        // registering a divergence is meant to lead to.
    ];

    /**
     * Relation managers whose write surface is the PIVOT rather than the related model.
     *
     * DERIVED, never listed — a manager that offers `AttachAction`/`AssociateAction` and no
     * `CreateAction` cannot create the related record, so every field on its form is a column of the
     * join table. Kept as a named predicate because reading it the other way is what produced two of
     * the three findings in the first measurement.
     */
    public static function writesThePivot(string $source): bool
    {
        // Read with COMMENTS BLANKED, in the same class whose `offPanelCreators()` already does it
        // and for the same reason: a manager whose docblock explains "there is no AttachAction
        // here" would otherwise be read as pivot-writing and drop out of parity silently. No file
        // trips it today; it is here so that explaining the rule cannot break it.
        $source = PhpSource::withoutComments($source);

        return (bool) preg_match('/(AttachAction|AssociateAction)::make/', $source)
            && ! preg_match('/CreateAction::make/', $source);
    }

    /**
     * Does this relation manager write the RELATED record's own columns?
     *
     * **`public function form(` is the wrong probe and was the first answer here.** Measured: 17 of
     * the 42 managers it called read-only actually collect fields — `WorkOrderCommentsRelationManager`
     * creates its record from a `CreateAction::make()->schema([...])` with no `form()` at all, and
     * `ChargeScheduleRelationManager::addCharge` is *the* door onto `Charge` from the lease page,
     * asking seven of its columns. Both were excluded from parity for ever AND printed by
     * `atriom:doors` under the label *"read-only tab"* — a false statement about a screen that
     * creates records, in the guard's own output.
     *
     * **Widening it to "collects any field" was the opposite mistake and was measured too**: it
     * turned a date-range FILTER (`TenantPaymentsRelationManager`'s `payment_from`/`payment_until`)
     * and an ASSIGNMENT action (`UnitOwnershipRentableItemsRelationManager::assignRentableItem`)
     * into doors, producing eleven findings that were all noise — the 435-finding failure in
     * miniature.
     *
     * So a custom action is a door only when what it collects is the related record's OWN columns:
     *
     *   - it must name at least one real column of that table — a filter's bounds name none;
     *   - it must NOT name the related model's own foreign key, because collecting `rentable_item_id`
     *     is how a screen says *pick an existing one*, which is an attach wearing different clothes.
     *
     * Asked of the SCHEMA rather than kept as a list, so the next such manager is classified by
     * being what it is.
     */
    public static function writesTheRelatedRecord(string $source, string $related): bool
    {
        if (self::writesThePivot($source)) {
            return false;
        }

        $bare = PhpSource::withoutComments($source);

        // Filament's own create/edit surfaces need no further argument: they save the record.
        if (preg_match('/public function form\(/', $bare) || preg_match('/CreateAction::make/', $bare)) {
            return true;
        }

        $asked = self::fieldsAskedInSource($bare);

        if ($asked === []) {
            return false;
        }

        $model = new $related;

        if (in_array($model->getForeignKey(), $asked, true)) {
            return false;
        }

        foreach ($asked as $field) {
            if (Schema::hasColumn($model->getTable(), $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every door in the application, keyed by the path that declares it.
     *
     * @return array<string, array{kind: string, model: class-string|null, writes: class-string|null, panel: string|null, note: string|null, via: string|null, unresolved: string|null}>
     */
    public static function doors(): array
    {
        $doors = [];

        foreach (self::panels() as $panel => $resources) {
            foreach ($resources as $resource) {
                $model = $resource::getModel();

                foreach (self::formFilesOf($resource) as $path) {
                    $doors[$path] = self::door(self::RESOURCE_FORM, $model, $model, $panel);
                }

                foreach (self::relationManagersOf($resource) as $path => $manager) {
                    // `array_merge`, NEVER `$manager + [...]`. The union operator keeps the value
                    // already at a key, and `door()` has just set `panel` to null — so the `+` form
                    // left every relation manager panel-less, no pair matched, and the parity check
                    // reported a clean sweep over ZERO pairs. `comparablePairs()` exists so the gate
                    // can see that, because six mutations could not.
                    $doors[$path] = array_merge($manager, ['panel' => $panel]);
                }
            }
        }

        foreach (self::declaredModelClasses(app_path('Filament/Imports'), 'Importer') as $path => $model) {
            $doors[$path] = self::door(self::IMPORTER, $model, $model, null);
        }

        foreach (self::declaredModelClasses(app_path('Filament/Exports'), 'Exporter') as $path => $model) {
            $doors[$path] = self::door(self::EXPORTER, $model, null, null);
        }

        foreach (self::apiResources() as $path => $model) {
            $doors[$path] = self::door(
                self::API_RESOURCE, $model, null, null, null, null,
                $model === null ? 'renders no resolvable record — declare @mixin' : null,
            );
        }

        foreach (self::filamentActionCreators($doors) as $key => $model) {
            $doors[$key] = self::door(self::ACTION, $model, $model, null);
        }

        foreach (self::offPanelCreators() as $key => $model) {
            $doors[$key] = self::door(self::OFF_PANEL_CREATOR, $model, $model, null);
        }

        ksort($doors);

        return $doors;
    }

    /**
     * Every door onto one record, whatever kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forModel(string $model): array
    {
        return array_filter(self::doors(), fn (array $door): bool => $door['model'] === $model);
    }

    /**
     * The other doors onto whatever the given file is a door onto — the co-edit question.
     *
     * A file can be keyed more than once — a creator building several records is keyed
     * `path → Model` per record — so the siblings are the union over every record that file is a
     * door onto, minus the file itself.
     *
     * **A relation manager is attributed to what it SHOWS, not to its parent.** So editing
     * `LeaseInvoicesRelationManager` reports the other `Invoice` doors and not the other `Lease`
     * screens. That is deliberate — the question is *what else writes this record* — but it is a
     * real limit worth knowing: the parent-screen half of a co-edit is not covered here.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function siblingsOf(string $path, ?array $doors = null): array
    {
        $doors ??= self::doors();
        $path = self::relative($path);

        $models = [];

        foreach ($doors as $candidate => $door) {
            // An off-panel creator is keyed `path → Model`, because one service can build several
            // records and a file-only key let one document's verdict stand for the next (the
            // finding already recorded against `MoneyDocumentDoors::offPanelCreators()`). So the
            // co-edit question compares the PATH half, or changing a service would find no siblings
            // at all — silently, which is the failure mode this class exists to end.
            if (self::pathOf($candidate) === $path && $door['model'] !== null) {
                $models[$door['model']] = true;
            }
        }

        // A MODEL file is a door onto itself — changing `$fillable`, a cast or a `saving` hook is
        // exactly the change that has to reach the forms, the importer and the API. It is not in
        // `doors()` (it is not a screen), so it is resolved here.
        if (preg_match('#^app/Models/([A-Za-z]+)\.php$#', $path, $m) && class_exists('App\\Models\\'.$m[1])) {
            $models['App\\Models\\'.$m[1]] = true;
        }

        if ($models === []) {
            return [];
        }

        return array_filter(
            $doors,
            fn (array $door, string $candidate): bool => self::pathOf($candidate) !== $path
                && $door['model'] !== null
                && isset($models[$door['model']]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Doors that could not be attributed to a record, with why.
     *
     * **Read off `doors()`, so it covers every KIND.** The first version walked relation managers
     * only while the class docblock claimed the `DeletionPolicy` idiom for all of them — and
     * measured, five API resource files were being dropped by a bare `class_exists()` with no else,
     * three of them real doors (`LoginLeaseResource` renders a `Lease`, and the two public-feed
     * resources render `Tenant` and `MarketingPost`). A change to `MarketingPost` was never told
     * about the renderer the shopper app reads.
     *
     * @return array<string, string>
     */
    public static function unclassifiable(bool $applyRegistry = true): array
    {
        $found = [];

        foreach (self::doors() as $path => $door) {
            if (($door['unresolved'] ?? null) !== null
                && ! ($applyRegistry && array_key_exists($path, self::NOT_A_RECORD))) {
                $found[$path] = $door['unresolved'];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * How many doors each panel contributed.
     *
     * Exposed so the gate can assert a floor PER PANEL. Asserting per KIND is not enough and the
     * comment claiming otherwise was false: admin alone supplies ~66 resource forms and ~66 relation
     * managers, so portal and vendor could both vanish inside `panels()`' catch and every
     * kind-level floor would still be met.
     *
     * @return array<string, int>
     */
    public static function doorsPerPanel(): array
    {
        $counts = [];

        foreach (array_keys(self::panels()) as $panel) {
            $counts[$panel] = 0;
        }

        foreach (self::doors() as $door) {
            if (($door['panel'] ?? null) !== null) {
                $counts[$door['panel']] = ($counts[$door['panel']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The (relation manager, resource form) pairs parity is actually asked of.
     *
     * **Exposed because the gate could not otherwise prove it was asking anything.** The historical
     * `$manager + ['panel' => $panel]` bug left every manager panel-less, so no pair matched and
     * `parityDisagreements()` returned an empty array — indistinguishable, to every assertion in the
     * gate, from a clean sweep. Restoring that bug left all seven tests GREEN, which was found by
     * review and not by the six mutations, because every one of them mutated something the empty
     * result still reported on. A count of pairs is the property that actually goes to zero.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function comparablePairs(): array
    {
        $doors = self::doors();
        $pairs = [];

        foreach ($doors as $path => $door) {
            if ($door['kind'] !== self::RELATION_MANAGER || $door['writes'] === null) {
                continue;
            }

            foreach ($doors as $formPath => $other) {
                if ($other['kind'] === self::RESOURCE_FORM
                    && $other['model'] === $door['writes']
                    && $other['panel'] === $door['panel']) {
                    $pairs[] = [$path, $formPath];
                }
            }
        }

        return $pairs;
    }

    /**
     * Fields one door asks for that a same-kind, same-audience sibling does not.
     *
     * Today that is a relation manager that WRITES its related record, against that record's own
     * resource form: both are operator-facing, both create the same record, and neither has any
     * reason to ask a different set. Every other combination was measured and is noise — see this
     * class's docblock.
     *
     * @param  bool  $applyRegistry  false to ask what the check would say with no exemptions, which
     *                               is how the gate proves it is still comparing something
     * @return array<int, string> one sentence per unregistered divergence
     */
    public static function parityDisagreements(bool $applyRegistry = true): array
    {
        $doors = self::doors();
        $found = [];

        foreach (self::comparablePairs() as [$path, $formPath]) {
            $door = $doors[$path];

            $missing = self::fieldsMissingFrom(
                self::fieldsAskedIn($formPath),
                self::fieldsAskedIn($path),
                $door['via'],
            );

            foreach ($missing as $field) {
                if ($applyRegistry && array_key_exists("{$path}::{$field}", self::PARITY_DIVERGES)) {
                    continue;
                }

                $found[] = "{$path} creates a ".class_basename((string) $door['writes'])
                    ." and never asks `{$field}`, which {$formPath} asks for";
            }
        }

        sort($found);

        return $found;
    }

    /**
     * What a form asks that a creating manager does not — the comparison itself, pure.
     *
     * Extracted so it can be PROVED on synthetic input: over the real pairs the answer is empty
     * (that is the intended state), and an empty answer is indistinguishable from a comparison
     * that stopped comparing — `parityDisagreements()` returning `[]` unconditionally left every
     * test in the gate green (review, 2026-09-10).
     *
     * `$via` is the key that LINKS the child to the record you are standing on. Filament fills it
     * in from the owner, so a manager not asking for it is a derivation, not a gap — the same
     * reasoning `MoneyDocumentDoors::DOOR_DERIVES` writes out for the deposit modal's `lease_id`.
     * Derived rather than registered, because every one of the sixty-seven managers would
     * otherwise need the same entry saying the same thing.
     *
     * @param  array<int, string>  $formAsks
     * @param  array<int, string>  $managerAsks
     * @return array<int, string>
     */
    public static function fieldsMissingFrom(array $formAsks, array $managerAsks, ?string $via): array
    {
        return array_values(array_diff($formAsks, $managerAsks, array_filter([$via])));
    }

    /**
     * Registered divergences that no longer describe anything.
     *
     * A stale exemption is the other half of the gate: it reads as a reviewed decision while the
     * code underneath it has moved on. Same failure direction as a `blocked_by` naming a relation
     * that does not exist.
     *
     * @return array<int, string>
     */
    public static function staleDivergences(): array
    {
        $doors = self::doors();
        $stale = [];

        foreach (array_keys(self::PARITY_DIVERGES) as $key) {
            [$path, $field] = explode('::', $key, 2);

            if (! isset($doors[$path])) {
                $stale[] = "{$key} names a door that no longer exists";

                continue;
            }

            if (in_array($field, self::fieldsAskedIn($path), true)) {
                $stale[] = "{$key} is registered as NOT asked, and that door now asks it";

                continue;
            }

            // **Two more ways it could be fooled, both found by review and both leaving the entry
            // standing while it protects nothing.** Give that manager an `AttachAction` and it
            // stops being compared at all (`writes` goes null, no pair); drop the field from the
            // counterpart FORM and the divergence is gone. Either way the exemption reads as a
            // reviewed decision about a comparison that is no longer made.
            $door = $doors[$path];

            if ($door['kind'] !== self::RELATION_MANAGER || $door['writes'] === null) {
                $stale[] = "{$key} names a door that is no longer compared to a resource form";

                continue;
            }

            $counterparts = array_filter(
                $doors,
                fn (array $other): bool => $other['kind'] === self::RESOURCE_FORM
                    && $other['model'] === $door['writes']
                    && $other['panel'] === $door['panel'],
            );

            if ($counterparts === []) {
                $stale[] = "{$key} names a door whose record no longer has a resource form to differ from";

                continue;
            }

            $asked = false;

            foreach (array_keys($counterparts) as $counterpart) {
                $asked = $asked || in_array($field, self::fieldsAskedIn($counterpart), true);
            }

            if (! $asked) {
                $stale[] = "{$key} is registered as a divergence and no resource form asks `{$field}` any more";
            }
        }

        return $stale;
    }

    /** Every column a Filament file ASKS for — the asking-vs-displaying distinction, as elsewhere. */
    public static function fieldsAskedIn(string $path): array
    {
        static $memo = [];

        if (isset($memo[$path])) {
            return $memo[$path];
        }

        $full = base_path($path);

        return $memo[$path] = is_file($full)
            ? self::fieldsAskedInSource((string) file_get_contents($full))
            : [];
    }

    /**
     * The same question asked of source already in hand.
     *
     * A display component (`TextEntry`, `Placeholder`) collects nothing and a layout one (`Section`,
     * `Grid`) has no state, so matching `::make(` alone would read a heading as a field. This is a
     * SUPERSET of `ModalFieldReach::ASKING_COMPONENTS` — it adds the components a relation manager
     * and a resource form use that an action modal does not — and the fact that there are now three
     * spellings of "what is a field" in this application is recorded as a debt in the class docblock
     * rather than quietly left for the next reader to discover.
     */
    public static function fieldsAskedInSource(string $source): array
    {
        $names = [];

        foreach ([
            'Select', 'Radio', 'ToggleButtons', 'TextInput', 'DatePicker', 'DateTimePicker',
            'TimePicker', 'Textarea', 'Toggle', 'Checkbox', 'MonthPicker', 'EntitySelect',
            'FileUpload', 'RichEditor', 'ColorPicker', 'KeyValue', 'CheckboxList', 'Repeater',
            // Found by review, all live in this tree: a `Hidden` still writes the column (three
            // sites, incl. `guard_name` and `advance_deduction`), `TagsInput` writes `checklist`,
            // and `EquipmentPicker` is this project's own record picker on a service-plan stop.
            'Hidden', 'TagsInput', 'EquipmentPicker',
        ] as $component) {
            if (preg_match_all('/'.$component."::make\('([a-z0-9_]+)'\)/", $source, $m)) {
                $names = array_merge($names, $m[1]);
            }
        }

        // The project components that name their own column rather than taking it as an argument.
        // Without these a form using them reads as not asking for the property or the bank account,
        // which is the opposite of what they are for. The property field has four spellings
        // (`make`, `scope`, `reportScope`, `registrationScope`) and matching only the first two
        // missed four call sites.
        if (preg_match('/PropertyField::[a-zA-Z]*[Ss]cope\(|PropertyField::make\(/', $source)) {
            $names[] = 'asset_id';
        }

        if (preg_match('/BankAccountField::(for|make)\(/', $source)) {
            $names[] = 'bank_account_id';
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /** The file half of a door key — everything before the ` → Model` suffix a creator carries. */
    public static function pathOf(string $key): string
    {
        return trim(explode(' → ', $key)[0]);
    }

    /** Path relative to the project root, which is how every door is keyed. */
    public static function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    /**
     * @param  string|null  $note  the door's SHAPE, for the reader — "read-only tab", "attaches"
     * @param  string|null  $unresolved  why this door could not be attributed at all — a FAILURE
     * @return array{kind: string, model: class-string|null, writes: class-string|null, panel: string|null, note: string|null, via: string|null, unresolved: string|null}
     */
    private static function door(string $kind, ?string $model, ?string $writes, ?string $panel, ?string $note = null, ?string $via = null, ?string $unresolved = null): array
    {
        return compact('kind', 'model', 'writes', 'panel', 'note', 'via', 'unresolved');
    }

    /** @return array<string, array<int, class-string>> */
    private static function panels(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $memo = [];

        foreach (Filament::getPanels() as $panel) {
            try {
                $memo[$panel->getId()] = $panel->getResources();
            } catch (Throwable) {
                // A panel that cannot enumerate its resources is not silently skipped — it would
                // make every sweep below vacuous for that panel. It is reported as an empty set and
                // the gate asserts a floor PER PANEL, which is the check that notices.
                $memo[$panel->getId()] = [];
            }
        }

        return $memo;
    }

    /**
     * The files that declare a resource's form.
     *
     * A resource either delegates to a `Schemas/…Form.php` (the convention here) or builds the
     * schema inline, so the door is the schema file where there is one and the resource itself where
     * there is not.
     *
     * **A resource with no form at all is not a form door.** The fallback used to claim the resource
     * file unconditionally, and measured, NOT ONE of the twelve resources it caught declares `form(`
     * — six portal read-only resources, the vendor work-order screen and four admin registers. So
     * twelve of seventy-seven "resource form" doors wrote nothing, inflated the premise floor, and
     * printed in `--check-diff` under a banner reading *these doors WRITE a record*.
     *
     * @return array<int, string>
     */
    private static function formFilesOf(string $resource): array
    {
        $file = (string) (new ReflectionClass($resource))->getFileName();
        $schemas = glob(dirname($file).'/Schemas/*Form.php') ?: [];

        if ($schemas !== []) {
            return array_map(self::relative(...), $schemas);
        }

        return preg_match('/function form\(/', (string) file_get_contents($file)) === 1
            ? [self::relative($file)]
            : [];
    }

    /**
     * A resource's relation managers, each resolved to what it actually writes.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function relationManagersOf(string $resource): array
    {
        $out = [];

        foreach ($resource::getRelations() as $relation) {
            $manager = is_string($relation)
                ? $relation
                : (method_exists($relation, 'getManager') ? $relation->getManager() : null);

            if (! is_string($manager) || ! class_exists($manager)) {
                continue;
            }

            $reflection = new ReflectionClass($manager);
            $path = self::relative((string) $reflection->getFileName());
            $source = (string) file_get_contents((string) $reflection->getFileName());
            $parent = $resource::getModel();

            // **Resolved by REFLECTION, never by grepping the file.** `AssetActivitiesRelationManager`
            // and its tenant twin declare no `$relationship` of their own — they inherit it from
            // `ActivitiesRelationManager` — so a regex over the file's own source reported both as
            // unattributable. Two of sixty-seven, which is small enough to have been waved through
            // as an exemption and would have been wrong.
            if (! $reflection->hasProperty('relationship')) {
                $out[$path] = self::door(self::RELATION_MANAGER, $parent, null, null, null, null, 'declares no $relationship');

                continue;
            }

            $property = $reflection->getProperty('relationship');
            $property->setAccessible(true);
            $name = $property->getValue();

            try {
                $relationInstance = (new $parent)->{$name}();
                $related = get_class($relationInstance->getRelated());
                $via = method_exists($relationInstance, 'getForeignKeyName')
                    ? $relationInstance->getForeignKeyName()
                    : null;
            } catch (Throwable $e) {
                $out[$path] = self::door(self::RELATION_MANAGER, $parent, null, null, null, null, "relation `{$name}` does not resolve: ".$e->getMessage());

                continue;
            }

            // THREE shapes, and telling them apart is the whole reason the first measurement of
            // this idea produced two false positives out of three findings:
            //   - writes the related record   -> a real door onto its columns
            //   - attaches an existing one    -> its form writes the PIVOT, not the record
            //   - neither                     -> a read-only tab, no door at all
            $writes = null;
            $shape = 'read-only tab';

            if (self::writesThePivot($source)) {
                $shape = 'attaches — its form writes the pivot';
            } elseif (self::writesTheRelatedRecord($source, $related)) {
                $writes = $related;
                $shape = null;
            }

            $out[$path] = self::door(self::RELATION_MANAGER, $related, $writes, null, $shape, $via);
        }

        return $out;
    }

    /**
     * Importers and exporters, read off the `$model` each one declares.
     *
     * @return array<string, class-string>
     */
    private static function declaredModelClasses(string $dir, string $suffix): array
    {
        $out = [];

        foreach (glob($dir.'/*'.$suffix.'.php') ?: [] as $file) {
            $class = 'App\\Filament\\'.basename(dirname($file)).'\\'.basename($file, '.php');

            if (! class_exists($class) || ! (new ReflectionClass($class))->hasProperty('model')) {
                continue;
            }

            $property = (new ReflectionClass($class))->getProperty('model');
            $property->setAccessible(true);
            $model = $property->getValue();

            if (is_string($model) && class_exists($model)) {
                $out[self::relative($file)] = $model;
            }
        }

        return $out;
    }

    /**
     * API resources, attributed by the `@mixin` they declare and only then by name.
     *
     * **The DECLARATION first.** An earlier comment here asserted there was none to read and that
     * the file name was the only signal — false: 18 of the 20 files carry an `@mixin`, including all
     * three the name-guess was dropping. A name heuristic was the weaker of two available signals,
     * and the comment justifying it was what stopped anyone looking for the stronger one. A file
     * that resolves to neither is returned as null and FAILS `unclassifiable()`.
     *
     * @return array<string, class-string|null>
     */
    private static function apiResources(): array
    {
        $out = [];
        $dir = app_path('Http/Resources');

        if (! is_dir($dir)) {
            return $out;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = self::relative($file->getPathname());
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/@mixin\s+\\\\?([A-Za-z0-9_\\\\]+)/', $source, $m)) {
                $declared = str_starts_with($m[1], 'App\\') ? $m[1] : 'App\\Models\\'.$m[1];

                if (class_exists($declared)) {
                    $out[$path] = $declared;

                    continue;
                }
            }

            $guess = 'App\\Models\\'.Str::beforeLast($file->getBasename('.php'), 'Resource');

            $out[$path] = class_exists($guess) ? $guess : null;
        }

        return $out;
    }

    /**
     * Filament files that build a record inside an action, keyed `path → Model`.
     *
     * Read exactly the way {@see offPanelCreators()} reads a service — a file is a creator BY
     * calling `Model::create([`, comments blanked first — and skipped where the file is already a
     * door of another kind, so a relation manager that also creates inline is not counted twice.
     *
     * @param  array<string, array<string, mixed>>  $already
     * @return array<string, class-string>
     */
    private static function filamentActionCreators(array $already): array
    {
        $out = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = self::relative($file->getPathname());

            if (isset($already[$path])) {
                continue;
            }

            $source = PhpSource::withoutComments((string) file_get_contents($file->getPathname()));

            if (! preg_match_all(self::CREATES, $source, $m)) {
                continue;
            }

            foreach (array_unique($m[1]) as $short) {
                if (class_exists('App\\Models\\'.$short)) {
                    $out[$path.' → '.$short] = 'App\\Models\\'.$short;
                }
            }
        }

        return $out;
    }

    /**
     * Files outside `app/Filament` that build a record themselves.
     *
     * Comments are blanked first: a docblock naming `Invoice::create([` while explaining this rule
     * would otherwise make this very file a creator of one, the prose false-positive already
     * recorded for two of the PDF gates.
     *
     * **A known limit:** this matches `Model::create([` only, so the 17 sites that build through a
     * relation (`$parent->things()->create([`) are not seen. `MoneyDocumentDoors` has the same limit.
     *
     * @return array<string, class-string>
     */
    private static function offPanelCreators(): array
    {
        $out = [];

        foreach ([app_path('Services'), app_path('Jobs'), app_path('Listeners'), app_path('Observers')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = PhpSource::withoutComments((string) file_get_contents($file->getPathname()));

                if (! preg_match_all(self::CREATES, $source, $m)) {
                    continue;
                }

                foreach (array_unique($m[1]) as $short) {
                    if (class_exists('App\\Models\\'.$short)) {
                        $out[self::relative($file->getPathname()).' → '.$short] = 'App\\Models\\'.$short;
                    }
                }
            }
        }

        return $out;
    }
}
