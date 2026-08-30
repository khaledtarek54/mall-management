<?php

namespace App\Console\Commands;

use Filament\Facades\Filament;
use Illuminate\Console\Command;

/**
 * Regenerates tests/e2e/filament-admin-manifest.json — the authoritative list of
 * every admin Filament resource + custom page that the Playwright system-smoke
 * spec walks. Run this after adding/removing a resource or page.
 *
 * The e2e smoke iterates the manifest; AdminSmokeManifestConformanceTest asserts
 * the committed file still matches the live panel, so coverage can never silently
 * drift out of sync with what's actually registered.
 */
class DumpAdminManifest extends Command
{
    protected $signature = 'atriom:dump-admin-manifest {--check : Exit non-zero if the committed manifest is stale instead of rewriting it}';

    protected $description = 'Regenerate the admin Filament resource/page manifest used by the Playwright system-smoke spec';

    public const PATH = 'tests/e2e/filament-admin-manifest.json';

    public function handle(): int
    {
        $live = static::manifest();
        $json = json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $path = base_path(self::PATH);

        if ($this->option('check')) {
            $current = is_file($path) ? file_get_contents($path) : '';
            if (trim($current) !== trim($json)) {
                $this->error(self::PATH.' is stale — run `php artisan atriom:dump-admin-manifest`.');

                return self::FAILURE;
            }
            $this->info(self::PATH.' is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $this->info('Wrote '.count($live['resources']).' resources + '.count($live['pages']).' pages to '.self::PATH);

        return self::SUCCESS;
    }

    /**
     * Introspect the admin panel into the manifest shape. Shared with the
     * conformance test so both compute coverage identically.
     */
    public static function manifest(): array
    {
        $panel = Filament::getPanel('admin');

        $resources = [];
        foreach ($panel->getResources() as $resourceClass) {
            $pages = array_keys($resourceClass::getPages());
            $resources[] = [
                'class' => class_basename($resourceClass),
                'slug' => $resourceClass::getSlug(),
                'hasCreate' => in_array('create', $pages, true),
                'hasEdit' => in_array('edit', $pages, true),
                'hasView' => in_array('view', $pages, true),
                'recordActions' => static::recordPageActions($resourceClass),
            ];
        }
        usort($resources, fn ($a, $b) => strcmp($a['slug'], $b['slug']));

        $pages = [];
        foreach ($panel->getPages() as $pageClass) {
            $pages[] = [
                'class' => class_basename($pageClass),
                'slug' => method_exists($pageClass, 'getSlug') ? $pageClass::getSlug() : null,
            ];
        }
        usort($pages, fn ($a, $b) => strcmp((string) $a['slug'], (string) $b['slug']));

        return ['resources' => $resources, 'pages' => $pages];
    }

    /**
     * The acts that live on this resource's RECORD page, and whether each opens a modal.
     *
     * On 2026-08-30 sixteen resources moved their write verbs off the list row and onto the
     * record — the list finds, the record acts. That created a surface the browser suite could not
     * see: `22-actions-sweep` walks LIST rows, and a Filament action builds its schema on MOUNT,
     * so a record page renders perfectly and fatals the moment somebody clicks. Emitting the acts
     * here lets the sweep open each one, without anybody keeping a list of names in JavaScript.
     *
     * `opensModal` is the safety flag and the reason this is derived rather than hand-written: an
     * action with a form or a confirmation shows a dialog that Escape closes, while one with
     * neither RUNS on click. The sweep may only click the first kind — the demo database it runs
     * against is the same one the reconciliation commands read.
     *
     * @return list<array{name: string, opensModal: bool}>
     */
    protected static function recordPageActions(string $resourceClass): array
    {
        $actionsClass = 'App\\Filament\\Admin\\Actions\\'.str_replace('Resource', 'Actions', class_basename($resourceClass));

        if (! class_exists($actionsClass) || ! method_exists($actionsClass, 'all')) {
            return [];
        }

        $acts = [];

        foreach ($actionsClass::all() as $action) {
            $acts[] = [
                'name' => $action->getName(),
                // The visible label, so the browser can find the button. Dumped in the app's own
                // locale (English in CI); the sweep matches on it rather than on a wire: attribute,
                // which is Filament's internal shape and changes between releases.
                'label' => (string) $action->getLabel(),
                // A form or a confirmation means a dialog opens and nothing has happened yet.
                // `hasModal()` is Filament's own predicate and answers null when it has not been
                // forced either way, in which case a confirmation is the remaining reason one shows.
                'opensModal' => ($action->hasModal() ?? false) || $action->isConfirmationRequired(),
            ];
        }

        return $acts;
    }
}
