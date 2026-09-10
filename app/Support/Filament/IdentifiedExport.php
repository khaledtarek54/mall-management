<?php

namespace App\Support\Filament;

use DomainException;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;

/**
 * AN EXPORT HAS TO SAY WHICH ROWS IT IS ABOUT.
 *
 * Reported by the tester with the file attached: a tenants export with only *Status* ticked, two
 * rows both reading "active", and nothing to say which tenants they were. The file is not merely
 * thin — it is unusable, and it looks like a successful export, so the operator finds out when they
 * open it somewhere else.
 *
 * Every export tool worth the name guarantees a key column: Excel's own export dialogs, Salesforce
 * reports, NetSuite saved searches and Yardi's Report Writer all carry the record's identity whether
 * or not you asked for it, because an anonymous row is not data.
 *
 * **It REFUSES rather than silently re-enabling the column, deliberately.** Forcing it back on would
 * be a save that quietly discards what the operator chose — the exact shape this panel has now been
 * reported for three times (a unit's status, a staff title, a lease's status), and the reason those
 * were fixed by not OFFERING the choice rather than by overriding it. Filament builds the column
 * checkboxes inside its own modal closure with no hook to lock one, so refusing at submit with a
 * sentence naming the columns that would satisfy it is the honest version of "cannot be deselected".
 *
 * The identifier is DERIVED from what the exporter actually offers, never listed per exporter: a new
 * exporter is covered by having a column called `code`, `number`, `reference`, `name` or `id`, which
 * every register in this system does.
 */
class IdentifiedExport
{
    /**
     * Column names that identify a row, in the order an operator would recognise them.
     *
     * `id` is last on purpose — it identifies a row to the DATABASE and is the least useful of these
     * to a person reading a spreadsheet, so it satisfies the rule without being what we suggest.
     *
     * @var array<int, string>
     */
    public const IDENTIFIERS = ['code', 'number', 'reference', 'name', 'id'];

    /**
     * The identifying columns this exporter offers.
     *
     * @param  class-string<Exporter>  $exporter
     * @return array<int, string>
     */
    public static function offeredBy(string $exporter): array
    {
        $names = array_map(fn (ExportColumn $column): string => $column->getName(), $exporter::getColumns());

        return array_values(array_intersect(self::IDENTIFIERS, $names));
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @param  array<string, mixed>  $columnMap
     *
     * @throws DomainException
     */
    public static function assertIdentified(string $exporter, array $columnMap): void
    {
        $identifiers = self::offeredBy($exporter);

        if ($identifiers === []) {
            // An exporter offering none of them is a question about THAT exporter, not something to
            // block an operator's export over — refusing here would make the file unobtainable
            // rather than unusable, which is worse.
            return;
        }

        foreach ($identifiers as $name) {
            if ((bool) data_get($columnMap, "{$name}.isEnabled", false)) {
                return;
            }
        }

        throw new DomainException(__('admin.refusals.export_needs_an_identifier', [
            'columns' => implode(', ', array_map(
                fn (string $name): string => (string) data_get($columnMap, "{$name}.label", $name),
                $identifiers,
            )),
        ]));
    }
}
