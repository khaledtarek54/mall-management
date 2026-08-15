<?php

namespace App\Support;

use App\Models\Concerns\GuardsPostingDate;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PostingDateNotOperatorTyped;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Where each GL source's entry date is checked against a closed accounting period.
 *
 * WHY A REGISTRY. Every source already declares the column its journal entry is dated from
 * (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). This declares the other half: **who refuses that
 * date when its period is closed.** The two together are what
 * PostingDateGuardConformanceTest enforces, so a new money source cannot ship without
 * someone answering the question.
 *
 * It exists because the answer was got wrong six times in a row — each fixed locally, as if it
 * were that module's own bug, and each time the next module shipped with the same hole. The
 * clearest illustration is custody: the first fix (F-93) guarded the SETTLEMENT and left the
 * GRANT open, so an operator could book a عهدة into a closed month, and the settlement guard
 * would then refuse every settlement of it (a settlement may not predate its grant) — a custody
 * recorded, unbacked in the books, and unsettleable.
 *
 * TWO KINDS OF ENTRY:
 *
 *  - **A guard class** — the service (or model) that runs `PostingDate::assertOpen` on the date.
 *    Prefer a service: the refusal can be raised before any related work begins. Several sources
 *    have no create/update service at all (their Filament resource writes the model), and for
 *    those the model's own save is the single choke point every path shares — form, console,
 *    API, seeder — so they use {@see GuardsPostingDate}.
 *
 *  - **`system:<reason>`** — the date can never be operator-typed, so there is nothing to guard.
 *    The conformance test fails if a form later offers a DatePicker for that column, because
 *    that exemption would then be silently false.
 */
class PostingDateGuards
{
    /** Marks a date the system sets; the text after the colon is the reason. */
    public const SYSTEM_PREFIX = 'system:';

    /** @var array<class-string, string>|null */
    private static ?array $guards = null;

    /**
     * Source model => the class that guards its posting date, or a `system:` reason.
     *
     * DERIVED from the models themselves (2026-08-15): each GL source declares
     * {@see PostingDateGuardedBy} or {@see PostingDateNotOperatorTyped}. The `system:` string
     * encoding is preserved here because every consumer reads this shape — the two attributes are
     * what removed the need for one array to carry two different kinds of answer.
     *
     * Keep in step with LedgerRealtimeSync::SOURCE_DATE_COLUMNS — the gate enforces it.
     *
     * @return array<class-string, string>
     */
    public static function guards(): array
    {
        if (self::$guards !== null) {
            return self::$guards;
        }

        $guards = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $model = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($model);

            foreach ($reflection->getAttributes(PostingDateGuardedBy::class) as $attribute) {
                $guards[$model] = $attribute->newInstance()->guard;
            }

            foreach ($reflection->getAttributes(PostingDateNotOperatorTyped::class) as $attribute) {
                $guards[$model] = self::SYSTEM_PREFIX.$attribute->newInstance()->reason;
            }
        }

        return self::$guards = $guards;
    }

    /** True when the entry is a `system:` exemption rather than a guard class. */
    public static function isSystemDated(string $guard): bool
    {
        return str_starts_with($guard, self::SYSTEM_PREFIX);
    }
}
