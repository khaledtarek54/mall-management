<?php

namespace App\Console\Commands;

use App\Models\PurchaseRequest;
use App\Services\Accounting\LedgerPoster;
use App\Services\MaintenanceWorkOrderService;
use App\Services\TenantRequestService;
use App\Support\ChangeImpact;
use App\Support\DeletionPolicy;
use App\Support\LedgerRealtimeSync;
use App\Support\PostingDateGuards;
use App\Support\PostingRoles;
use App\Support\PropertyIsolation;
use App\Support\ScreenGuides;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Feed the visual handbook from the registries, so its interactive parts cannot describe a system
 * that does not exist.
 *
 * **This is the same argument as `atriom:dump-system-census` and `atriom:dump-registries`, one layer
 * out.** Those keep the MARKDOWN honest; this keeps the handbook's Vue components honest. A
 * `<PostingExplorer>` listing twelve GL sources while `LedgerPoster` posts twenty-four is exactly
 * the failure `docs/modules/21-general-ledger.md` already shipped once, and a component is worse
 * than a paragraph for it: a picture of a system reads as authoritative in a way prose does not.
 *
 * ## What is dumped, and what deliberately is not
 *
 * Everything here is a REGISTRY — a constant a human maintains and a conformance gate already
 * guards. That is the whole selection rule.
 *
 * The obvious omission is the **debit/credit lines each journalizer posts**. They are not in a
 * registry and they are not statically derivable: `InvoiceJournalizer` resolves its revenue role
 * per line through `ChargeCode::roleFor()`, i.e. from a table an accountant maintains at runtime,
 * with a hard-coded floor behind it. Any "map" of them would be a plausible guess rendered as a
 * diagram, which is worse than not drawing it. The T-account cards on the module pages stay
 * hand-authored, where they are explained with worked numbers and a human vouches for them.
 *
 * So the posting explorer answers what IS derivable and is the more useful question anyway:
 * *what posts to the ledger, when is it dated, may it be edited afterwards, and can it be deleted.*
 */
class DumpHandbookData extends Command
{
    protected $signature = 'atriom:dump-handbook-data';

    protected $description = 'Regenerate the handbook\'s generated datasets from the code registries';

    /** Where the VitePress components read from. */
    private const OUT = 'docs/visual/.vitepress/data';

