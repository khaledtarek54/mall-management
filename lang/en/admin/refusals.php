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
        'stock_movement_quantity_zero' => 'A receipt or a consumption has to move something, so its quantity cannot be zero. Type the number of units that actually moved — a correction that nets to nothing is recorded as an Adjustment, which is the one movement allowed to be zero.',
        'stock_movement_has_no_value' => ':item carries no unit cost, and none has ever been received at one either — so moving it would change the stock on hand and post nothing to the general ledger. Set a unit cost on the item, or record a receipt that carries one, and try again.',
        'stock_transfer_quantity_zero' => 'A transfer has to move something — type how many units are going to the other store.',
        'stock_transfer_same_store' => ':store is both the source and the destination, so nothing would move. Pick a different destination — to correct a quantity inside one store, use Adjust instead.',
        'stock_transfer_across_properties' => 'Stock can only be transferred between stores in the same property: a transfer posts no journal entry, so across properties one mall\'s books would keep stock it no longer holds while the other\'s would not show what it now has. Adjust it out of :from and receive it at :to instead, so each property\'s books record the value leaving and arriving.',
        'holdover_rate_below_floor' => 'A holdover is priced as a percentage of the last rent, so it cannot be less than :min% — below that, staying on costs the tenant less than renewing. Type at least :min% here, or raise the portfolio default under Settings → Billing. A genuinely reduced wind-down rent is a rent change or a relief, not a holdover.',
        'work_permit_issued_is_fixed' => 'This permit has been issued, so what it authorises is fixed — people may already be working under it. Cancel it and issue a corrected one.',
        'invoice_void_has_write_off' => 'Invoice :number carries a write-off. Reverse the write-off first — voiding on top of it would leave the bad-debt loss standing against a document that no longer exists, and drive receivables negative.',
        'cheque_deposit_future' => 'A cheque cannot be lodged on a future date — you either handed it to the bank or you did not. Use the day you actually presented it.',
        'payroll_approval_in_progress' => 'Another approval for this month is already running. Wait for it to finish, then reload this run before approving.',
        'announcement_window_closed' => 'This notice ends :expired, which has already passed — every tenant would be sent to a notice they cannot open, and a sent notice cannot be corrected. Move the end date forward first.',
        'tenant_request_needs_a_unit' => 'This account has no shop to report against — a lease that has ended, or a purchase not yet handed over. Ask the operator to check the record.',
        'bank_account_is_a_posting_role' => 'Account :account is a posting role — it is where documents that name no bank account land. Pointing a real bank at it merges the two, so every unattributed entry would be offered when reconciling this bank. Give this account its own chart leaf.',
        'bank_account_shares_a_chart_account' => 'That chart account already belongs to :other. Two banks on one account means reconciling either one offers the other\'s entries as matches — a wrong match that still balances. Each bank needs its own.',
        'bank_statement_rehomed_after_matching' => 'This statement already explains :count of its lines with postings in :account\'s ledger. Moving it to another bank would leave those matches explaining one bank with another bank\'s money — and both reconciliations would still balance, so nothing would say so. Unmatch those lines first on the Lines tab, or import the file again under the right account.',
        'payment_credit_overdrawn' => 'This receipt cannot be re-allocated in full: :shortfall of it has already been applied to another invoice as on-account credit. Reverse that application first.',
        'credit_note_void_is_terminal' => 'A voided credit note is closed for good — its ledger entry has been reversed. Raise a new note if credit is due.',
        'credit_note_status_is_an_act' => 'A credit note is issued or voided by its own button, not by picking a status. Those acts check the accounting period, post to the ledger and record why — use Issue or Void.',
        'cam_carve_out_needs_a_stated_share' => 'Lease :lease is carved out of the share denominator but its contract names no share. A lease outside the divisor has no area basis left to derive one from — state its share, or put it back in the denominator.',
        'cam_pool_has_unbilled_allocations' => 'This pool still has :count allocation(s) nobody has billed. A year is not reconciled while a tenant\'s share has not been acted on — bill them, or void the ones that should not be billed.',
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
        'invoice_write_off_is_an_act' => 'An invoice cannot be marked written off by editing its status — use the Write off action, which records the reason and posts the bad debt.',
        'invoice_ar_already_relieved' => 'Invoice :number is \':status\' — its receivable has already been relieved, so no further settlement can be applied to it.',
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
        'cf_model_immutable' => 'A custom field cannot be moved to another record type — the record type and the key together are the address of every answer already recorded, so moving it would leave those answers on records that no longer offer or read the field. Add the field on the other record type instead and switch this one off: the answers already given keep their label either way.',
        'cf_choice_needs_option' => 'A choice field needs at least one choice.',
        'cf_key_taken' => 'This record type already has a field keyed [:key] — ":label". A key is the address of every value recorded under it, so two fields cannot share one. Give this field a different key, or use the existing one and rename its label.',
        'cf_key_taken_inactive' => 'This record type already has a field keyed [:key] — ":label" — and it is switched off. Turn it back on rather than adding a second: every answer already recorded sits under that key, and a duplicate could never read them. Otherwise give this field a different key.',
        'cheque_deposit_state' => 'Only a held (or re-presented, bounced) cheque can be deposited.',
        'cheque_clear_state' => 'Only a held or deposited cheque can be cleared.',
        'cheque_bounce_state' => 'Only a held or deposited cheque can bounce.',
        'cheque_cleared_cancel' => 'A cleared cheque cannot be cancelled — void its payment instead.',
        'payroll_deductions_exceed_gross' => 'Payroll deductions exceed gross salaries; fix the amounts before approving.',
        'bill_cancel_has_payments' => 'Cannot cancel a bill that has payments. Void them first (Payments → Void payment), then cancel the bill.',
        'payment_allocations_unstable' => "This payment's allocations kept changing while it was being locked. Try again in a moment.",
        'payment_void_state' => 'Only a received receipt can be voided.',
        'payment_method_moves_no_money' => 'A payment rail has to move money in at least one direction. With both "Accepts money in" and "Pays money out" switched off, no picker will ever offer it and no document can name it. Switch one back on — or leave both on and untick "Active", which is how a rail is retired without changing the documents that already name it.',
        'invoice_void_eta_filed' => 'This invoice was filed with the Tax Authority and cannot be voided internally — issue a credit note instead.',
        'invoice_void_has_cash' => 'Invoice :number carries captured payments, so it cannot be voided — void the receipt first, then void the invoice.',
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
        'period_reopen_year_is_closed' => 'Period :month cannot be reopened on its own: the year-end closing entry for FY:year still stands, so anything posted into :month would never reach retained earnings. Use “Reopen year” at the top of this screen — it voids the closing entry and unlocks every month — then close the year again once the corrections are in.',
        // ── Added 2026-08-30 — nine refusals that were still raw English ────────────────────
        // These render as a toast to whoever pressed the button, so they are the app talking to a
        // person, not a developer error. Two of them also interpolated a raw status/column value.
        'cam_pool_identity_locked_after_billing' => 'A pool\'s property, year and code cannot change once an allocation has been billed — the tenants\' recovery invoices name them. Void the billed allocations first, or create a separate pool for the other year.',
        'cam_basis_locked_after_billing' => 'The CAM recovery basis cannot change once an allocation has been billed — void the billed allocations first.',
        'cam_sync_locked_after_billing' => 'This pool cannot be re-sourced from the ledger once an allocation has been billed — the tenants\' recovery invoices were worked out from the figures it holds now. Void the billed allocations first, then sync again.',
        'vendor_not_dispatchable' => 'Vendor :vendor cannot be dispatched: it is blacklisted or inactive, or its insurance certificate has lapsed.',
        'overlapping_charge_schedule' => 'Lease :reference has overlapping charge-schedule rows for :period (:detail). Exactly one row per charge type may cover a period — close the earlier row before the later one starts.',
        'write_off_not_live' => 'Invoice :number is :status — only a live receivable can be written off.',
        'write_off_nothing_outstanding' => 'Invoice :number has nothing outstanding — there is no debt to write off.',
        'write_off_already_full' => 'Invoice :number is already fully written off (:written of :outstanding).',
        'write_off_already_reversed' => 'This write-off has already been reversed.',
        'write_off_reverse_no_open_period' => 'Cannot reverse this write-off: neither its own accounting period nor the current one is open, so the ledger entry could not be voided. Reopen a period first.',
        'write_off_exceeds_remaining' => 'Cannot write off :amount against invoice :number: only :remaining is left to write off.',
        'write_off_exceeds_remaining_partial' => 'Cannot write off :amount against invoice :number: only :remaining is left to write off (:written of :outstanding already written off).',
        'owner_statement_finalised_exists' => 'A finalised statement already exists for this property and period — revise it instead of regenerating.',
        'owner_statement_has_active_disbursements' => 'This run cannot be revised while it has active disbursements — cancel the scheduled or approved payouts first. If the owner has already been paid, correct the difference in the next period rather than revising the paid statement.',
        'lease_option_not_open' => 'This option is :status — only an open option can be exercised.',
        'cam_cap_term_incomplete' => 'A :type CAM cap needs :fields. Without them the cap resolves to nothing and the tenant is billed in full, while the lease still shows a cap term.',
        // ── SW-144 — retiring a chart account that money is still routed to ─────────────────
        // The deletion guard on LedgerAccount tells the operator to deactivate an account instead
        // of deleting it; doing that under a bank account, a payment rail or an expense category
        // silently re-homes every entry ever posted through it onto the generic floor account.
        'ledger_account_still_routes_money' => 'Account :code cannot be retired while money is still routed through it — :referrers. Retiring it would re-post every entry already made through it onto the generic fallback account on the next ledger sync, and the bank reconciliation would stop finding them. Point :referrers at another account first — or clear the account it names — and then retire this one.',
        'money_route_bank_account' => 'bank account “:name”',
        'money_route_payment_method' => 'payment method “:name”',
        'money_route_expense_category' => 'expense category “:name”',
        // ── Added 2026-09-04 (SW-119) ───────────────────────────────────────────────────
        'document_template_duplicate_block' => 'There is already a wording block for :block on this scope. One row per block per property — the house default holds one, and each mall may override it with one — because two rows for one slot is a tie the document would break by whichever happened to be saved first, and that is nobody\'s decision. Open the row that exists and edit it, or switch it off before writing another.',
        // ── Added 2026-09-04 (SW-068) ───────────────────────────────────────────────────
        'recurring_schedule_contract_ended' => 'This schedule names the contract ":contract", which has ended (:ends), so it would never book again. Link a contract that is still running, or clear the contract and give the schedule its own end date.',
        'recurring_schedule_never_books' => 'This schedule would never book a single cost: its first booking falls on :first, which is after the end date :ends — so the nightly run would skip it for ever and nothing on any screen would say so. Move the end date past :first, bring the start date forward, change the day of the month, or clear the end date so it runs until you switch it off.',
        // ── Added 2026-09-05 (tester's board) ──────────────────────────────────────────
        'asset_owner_already_attached' => ':name already owns a share of this property, and one person holds one share here — two rows would be two answers to "how much of it is theirs", and the owner statement divides by exactly that number. Open the row that exists and change the percentage or the dates, or record the sale by setting an end date on it.',
        'asset_staff_already_attached' => ':name is already assigned to this property. Open the row that exists to change their title or their dates — a second assignment would say two different things about one person\'s tenure, and the access this grants is decided by whether ANY live row exists.',
        'department_member_already_attached' => ':name is already a member of this department. Open the row that exists to change their role or their dates — a second membership would say two different things about one person, and it is membership that grants the department\'s role.',
        'unit_area_exceeds_property' => 'A unit of :area m² will not fit in :property, which states :leasable m² of leasable area in total. Correct the unit\'s area, or correct the property\'s leasable area if the building is bigger than recorded — a unit larger than the mall takes more than a 100% share of every recovery apportioned by area, and under-charges every other tenant.',
    ],
];
