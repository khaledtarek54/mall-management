<?php

use App\Models\Asset;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * **The gate on "no operator ever sees a raw translation key."**
 *
 * There are two ways a screen ends up untranslated, and they need opposite tests:
 *
 *   1. A string that never goes through `__()` at all — hardcoded English. Grep finds these,
 *      and `app/Filament` is clean of them.
 *   2. A `__()` call naming a key that **does not exist**. Laravel returns the key itself, so
 *      the sidebar renders the literal text `admin.navigation.property_overrides`. This is the
 *      one that shipped, and no amount of EN↔AR *parity* checking could have caught it: both
 *      catalogues were perfectly balanced, and the key was simply absent from both.
 *
 * Three more were found the same way once the check existed: a payment-received email that
 * mailed the tenant the raw string `admin.fields.payment_methods.instapay` (its `?:` fallback
 * could not fire — a missing `__()` returns the key, and a non-empty string is truthy), and
 * Laravel's entire validation catalogue, which ships English-only. With no `lang/ar/validation.php`,
 * every "field is required" in the panel, both portals and the API was English for an Arabic
 * operator — every form in every resource, invisible to a sweep that only read our own files.
 *
 * Test D is the one that speaks to the report: it RENDERS each resource's and page's own labels
 * in both locales and fails on anything that still looks like a key. A grep can miss a label
 * built at runtime; asking the class what it will display cannot.
 *
 * **Test F is the third failure mode, and the nastiest, because there is no string to find.**
 * A Filament component given no `->label()` HUMANISES its attribute name — `Group::make('category')`
 * renders the English word "Category" in the group selector and in every group heading. Nothing is
 * hardcoded, so the hardcoded-string sweep is silent; no key is referenced, so test A is silent;
 * both catalogues stay in parity, so test B is silent. It was reported from a screenshot of the
 * Arabic report hub, and 21 components across the panel had the same omission.
 */

/** Does this string look like an unresolved translation key rather than a label? */
function looksLikeTranslationKey(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){2,}$/i', $value) === 1;
}

/**
 * Every translation key referenced from source, split into fully-static keys and the
 * resolvable prefix of interpolated ones (`__("admin.enums.method.{$x}")` → `admin.enums.method`).
 *
 * @return array{static: array<string, string>, prefixes: array<string, string>}
 */
function referencedTranslationKeys(): array
{
    $static = [];
    $prefixes = [];

    foreach (['app', 'resources/views', 'routes', 'database/seeders'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $short = str_replace(base_path().'/', '', $file->getPathname());

            if (preg_match_all("/(?:__|trans_choice|trans|@lang)\(\s*'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/i", $source, $m)) {
                foreach ($m[1] as $key) {
                    $static[$key] ??= $short;
                }
            }

            if (preg_match_all('/(?:__|trans_choice|trans)\(\s*"([a-z0-9_]+(?:\.[a-z0-9_]+)*)\.\{/i', $source, $m)) {
                foreach ($m[1] as $key) {
                    $prefixes[$key] ??= $short;
                }
            }
        }
    }

    return ['static' => $static, 'prefixes' => $prefixes];
}

it('A: every translation key referenced in code exists, in English AND Arabic', function () {
    $referenced = referencedTranslationKeys();
    $broken = [];

    foreach (['en', 'ar'] as $locale) {
        foreach ($referenced['static'] as $key => $file) {
            if (! Lang::has($key, $locale)) {
                $broken[] = "[{$locale}] {$key}  ({$file})";
            }
        }

        // An interpolated key can't be resolved in full, but its namespace can: if
        // `admin.enums.method` doesn't exist, no `admin.enums.method.*` ever will.
        foreach ($referenced['prefixes'] as $key => $file) {
            if (! Lang::has($key, $locale)) {
                $broken[] = "[{$locale}] {$key}.*  ({$file})";
            }
        }
    }

    expect($broken)->toBe([], "These keys are used in code but missing from the catalogue, so the raw key is what the operator sees:\n  ".implode("\n  ", $broken));

    // Vacuity guard: a scanner that silently matched nothing would pass.
    expect(count($referenced['static']))->toBeGreaterThan(3000);
})->group('conformance');

