<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Egyptian Tax Authority (ETA) e-Invoicing
    |--------------------------------------------------------------------------
    |
    | Egypt mandates e-invoice submission to ETA for B2B transactions. This
    | config drives both the test-environment integration and the demo-friendly
    | mock mode.
    |
    | When `enabled` is false, the "Submit to ETA" admin action is hidden.
    | When `enabled` is true but `mock` is also true, submissions run through a
    | stub client that returns a canned valid response — useful for demos before
    | real ETA test credentials land. Flip `mock` to false once credentials are
    | wired into the env to hit ETA's actual preproduction endpoint.
    |
    */

    'enabled' => env('ETA_ENABLED', true),
    'mock' => env('ETA_MOCK', true),

    'endpoint' => env('ETA_ENDPOINT', 'https://api.preprod.invoicing.eta.gov.eg'),
    'auth_endpoint' => env('ETA_AUTH_ENDPOINT', 'https://id.preprod.eta.gov.eg/connect/token'),

    'client_id' => env('ETA_CLIENT_ID'),
    'client_secret' => env('ETA_CLIENT_SECRET'),

    /*
    | Seller (issuer) identity — populates every submitted document.
    | Update once the operator's commercial register / TIN is finalized.
    */
    'issuer' => [
        'tax_registration_number' => env('ETA_ISSUER_TRN', '100000000'),
        'type' => env('ETA_ISSUER_TYPE', 'B'), // B = business
        'name' => env('ETA_ISSUER_NAME', 'Atriom Demo Operator'),
        'address' => [
            'country' => env('ETA_ISSUER_COUNTRY', 'EG'),
            'governate' => env('ETA_ISSUER_GOVERNATE', 'Giza'),
            'region_city' => env('ETA_ISSUER_CITY', '6th of October City'),
            'street' => env('ETA_ISSUER_STREET', 'Wahat Road'),
            'building_number' => env('ETA_ISSUER_BUILDING', '1'),
        ],
    ],
];
