<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('audit:prune', function (): void {
    $days = max(1, (int) config('margin.audit_retention_days', 365));
    $deleted = AuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Pruned {$deleted} expired audit log records.");
})->purpose('Remove audit logs older than the configured retention period');

Schedule::command('audit:prune')->dailyAt('03:30')->withoutOverlapping();
