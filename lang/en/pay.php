<?php

return [
    'title' => 'Pay invoice :number',
    'secure_payment' => 'secure payment',

    'amount_due' => 'Amount due',
    'amount_paid' => 'Amount paid',
    'amount' => 'Amount',

    'invoice' => 'Invoice',
    'billed_to' => 'Billed to',
    'period' => 'Period',
    'due' => 'Due',

    'pay_with_card' => 'Pay with card',
    'unavailable' => 'Online payment is temporarily unavailable. Please try again later.',
    'secured_by' => 'Secured by Paymob',
    'redirect_note' => 'You will be redirected to a secure payment page.',

    'states' => [
        'paid' => ['title' => 'Payment successful', 'msg' => 'Your payment has been received. Thank you.'],
        'failed' => ['title' => 'Payment failed', 'msg' => 'The payment did not go through. You can try again.'],
        'processing' => ['title' => 'Processing payment', 'msg' => 'We are confirming your payment. This page will update automatically.'],
        'unpaid' => ['title' => 'Invoice not paid', 'msg' => 'This invoice has not been paid yet.'],
    ],

    'open_app' => 'Open the app to confirm',
    'try_again' => 'Try again',
];
