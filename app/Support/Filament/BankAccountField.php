<?php

namespace App\Support\Filament;

use App\Models\BankAccount;
use App\Models\PaymentMethod;
use App\Support\MoneyAccount;
use App\Support\TenantScope;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * "Which bank account did this money move through?" — one field, eight money forms.
 *
 * The register has existed since 2026-08-11 and nothing on a money document pointed at it, so every
 * posting resolved the generic `bank` role. A mall banking in two places therefore put both banks'
 * money in one chart account, and the reconciliation matcher — which finds candidates BY that
 * account — offered the other bank's postings. See {@see MoneyAccount}.
 *
 * ## Asked, defaulted, required — in that order, and all three or none
 *
 * EG-12 shipped the field OPTIONAL on every form with no default, so on a real install almost every
 * document still named nothing and the separation stayed theoretical. Yardi's answer is to make the
 * cash account **mandatory** on every money movement — and it is liveable there for exactly one
 * reason: the **property carries default cash accounts**, so a receipt arrives with its bank already
 * filled and the operator confirms rather than chooses.
 *
 * **Required without a default is the worst half of that design.** An operator picking the same
 * value three hundred times a month eventually picks the wrong one, and a wrong bank account is
 * worse than none: `MatchBankStatementLineService::candidatesFor()` finds candidates BY the chart
 * account, so the mistake is then presented as a real match against the wrong statement. So this
 * field does all three or it does none of them.
 *
 * ## The requirement is a ROW, and it is conditional twice
 *
 * `payment_methods.requires_bank_account` decides whether naming an account is part of recording
 * money on this rail. Not a literal: `RecurringExpenseForm` asked the same question with a hardcoded
 * `!== 'cash'`, which is a filter written twice and wrong the day the operator activates Fawry.
 *
 * The second condition is availability. An install with no bank account registered for this property
 * must still be able to record a receipt — refusing there would block a core workflow over a
 * register the operator has not reached yet, so the requirement lifts and
 * `ConfigurationHealth::bankAccountsRegistered()` raises it as the advisory it is.
 *
 * ## Deliberately NOT hidden on a cash rail
 *
 * The obvious shape — `->visible()` when the rail requires one — is wrong twice here. A hidden
 * Filament field is not dehydrated, so a document switched from transfer to cash would silently
 * KEEP the bank account it already names while the operator watches the picker disappear; and
 * `bank_account_id` is classified DERIVED, so clearing it to compensate would void and re-post the
 * document's ledger entry. Yardi shows the cash account on every money screen for its own version of
 * this reason. It stays visible and merely stops being required, which is also the state every
 * install is in today.
 *
 * An `EntitySelect`, not a `Select`: it picks a RECORD, so it gets the folded blob search, the
 * two-line option and — the part that matters — the property scope as a WRITE GUARD, because
 * Filament validates a Select by asking it to resolve the submitted value's label. Narrowing to the
 * SELECTED mall on top of that is this field's own choice: a receipt banked in Mall A's account is
 * not something you record while working in Mall B.
 */
final class BankAccountField
{
    /**
     * @param  class-string  $model  the money document this field sits on — it declares the PURPOSE
     *                               its money belongs to and the COLUMN naming its rail, so the form
     *                               and `RecordsBankAccount`'s own default can never disagree
     */
    public static function for(string $model, string $name = 'bank_account_id'): EntitySelect
    {
        $purpose = $model::bankAccountPurpose();
        $rail = $model::bankAccountRailColumn();

        // The bank must belong to the property the DOCUMENT says its money belongs to — asked of the
        // model, never assumed to be the selected mall: a fixed asset transferred to another mall
        // keeps its purchase bank in the one it was bought in (point 18), and a picker narrowed to
        // the switcher could label neither that bank nor accept another — every save refused.
        // `instanceof`, not `exists`: on an action modal `$record` is the modal's OWNER (the lease
        // behind *Record deposit*), which answers for nothing here.
        return self::make($name, fn (?Model $record): ?int => $record instanceof $model && $record->exists
            ? $model::bankAccountPropertyOf($record)
            : TenantScope::currentAssetId())
            // A CLOSURE, and evaluated per render rather than once: `TenantScope::currentAssetId()`
            // is a request-time fact, and a default resolved when the schema class is loaded would
            // pin every form on the box to whichever property was selected first.
            ->default(fn () => BankAccount::defaultFor(TenantScope::currentAssetId(), $purpose)?->id)
            // Evaluated at VALIDATION with the submitted rail in state, so the refusal is correct
            // whether or not the rail select is `->live()`; live only decides how soon the asterisk
            // appears. `$get` on the rail rather than on the record, because an operator changing
            // the rail mid-edit is exactly when this has to change with them.
            ->required(fn (Get $get, ?Model $record): bool => self::isRequired($get($rail)) && self::stillAsked($record, $rail, $get($rail)))
            // A bank the rail does not carry is not recorded (2026-09-12). The default above fills
            // the property's account in from mount while the rail beside it defaults to `cash`, and
            // `MoneyAccount` lets a NAMED account win over the rail — so a purchase left on both
            // defaults booked its credit to the BANK (measured through the real create page, on the
            // expense form and the fixed-asset form alike). Dehydrating null where the rail asks no
            // bank keeps the field visible (the docblock's reason for never hiding it) while the
            // books follow the rail the operator actually picked.
            ->dehydrateStateUsing(fn (mixed $state, Get $get): mixed => PaymentMethod::requiresBankAccount(is_string($get($rail)) ? $get($rail) : null) ? $state : null)
            ->helperText(fn (Get $get, ?Model $record): string => self::isRequired($get($rail)) && self::stillAsked($record, $rail, $get($rail))
                ? __('admin.helpers.bank_account_required_on_document')
                : __('admin.helpers.bank_account_on_document'));
    }

