<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Mailward has nothing to seed.
 *
 * Administrators are ordinary mail accounts flagged in iRedMail, so a fresh
 * iRedMail install already has a global admin and the panel is usable on
 * first boot with no setup wizard and no seeding
 * (docs/decisions/0003-reuse-iredmail-admin-model.md).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
