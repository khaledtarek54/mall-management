<?php

namespace App\Support;

use App\Support\Attributes\PortfolioShared;
use App\Support\Attributes\PropertyItself;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * The authoritative register of which models are SHARED across every property and which are
 * ISOLATED to one property — the code-level source of truth for total property isolation.
 * See docs/PROPERTY-ISOLATION.md.
 *
 * `PropertyIsolationConformanceTest` reflects over this register so that a new model or resource
 * that ships unclassified (or a property-owned resource that ships unscoped) FAILS the build
 * instead of leaking silently in production.
 *
 * **Each model now declares its own classification** ({@see PortfolioShared},
 * {@see PropertyOwned}, {@see PropertyItself}) and this file derives the three registers rather
 * than storing them. The reasoning that used to sit as a comment beside each entry moved with it,
 * verbatim, onto the model — the answer to "why is Vendor shared?" now sits on `Vendor`.
 *
 * **That does not weaken the gate.** It never trusted this file for completeness: it globs
 * `app/Models` and asks whether each model is classified, so a model declaring nothing is still
 * unclassified and still fails the build. A register derived from the same source it is checked
 * against could not catch an omission; this one is checked against the filesystem.
 *
 * The property-owned register answers `via` — null for a direct `asset_id` column, or the relation
 * chain ending at a model that has one (`Invoice → lease → unit` is `'lease.unit'`).
 */
class PropertyIsolation
{
    /**
     * The derived registers, built once per process.
     *
     * Only the ENUMERATING methods pay for the scan; the per-model questions reflect on the single
     * class they were handed. `isOwned()` / `linkageFor()` are on the read path of every scoped
     * resource query, so they must not glob the models directory.
     *
     * @var array{shared: array<int, class-string>, owned: array<class-string, string|null>, self: array<int, class-string>}|null
     */
    private static ?array $registers = null;

    /**
     * @return array{shared: array<int, class-string>, owned: array<class-string, string|null>, self: array<int, class-string>}
     */
    private static function registers(): array
    {
        if (self::$registers !== null) {
            return self::$registers;
        }

        $registers = ['shared' => [], 'owned' => [], 'self' => []];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $model = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($model);

            if ($reflection->isAbstract()) {
                continue;
            }

            // Not inherited: a child model must state its own classification rather than quietly
            // adopting a parent's, because "scoped like my parent" is exactly the assumption that
            // leaks a property.
            if ($reflection->getAttributes(PortfolioShared::class) !== []) {
                $registers['shared'][] = $model;
            }

            foreach ($reflection->getAttributes(PropertyOwned::class) as $attribute) {
                $registers['owned'][$model] = $attribute->newInstance()->via;
            }

            if ($reflection->getAttributes(PropertyItself::class) !== []) {
                $registers['self'][] = $model;
            }
        }

        return self::$registers = $registers;
    }

    /**
     * The per-model questions. Each reflects on the one class it was handed.
     *
     * @param  class-string  $model
     */
    private static function declared(string $model, string $attribute): ?object
    {
        if (! class_exists($model)) {
            return null;
        }

        $found = (new ReflectionClass($model))->getAttributes($attribute);

        return $found === [] ? null : $found[0]->newInstance();
    }

    public static function isShared(string $model): bool
    {
        return self::declared($model, PortfolioShared::class) !== null;
    }

    public static function isOwned(string $model): bool
    {
        return self::declared($model, PropertyOwned::class) !== null;
    }

    /** True when the owned model carries a direct `asset_id` column. */
    public static function isDirect(string $model): bool
    {
        $declared = self::declared($model, PropertyOwned::class);

        return $declared instanceof PropertyOwned && $declared->via === null;
    }

    /** The relation chain for an indirect owned model, or null for direct / unknown. */
    public static function linkageFor(string $model): ?string
    {
        $declared = self::declared($model, PropertyOwned::class);

        return $declared instanceof PropertyOwned ? $declared->via : null;
    }

    /** Every model is expected to be classified into exactly one bucket. */
    public static function isClassified(string $model): bool
    {
        return self::isShared($model)
            || self::isOwned($model)
            || self::declared($model, PropertyItself::class) !== null;
    }

    /** @return array<int, class-string> */
    public static function ownedModels(): array
    {
        return array_keys(self::registers()['owned']);
    }

    /** @return array<int, class-string> */
    public static function sharedModels(): array
    {
        return self::registers()['shared'];
    }

    /**
     * The property-owned register as a map of model => linkage (null = direct `asset_id`).
     *
     * Prefer `linkageFor()` / `isDirect()` when asking about ONE model; this is for the callers
     * that legitimately enumerate the whole register (the census, the registry dump, the handbook).
     *
     * @return array<class-string, string|null>
     */
    public static function owned(): array
    {
        return self::registers()['owned'];
    }

    /**
     * The property itself — scoped by identity, in neither bucket.
     *
     * @return array<int, class-string>
     */
    public static function selfModels(): array
    {
        return self::registers()['self'];
    }
}