it('B: the English and Arabic catalogues carry exactly the same keys', function () {
    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $out = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $out += is_array($value) && $value !== [] ? $flatten($value, $path) : [$path => $value];
        }

        return $out;
    };

    $problems = [];

    foreach (glob(lang_path('en/*.php')) as $file) {
        $name = basename($file, '.php');
        $arabicFile = lang_path("ar/{$name}.php");

        if (! file_exists($arabicFile)) {
            $problems[] = "lang/ar/{$name}.php does not exist — that whole file is English for Arabic users";

            continue;
        }

        $en = $flatten(require $file);
        $ar = $flatten(require $arabicFile);

        foreach (array_keys(array_diff_key($en, $ar)) as $key) {
            $problems[] = "missing from ar/{$name}.php: {$key}";
        }

        foreach (array_keys(array_diff_key($ar, $en)) as $key) {
            $problems[] = "orphaned in ar/{$name}.php (no English counterpart): {$key}";
        }
    }

    expect($problems)->toBe([], implode("\n  ", array_merge([''], $problems)));
})->group('conformance');

it('C: the Arabic validation catalogue keeps pace with the one Laravel ships', function () {
    // lang/en/validation.php is a published COPY of the framework's file. If a Laravel upgrade
    // adds or renames a rule, the copy goes stale and that rule's message silently reverts to
    // English for Arabic users — the exact failure this file was created to fix, returning by
    // way of composer update rather than by anyone editing anything.
    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $out = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $out += is_array($value) && $value !== [] ? $flatten($value, $path) : [$path => $value];
        }

        return $out;
    };

    $framework = $flatten(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
    $published = $flatten(require lang_path('en/validation.php'));
    $arabic = $flatten(require lang_path('ar/validation.php'));

    // `custom` and `attributes` are ours to shape, so compare only the rule messages.
    $rules = fn (array $set) => array_filter(
        $set,
        fn (string $key) => ! str_starts_with($key, 'custom.') && ! str_starts_with($key, 'attributes.'),
        ARRAY_FILTER_USE_KEY,
    );

    $missingFromPublished = array_keys(array_diff_key($rules($framework), $rules($published)));
    $missingFromArabic = array_keys(array_diff_key($rules($published), $rules($arabic)));

    expect($missingFromPublished)->toBe([], 'Laravel added validation rules that lang/en/validation.php has not picked up: '.implode(', ', $missingFromPublished));
    expect($missingFromArabic)->toBe([], 'These validation messages have no Arabic translation: '.implode(', ', $missingFromArabic));
})->group('conformance');

it('D: every admin resource and page renders real labels, not raw keys, in both locales', function () {
    $panel = Filament::getPanel('admin');
    $original = app()->getLocale();
    $offenders = [];

    try {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ($panel->getResources() as $resource) {
                foreach ([
                    'getNavigationLabel',
                    'getModelLabel',
                    'getPluralModelLabel',
                    'getNavigationGroup',
                ] as $method) {
                    if (! method_exists($resource, $method)) {
                        continue;
                    }

                    $label = rescue(fn () => $resource::{$method}(), null, false);

                    if (looksLikeTranslationKey($label)) {
                        $offenders[] = "[{$locale}] ".class_basename($resource)."::{$method}() → {$label}";
                    }
                }
            }

            foreach ($panel->getPages() as $page) {
                foreach (['getNavigationLabel', 'getNavigationGroup', 'getTitle'] as $method) {
                    if (! method_exists($page, $method)) {
                        continue;
                    }

                    // getTitle() is an instance method on Filament pages; the rest are static.
                    $label = rescue(function () use ($page, $method) {
                        $reflection = new ReflectionMethod($page, $method);

                        return $reflection->isStatic()
                            ? $page::{$method}()
                            : $reflection->invoke(app($page));
                    }, null, false);

                    if (looksLikeTranslationKey($label)) {
                        $offenders[] = "[{$locale}] ".class_basename($page)."::{$method}() → {$label}";
                    }
                }
            }
        }
    } finally {
        app()->setLocale($original);
    }

    expect($offenders)->toBe([], "These render an unresolved translation key in the navigation or page header:\n  ".implode("\n  ", $offenders));
})->group('conformance');

