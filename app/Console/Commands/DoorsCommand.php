<?php

namespace App\Console\Commands;

use App\Support\WriteSurfaces;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What else writes this record? — `/safe-change` step 2, as a command.
 *
 * **Why this exists.** Both `CLAUDE.md` and the `/safe-change` skill already carry the rule:
 * *enumerate the doors onto the thing by grepping the thing, never from the diff you just wrote.*
 * It is the most-repeated defect in this codebase's own history — the deposit modal that never got
 * the bank field the six other doors got, the fifteenth document-number allocator found in the file
 * below the fourteenth, the credit-note half of a line-narrative change that was inert because no
 * writer could store a key. Every one of them was a change that reached one door.
 *
 * A sentence is not a gate. This is the sentence as something you can run.
 *
 * ## Three ways to ask
 *
 *   `atriom:doors Area`                       every door onto that record
 *   `atriom:doors app/Filament/.../Foo.php`   the siblings of one file
 *   `atriom:doors --check-diff`               what your CHANGE has not looked at yet
 *
 * The third is the guard proper, and it is deliberately the only one that can fail. A **parity**
 * rule — every door must ask what its siblings ask — was built first and measured at 435 findings,
 * because most sibling doors are *supposed* to differ: an operator's form and a tenant's form for
 * one model differ by twenty fields, every one of them a field a tenant must not set. The question
 * that survives contact with the code is not *do these agree* but *did you look*, and only a diff
 * can ask it.
 *
 * It exits non-zero when the change touched one door and left a sibling alone, so it can stand in a
 * pre-commit hook. That is an advisory failure by design: leaving a sibling alone is very often the
 * right answer, and the value is that the decision was made rather than missed.
 */
class DoorsCommand extends Command
{
    protected $signature = 'atriom:doors
        {subject? : a model name (Area), a class (App\\Models\\Area) or a path to one door}
        {--check-diff= : compare against this git ref instead of naming a subject (default HEAD)}
        {--all : list every door in the application, grouped by record}';

    protected $description = 'Every screen, importer and service that writes a record — and what your diff has not touched';

    public function handle(): int
    {
        $doors = WriteSurfaces::doors();

        if ($this->option('all')) {
            return $this->listEverything($doors);
        }

        if ($this->option('check-diff') !== null || $this->argument('subject') === null) {
            return $this->checkDiff($doors);
        }

        return $this->describeSubject((string) $this->argument('subject'), $doors);
    }

    /** Every door onto one record, or the siblings of one file. */
    private function describeSubject(string $subject, array $doors): int
    {
        if (str_contains($subject, '/') || str_ends_with($subject, '.php')) {
            $path = WriteSurfaces::relative($subject);
            $siblings = WriteSurfaces::siblingsOf($path, $doors);

            $this->components->info("Doors that share a record with {$path}");

            if ($siblings === []) {
                $this->line('  <fg=gray>none — this file is the only door onto what it writes,</>');
                $this->line('  <fg=gray>or it is not a door this registry can see (see WriteSurfaces).</>');

                return self::SUCCESS;
            }

            $this->render($siblings);

            return self::SUCCESS;
        }

        $model = class_exists($subject) ? $subject : 'App\\Models\\'.Str::studly($subject);

        if (! class_exists($model)) {
            $this->components->error("No such model: {$subject}");

            return self::FAILURE;
        }

        $onto = WriteSurfaces::forModel($model);

        $this->components->info(class_basename($model).' is written through '.count($onto).' door(s)');
        $this->render($onto);

        return self::SUCCESS;
    }

