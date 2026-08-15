<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One line of a procurement request (FR-PROC-01: "item(s), quantity, and justification").
 *
 * A line is EITHER a catalog item (which becomes stock on receipt, FR-PROC-04) OR free text for
 * something we do not stock — the module's own preamble covers "spare parts, consumables, and
 * services", and a service is not stock. Never both: the two would disagree about what was bought.
 */
#[DeletionAllowed(reason: 'parent-managed: edited while the request is still a draft')]
#[PropertyOwned(via: 'request')]
class PurchaseRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id', 'inventory_item_id', 'description',
        'quantity', 'unit_cost', 'line_value', 'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'line_value' => 'decimal:2',
    ];

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** The receipt this line produced (FR-WH-02's "linked procurement reference", from the line's side). */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    /** Does this line become stock when it arrives? */
    public function isStockable(): bool
    {
        return $this->inventory_item_id !== null;
    }

    public function label(): string
    {
        return $this->isStockable()
            ? trim(($this->item?->sku ?? '').' — '.($this->item?->name ?? ''), ' —')
            : (string) $this->description;
    }

    protected static function booted(): void
    {
        // ── The lines are settled once the request is approved ─────────────────────────────────
        // This is an approval-ladder hole, not a balance one. `recomputeTotal()` re-derives
        // `total_value` from Σ lines on every line write, but deliberately freezes
        // `required_permission` once the request leaves `requested` — so the record keeps saying
        // who was SUPPOSED to sign it off (the F-104 fix, and correct). With the lines unfrozen:
        //
        //   raise 5,000 → tier_1 → a supervisor approves it, correctly;
        //   add a 500,000 line to the approved request;
        //   total_value becomes 505,000 while required_permission still reads tier_1.
        //
        // The mall is committed two tiers above what anyone with the authority signed off, and the
        // record asserts a supervisor approved it. The mechanism whose whole job is to fail closed,
        // failing open.
        //
        // The rule already existed as `PurchaseRequestLinesRelationManager::editable()`
        // (`status === requested`, gating the add/edit/delete actions) — a property of that screen.
        // Here it covers the import / console / service / future-screen paths too, mirroring the
        // header freeze `PurchaseRequest::updating` already applies to asset / warehouse /
        // justification: the lines are as much of what the approval signed off on as the warehouse
        // the goods land in. (Module 29 close-out, 2026-08-11.)
        // Frozen: WHAT was approved and at what price. `stock_movement_id` is deliberately absent —
        // receiving goods stamps the line it fulfilled, on a request that is by definition past
        // `requested`, and freezing the whole row would make the module unreceivable. (Caught by
        // running the suite: the first cut of this guard broke 18 tests across receipt and GRNI
        // clearing, which is the difference between "the approval is settled" and "the row is".)
        $commercial = ['inventory_item_id', 'description', 'quantity', 'unit_cost', 'line_value'];

        $assertRequestIsOpen = function (self $line, bool $isDelete = false) use ($commercial) {
            $request = $line->request;

            if ($request === null || $request->status === PurchaseRequest::STATUS_REQUESTED) {
                return;
            }

            // An existing line may still be STAMPED by fulfilment; it may not be re-priced.
            // A DELETE is always a change to what was approved, and nothing is dirty on one, so it
            // cannot be waved through by the same test.
            if (! $isDelete && $line->exists && ! $line->isDirty($commercial)) {
                return;
            }

            throw new \DomainException(__('admin.purchase_requests.errors.lines_frozen_after_approval'));
        };

        static::saving(fn (self $line) => $assertRequestIsOpen($line));
        static::deleting(fn (self $line) => $assertRequestIsOpen($line, isDelete: true));

        static::saving(function (self $line) {
            if ($line->inventory_item_id === null && blank($line->description)) {
                throw new InvalidArgumentException('A purchase line must name a catalog item or describe what is being bought.');
            }

            if ($line->inventory_item_id !== null && filled($line->description)) {
                throw new InvalidArgumentException('A purchase line is either a catalog item or free text, not both.');
            }

            if ((float) $line->quantity <= 0) {
                throw new InvalidArgumentException('A purchase line quantity must be greater than zero.');
            }

            // A negative cost would make a line *reduce* what the request is worth — and the total
            // is what the approval tier is judged on (FR-PROC-02).
            if ((float) $line->unit_cost < 0) {
                throw new InvalidArgumentException('A purchase line unit cost cannot be negative.');
            }

            // A line that becomes STOCK needs a real cost, and it has to be caught here rather
            // than at receipt.
            //
            // Stock that moves at zero value posts nothing to the GL (InventoryMovementJournalizer
            // returns null for a zero-value movement), so inventory would inflate while the money
            // never appears — which is why the ad-hoc receipt path has always required
            // minValue(0.01). This line will BECOME such a receipt, so the same rule applies.
            //
            // Failing at request time is the point: allowing 0 here let a request be raised,
            // approved and ORDERED, and only then die at receipt — after the mall had committed
            // to buy it. A service line is exempt: it never becomes stock, so there is nothing to
            // value (a free warranty visit is a real thing to record).
            if ($line->inventory_item_id !== null && (float) $line->unit_cost <= 0) {
                throw new InvalidArgumentException(
                    'A purchase line for a catalog item needs a unit cost — stock that arrives at zero value posts nothing to the books.'
                );
            }

            // Derived on every write path, not just the form.
            $line->line_value = round((float) $line->quantity * (float) $line->unit_cost, 2);
        });

        // The parent's total is what someone approves, so it can never lag its lines.
        static::saved(fn (self $line) => $line->request?->recomputeTotal());
        static::deleted(fn (self $line) => $line->request?->recomputeTotal());
    }
}