it('E: no language file declares the same key twice', function () {
    // A duplicate key in a PHP array literal is not an error — the LATER one silently wins and
    // the earlier translation is dead text. Nothing else in this file can see it: both
    // catalogues stay perfectly in parity (test B), every key resolves (test A), and the labels
    // render (test D). The array simply does not contain what the file appears to say.
    //
    // Found the hard way: `admin.enums.category` was already the UNIT category, and a second
    // block added under the same name vanished on load — the symptom was a category dropdown
    // that stayed stubbornly English no matter what was added to the file. Six more pairs were
    // already there, six dead translations per locale.
    //
    // Parsed rather than grepped: sibling keys are only duplicates at the SAME nesting level,
    // which a regex cannot tell you.
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $duplicates = [];

    $walk = function (Node\Expr\Array_ $array, string $path, string $file) use (&$walk, &$duplicates): void {
        $seen = [];

        foreach ($array->items as $item) {
            if (! $item?->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = $item->key->value;
            $full = $path === '' ? $key : "{$path}.{$key}";

            if (isset($seen[$key])) {
                $duplicates[] = "{$file}: '{$full}' declared at line {$seen[$key]} and again at {$item->key->getLine()} — the first is dead";
            }

            $seen[$key] = $item->key->getLine();

            if ($item->value instanceof Node\Expr\Array_) {
                $walk($item->value, $full, $file);
            }
        }
    };

    // Sub-directories too. When `admin.php` was split into `admin/*.php` (2026-08-15) this glob
    // stopped reaching 4,711 keys per locale — the largest catalogue in the project, and the one
    // the seven original duplicates were found in.
    $files = array_merge(
        glob(lang_path('en/*.php')), glob(lang_path('en/*/*.php')),
        glob(lang_path('ar/*.php')), glob(lang_path('ar/*/*.php')),
    );

    $walked = 0;
    $unwalkable = [];

    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file);
        $statements = $parser->parse(file_get_contents($file));
        $return = end($statements);

        if ($return instanceof Node\Stmt\Return_ && $return->expr instanceof Node\Expr\Array_) {
            $walk($return->expr, '', $relative);
            $walked++;

            continue;
        }

        // An AGGREGATOR (`return $merged;`) is legitimate, but it is opaque to this AST walk — its
        // keys must be reachable as partials, or a whole catalogue leaves the sweep silently.
        $unwalkable[] = $relative;
    }

    expect($duplicates)->toBe([], "Duplicate translation keys — the earlier value never loads:\n  ".implode("\n  ", $duplicates));

    // GUARD THE GUARD, and count what was actually WALKED rather than what was listed. The previous
    // floor counted FILES: after the split it was satisfied by the sixteen non-admin files while
    // this check covered zero admin keys and still passed — a dead gate that looked alive, which is
    // the exact failure this whole file exists to prevent.
    expect($walked)->toBeGreaterThan(40, "Only {$walked} language files were actually walked — a catalogue has dropped out of this sweep.");

    // Every aggregator must be accounted for: its partials have to be globbed above, or its keys
    // are unchecked. Naming them keeps the omission visible instead of silent.
    $aggregatorsWithoutPartials = array_values(array_filter(
        $unwalkable,
        fn (string $f): bool => glob(base_path(dirname($f).'/'.basename($f, '.php').'/*.php')) === [],
    ));

    expect($aggregatorsWithoutPartials)->toBe([], implode('', [
        'These language files return something other than an array literal and have no partial ',
        'directory, so their keys are in NO sweep: '.implode(', ', $aggregatorsWithoutPartials),
    ]));
})->group('conformance');

