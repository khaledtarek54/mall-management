<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Egyptian Tax Authority e-invoicing toggles. eta_enabled gates the
 * Submit-to-ETA actions + the EtaCompliance dashboard widget. eta_mock
 * controls whether the submission service uses the stubbed Valid response
 * or hits the real preprod/production endpoint.
 */
class EtaSettings extends Settings
{
    public bool $enabled = true;

    public bool $mock = true;

    public string $issuer_name = 'Atriom Demo Operator';

    public string $issuer_tax_registration_number = '123-456-789';

    public static function group(): string
    {
        return 'eta';
    }
}
