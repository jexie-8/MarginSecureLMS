<?php

namespace Tests\Feature\Auth;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resetting_a_password_ends_every_existing_session(): void
    {
        $university = University::create(['name' => 'Uni', 'code' => uniqid('U')]);
        $user = User::create([
            'name' => 'Student',
            'email' => 'student@example.test',
            'password' => 'Old-password-123!',
            'role' => 'student',
            'university_id' => $university->id,
            'account_status' => 'active',
        ]);

        foreach (['stolen-session-one', 'stolen-session-two'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '203.0.113.9',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->postJson('/api/v1/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'New-password-456!',
            'password_confirmation' => 'New-password-456!',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }
}
