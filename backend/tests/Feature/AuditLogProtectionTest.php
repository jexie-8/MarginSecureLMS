<?php

namespace Tests\Feature;

use App\Models\University;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit log must be append-only at the database level. The trigger tests
 * run as the (superuser) test account to prove even a powerful account cannot
 * alter history; the privilege tests run as the restricted margin_app role.
 */
class AuditLogProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function addLog(string $event = 'test.event', ?int $actorId = null, $createdAt = null): int
    {
        return DB::table('audit_logs')->insertGetId([
            'actor_id' => $actorId,
            'event' => $event,
            'metadata' => '{}',
            'created_at' => $createdAt ?? now(),
        ]);
    }

    /** Run a statement that is expected to be rejected, inside a savepoint. */
    private function assertRejected(callable $statement, ?string $role = null): void
    {
        if ($role !== null) {
            DB::statement("SET ROLE {$role}");
        }

        try {
            DB::transaction($statement);
            $this->fail('The database accepted a change to the audit log.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('audit_logs', $e->getMessage());
        } finally {
            if ($role !== null) {
                DB::statement('RESET ROLE');
            }
        }
    }

    public function test_records_cannot_be_edited_or_deleted_even_by_a_superuser(): void
    {
        $id = $this->addLog();

        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->update(['event' => 'tampered']));
        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->update(['metadata' => '{"x":1}']));
        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->delete());
        $this->assertRejected(fn () => DB::table('audit_logs')->delete());

        $this->assertSame('test.event', DB::table('audit_logs')->where('id', $id)->value('event'));
    }

    public function test_the_table_cannot_be_truncated(): void
    {
        $this->addLog();

        $this->assertRejected(fn () => DB::statement('TRUNCATE audit_logs'));

        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_new_records_can_still_be_appended_and_read(): void
    {
        $this->addLog('first');
        $this->addLog('second');

        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_the_app_role_can_only_insert_and_read(): void
    {
        $id = $this->addLog();

        DB::statement('SET ROLE margin_app');
        try {
            $this->assertSame(1, DB::table('audit_logs')->count());
            DB::table('audit_logs')->insert(['event' => 'from.app', 'metadata' => '{}', 'created_at' => now()]);
        } finally {
            DB::statement('RESET ROLE');
        }

        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->update(['event' => 'x']), 'margin_app');
        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->delete(), 'margin_app');
        $this->assertRejected(fn () => DB::statement('TRUNCATE audit_logs'), 'margin_app');
        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_removing_a_user_only_clears_the_actor_reference(): void
    {
        $university = University::create(['name' => 'Uni', 'code' => 'U1']);
        $user = User::create([
            'name' => 'Temp', 'email' => 'temp@example.test', 'password' => 'Pass-word-123!',
            'role' => 'student', 'university_id' => $university->id, 'account_status' => 'active',
        ]);
        $id = $this->addLog('user.action', $user->id);

        DB::table('users')->where('id', $user->id)->delete();

        $row = DB::table('audit_logs')->where('id', $id)->first();
        $this->assertNull($row->actor_id);
        $this->assertSame('user.action', $row->event);
    }

    public function test_the_prune_job_removes_only_expired_records(): void
    {
        config(['margin.audit_retention_days' => 365]);
        $old = $this->addLog('old', null, now()->subDays(366));
        $recent = $this->addLog('recent', null, now()->subDays(364));

        $this->assertSame(0, Artisan::call('audit:prune'));

        $this->assertNull(DB::table('audit_logs')->where('id', $old)->first());
        $this->assertNotNull(DB::table('audit_logs')->where('id', $recent)->first());
    }

    public function test_the_app_role_can_prune_through_the_function_but_not_directly(): void
    {
        $old = $this->addLog('old', null, now()->subDays(400));

        DB::statement('SET ROLE margin_app');
        try {
            $removed = DB::selectOne('select prune_audit_logs(365) as removed')->removed;
        } finally {
            DB::statement('RESET ROLE');
        }

        $this->assertSame(1, (int) $removed);
        $this->assertNull(DB::table('audit_logs')->where('id', $old)->first());
    }

    public function test_retention_shorter_than_thirty_days_is_refused(): void
    {
        $id = $this->addLog('recent', null, now()->subDays(10));

        $this->assertRejected(fn () => DB::selectOne('select prune_audit_logs(1)'));

        $this->assertNotNull(DB::table('audit_logs')->where('id', $id)->first());
    }

    public function test_the_prune_flag_does_not_stay_open_after_pruning(): void
    {
        $this->addLog('old', null, now()->subDays(400));
        DB::selectOne('select prune_audit_logs(365)');
        $id = $this->addLog();

        $this->assertRejected(fn () => DB::table('audit_logs')->where('id', $id)->delete());
    }
}
