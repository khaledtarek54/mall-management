<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vendors.email` and `vendor_contacts.email` widen from 200 to 255.
 *
 * Found by sweeping every door onto a string column against the column itself: `VendorImporter`
 * validated an address at `max:255` into a `varchar(200)`. The row passes validation and the
 * INSERT then refuses it — a raw "Data too long for column" in `failed_import_rows` instead of a
 * field-level message, or, on a connection that is not strict, a silently truncated address that
 * the operator only discovers when a purchase order bounces.
 *
 * The importer is the door that was right. **255 is this application's own convention for an email
 * address** — `users`, `tenants` and `tenant_users` all hold 255 — and RFC 5321 caps a path at 254,
 * so 200 was an arbitrary narrowing on the two supplier tables and nowhere else. Narrowing the
 * importer to match would have satisfied the rule while refusing a migrating operator's real file,
 * which is the one thing an importer must not do.
 *
 * `vendor_contacts.email` travels with it deliberately: it is the login for the contractor portal
 * and the same kind of value, and leaving one table at 200 would restore the same divergence one
 * relation along the moment somebody writes a contact importer.
 *
 * Widening only, so no data can be lost and nothing needs backfilling. Neither column is indexed
 * (checked), so this is a plain column change on both drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
        });

        Schema::table('vendor_contacts', function (Blueprint $table) {
            // `->change()` replaces the WHOLE definition, so the nullability has to be restated or
            // widening the column would quietly make it required — and a contractor contact with
            // no email address is an ordinary row.
            $table->string('email', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing back would truncate any address longer than 200 that has since been stored, so
        // the reverse is deliberately not automatic.
    }
};