    public function handle(): int
    {
        $dir = base_path(self::OUT);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sets = [
            'gl-sources' => $this->glSources(),
            'posting-roles' => $this->postingRoles(),
            'isolation' => $this->isolation(),
            'workflows' => $this->workflows(),
            'screens' => $this->screens(),
        ];

        foreach ($sets as $name => $data) {
            file_put_contents(
                "{$dir}/{$name}.json",
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
            $this->line(sprintf('  %-16s %d entries', $name, is_countable($data) ? count($data) : 0));
        }

        $this->info('Handbook datasets regenerated from the registries.');

        return self::SUCCESS;
    }

    /**
     * Every document that reaches the general ledger, with the four facts an operator asks about it.
     *
     * Derived from `LedgerPoster::JOURNALIZERS`, which is the single registry all four dispatch
     * paths already derive from — so this cannot list a source the system does not post, nor miss
     * one it does.
     *
     * @return array<int, array<string, mixed>>
     */
    private function glSources(): array
    {
        $rows = [];

        foreach (LedgerPoster::JOURNALIZERS as $model => $journalizer) {
            $guard = PostingDateGuards::GUARDS[$model] ?? null;

            $rows[] = [
                'model' => class_basename($model),
                'label' => Str::headline(class_basename($model)),
                'journalizer' => class_basename($journalizer),
                // Which column the sweep windows on — i.e. what date the entry lands under.
                'date_column' => LedgerRealtimeSync::SOURCE_DATE_COLUMNS[$model] ?? null,
                // A `system:` reason means the date is never operator-typed, so there is nothing
                // to guard rather than a guard missing.
                'posting_date_guard' => is_string($guard) && str_starts_with($guard, 'system:')
                    ? null
                    : (is_string($guard) ? class_basename($guard) : null),
                'posting_date_note' => is_string($guard) && str_starts_with($guard, 'system:')
                    ? substr($guard, strlen('system:'))
                    : null,
                'deletable' => $this->deletionTier($model),
                // How many of its columns may move a posted entry, by policy.
                'change_impact' => $this->changeImpactSummary($model),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /** @return array<int, array<string, string>> */
    private function postingRoles(): array
    {
        $rows = [];

        foreach (PostingRoles::ROLES as $role => $group) {
            $rows[] = [
                'role' => $role,
                'label' => Str::headline($role),
                'group' => $group,
            ];
        }

        return $rows;
    }

    /**
     * Which models are property-owned, which are shared, and how each is scoped.
     *
     * @return array<int, array<string, string>>
     */
    private function isolation(): array
    {
        $rows = [];

        foreach (['shared' => PropertyIsolation::SHARED, 'owned' => PropertyIsolation::OWNED, 'self' => PropertyIsolation::SELF] as $classification => $models) {
            foreach ($models as $key => $value) {
                // The registers mix shapes: some entries are `Model::class => 'reason'`, others are
                // a bare list. Take whichever side is the class name.
                $model = is_string($key) && class_exists($key) ? $key : (is_string($value) ? $value : null);

                if ($model === null || ! class_exists($model)) {
                    continue;
                }

                $rows[] = [
                    'model' => class_basename($model),
                    'label' => Str::headline(class_basename($model)),
                    'classification' => $classification,
                    'note' => is_string($key) && class_exists($key) && is_string($value) ? $value : null,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * The state machines the services ENFORCE — the same matrices the admin Workflows page renders.
     *
     * Taken from the enforcing constant rather than re-listed, so the handbook cannot draw a
     * transition the code refuses (or miss one it allows).
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function workflows(): array
    {
        return [
            'tenant_request' => TenantRequestService::TRANSITIONS,
            'work_order' => MaintenanceWorkOrderService::TRANSITIONS,
            'purchase_request' => PurchaseRequest::TRANSITIONS,
        ];
    }

    /**
     * Every screen an operator can open, and the guide key that explains it.
     *
     * Lets the handbook link a module page straight at the screen it describes, and — because the
     * guide registry is exhaustive and gated — makes a module page that names a screen which no
     * longer exists a visible mismatch rather than a silent one.
     *
     * @return array<int, array<string, string>>
     */
    private function screens(): array
    {
        $rows = [];

        foreach (ScreenGuides::SCREENS as $class => $key) {
            $rows[] = [
                'screen' => class_basename($class),
                'panel' => str_contains($class, '\\Portal\\') ? 'portal' : 'admin',
                'kind' => str_contains($class, '\\Resources\\') ? 'resource' : 'page',
                'guide' => $key,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['guide'], $b['guide']));

        return $rows;
    }

    /**
     * The deletion tier, plus the remedy the registry states.
     *
     * **Both registers are keyed BY CLASS with the reason as the value.** The first version of this
     * asked `in_array($model, NEVER_DELETABLE)`, which searches the reasons rather than the classes
     * and so answered "allowed" for every money record in the system — a credit note rendered as
     * freely deletable, which is the exact opposite of the invariant. Generated data that is
     * confidently wrong is worse than none, so this reads the keys.
     *
     * @return array{tier: string, instead: string|null}
     */
    private function deletionTier(string $model): array
    {
        if (array_key_exists($model, DeletionPolicy::NEVER_DELETABLE)) {
            return ['tier' => 'never', 'instead' => DeletionPolicy::NEVER_DELETABLE[$model]];
        }

        if (array_key_exists($model, DeletionPolicy::WHEN_UNUSED)) {
            return [
                'tier' => 'when_unused',
                'instead' => DeletionPolicy::WHEN_UNUSED[$model]['instead'] ?? null,
            ];
        }

        return ['tier' => 'allowed', 'instead' => null];
    }

    /**
     * A count per impact class — "three of its fields are refused outright, two re-derive the entry".
     *
     * @return array<string, int>
     */
    private function changeImpactSummary(string $model): array
    {
        $policy = ChangeImpact::POLICY[$model] ?? [];
        $summary = [];

        // POLICY is keyed by VERDICT, and each verdict holds either `column => reason` or a bare
        // list of columns. The `committed` key is prose about when the record becomes evidence —
        // not a verdict — so it is skipped rather than counted as one.
        foreach (ChangeImpact::VERDICTS as $verdict) {
            $columns = $policy[$verdict] ?? [];

            if ($columns !== []) {
                $summary[$verdict] = count($columns);
            }
        }

        return $summary;
    }
}
