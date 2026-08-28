<?php

/**
 * The vendor portal's own language file — a contractor is not an operator and not a tenant, so its
 * wording lives apart from `admin.*` and `portal.*` rather than borrowing either.
 */

return [
    'notifications' => [
        'dispatched_title' => 'A new job for you',
        'dispatched_body' => ':reference — :title, at :property.',
    ],
    'jobs' => [
        'quote' => 'Send a quote',
        'quote_confirm' => 'Send a price for this job. The operator approves or declines it; nothing is committed until they do.',
        'quote_supplementary' => 'Extra work on top of a price already agreed',
        'quote_supplementary_helper' => 'Leave this off for the whole price of the job. Switch it on only when a price is already agreed and you have found more work.',
        'quote_labour' => 'Labour',
        'quote_material' => 'Materials',
        'quote_service' => 'Subcontract / hire',
        'quote_amounts_helper' => 'Net of tax. At least one must be more than zero.',
        'quote_scope' => 'What the price covers',
        'quote_sent' => 'Quote sent.',
        'evidence' => 'Photos',
        'evidence_helper' => 'Photographs of the finished work. New photos are added to any already attached.',
        'evidence_attached' => 'Photos attached.',
        'update' => 'Add an update',
        'update_body' => 'Update',
        'update_posted' => 'Update posted.',
        'singular' => 'Job',
        'plural' => 'My jobs',
        'reference' => 'Reference',
        'title' => 'Job',
        'property' => 'Property',
        'priority' => 'Priority',
        'scheduled_for' => 'Scheduled',
        'status' => 'Status',
        'accepted_at' => 'Accepted',
        'not_accepted' => 'Not accepted yet',
        'accept' => 'Accept',
        'accept_confirm' => 'Accepting confirms you have received this job and starts the agreed response time.',
        'accepted' => 'Job accepted.',
        'accept_closed' => 'This job is closed — there is nothing left to accept.',
        'empty_heading' => 'No jobs yet',
        'empty_description' => 'Jobs appear here as soon as they are dispatched to you.',
    ],
];
