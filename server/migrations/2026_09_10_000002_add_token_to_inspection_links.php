<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the inspection link itself, not only its fingerprint.
 *
 * A link was stored as `token_hash` alone, which is right for looking one up
 * and wrong for everything an operator needs afterwards. Once the modal that
 * minted it closed, nobody could see the link again: not to send it a second
 * time, not to check which vehicle it was for, not to tell an expired link
 * from a live one. The only recourse was to mint another.
 *
 * So the token is kept alongside its hash, encrypted at rest with the
 * application key. It is a capability URL — it grants filling in one published
 * form, once, for one vehicle, until it expires — not a credential, and being
 * able to re-read one is how every share link behaves.
 *
 * `token_hash` stays exactly as it is: it is the unique index a public request
 * is resolved through, and nothing about the lookup path changes. Links minted
 * before this migration keep a null token and are shown without their URL.
 */
return new class extends Migration {
    public function up()
    {
        Schema::table('inspection_links', function (Blueprint $table) {
            $table->text('token')->nullable()->after('token_hash');
        });
    }

    public function down()
    {
        Schema::table('inspection_links', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
