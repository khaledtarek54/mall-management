<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A contractor gets a login** — step 2 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`.
 *
 * `VendorContact` already held the person (name, email, phone, per vendor). It gains only what a
 * login needs. Deliberately modelled on `TenantUser`, which solved this exact problem: a company
 * with several people, its own guard, its own panel, every query scoped to the company. Reusing the
 * shape means reusing its scoping and its failure modes rather than discovering them again.
 *
 * **`is_portal_user` is the switch, and it is OFF for every existing row.** A contact is somebody's
 * phone number until an operator decides otherwise; flipping the column on for the ~existing rows
 * would hand accounts to people who never asked for one and whose email may be a shared inbox.
 *
 * **There is deliberately no `is_admin` twin.** The tenant portal distinguishes a writer from
 * read-only staff; a contractor's contacts are few and all of them act, so every portal contact may
 * act. Fewer states, fewer bugs — §4 of the design states it as the one intended difference.
 *
 * **`email` stays nullable and carries no unique index.** Two contractors can legitimately share a
 * switchboard address. Uniqueness is required only among rows that can actually SIGN IN, which is a
 * partial-index question MySQL cannot express portably — so the rule lives on the model. See the
 * comment in `up()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_contacts', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->boolean('is_portal_user')->default(false)->after('is_primary');
            $table->timestamp('last_login_at')->nullable()->after('is_portal_user');
            $table->rememberToken();
        });

        // **No unique index here, deliberately, and the first draft of this migration got it wrong.**
        // A unique on `(email, is_portal_user)` looks right and is not: two NON-login contacts
        // sharing a switchboard address both carry `is_portal_user = 0`, so the index would refuse
        // the second — a data rule invented by the login feature and imposed on rows that never
        // sign in. MySQL exempts NULLs from a unique index but not `false`.
        //
        // What is actually wanted is "unique among rows that can sign in", which MySQL cannot
        // express without a stored generated column — and a generated column behaves differently on
        // SQLite, which is what the suite runs on, so it would be green here and untested there.
        // The rule lives on the model instead (`VendorContact::booted`), which is the single choke
        // point every path shares — form, importer, console, API — and is the same reasoning
        // `GuardsPostingDate` gives for sitting on the model rather than in a service.
    }

    public function down(): void
    {
        Schema::table('vendor_contacts', function (Blueprint $table) {
            $table->dropColumn(['password', 'is_portal_user', 'last_login_at', 'remember_token']);
        });
    }
};
