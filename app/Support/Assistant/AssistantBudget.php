<?php

namespace App\Support\Assistant;

use App\Models\AssistantQuestion;
use Illuminate\Support\Carbon;

/**
 * What the model tier has cost this month, and whether it may spend more.
 *
 * **Derived from the rows, not from a counter.** Spend is summed off the tokens recorded on
 * `assistant_questions`, which means it survives a cache flush, a queue restart and a deploy, and
 * it can be audited against the same rows the miss list is built from. A counter in the cache would
 * reset to zero on a Redis flush and hand the month a fresh budget nobody granted.
 *
 * **It is an ESTIMATE against a ceiling, never a bill.** Anthropic's invoice is the bill. This
 * exists so a loop, a script or an enthusiastic afternoon cannot produce a surprise, and it is
 * deliberately conservative: it counts every token the app was told about, including calls whose
 * answers were discarded.
 */
final class AssistantBudget
{
    public static function spentThisMonth(?Carbon $on = null): float
    {
        $month = ($on ?? now())->startOfMonth();

        $row = AssistantQuestion::query()
            ->where('created_at', '>=', $month)
            ->selectRaw('COALESCE(SUM(model_input_tokens), 0) as input')
            ->selectRaw('COALESCE(SUM(model_output_tokens), 0) as output')
            ->first();

        return self::costOf((int) ($row->input ?? 0), (int) ($row->output ?? 0));
    }

    public static function costOf(int $inputTokens, int $outputTokens): float
    {
        $rates = config('assistant.rates');

        return ($inputTokens / 1_000_000) * (float) $rates['input_per_mtok']
            + ($outputTokens / 1_000_000) * (float) $rates['output_per_mtok'];
    }

    public static function ceiling(): float
    {
        return (float) config('assistant.monthly_ceiling_usd');
    }

    /**
     * Whether another call may be made.
     *
     * Checked BEFORE the call, so the ceiling is a wall rather than a report. A zero ceiling means
     * "never spend" — a supported way to keep the driver configured while switching the spending
     * off, without editing the driver and losing the key.
     */
    public static function allowsAnotherCall(): bool
    {
        $ceiling = self::ceiling();

        if ($ceiling <= 0.0) {
            return false;
        }

        return self::spentThisMonth() < $ceiling;
    }
}
