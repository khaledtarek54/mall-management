<?php

namespace Tests\Support;

use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Concerns\ScopesViaProperty;
use App\Support\PropertyIsolation;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use ReflectionClass;

/**
 * WHICH SCREENS CAN BE LOOKING AT A PROPERTY OTHER THAN THE SELECTED ONE — and what they are
 * therefore not allowed to leave to `Filament::getTenant()`.
 *
 * Every admin route carries a `{tenant}` segment, and `Resource::getUrl()` fills it from the
 * SWITCHER. On almost every screen that is right, because the record you are looking at belongs to
 * the selected property by construction. The exception is a screen whose OWNER RECORD is not
 * property-scoped — `AssetResource` above all, which lists the whole portfolio on purpose — where
 * the record on the row and the mall in the switcher are routinely two different malls.
 *
 * Both halves are DERIVED, so neither is a list anybody has to maintain.
 *
 * The TARGET side is one question: a model that IS `#[PropertyOwned]` resolves its route-bound
 * record through a property-scoped query, so a link naming the wrong mall is a 404 rather than a
 * 403.
 *
 * The OWNER side is a UNION of two, and getting it wrong is how the first version of this gate
 * skipped two of the six resources it most needed to read. A screen's rows are guaranteed to be the
 * selected mall's only when the resource is BOTH property-owned AND narrows itself to the selected
 * property (`ScopesToProperty` / `ScopesViaProperty`). Miss either half and you have a hole:
 *
 *  - asking only *"is the model `#[PropertyOwned]`"* skips `DepartmentResource` and
 *    `OwnerRequestResource`, which carry `#[PropertyOwned(portfolioRowsWhenNull: true)]` models and
 *    still list the operator's whole ASSIGNED SET, because they declare `$isScopedToTenant = false`
 *    and scope themselves that way on purpose;
 *  - asking only *"does it use a scoping trait"* skips `TenantResource`, which uses one — and whose
 *    violations and sales-declaration TABS are scoped by nothing at all.
 *
 * **THAT SECOND ONE IS THE LIMIT WORTH STATING RATHER THAN IMPLYING AWAY:** a scoped OWNER does not
 * make its TABS scoped. `TenantResource` is caught here only because a `Tenant` is
 * `#[PortfolioShared]`. A future property-owned, trait-scoped resource with a tab reaching across
 * malls would not be swept, and the honest answer to that is this paragraph rather than a claim of
 * completeness.
 *
 * **Also not swept: admin Pages and Widgets.** Six of them already pass `tenant:` explicitly, which
 * is direct evidence the same defect class lives there — and nothing keeps them that way. Bringing
 * them in means auditing every report page's scoping first, which is its own piece of work.
 */
class PropertyLinks
{
    /**
     * Files that belong to a screen whose owner record may be another property, keyed by the
     * resource that owns them: the resource's own directory plus every relation manager it
     * registers (which usually live in the shared `RelationManagers` namespace, outside it).
     *
     * @return array<class-string, list<string>>
     */
    public static function filesByPortfolioWideOwner(): array
    {
        $out = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $model = $resource::getModel();

            // Skip ONLY a resource whose rows are guaranteed to be the selected mall's — property
            // owned AND narrowing itself to the selected property. See the class docblock for why
            // either test alone leaves a hole.
            if ($model === '' || (PropertyIsolation::isOwned($model) && static::narrowsToSelectedProperty($resource))) {
                continue;
            }

            $files = [];

            $dir = dirname((string) (new ReflectionClass($resource))->getFileName());
            foreach (static::phpFilesUnder($dir) as $file) {
                $files[] = $file;
            }

            foreach (static::relationManagersOf($resource) as $manager) {
                $files[] = (string) (new ReflectionClass($manager))->getFileName();
            }

            $out[$resource] = array_values(array_unique($files));
        }