it('F: no Filament component renders an auto-generated English label', function () {
    // Components that display a label derived from their attribute name when none is set.
    $labelled = [
        'TextColumn', 'IconColumn', 'ImageColumn', 'SelectColumn', 'ToggleColumn', 'CheckboxColumn', 'ColorColumn',
        'TextInput', 'Select', 'Toggle', 'DatePicker', 'DateTimePicker', 'TimePicker', 'Textarea', 'Checkbox',
        'Radio', 'Repeater', 'FileUpload', 'SpatieMediaLibraryFileUpload', 'KeyValue', 'ColorPicker',
        'RichEditor', 'MarkdownEditor', 'TagsInput', 'Placeholder',
        'TextEntry', 'IconEntry', 'ImageEntry', 'ColorEntry', 'KeyValueEntry', 'RepeatableEntry',
        'Group', 'SelectFilter', 'TernaryFilter', 'Filter', 'QueryBuilder',
    ];

    // These take their HEADING as the first argument, so `Section::make(__('x'))` is labelled.
    // Only a bare string literal there is untranslated.
    $headingFirst = ['Section', 'Fieldset', 'Tab', 'Step', 'Wizard'];

    // Filament ships translated labels for its own actions; a bare ::make() is correct.
    $builtIn = [
        'TrashedFilter', 'CreateAction', 'EditAction', 'ViewAction', 'DeleteAction', 'ForceDeleteAction',
        'RestoreAction', 'DeleteBulkAction', 'ForceDeleteBulkAction', 'RestoreBulkAction', 'ExportAction',
        'ExportBulkAction', 'ImportAction', 'ReplicateAction', 'DetachAction', 'AttachAction',
        'AssociateAction', 'DissociateAction',
    ];

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $offenders = [];
    $unparseable = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(base_path().'/', '', $file->getPathname());

        // A file mid-edit elsewhere in the tree must not surface as a php-parser stack trace with
        // no filename in it. Name the file and move on: a syntax error already fails everything
        // else, so this gate's job is to be legible about it, not to be the one that reports it.
        try {
            $ast = $parser->parse(file_get_contents($file->getPathname()));
        } catch (Throwable $e) {
            $unparseable[] = "{$path} — {$e->getMessage()}";

            continue;
        }

        if ($ast === null) {
            $unparseable[] = $path;

            continue;
        }

        // Every node that is the receiver of a method call — anything NOT in this set and still
        // a MethodCall is the outermost link of a fluent chain, which is where we start walking.
        $receivers = new SplObjectStorage;
        $collect = new class($receivers) extends NodeVisitorAbstract
        {
            public function __construct(public SplObjectStorage $set) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->set->attach($node->var);
                }
            }
        };
        $traverser = new NodeTraverser;
        $traverser->addVisitor($collect);
        $traverser->traverse($ast);

        $scan = new class($receivers, $path, $offenders, $labelled, $headingFirst, $builtIn) extends NodeVisitorAbstract
        {
            public function __construct(
                public SplObjectStorage $set,
                public string $path,
                public array &$offenders,
                public array $labelled,
                public array $headingFirst,
                public array $builtIn,
            ) {}

            public function enterNode(Node $node)
            {
                if (! $node instanceof Node\Expr\MethodCall || $this->set->contains($node)) {
                    return null;
                }

                $methods = [];
                $cursor = $node;

                while ($cursor instanceof Node\Expr\MethodCall) {
                    $methods[] = $cursor->name->name ?? '';
                    $cursor = $cursor->var;
                }

                if (! $cursor instanceof Node\Expr\StaticCall || ($cursor->name->name ?? '') !== 'make') {
                    return null;
                }

                $class = $cursor->class->getLast() ?? '';

                if (in_array($class, $this->builtIn, true)) {
                    return null;
                }

                $argument = $cursor->args[0]->value ?? null;
                $isLiteral = $argument instanceof Node\Scalar\String_;

                if (in_array($class, $this->headingFirst, true)) {
                    if ($isLiteral) {
                        $this->offenders[] = "{$this->path}:{$cursor->getLine()} — {$class}::make('{$argument->value}') hardcodes its heading";
                    }

                    return null;
                }

                if (! in_array($class, $this->labelled, true)) {
                    return null;
                }

                if (array_intersect(['label', 'hiddenLabel', 'translateLabel'], $methods)) {
                    return null;
                }

                $name = $isLiteral ? $argument->value : '(dynamic)';
                $this->offenders[] = "{$this->path}:{$cursor->getLine()} — {$class}::make('{$name}') has no ->label(), so Filament humanises the attribute name into English";

                return null;
            }
        };
        $traverser2 = new NodeTraverser;
        $traverser2->addVisitor($scan);
        $traverser2->traverse($ast);
        $offenders = $scan->offenders;
    }

    expect($unparseable)->toBe([], "These files could not be parsed, so this gate could not inspect them:\n  ".implode("\n  ", $unparseable));
    expect($offenders)->toBe([], "Untranslated by omission — add ->label(__('…')), or ->hiddenLabel() where the label is deliberately not shown:\n  ".implode("\n  ", $offenders));
})->group('conformance');

