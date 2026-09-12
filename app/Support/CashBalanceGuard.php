<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerPoster;
use App\Settings\AccountingSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * **A cash box is never driven below zero; a bank account may be, and the operator is told.**
 * (Meeting 2026-09-02, point 16 — *"Sndo2 3am / bank menf3sh ykon da2n, lazm ykon fe amount fl
 * 7sab."*)
 *
 * ## The standard
 *
 * Yardi does not block an outbound payment on balance — a bank can be overdrawn, and Voyager's
 * control is the reconciliation. SAP's cash journal is the one benchmark that MODELS a cash box,
 * and it refuses a posting that would make the box's balance negative, as a hard error; its bank
 * accounts may overdraw. Odoo blocks neither. Egyptian practice: the خزينة is never in credit —
 * nobody hands out banknotes that are not in the drawer. So the two kinds of money account get
 * two rules, each a per-property setting ({@see AccountingSettings}), and BOTH ship OFF — Yardi's
 * default, under the rule this project ships every stricter rule: the market's default in code,
 * the client's own rule what they SET (skill §3b). OFF still WARNS: the save goes through and the
 * operator is told, in figures, that the account is overdrawn as at that date.
 *
 *  - **`accounting.refuse_overdrawn_cash`** — ON is SAP's cash-journal rule (a physical box cannot
 *    be negative in any system that has one; Yardi has no cash box to defer to). Stricter than
 *    Yardi, and stated as such — the client's setting on staging.
 *  - **`accounting.refuse_overdrawn_bank`** — ON is the client's own *"the bank can never be in
 *    credit"*; OFF is Yardi's and Odoo's answer (an overdraft facility is legitimate, and refusing
 *    a real payment because its receipt was keyed an hour later is the worse failure).
 *
 * ## One seam, derived from the books
 *
 * Which documents pay out of a cash box or a bank is not a list here. Every GL source's journalizer
 * already says which account its money leaves through (`MoneyAccount::for()` — the document's own
 * bank, else its rail's account, else the posting role), so the guard asks the poster what the
 * save would MOVE ({@see LedgerPoster::accountMovements()}) and looks at the accounts it credits.
 * An expense, a supplier payment, an owner disbursement, a payroll run, an advance, a custody
 * grant, a deposit refund and an asset purchase are all covered by BEING posting sources, and the
 * twenty-sixth is covered the day it is registered. A receipt debits the account and is never
 * refused; an edit is judged on its INCREASE over what is already posted; a void removes an
 * outflow and is never refused either (a payment recorded in error must stay reversible).
 *
 * ## "Below zero" is asked of every day from the document's date onward
 *
 * SAP's cash journal checks the balance on the posting date, and a back-dated outflow can leave a
 * LATER day negative while the posting day itself is fine. So the guard walks the running balance
 * from the document's date to the last posted movement and takes the LOWEST point: a payment dated
 * the 5th against a box that had 10,000 on the 5th and 200 on the 20th is refused if it would take
 * the 20th below zero. Per property — the cash role is one chart account dimensioned by
 * `journal_entries.asset_id`, and each mall's drawer is its own.
 *
 * ## What it reads and what it cannot see
 *
 * The balance is the LEDGER's, not a second sum over documents — one truth about money. The ledger
 * is posted by an after-commit job, so a receipt keyed seconds ago may not be in the balance yet;
 * that is the lag Horizon closes in milliseconds and the reason the bank default is WARN. Fails
 * OPEN when the chart cannot answer (an unmapped role, a journalizer that throws on an unsaved
 * document): refusing ordinary work because the accounting setup is incomplete is the worse
 * failure, and the sealed-period guard beside this made the same choice for the same reason.
 */
class CashBalanceGuard
{
    /**
     * Refuse (or warn on) a save that would overdraw a cash box or a bank account.
     *
     * @throws DomainException
     */
    public static function guard(Model $model): void
    {
        if (! array_key_exists($model::class, LedgerPoster::JOURNALIZERS)) {
            return;
        }

        // An edit that reaches no ledger field cannot move money — the same pre-filter
        // `SealedPeriod` runs, so a note or a dunning stamp costs no journalizer call.
        if ($model->exists && ! SealedPeriod::touchesTheLedger($model)) {
            return;
        }

        try {
            $movement = app(LedgerPoster::class)->accountMovements($model);
        } catch (DomainException $e) {
            // The chart cannot answer — an unmapped posting role, a period that does not exist. That
            // is a SETUP state `ConfigurationHealth` already reports as blocking, and on such an
            // install every money document meets it, so it is not worth a warning per save.
            Log::debug('Cash-balance guard could not evaluate '.$model::class.($model->getKey() ? ' #'.$model->getKey() : '').': '.$e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::warning('Cash-balance guard could not evaluate '.$model::class.($model->getKey() ? ' #'.$model->getKey() : '').': '.$e->getMessage());

            return;
        }

        if ($movement === null || $movement['entry_date'] === null) {
            return;
        }

        $outflows = array_filter($movement['net'], fn (float $v) => $v < 0);
        if ($outflows === []) {
            return;
        }

        $assetId = $movement['asset_id'];
        $from = CarbonImmutable::parse($movement['entry_date']);

        // A source dated by its PERIOD (payroll: `period_month`, the 1st) books the money on a day
        // it did not leave — the run is approved and paid at month-end. The books keep their date;
        // the cash question is asked from the day of the act, or every month-end approval would be
        // measured against the balance on the 1st. Derived from the registry's date column, so a
        // second period-dated source inherits the rule.
        if ((LedgerRealtimeSync::SOURCE_DATE_COLUMNS[$model::class] ?? null) === 'period_month') {
            $from = $from->max(CarbonImmutable::today());
        }

        foreach ($outflows as $accountId => $delta) {
            $kind = self::kindOf((int) $accountId, $assetId);
            if ($kind === null) {
                continue;
            }

            // A bank's OWN chart account belongs to one property by construction, so it is read
            // whole: a receipt allocated across two malls posts with no property dimension and
            // would otherwise be missing from the account it landed in. The shared `cash` and `bank`
            // ROLE accounts are one chart account per dimension, and stay per property.
            $whole = self::isABanksOwnAccount((int) $accountId);
            $lowest = self::lowestBalanceFrom((int) $accountId, $whole ? null : $assetId, $from, whole: $whole, excludeEntryId: $movement['replaces']);
            $after = round($lowest + $delta, 2);
            if ($after >= 0) {
                continue;
            }

            $account = LedgerAccount::find($accountId);
            $words = [
                'account' => $account?->{'name_'.app()->getLocale()} ?: ($account?->name_en ?: '#'.$accountId),
                'property' => $assetId ? (Asset::find($assetId)?->name ?? '') : __('admin.fields.portfolio'),
                'date' => $from->locale(app()->getLocale())->isoFormat('D MMMM YYYY'),
                'balance' => number_format($lowest, 2),
                'amount' => number_format(abs($delta), 2),
                'shortfall' => number_format(abs($after), 2),
            ];

            if (self::refuses($kind, $assetId)) {
                // A wildcard `creating` listener runs AFTER the model's own, and
                // `AllocatesDocumentNumber` takes its cache lock in `creating` and releases it in
                // `created` — a throw here would leave that lock held for its TTL, so the NEXT
                // document of the same prefix waits out the block and degrades to unlocked
                // allocation (measured: under a frozen test clock the wait never ends). Hand the
                // lock back before refusing.
                if (method_exists($model, 'releaseDocumentNumberLock')) {
                    $model->releaseDocumentNumberLock();
                }

                throw new DomainException(__("admin.refusals.{$kind}_overdrawn", $words));
            }

            // Warn, once per save. A Filament notification is a session push, which in the
            // operator's own request renders as a toast on the next paint and in a queue worker or
            // a console run lands in a store nothing saves — harmless there, and one code path
            // beats a console check the suite (which IS a console) could never exercise.
            Notification::make()
                ->title(__("admin.notifications.{$kind}_overdrawn_title"))
                ->body(__("admin.notifications.{$kind}_overdrawn_body", $words))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * `cash_box` for the account the property's `cash` rail / posting role resolves to, `bank` for
     * a registered bank account's own leaf, the `bank` role or a non-cash rail's account, null for
     * anything else the document credits (AR, AP, a liability — not money in hand).
     *
     * @return 'cash_box'|'bank'|null
     */
    public static function kindOf(int $accountId, ?int $assetId): ?string
    {
        // One query before the classification: money in hand is an ASSET account. Every source
        // credits something — an invoice credits revenue and VAT — and resolving the cash and bank
        // roles for each of those cost ~9 queries a line on documents that pay nothing out.
        if (LedgerAccount::query()->whereKey($accountId)->value('type') !== 'asset') {
            return null;
        }

        $accounts = app(AccountResolver::class);

        try {
            if (MoneyAccount::for(null, 'cash', $assetId, $accounts) === $accountId) {
                return 'cash_box';
            }
        } catch (\Throwable) {
            // No cash role mapped — nothing can be a cash box on this install.
        }

        if (self::isABanksOwnAccount($accountId)) {
            return 'bank';
        }

        if (PaymentMethod::query()->where('ledger_account_id', $accountId)->where('code', '!=', 'cash')->exists()) {
            return 'bank';
        }

        try {
            if ($accounts->id('bank', $assetId) === $accountId) {
                return 'bank';
            }
        } catch (\Throwable) {
            // No bank role mapped.
        }

        return null;
    }

    /** A chart account minted for (or pointed at by) a registered bank account — one property's own. */
    public static function isABanksOwnAccount(int $accountId): bool
    {
        return BankAccount::withTrashed()->where('ledger_account_id', $accountId)->exists();
    }

    /** Does THIS property refuse an overdraft of this kind, or only warn? */
    public static function refuses(string $kind, ?int $assetId): bool
    {
        // Two literal reads rather than one composed key: `PropertySettingsConformanceTest` proves an
        // override is READ by finding the call with its key, and a key built at runtime is invisible
        // to it — a setting nothing visibly consults is the inert-settings defect.
        $value = $kind === 'cash_box'
            ? PropertySettings::get('accounting.refuse_overdrawn_cash', $assetId)
            : PropertySettings::get('accounting.refuse_overdrawn_bank', $assetId);

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * The lowest point the account's running balance reaches on any day from `$from` onward —
     * opening balance before the date, then each posted day's net in order. The document's own
     * live entry (`$excludeEntryId`, from {@see LedgerPoster::accountMovements()}) is left OUT and
     * the whole new payload judged against what remains, so an edit is judged on its increase, and a
     * document re-dated or re-homed on its full amount where it now lands.
     */
    public static function lowestBalanceFrom(int $accountId, ?int $assetId, CarbonImmutable $from, bool $whole = false, ?int $excludeEntryId = null): float
    {
        $base = self::lines($accountId, $assetId, $whole, $excludeEntryId);

        $opening = round((float) (clone $base)->whereDate('je.entry_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as net')->value('net'), 2);

        $days = (clone $base)->whereDate('je.entry_date', '>=', $from->toDateString())
            ->selectRaw('je.entry_date as day, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as net')
            ->groupBy('je.entry_date')
            ->orderBy('je.entry_date')
            ->pluck('net', 'day')
            ->mapWithKeys(fn ($net, $day) => [substr((string) $day, 0, 10) => (float) $net]);

        // The candidates are END-OF-DAY balances on `$from` and every posted day after it — the
        // days the document changes. The day before it is not one: a receipt keyed the same day
        // as the payment counts (SAP's cash journal judges the day's closing balance), so the
        // opening figure stands in only for a `$from` with no movement of its own.
        $running = $opening;
        $lowest = $days->has($from->toDateString()) ? null : $opening;
        foreach ($days as $net) {
            $running = round($running + $net, 2);
            $lowest = $lowest === null ? $running : min($lowest, $running);
        }

        return $lowest ?? $opening;
    }

    /** The account's balance today — per property dimension, or the whole account (`$whole`). */
    public static function balanceOf(int $accountId, ?int $assetId, bool $whole = false): float
    {
        return round((float) self::lines($accountId, $assetId, $whole)
            ->selectRaw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as net')->value('net'), 2);
    }

    /**
     * The reportable lines of one account: every dimension (`$whole`), one property's, or the
     * portfolio's own (`$assetId` null) — less the document's own live entry, which is what a
     * save would replace and must not be counted against itself.
     */
    private static function lines(int $accountId, ?int $assetId, bool $whole, ?int $excludeEntryId = null): Builder
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', JournalEntry::REPORTABLE_STATUSES)
            ->whereNull('je.deleted_at')
            ->where('jl.ledger_account_id', $accountId)
            ->when(! $whole, fn ($q) => $assetId === null ? $q->whereNull('je.asset_id') : $q->where('je.asset_id', $assetId))
            ->when($excludeEntryId !== null, fn ($q) => $q->where('je.id', '!=', $excludeEntryId));
    }
}
