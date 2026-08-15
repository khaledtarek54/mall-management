<?php

namespace App\Support\Attributes;

use App\Support\PostingDateGuards;
use Attribute;

/**
 * This GL source's posting date is derived by the system and cannot be typed by an operator, so
 * there is nothing to guard. `$reason` must say exactly how the date is set and which inputs are
 * operator-reachable.
 *
 * **Write this one carefully.** An exemption asserting a safety property that does not hold is
 * worse than no entry at all, because the gate then reports coverage it does not have. That is not
 * hypothetical: `DepositApplication` claimed to be system-dated while the service stamped a
 * parameter taken straight off an unconstrained DatePicker, and a settlement back-dated into a
 * closed March netted 120,000 of arrears off a deposit, showed "Saved ✓", and left a tie-out gap of
 * exactly that much when the post was refused inside the best-effort sync job.
 *
 * The gate cannot catch this class of error for you — it checks declarations, and the offending
 * field may live on another resource under another name. Verify the claim against the code.
 *
 * @see PostingDateGuards
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PostingDateNotOperatorTyped
{
    public function __construct(public string $reason) {}
}
