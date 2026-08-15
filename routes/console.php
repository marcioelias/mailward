<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * The audit log is pruned only when a retention window is configured
 * (docs/features/audit-log.md BR-05; docs/reference/decisions-needed.md Q11,
 * answered 2026-08-15). With no window the schedule is empty and nothing is
 * ever deleted, which is the default.
 */
if (config('mailward.audit.retention_days') !== null) {
    Schedule::command('activitylog:clean')->daily();
}