    /**
     * Whether the question is still open on THIS row — the half of the requirement the model's
     * own docblock already states: *"an existing document keeps whatever it has, including null."*
     *
     * On CREATE, always. On EDIT the requirement stands only where the operator is CHANGING the
     * rail (a purchase re-stated as a transfer must say which bank) or the row already NAMES an
     * account (clearing it is a restatement to the generic role, never a correction). A row
     * written before the register existed — every pre-register asset and expense on a real
     * install is `bank` / null — is left alone: measured, a name-only save of one was refused
     * with *"The bank account field is required"*, and picking an account to satisfy it was a
     * DERIVED re-post of a January acquisition that `SealedPeriod` then refused as well. Both doors
     * shut, on a field the operator never touched (the review caught it, 2026-09-12).
     */
    private static function stillAsked(?Model $record, string $rail, mixed $submittedRail): bool
    {
        if ($record === null || ! $record->exists) {
            return true;
        }

        return $record->bank_account_id !== null
            || (string) $record->getOriginal($rail) !== (string) $submittedRail;
    }

    /**
     * The picker alone, with no requirement and no default.
     *
     * Kept for a call site that has no money document behind it to ask — there are none today, and
     * {@see for()} is what every form uses. It stays the one builder of the component so a future
     * one cannot grow a second, differently-scoped copy.
     */
    public static function make(string $name = 'bank_account_id', ?Closure $propertyOf = null): EntitySelect
    {
        $propertyOf ??= fn (?Model $record): ?int => TenantScope::currentAssetId();

        return EntitySelect::make($name)
            ->label(__('admin.resources.bank_account.singular'))
            ->entity(BankAccount::class)
            // The PROPERTY clause below is a hard filter, and is meant to be: `EntitySelect`
            // resolves a submitted value's LABEL through this query, so a value it cannot label is
            // refused at validation. That is the write guard — and the property it narrows to is
            // the one the DOCUMENT answers for ({@see for()}), the selected mall only where nothing
            // answers. `acrossProperties()` is what lets the two differ: the registry's own scope
            // narrows to the switcher, and a transferred asset's bank is in the mall it was bought
            // in, so with both clauses applied the row's own value labelled nothing (measured,
            // 2026-09-12). Stated here as that method requires: this call site owns the guard,
            // and the guard is still exactly ONE property — never the portfolio.
            ->acrossProperties()
            ->modifyOptionsQuery(fn ($query, ?Model $record) => $query
                // `withTrashed()` for the same reason `is_active` is a suggestion and not a filter,
                // and it was the half the first fix missed: `OptionDisplay::pickable()` builds from
                // `$model::query()`, so the SoftDeletes global scope applies — and because the LABEL
                // lookup runs through this same query, a soft-deleted bank account made every
                // document naming it fail validation. Identical lockout, one column over. It also
                // keeps the picker agreeing with `MoneyAccount`, which reads `withTrashed()` on
                // purpose: money that moved through an account moved through it.
                ->withTrashed()
                ->when(
                    $propertyOf($record),
                    fn ($q, $id) => $q->where('asset_id', $id),
                    // Nothing answers (no tenant selected): the visible set, which is what the
                    // registry's own scope would have applied — never the whole portfolio.
                    fn ($q) => $q->when(TenantScope::visibleAssetIds(), fn ($qq, $ids) => $qq->whereIn('asset_id', $ids)),
                ))
            // `is_active` AND `deleted_at` narrow what you SEE, never what you can FIND — CLAUDE.md's
            // `->suggest()` rule, which the first cut broke by putting `is_active` in the hard
            // filter. Because the label lookup runs through that same query, retiring a bank
            // account made EVERY document naming it fail validation: an expense could no longer be
            // cancelled, re-dated or re-homed, on a field the operator cannot even edit. A closed
            // account should drop off the list for new documents and stay readable on the old ones.
            // Both conditions belong here together — deleting is just a louder way of retiring.
            ->suggest(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))
            ->helperText(__('admin.helpers.bank_account_on_document'));
    }

    /**
     * Both conditions, in one place so the asterisk and the refusal cannot disagree.
     *
     * The availability half is a bounded `exists()` against the SUGGESTED set — active, not
     * trashed, this property — because requiring an answer the picker cannot offer is a form that
     * can only refuse. It runs on the already-required branch only, so a cash receipt costs nothing.
     */
    private static function isRequired(mixed $rail): bool
    {
        if (! PaymentMethod::requiresBankAccount(is_string($rail) ? $rail : null)) {
            return false;
        }

        $assetId = TenantScope::currentAssetId();

        return $assetId !== null && BankAccount::query()
            ->active()
            ->where('asset_id', $assetId)
            ->exists();
    }
}
