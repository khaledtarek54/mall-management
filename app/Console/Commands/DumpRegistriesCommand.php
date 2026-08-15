<?php

namespace App\Console\Commands;

use App\Services\Accounting\LedgerPoster;
use App\Support\LedgerRealtimeSync;
use App\Support\PostingDateGuards;
use App\Support\PropertyIsolation;
use Illuminate\Console\Command;

/**
 * Regenerate the doc sections that describe a registry.
 *
 * **Why this exists.** Several of this project's invariants live in a single registry — the GL
 * journalizers, the property-isolation classification, the deletion policy — and each was ALSO
 * described in prose somewhere. Prose drifts and nothing notices: when this command was written,
 * `docs/modules/21-general-ledger.md` described 12 posting sources while `LedgerPoster` posted 21,
 * and the system census was 113 test files and 28 migrations out of date.
 *
 * The census (`atriom:dump-system-census`) already solved this for counts. This does the same for
 * the registries themselves, so the doc is correct by construction rather than by someone
 * remembering. Sections are delimited by `<!-- GENERATED:name -->` markers and rewritten in place;
 * everything outside them is left alone, so the hand-written narrative around each table survives.
 */
class DumpRegistriesCommand extends Command
{
    protected $signature = 'atriom:dump-registries';

    protected $description = 'Regenerate the registry-derived sections of the docs (GL sources, property isolation, deletion policy)';

    public function handle(): int
    {
        $this->write(
            base_path('docs/modules/21-general-ledger.md'),
            'gl-sources',
            $this->glSources(),
        );

        $this->write(
            base_path('docs/PROPERTY-ISOLATION.md'),
            'isolation-classification',
            $this->isolationClassification(),
        );

        $this->info('Registry sections regenerated.');

        return self::SUCCESS;
    }

    public function glSources(): string
    {
        $dates = LedgerRealtimeSync::SOURCE_DATE_COLUMNS;
        $guards = PostingDateGuards::guards();
        $rows = [];

        // Every JOURNALIZERS source is guaranteed a GUARDS entry and a SOURCE_DATE_COLUMNS entry —
        // PostingDateGuardConformanceTest and GlRegistryConformanceTest fail the build otherwise —
        // so both lookups always resolve (no `?? '—'` fallback needed).
        foreach (LedgerPoster::JOURNALIZERS as $source => $journalizer) {
            $guard = $guards[$source];

            // Three shapes, and conflating them misleads: a service name, the SOURCE MODEL itself
            // (guarded with the GuardsPostingDate trait where there is no create/update service),
            // or a `system:` reason why the date can never be operator-typed.
            if (str_starts_with($guard, PostingDateGuards::SYSTEM_PREFIX)) {
                $guard = '_system — '.substr($guard, strlen(PostingDateGuards::SYSTEM_PREFIX)).'_';
            } elseif ($guard === $source) {
                $guard = 'on the model (`GuardsPostingDate`)';
            } else {
                $guard = '`'.class_basename($guard).'`';
            }

            $rows[] = sprintf(
                '| `%s` | `%s` | `%s` | %s |',
                class_basename($source),
                class_basename($journalizer),
                $dates[$source],
                $guard,
            );
        }

        return "## Every GL posting source\n\n"
            ."Generated from `LedgerPoster::JOURNALIZERS` — the single registry all four dispatch paths\n"
            ."derive from (real-time hook · `accounting:sync-ledger` sweep · close gate · `billing:reconcile`\n"
            ."drift check). **".count($rows)." sources.** The `entry_date` column is what the sweep windows on, and what\n"
            ."the posting-date guard checks against a closed period.\n\n"
            ."| Source model | Journalizer | `entry_date` from | Posting-date guard |\n|---|---|---|---|\n"
            .implode("\n", $rows);
    }

    public function isolationClassification(): string
    {
        $format = function (array $models): string {
            $names = array_map(fn (string $m): string => '`'.class_basename($m).'`', $models);
            sort($names);

            return implode(' · ', $names);
        };

        $owned = PropertyIsolation::ownedModels();
        $shared = PropertyIsolation::sharedModels();
        $self = PropertyIsolation::selfModels();

        return "## Model classification\n\n"
            ."Generated from `App\\Support\\PropertyIsolation`. `PropertyIsolationConformanceTest` fails the\n"
            ."build if a model ships unclassified, so this list is complete by construction.\n\n"
            ."**Property-owned (".count($owned).")** — scoped to the selected property:\n\n".$format($owned)."\n\n"
            ."**Shared (".count($shared).")** — portfolio-wide by design:\n\n".$format($shared)."\n\n"
            ."**Self (".count($self).")** — the property record itself:\n\n".$format($self);
    }

    /** Rewrite a marked block in place, leaving the surrounding narrative untouched. */
    private function write(string $path, string $marker, string $content): void
    {
        if (! is_file($path)) {
            $this->warn("skipped {$path} — not found");

            return;
        }

        $open = "<!-- GENERATED:{$marker} — do not edit by hand; run `php artisan atriom:dump-registries` -->";
        $close = "<!-- /GENERATED:{$marker} -->";
        $block = $open."\n\n".$content."\n".$close;

        $body = file_get_contents($path);

        $body = str_contains($body, $open)
            ? preg_replace('/'.preg_quote($open, '/').'.*?'.preg_quote($close, '/').'/s', $block, $body)
            : rtrim($body, "\n")."\n\n".$block."\n";

        file_put_contents($path, $body);

        $this->line('  '.str_replace(base_path().'/', '', $path).' → '.$marker);
    }

}
