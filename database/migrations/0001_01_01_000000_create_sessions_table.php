<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Laravel skeleton ships this table inside its users migration. Mailward
 * has no users table (docs/decisions/0003-reuse-iredmail-admin-model.md), so
 * the sessions table is created on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            /*
             * The authenticated identifier is a mail address, not an integer
             * key: identity comes from vmail.mailbox, whose primary key is
             * the address itself. No foreign key is possible — the account
             * lives in a database Mailward does not own.
             */
            $table->string('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
