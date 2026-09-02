<?php

namespace App\Console\Commands;

use App\Models\AssistantQuestion;
use App\Support\Assistant\AssistantBudget;
use Illuminate\Console\Command;

/**
 * What the assistant is being asked, and how often it is getting it wrong.
 *
 * **A command rather than a screen, deliberately.** The panel now has exactly one assistant
 * surface — the floating chat — and adding a second page to read its statistics would put an
 * operator in front of a dashboard about the software rather than about their mall. This is for
 * whoever is deciding whether the model tier earns its money, which is a development question.
 *
 * **It leads with the ratings, not the misses.** The A phase's signal was "what matched nothing",
 * and measured on 45 real questions that list is EMPTY: with 189 corpus entries and 1,050
 * documentation sections something always matches. A feature that looks permanently healthy while
 * being wrong is worse than one that looks broken, so the first number here is what readers marked
 * unhelpful.
 */
class AssistantReportCommand extends Command
{
    protected $signature = 'atriom:assistant-report {--days=30 : How far back to look}';

    protected $description = 'What people asked the assistant, and how much of it landed';

    public function handle(): int
    {
        $since = now()->subDays((int) $this->option('days'));
        $asked = AssistantQuestion::where('created_at', '>=', $since);

        $total = (clone $asked)->count();

        if ($total === 0) {
            $this->info('Nobody has asked the assistant anything in that window.');

            return self::SUCCESS;
        }

        $rated = (clone $asked)->whereNotNull('was_helpful')->count();
        $unhelpful = (clone $asked)->where('was_helpful', false)->count();
        $unmatched = (clone $asked)->where('matched', false)->count();
        $unworded = (clone $asked)->where('matched', true)->whereNull('model_answer')->count();

        $this->table(['', ''], [
            ['Questions asked', $total],
            ['Rated by a reader', $rated === 0 ? '0  ← no signal yet; ask people to use the thumbs' : $rated],
            ['Marked NOT helpful', $unhelpful],
            ['Matched nothing at all', $unmatched],
            ['Matched but not worded (model off, quota, error)', $unworded],
            ['Model spend this month', '$'.number_format(AssistantBudget::spentThisMonth(), 2).' of $'.number_format(AssistantBudget::ceiling(), 2)],
        ]);

        // The list worth acting on: an answer somebody read and rejected.
        $bad = (clone $asked)->where('was_helpful', false)->latest('id')->limit(15)->get();

        if ($bad->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Marked not helpful — each is either a thin screen guide or a ranking problem:</comment>');

            foreach ($bad as $row) {
                $this->line(sprintf('  %-58s → %s', mb_substr($row->question, 0, 58), $row->top_key ?? '(nothing)'));
            }
        }

        // Still worth printing, and still worth knowing it may be empty by construction.
        $misses = (clone $asked)->where('matched', false)
            ->selectRaw('question_folded, COUNT(*) as n, MAX(question) as q')
            ->groupBy('question_folded')->orderByDesc('n')->limit(10)->get();

        if ($misses->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Matched nothing:</comment>');

            foreach ($misses as $miss) {
                $this->line(sprintf('  %-3d %s', $miss->n, mb_substr($miss->q, 0, 62)));
            }
        }

        return self::SUCCESS;
    }
}
