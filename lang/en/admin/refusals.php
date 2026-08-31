<?php

/**
 * **Refusals** — every `DomainException` an operator can trigger, in their own language.
 *
 * On 2026-08-28 **62 of the 259 refusal messages raised by `app/Models` and `app/Services` were
 * raw English strings** (24%), and they were not spread evenly: they clustered in the money
 * immutability guards and the posting engine — exactly the sentences an Egyptian accountant working
 * the panel in Arabic reads most. `bootstrap/app.php` renders a `DomainException` as a toast, so
 * these are not developer errors; they are the app talking to a person.
 *
 * Two things were wrong and only one was visible. The message was English — and it interpolated the
 * raw COLUMN NAME, so the operator was told *"A captured payment's payment_date is immutable"*, half
 * a sentence of database schema in the middle of a business rule. Field names now resolve through
 * `admin.fields.*`, the same catalogue the forms label from, so the refusal names the field the way
 * the screen does — «تاريخ الدفع», not `payment_date`.
 *
 * Keys are grouped by what refused, not by which class raised it: an operator meets the rule, not
 * the file.
 */

return [
    'refusals' => [
        'immutable_committed_money' => 'This document is already on the books, so its :field cannot be changed — reverse it and re-enter it instead.',
        'not_a_money_document' => 'That document does not post to the general ledger, so there is nothing to reverse.',
        'immutable_lease' => 'A \':status\' lease is immutable — reverse or renew it instead.',
        'immutable_payment' => 'A captured receipt\'s :field is immutable — void the receipt and re-record it instead.',
        'immutable_invoice' => 'A finalized invoice\'s :field is immutable — void it and re-issue instead.',
        'immutable_credit_note' => 'A finalized credit note\'s :field is immutable — void it and issue a new one.',
        'immutable_vendor_bill' => 'A finalized vendor bill is immutable — cancel and re-enter it instead of editing its amount, vendor, category, or purchase link.',
        'immutable_disbursement' => 'A :status disbursement is immutable.',
        'immutable_cheque' => 'A :status post-dated cheque is immutable.',
        'invoice_no_return_to_draft' => 'An issued invoice cannot be returned to draft — void it or issue a credit note instead.',
        'credit_note_no_return_to_draft' => 'A finalized credit note cannot be returned to draft — void it and issue a new one instead.',
        'credit_note_still_applied' => 'Cannot delete a credit note whose credit is still applied — reverse the application first, then delete.',
        'bill_pr_other_property' => 'The linked purchase request belongs to a different property than the bill.',
        'cheque_invoice_other_property' => 'The linked invoice belongs to a different property than the cheque.',
        'cheque_invoice_other_tenant' => 'The linked invoice belongs to a different tenant than the cheque.',
        'pr_warehouse_other_property' => 'The selected warehouse belongs to a different property than the request.',
        'pr_locked_after_approval' => 'A purchase request cannot change its property, warehouse, or justification after it has been approved.',
        'cf_model_not_extensible' => '[:model] is not a record type that carries custom fields.',
        'cf_bad_key' => '[:key] is not a usable field key — use lower-case letters, digits and underscores, starting with a letter.',
        'cf_key_immutable' => 'A custom field\'s key cannot change — every value already recorded is stored under it. Rename the label instead.',
        'cf_choice_needs_option' => 'A choice field needs at least one choice.',
        'cheque_deposit_state' => 'Only a held (or re-presented, bounced) cheque can be deposited.',
        'cheque_clear_state' => 'Only a held or deposited cheque can be cleared.',
        'cheque_bounce_state' => 'Only a held or deposited cheque can bounce.',
        'cheque_cleared_cancel' => 'A cleared cheque cannot be cancelled — void its payment instead.',
        'payroll_deductions_exceed_gross' => 'Payroll deductions exceed gross salaries; fix the amounts before approving.',
        'bill_cancel_has_payments' => 'Cannot cancel a bill that has payments. Void them first (Payments → Void payment), then cancel the bill.',
        'payment_void_state' => 'Only a received receipt can be voided.',
        'invoice_void_draft' => 'A draft invoice is deleted, not voided — voiding is for issued invoices.',
        'invoice_void_eta_filed' => 'This invoice was filed with the Tax Authority and cannot be voided internally — issue a credit note instead.',
        'invoice_void_has_cash' => 'Cannot void an invoice with captured payments — void the receipt first, then void the invoice.',
        'write_off_positive' => 'A write-off amount must be greater than zero.',
        'disb_needs_finalised_run' => 'A disbursement can only be scheduled against a finalised owner statement.',
        'disb_amount_positive' => 'The disbursement amount must be positive.',
        'disb_exceeds_remaining' => 'The amount exceeds the :remaining still owed to the owner.',
        'disb_approve_state' => 'Only a scheduled disbursement can be approved.',
        'disb_approve_tier' => 'You are not authorised to approve a disbursement of this amount.',
        'disb_approve_tier_lost' => 'You lack the approval tier that was required when this was scheduled.',
        'disb_pay_state' => 'Only an approved disbursement can be marked paid.',
        'disb_paid_no_cancel' => 'A paid disbursement cannot be cancelled.',
        'run_finalise_state' => 'Only a draft owner-statement run can be finalised.',
        'run_revise_state' => 'Only a finalised owner-statement run can be revised.',
        'map_missing' => 'No account mapping for the \':role\' posting role.',
        'map_account_missing' => 'Account mapping \':role\' points to an account that no longer exists.',
        'map_account_not_postable' => 'Account mapping \':role\' points to a summary account (:code) that cannot be posted to.',
        'je_post_voided' => 'A voided journal entry cannot be posted.',
        'je_void_state' => 'Only a posted journal entry can be voided.',
        'je_needs_two_lines' => 'A journal entry needs at least two lines.',
        'je_line_unknown_account' => 'Line :line: unknown ledger account.',
        'je_line_summary_account' => 'Line :line: account :code is a summary account and cannot be posted to.',
        'je_line_inactive_account' => 'Line :line: account :code is inactive.',
        'je_line_negative' => 'Line :line: amounts cannot be negative.',
        'je_line_two_sided' => 'Line :line: a line is either debit OR credit, not both.',
        'je_line_empty' => 'Line :line: a line must have a debit or a credit amount.',
        'je_zero_amount' => 'A journal entry must move a non-zero amount.',
        'je_unbalanced' => 'Journal entry is not balanced: debit :debit does not equal credit :credit.',
        'je_unknown_account' => 'A journal line references an unknown ledger account.',
        'je_void_no_open_period' => 'Cannot void: neither the original entry\'s period nor the current period is open. Reopen a period first.',
        'je_no_period' => 'No accounting period is defined for :date.',
        'je_period_closed' => 'Accounting period :month is closed — nothing can be posted into it.',
        // ── Added 2026-08-30 — nine refusals that were still raw English ────────────────────
        // These render as a toast to whoever pressed the button, so they are the app talking to a
        // person, not a developer error. Two of them also interpolated a raw status/column value.
        'cam_basis_locked_after_billing' => 'The CAM recovery basis cannot change once an allocation has been billed — void the billed allocations first.',
        'vendor_not_dispatchable' => 'Vendor :vendor cannot be dispatched: it is blacklisted or inactive, or its insurance certificate has lapsed.',
        'overlapping_charge_schedule' => 'Lease :reference has overlapping charge-schedule rows for :period (:detail). Exactly one row per charge type may cover a period — close the earlier row before the later one starts.',
        'write_off_not_live' => 'Invoice :number is :status — only a live receivable can be written off.',
        'write_off_nothing_outstanding' => 'Invoice :number has nothing outstanding — there is no debt to write off.',
        'write_off_already_full' => 'Invoice :number is already fully written off (:written of :outstanding).',
        'write_off_exceeds_remaining' => 'Cannot write off :amount against invoice :number: only :remaining is left to write off.',
        'write_off_exceeds_remaining_partial' => 'Cannot write off :amount against invoice :number: only :remaining is left to write off (:written of :outstanding already written off).',
        'owner_statement_finalised_exists' => 'A finalised statement already exists for this property and period — revise it instead of regenerating.',
        'owner_statement_has_active_disbursements' => 'This run cannot be revised while it has active disbursements — cancel the scheduled or approved payouts first. If the owner has already been paid, correct the difference in the next period rather than revising the paid statement.',
        'lease_option_not_open' => "This option is :status — only an open option can be exercised.",
        'cam_cap_term_incomplete' => 'A :type CAM cap needs :fields. Without them the cap resolves to nothing and the tenant is billed in full, while the lease still shows a cap term.',
    ],
];
