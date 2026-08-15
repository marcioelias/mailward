<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log required by docs/policies/authorization.md §7 and listed as a
 * Mailward-owned entity in docs/02-domain.md §13.
 *
 * Adapted from spatie/laravel-activitylog's published migration in one
 * respect that matters: the package's `nullableMorphs` gives the subject and
 * causer integer keys, and neither is an integer here. An administrator is a
 * mail address and a domain is its own name, so both morph keys are strings.
 *
 * The table is append-only by intent, and deliberately keeps references to
 * accounts that no longer exist — it is the one table exempt from the orphan
 * cleanup a deletion performs (docs/02-domain.md §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();

            $table->string('log_name')->nullable()->index();
            $table->text('description');

            // String morph keys: a mailbox is keyed by address, a domain by
            // its own name. There is no foreign key — the row they point at
            // lives in a database Mailward does not own (ADR-0002).
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);

            $table->string('event')->nullable();

            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->index(['causer_type', 'causer_id']);

            // The acting address, denormalised so the log stays readable after
            // the account is gone, and the IP the policy requires.
            $table->string('actor')->nullable()->index();
            $table->string('ip_address', 45)->nullable();

            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
