<?php

namespace App\Models;

use App\Models\Concerns\IsCodeCatalogue;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * نوع مستند مورّد — a kind of compliance paper, and whether its lapse stops the vendor working.
 *
 * ## Why this is a row, and what makes it different from the other catalogues
 *
 * The other five name an accounting or routing consequence — an account, a trade, a tariff. This one
 * names a **liability decision**: {@see blocks_dispatch}. `VendorDocument::BLOCKING_TYPES` held it as
 * an array literal containing one value, with a docblock explaining the trade — an uninsured
 * contractor on the mall floor is a risk the operator carries; a lapsed tax card is a finance
 * problem and blocking emergency repairs over paperwork is worse. That reasoning is sound and it is
 * still the shipped default. What was wrong is that revising it needed a deploy, when an operator
 * dealing with a government client may be told a lapsed social-insurance certificate blocks too.
 *
 * The six shipped types are also not the world: a fire-safety contractor needs a civil-defence
 * permit, a lift company an equipment certificate, a food-court cleaner health cards. All arrive as
 * "Other" today, and an expiring "Other" tells the operator nothing about what is expiring.
 *
 * ## The fail-open direction, and the guard against it
 *
 * {@see blockingCodes()} feeds a `whereIn`, and an empty `whereIn` matches NOTHING — so a catalogue
 * that answered `[]` would make every vendor dispatchable and delete the compliance gate silently.
 * The floor is therefore applied when the table holds NO ROWS AT ALL, which is the unseeded case.
 * A table that holds rows of which none block is the operator's actual decision and is honoured.
 *
 * INACTIVE rows still block. `is_active` governs what a picker OFFERS; retiring a type must not
 * quietly un-block the certificates already filed under it.
 */
#[DeletableWhenUnused(
    blockedBy: ['documents'],
    instead: 'Deactivate it. A type that classified a filed certificate stays in the catalogue, because the vendor record, the renewal chase and the expiry notice all read its label.',
)]
// Shared: the compliance file Eltizam requires of a supplier is one policy across the portfolio.
#[PortfolioShared]
class VendorDocumentType extends Model
{
    use IsCodeCatalogue;
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'blocks_dispatch',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'blocks_dispatch' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'blocks_dispatch' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** Certificates filed under this type — what makes it undeletable once used. */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class, 'type', 'code');
    }

    protected static function catalogueMemoKey(): string
    {
        return 'vendor_document_type';
    }

    protected static function catalogueFallbackGroup(): string
    {
        return 'admin.vendors.documents.types';
    }

    /** @return array<int, string> */
    protected static function catalogueMemoSuffixes(): array
    {
        return ['blocking'];
    }

    /** @return array<int, string> */
    protected static function catalogueFloorCodes(): array
    {
        return ValueSets::allowed('vendor_documents', 'type') ?? [];
    }

    /**
     * The types whose lapse stops a vendor being dispatched.
     *
     * Read on every dispatchability check and inside the assignable-vendor picker's subquery, so it
     * is memoised and dropped on write like the rest of the catalogue.
     *
     * Three things this must get right, all fail-OPEN if it does not:
     *
     * - **the floor is applied PER CODE, not per table.** A shipped blocking type keeps blocking
     *   unless a ROW for that code says otherwise. Keying it on "the table has any row at all" was
     *   wrong in the case that will actually happen: on a box where the seeder step was missed, the
     *   operator's FIRST custom type would have made the table non-empty and silently un-blocked
     *   insurance for every vendor — a liability event caused by adding an unrelated row.
     * - **INACTIVE rows still block.** `is_active` decides what a picker OFFERS, not whether a
     *   certificate already on file counts; retiring a type must not release every uninsured
     *   contractor on the books.
     * - **an operator who unticks everything meant it.** Rows exist and none block, so nothing
     *   blocks. That is a decision, not an empty catalogue, and it is honoured.
     *
     * @return array<int, string>
     */
    public static function blockingCodes(): array
    {
        $memo = self::catalogueMemoKey().'.blocking';

        if (app()->has($memo)) {
            return app($memo);
        }

        try {
            // Every row, inactive included — see above.
            $rows = static::query()->pluck('blocks_dispatch', 'code')->all();
        } catch (\Throwable) {
            // Before the table exists.
            return VendorDocument::BLOCKING_TYPES;
        }

        $codes = [];

        foreach (VendorDocument::BLOCKING_TYPES as $shipped) {
            if (! array_key_exists($shipped, $rows) || $rows[$shipped]) {
                $codes[] = $shipped;
            }
        }

        foreach ($rows as $code => $blocks) {
            if ($blocks) {
                $codes[] = (string) $code;
            }
        }

        $codes = array_values(array_unique($codes));

        app()->instance($memo, $codes);

        return $codes;
    }

    /** Does a lapse of this TYPE stop the vendor working? Asked of one stored code. */
    public static function blocks(?string $code): bool
    {
        return $code !== null && in_array($code, static::blockingCodes(), true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'vendor_document_type');
    }
}