    /**
     * The co-edit check: for every door the diff touched, name the siblings it did not.
     *
     * The comparison is against the working tree PLUS what is staged, because the question is asked
     * while the change is being made — a check that only reads committed history answers it too
     * late to be useful.
     */
    private function checkDiff(array $doors): int
    {
        // `?:` treats the string "0" as absent, and `0` is a legal git ref (`HEAD@{0}` aside, a
        // branch may be named it). `??` plus an emptiness test says what is meant.
        $option = $this->option('check-diff');
        $ref = ($option === null || $option === '') ? 'HEAD' : (string) $option;

        try {
            $changed = $this->changedFiles($ref);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($changed === []) {
            $this->components->info("No changes against {$ref}.");

            return self::SUCCESS;
        }

        $touched = array_flip($changed);
        $unvisited = [];

        foreach ($changed as $path) {
            foreach (WriteSurfaces::siblingsOf($path, $doors) as $key => $door) {
                $siblingPath = WriteSurfaces::pathOf($key);

                if (isset($touched[$siblingPath])) {
                    continue;
                }

                // Keyed by the SIBLING, not by the changed file. Two changed doors onto one record
                // would otherwise report the same untouched third door twice, and a list that
                // repeats itself is one people stop reading.
                $unvisited[$key] = $door + ['because' => $path];
            }
        }

        $this->components->info(count($changed).' changed file(s) against '.$ref);

        if ($unvisited === []) {
            $this->components->info('Every door onto every record this change touches was touched too.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=yellow;options=bold>These doors write a record your change touches, and the change did not touch them:</>');

        // Grouped by RECORD rather than listed flat. A flat list repeats the reason on every row —
        // measured at twice the length for the same content on a 22-file change — and the reader's
        // question is "what else writes a Lease", which is a question about the record.
        $byModel = [];

        foreach ($unvisited as $key => $door) {
            $byModel[$door['model']][$key] = $door;
        }

        ksort($byModel);

        foreach ($byModel as $model => $group) {
            $this->newLine();
            $this->line(sprintf(
                '  <options=bold>%s</> <fg=gray>— you changed %s</>',
                class_basename($model),
                implode(', ', array_values(array_unique(array_column($group, 'because')))),
            ));

            $this->render($group, '    ');
        }

        $this->newLine();
        $this->line('  <fg=gray>Leaving one alone is often right — an operator form and a tenant form for one</>');
        $this->line('  <fg=gray>record are supposed to differ. Say WHICH you left and why, in the commit.</>');

        return self::FAILURE;
    }

    /**
     * Everything the working tree has changed against `$ref`, plus staged, plus untracked.
     *
     * **Run with `git -C` from the project root and `--full-name`.** `git ls-files --others` prints
     * paths relative to the CWD, so running this from `app/Support` silently matched none of the
     * untracked files — measured, 50 changed files instead of 54, with no error. Door keys are
     * root-relative, so anything else compares two different alphabets.
     *
     * **The ref is escaped.** A developer-supplied argument reaching a shell is one
     * `escapeshellarg()` away from running whatever it likes, and even benignly a ref with a space
     * in it breaks the command.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException when the ref does not resolve — see {@see checkDiff()}
     */
    private function changedFiles(string $ref): array
    {
        $root = escapeshellarg(base_path());
        $out = [];

        // **A bad ref must be an ERROR, not a quieter check.** `git diff` exits non-zero on an
        // unknown ref; the first version dropped that half and let the staged + untracked halves
        // answer, so `--check-diff=typo` reported "5 changed files … every door was touched" and
        // exited 0 where the real answer was 54 files and five records flagged. A guard that goes
        // green on a typo is worse than no guard, because the green is remembered.
        exec("git -C {$root} rev-parse --verify --quiet ".escapeshellarg($ref.'^{commit}').' 2>/dev/null', $probe, $status);

        if ($status !== 0) {
            throw new RuntimeException("Not a git ref: {$ref}");
        }

        foreach ([
            'diff --name-only '.escapeshellarg($ref),
            'diff --name-only --cached',
            'ls-files --others --exclude-standard --full-name',
        ] as $subcommand) {
            $lines = [];
            exec("git -C {$root} {$subcommand} 2>/dev/null", $lines, $status);

            if ($status === 0) {
                $out = array_merge($out, $lines);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    private function listEverything(array $doors): int
    {
        $byModel = [];

        foreach ($doors as $key => $door) {
            $byModel[$door['model'] ?? '(unattributed)'][$key] = $door;
        }

        ksort($byModel);

        foreach ($byModel as $model => $onto) {
            $this->newLine();
            $this->line('  <options=bold>'.class_basename($model).'</> <fg=gray>('.count($onto).')</>');
            $this->render($onto, '    ');
        }

        $this->newLine();
        $this->components->info(count($doors).' doors over '.count($byModel).' records');

        return self::SUCCESS;
    }

    private function render(array $doors, string $indent = '  '): void
    {
        $order = [
            WriteSurfaces::RESOURCE_FORM, WriteSurfaces::RELATION_MANAGER, WriteSurfaces::IMPORTER,
            WriteSurfaces::EXPORTER, WriteSurfaces::API_RESOURCE, WriteSurfaces::OFF_PANEL_CREATOR,
        ];

        uasort($doors, fn (array $a, array $b): int => array_search($a['kind'], $order, true) <=> array_search($b['kind'], $order, true));

        foreach ($doors as $key => $door) {
            $notes = [];

            if (($door['panel'] ?? null) !== null) {
                $notes[] = $door['panel'];
            }

            // The manager's shape, said out loud — an ATTACH manager's form writes the join table
            // and a read-only tab writes nothing, so reading either as a door onto the record's own
            // columns is what produced two of the three findings in the first measurement.
            if (($door['note'] ?? null) !== null) {
                $notes[] = $door['note'];
            }

            $this->line(sprintf(
                '%s<fg=cyan>%-18s</> %s%s',
                $indent,
                $door['kind'],
                // The PATH, never the raw key: an off-panel creator is keyed `path → Model` so one
                // service building several records stays one row per record, and that suffix is an
                // internal disambiguator rather than something to print at a reader.
                WriteSurfaces::pathOf($key),
                $notes === [] ? '' : ' <fg=gray>('.implode(', ', $notes).')</>',
            ));
        }
    }
}
