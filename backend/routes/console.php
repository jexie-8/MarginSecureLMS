<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('audit:prune', function (): void {
    // Never shorter than 30 days, matching the database function's own floor.
    $days = max(30, (int) config('margin.audit_retention_days', 365));
    // On PostgreSQL the audit table is append-only, so expired records are
    // removed by a restricted database function, never by a direct DELETE.
    $deleted = DB::getDriverName() === 'pgsql'
        ? (int) DB::selectOne('select prune_audit_logs(?) as removed', [$days])->removed
        : AuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Pruned {$deleted} expired audit log records.");
})->purpose('Remove audit logs older than the configured retention period');

Schedule::command('audit:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:45')->withoutOverlapping();
