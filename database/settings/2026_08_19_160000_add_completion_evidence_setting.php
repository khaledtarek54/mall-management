<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Require a photograph (or any attachment) before a work order can be marked done — OFF.
 *
 * Off is the honest default and not timidity. Turning this on mid-flight would refuse the next
 * completion every engineer attempts, on jobs they have already finished, with an error about a
 * rule nobody told them about — and the reliable outcome of that is a photograph of a wall, taken
 * to satisfy the gate. Evidence collected to clear a validation is worse than no evidence, because
 * it looks like proof.
 *
 * So the sequence is: attachments exist first, the crew gets used to adding them, and the operator
 * switches the requirement on when the habit is real. Same conservative shipping posture as
 * straight-line rent (`accounting.straight_line_enabled`), the NSF fee (amount 0 = off) and the
 * gratuity accrual.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sla.require_completion_evidence', false);
    }

    public function down(): void
    {
        $this->migrator->delete('sla.require_completion_evidence');
    }
};
