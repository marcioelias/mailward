<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run through the application's TestCase. RefreshDatabase is
| deliberately not applied globally: it would migrate the default connection
| on every test, and most of what Mailward reads lives on the vmail
| connection, which is never migrated (docs/01-architecture.md §3).
|
*/

pest()->extend(TestCase::class)->in('Feature');