it('G: no language key contains a dot, which would make it unreachable', function () {
    // `__()` splits its key on dots, so a key written as `'approvals.tier_1' => '…'` is looked up
    // as ['approvals']['tier_1'] and never found — Laravel then returns the key, and the operator
    // reads `admin.enums.approval_tier.approvals.tier_1` on screen.
    //
    // This shipped exactly once, and it is invisible to every other test here: the key EXISTS in
    // the file (so a human reading it sees a translation), the catalogues stay in parity, and the
    // static scan only ever verified the PREFIX of an interpolated key — `admin.enums.approval_tier`
    // resolves fine; it is the composed leaf that does not.
    //
    // The values are permission names (`approvals.tier_1`) and a stored value must not be reshaped
    // to suit a lang file, so the fix is to NEST the array to match how the key is traversed.
    $dotted = [];

    $walk = function (array $items, string $prefix, string $file) use (&$walk, &$dotted): void {
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_string($key) && str_contains($key, '.')) {
                $dotted[] = "{$file}: '{$path}' — the dot inside this key makes it unreachable; nest it instead";
            }

            if (is_array($value)) {
                $walk($value, $path, $file);
            }
        }
    };

    $files = array_merge(glob(lang_path('en/*.php')), glob(lang_path('ar/*.php')));

    foreach ($files as $file) {
        $walk(require $file, '', str_replace(base_path().'/', '', $file));
    }

    expect($dotted)->toBe([], implode("\n  ", array_merge([''], $dotted)));
    expect(count($files))->toBeGreaterThan(10);
})->group('conformance');

/**
 * Every translation-key namespace we own. A key from any of these reaching the screen is a bug.
 */
const KEY_NAMESPACES = 'admin|validation|auth|errors|guides|api|pay|mail';

/**
 * Any of our translation keys appearing in a page's VISIBLE TEXT.
 *
 * Text only, deliberately: Livewire registers its components under dotted names
 * (`admin.resources.leases.pages.list`) that live in attributes and script payloads, and matching
 * raw HTML produced ~44 false positives per run.
 *
 * @return array<int, string>
 */
function rawKeysVisibleIn(string $html): array
{
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

    preg_match_all('/\b('.KEY_NAMESPACES.')\.[a-z0-9_]+(?:\.[a-z0-9_]+)+/', $text, $matches);

    return array_values(array_unique($matches[0]));
}

