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
}
