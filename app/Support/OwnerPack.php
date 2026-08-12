<?php

namespace App\Support;

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\RentRoll;

/**
 * What an owner receives at month end, in one file (RP-08).
 *
 * Module 32 already issues the owner STATEMENT — the money owed to Jawad for a period. What an owner
 * actually asks for next is the evidence behind it: how the mall traded, who is in it, and who has
 * not paid. Today that means an operator opening five reports, setting the property on each,
 * exporting each, and attaching five files to an email — per owner, per month.
 *
 * ## The whole risk here is scope
 *
 * A pack is assembled by the OPERATOR and sent to ONE owner. Every report in it must be rendered for
 * that owner's properties alone: a portfolio-wide income statement in Jawad's pack shows him another
 * landlord's revenue, and it is a leak that looks exactly like a working feature — the file opens,
 * the numbers are real, and nobody notices they are the wrong ones.
 *
 * So {@see REPORTS} is an allow-list rather than "every deliverable report". What actually enforces
 * the scope is that the pack is rendered **as the owner** — `TenantScope` derives visibility from
 * the authenticated user's properties — and tenure comes from `currentOwnedAssets()`, so a former
 * owner's pack stops at what they still hold. See `BuildOwnerPackService`, which documents which of
 * its two scoping mechanisms is the load-bearing one and which is presentation.
 *
 * ## Why these five and not the other fifteen
 *
 * An owner is a landlord, not an operator. They are owed a trading picture and the receivable
 * position; they are not owed the operator's procurement, payroll or work-order backlog — those are
 * Eltizam's business, and sending them invites questions the management agreement does not make the
 * owner a party to.
 */
class OwnerPack
{
    /**
     * The reports in an owner's pack, and why each belongs to the OWNER rather than the operator.
     *
     * @var array<class-string, string>
     */
    public const REPORTS = [
        IncomeStatement::class => 'How the property traded in the period — the statement of the money the owner statement then settles.',
        BalanceSheet::class => 'What the property holds and owes at the period end; an owner reading only a P&L cannot see a deposit balance they are ultimately liable for.',
        CashFlow::class => 'Trading profit is not cash, and an owner asking "where is my money" is asking this rather than the income statement.',
        RentRoll::class => 'Who is in the building, on what terms, at what rent — the schedule an owner checks the statement against line by line.',
        ArAging::class => 'What is owed and how late. An owner is entitled to know that this month was collected as well as billed, and it is the single figure a distribution is most often queried against.',
    ];

    /**
     * Deliberately excluded, because the omissions are the reasoning.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'procurement / vendor spend' => "Eltizam's operating detail, not the owner's. The management agreement makes the owner a party to the RESULT, not to how the operator buys.",
        'payroll' => 'Staff pay is the operator\'s own confidential data and is not attributable to one owner even where it is recharged.',
        'work orders / maintenance backlog' => 'Operational, and an owner reading it out of context turns a normal queue into an escalation. The owner-requests module is where an owner raises what they see.',
        'tenant sales declarations' => 'Commercially confidential to the RETAILER. It reaches the owner only through the percentage rent it produces, which is already on the statement.',
    ];

    /** @return array<int, class-string> */
    public static function reports(): array
    {
        return array_keys(self::REPORTS);
    }
}