it('H: no ADMIN screen renders a raw translation key, in either locale', function () {
    // The gate for keys STATIC analysis cannot see. A label built as `__("…{$record->status}")`
    // composes its key from a database value, so the only way to know it resolves is to render the
    // page against real rows and look.
    //
    // **Coverage is bounded by the fixture, and that is not a footnote.** A table with no rows
    // renders no cells, so nothing is checked — the approval-tier bug that prompted this test lives
    // on a list `DemoSeeder` does not populate, which is why ApprovalRulesSeeder runs below, and
    // why the first version of this test passed with the bug still present. Adding an enum-bearing
    // resource means seeding a row for it, or this silently covers less than it appears to.
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);

    $user = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
    $this->actingAs($user);

    $asset = Asset::where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();

    $found = [];
    $rendered = 0;
    $original = app()->getLocale();

    try {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            asTenant($asset, function () use ($asset, $locale, &$found, &$rendered) {
                $urls = [];

                foreach (Filament::getPanel('admin')->getResources() as $resource) {
                    $label = class_basename($resource);

                    if ($resource::getPages()['index'] ?? null) {
                        $urls["{$label} list"] = $resource::getUrl('index', [], tenant: $asset);
                    }

                    // EDIT pages too. A form renders labels, options, helper text and placeholders
                    // that never appear on a list, and it is where most enum SELECTS live.
                    if ($resource::getPages()['edit'] ?? null) {
                        $record = rescue(fn () => $resource::getEloquentQuery()->first(), null, false);

                        if ($record) {
                            $url = rescue(fn () => $resource::getUrl('edit', ['record' => $record], tenant: $asset), null, false);

                            if ($url) {
                                $urls["{$label} edit"] = $url;
                            }
                        }
                    }
                }

                // PAGES too. The report hub is a Page, and it is where the first raw label was
                // spotted — a sweep of resource lists alone would have missed the screen that
                // started this.
                foreach (Filament::getPanel('admin')->getPages() as $page) {
                    $url = rescue(fn () => $page::getUrl(tenant: $asset), null, false);

                    if ($url !== null) {
                        $urls[class_basename($page)] = $url;
                    }
                }

                foreach ($urls as $label => $url) {
                    $response = rescue(fn () => $this->get($url), null, false);

                    if ($response === null || $response->getStatusCode() !== 200) {
                        continue;
                    }

                    $rendered++;

                    foreach (rawKeysVisibleIn($response->getContent()) as $key) {
                        $found[] = "[{$locale}] {$label} renders the raw key {$key}";
                    }
                }
            });
        }
    } finally {
        app()->setLocale($original);
    }

    expect(array_unique($found))->toBe([], "A translation key reached the screen instead of a translation:\n  ".implode("\n  ", array_unique($found)));

    // Vacuity guard: if the panel stopped enumerating, this would pass having rendered nothing.
    expect($rendered)->toBeGreaterThan(150);
})->group('conformance');

it('I: no TENANT PORTAL screen renders a raw translation key, in either locale', function () {
    // The portal is the surface a TENANT sees, and the one most likely to be read in Arabic. It
    // shares `lang/*/admin.php` with the panel but renders its own resources, so a key that only
    // the portal composes is checked nowhere else.
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(DemoSeeder::class);

    $tenantUser = TenantUser::query()->where('is_admin', true)->firstOrFail();
    $this->actingAs($tenantUser, 'portal');

    $found = [];
    $rendered = 0;
    $original = app()->getLocale();
    $originalPanel = Filament::getCurrentPanel();

    try {
        Filament::setCurrentPanel(Filament::getPanel('portal'));

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $urls = [];

            foreach (Filament::getPanel('portal')->getResources() as $resource) {
                if ($resource::getPages()['index'] ?? null) {
                    $urls[class_basename($resource)] = $resource::getUrl('index');
                }
            }

            foreach (Filament::getPanel('portal')->getPages() as $page) {
                $url = rescue(fn () => $page::getUrl(), null, false);

                if ($url !== null) {
                    $urls[class_basename($page)] = $url;
                }
            }

            foreach ($urls as $label => $url) {
                $response = rescue(fn () => $this->get($url), null, false);

                if ($response === null || $response->getStatusCode() !== 200) {
                    continue;
                }

                $rendered++;

                foreach (rawKeysVisibleIn($response->getContent()) as $key) {
                    $found[] = "[{$locale}] portal {$label} renders the raw key {$key}";
                }
            }
        }
    } finally {
        app()->setLocale($original);
        Filament::setCurrentPanel($originalPanel);
    }

    expect(array_unique($found))->toBe([], "A translation key reached the tenant's screen:\n  ".implode("\n  ", array_unique($found)));
    expect($rendered)->toBeGreaterThan(10);
})->group('conformance');
