<?php

namespace App\Rules;

use App\Models\LedgerAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A chart-of-accounts code in a defined range (leading digit 1-5) must carry the
 * matching account nature. Surfaces the same guard as the model as a clean inline
 * form error. Custom ranges (6-9/0) are unconstrained.
 */
class AccountCodeMatchesType implements ValidationRule
{
    public function __construct(private ?string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $expected = LedgerAccount::expectedTypeForCode((string) $value);

        if ($expected !== null && $this->type !== null && $this->type !== $expected) {
            $fail(__('admin.validation.account_code_type_mismatch', [
                'digit' => substr((string) $value, 0, 1),
                'expected' => __("admin.enums.ledger_account_type.{$expected}"),
            ]));
        }
    }
}