        return $out;
    }

    /**
     * Does this resource narrow its own list to the SELECTED property?
     *
     * Read off the two traits that are the one place that narrowing is written
     * (`ScopesToProperty::scopeToProperty()` keys on `TenantScope::currentAssetId()`), rather than
     * off `isScopedToTenant()` — which `BypassesFilamentTenantAutoScope` makes false for the
     * property-scoped resources too, so it cannot tell them from the portfolio-wide ones.
     */
    public static function narrowsToSelectedProperty(string $resource): bool
    {
        $uses = class_uses_recursive($resource);

        return isset($uses[ScopesToProperty::class]) || isset($uses[ScopesViaProperty::class]);
    }

    /**
     * @return list<class-string>
     */
    public static function relationManagersOf(string $resource): array
    {
        $managers = [];

        foreach ($resource::getRelations() as $relation) {
            // A group holds several managers; a bare string is one.
            foreach ($relation instanceof RelationGroup ? $relation->getManagers() : [$relation] as $manager) {
                if (is_string($manager) && class_exists($manager)) {
                    $managers[] = $manager;
                }
            }
        }

        return $managers;
    }

    /**
     * Every `SomeResource::getUrl(…)` in a file, with whether it named a `tenant:`.
     *
     * TOKENISED, NEVER GREPPED, and comments are dropped FIRST — the fix for this very defect
     * carries a docblock that says the words `getUrl()` and `tenant`, and a raw match would read
     * the sentence explaining the rule as a call site obeying it. That prose false-positive has
     * been shipped by three gates in this repo already.
     *
     * @return list<array{target: class-string, names_tenant: bool, line: int}>
     */
    public static function resourceLinksIn(string $file): array
    {
        $code = (string) file_get_contents($file);
        $imports = static::importsIn($code);

        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        $out = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! str_ends_with($token[1], 'Resource')) {
                continue;
            }
            if (($tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON
                || ($tokens[$i + 2][1] ?? null) !== 'getUrl'
                || ($tokens[$i + 3] ?? null) !== '(') {
                continue;
            }

            $target = $imports[$token[1]] ?? null;

            if ($target === null || ! class_exists($target)) {
                continue;
            }

            // Walk the ARGUMENT LIST to its own closing paren, counting over TOKENS. Counting
            // brackets over raw characters fails open on a `(` inside a string literal and runs to
            // the end of the file, picking up whatever it finds there.
            $depth = 0;
            $namesTenant = false;
            $arguments = 1;
            for ($j = $i + 3; $j < count($tokens); $j++) {
                $t = $tokens[$j];

                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && $t === ',') {
                    $arguments++;
                } elseif ($depth === 1
                    && is_array($t)
                    && $t[0] === T_STRING
                    && $t[1] === 'tenant'
                    && ($tokens[$j + 1] ?? null) === ':') {
                    // A named argument at the CALL's own depth — not a `tenant` mentioned inside a
                    // nested closure or array.
                    $namesTenant = true;
                }
            }

            // `getUrl($name, $parameters, $isAbsolute, $panel, $tenant, …)` — a fifth POSITIONAL
            // argument is the tenant just as surely as a named one, and reporting that correct call
            // as an offender is how a gate gets weakened rather than fixed. Nobody writes it that
            // way here today; this costs one comparison to be right about it.
            $namesTenant = $namesTenant || $arguments >= 5;

            $out[] = [
                'target' => $target,
                'names_tenant' => $namesTenant,
                'line' => $token[2],
            ];
        }

        return $out;
    }

    /**
     * Short class name => FQCN, from the file's own `use` statements.
     *
     * @return array<string, class-string>
     */
    protected static function importsIn(string $code): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $code, $matches, PREG_SET_ORDER);

        $imports = [];

        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? '';
            $imports[$alias !== '' ? $alias : substr((string) strrchr('\\'.$fqcn, '\\'), 1)] = $fqcn;
        }

        return $imports;
    }

    /**
     * @return list<string>
     */
    protected static function phpFilesUnder(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
