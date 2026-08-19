<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Support\Filament\PropertyField;
use App\Support\PropertyIsolation;
use Filament\Resources\Resource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Find every money document filed against NO property.
 *
 * **Why this exists, and why only now.** A handful of models declare
 * `#[PropertyOwned(portfolioRowsWhenNull: true)]`, which says a null `asset_id` is portfolio-level
 * overhead every property must still see — an operator-wide insurance bill is not hidden because
 * someone picked a mall. That flag is load-bearing and stays. Its side effect is that such a row
 * appears on **every** mall's list, so a row that is property-less *by accident* is not merely
 * mis-filed: it is visible everywhere, it lands in no mall's owner statement
 * (`GenerateOwnerStatementRunService` scopes `where('asset_id', $asset->id)`), and nothing about it
 * looks wrong on screen.
 *
 * Since {@see PropertyField} pinned the pickers, **no panel screen can produce one any more** — a
 * blank property is a bare 403 from `assertAssetInScope()`. That closes the operator path and
 * leaves exactly two that are still open, both of which run before anyone looks at a screen: a CSV
 * **import**, and a **migration** from the system the operator is leaving. Those are precisely the
 * moments this command is for, which is why it exits non-zero rather than printing a report someone
 * remembers to read — the same contract as `atriom:audit-charge-schedules`.
 *
 * **What counts as an accident is DERIVED, not listed.** A hybrid model whose form is registered in
 * `PropertyField::PORTFOLIO_LEVEL` is one where blank is the normal, meaningful answer (a global
 * department), so its nulls are reported and forgiven. Every other hybrid model is a money document
 * whose picker is pinned, so a null can only be legacy — reported and failed. Deriving it from the
 * register that already governs those screens means the two can never disagree; a screen that later
 * earns a free picker stops being flagged here on the same commit, without anyone remembering this
 * file exists.
 *
 * Read-only. It never repairs a row: the right correction for a posted entry is a reversing entry,
 * and for an unposted one an edit, and neither is a decision a sweep should take on money.
 */
class AuditPropertyDimensionCommand extends Command
{
    protected $signature = 'atriom:audit-property-dimension
        {--limit=25 : How many offending rows to name per model}';

    protected $description = 'Report money documents carrying no property (asset_id), which show on every mall and reach no owner statement.';

    /**
     * Rows that are SUPERSEDED history rather than live documents, and are therefore not audited.
     *
     * **Why this exists (2026-08-19).** The ledger here is *derived*: `LedgerPoster::sync()` re-reads
     * a document and, when its posted entry no longer matches, **voids it and posts a fresh one**. So
     * a void entry is a snapshot of a state the document has already left — and auditing it for a
     * defect in the CURRENT state means every corrected document fails this command for ever.
     *
     * That is not theoretical. Fixing the null-property receipt in `PaymentJournalizer` on the same
     * day produced exactly this shape: the sweep voided the property-less entry, posted a correct
     * one, and the void row stayed behind carrying the old NULL. The audit would have gone on
     * reporting a defect that had been fixed, on a row nobody can act on.
     *
     * And it named a remedy that cannot be performed. The failure text says *"correct a posted entry
     * with a reversing entry; edit an unposted one"* — a void entry is neither. **A check that fails
     * with no available action is a check people learn to skip**, which costs more than the rows it
     * would have caught.
     *
     * Narrow on purpose: only statuses that mean *this row has been superseded and posts nothing*.
     * A `cancelled` invoice is NOT here — it still explains a number the tenant remembers.
     *
     * @var array<class-string, array{column: string, values: array<int, string>, reason: string}>
     */
    private const SUPERSEDED = [
        JournalEntry::class => [
            'column' => 'status',
            'values' => ['void'],
            'reason' => 'a void entry posts nothing and is the by-product of every void-and-repost correction',
        ],
    ];

    public function handle(): int
    {
        $expectedNulls = $this->modelsWhoseBlankIsMeaningful();
        $checked = 0;
        $failures = [];

        foreach (PropertyIsolation::hybridModels() as $model) {
            /** @var Model $instance */
            $instance = new $model;
            $table = $instance->getTable();
            $checked++;

            $rowsQuery = fn () => DB::table($table)
                ->whereNull('asset_id')
                ->when(
                    isset(self::SUPERSEDED[$model]),
                    fn ($q) => $q->whereNotIn(self::SUPERSEDED[$model]['column'], self::SUPERSEDED[$model]['values'])
                );

            $count = $rowsQuery()->count();

            if ($count === 0) {
                continue;
            }

            if (in_array($model, $expectedNulls, true)) {
                $this->line(sprintf(
                    '  <fg=gray>%s — %d portfolio-level row(s). Expected: its property field is deliberately free.</>',
                    class_basename($model),
                    $count,
                ));

                continue;
            }

            $failures[$model] = $count;
            $this->newLine();
            $this->warn(sprintf('%s — %d row(s) with no property', class_basename($model), $count));

            $rows = $rowsQuery()->limit(max(1, (int) $this->option('limit')))->get();

            foreach ($rows as $row) {
                $this->line('    #'.$row->id.'  '.$this->describe($row));
            }

            if ($count > count($rows)) {
                $this->line(sprintf('    … and %d more (raise --limit to see them).', $count - count($rows)));
            }
        }

        $this->newLine();

        if ($failures === []) {
            $this->info("Checked {$checked} model(s) that may carry a null property. Every money document names one.");

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Found %d money document(s) with no property, across %d model(s).',
            array_sum($failures),
            count($failures),
        ));
        $this->line('These show on EVERY mall\'s list and reach no owner statement. No panel screen can');
        $this->line('create one, so each came from an import or a migration. Correct a posted entry with a');
        $this->line('reversing entry and re-file it; edit an unposted one. This command never repairs a row.');

        return self::FAILURE;
    }

    /**
     * The models whose blank property is a real answer rather than an omission.
     *
     * Derived from `PropertyField::PORTFOLIO_LEVEL` — the register that decides which SCREENS keep a
     * free, nullable picker — by walking each registered path back to the resource in its directory
     * and asking that resource what model it edits. A second hand-written list here would be a
     * second truth about the same decision, and the failure mode of the two disagreeing is this
     * command crying wolf on every run until somebody stops reading it.
     *
     * @return list<class-string>
     */
    private function modelsWhoseBlankIsMeaningful(): array
    {
        $models = [];

        foreach (array_keys(PropertyField::PORTFOLIO_LEVEL) as $path) {
            $directory = dirname(dirname(base_path($path)));

            foreach (glob($directory.'/*Resource.php') ?: [] as $file) {
                $resource = 'App\\Filament\\Admin\\Resources\\'
                    .basename($directory).'\\'.basename($file, '.php');

                if (class_exists($resource) && is_subclass_of($resource, Resource::class)) {
                    $models[] = $resource::getModel();
                }
            }
        }

        return array_values(array_unique($models));
    }

    /** A one-line identity for a row, using whichever natural key the table happens to carry. */
    private function describe(object $row): string
    {
        foreach (['number', 'reference', 'name', 'code', 'title'] as $column) {
            if (! empty($row->{$column})) {
                return (string) $row->{$column};
            }
        }

        return '(no reference)';
    }
}
