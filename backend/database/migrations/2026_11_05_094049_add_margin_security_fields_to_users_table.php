<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('university_id')
                ->nullable()
                ->constrained('universities')
                ->restrictOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->restrictOnDelete();

            $table->enum('role', [
                'platform_admin',
                'university_admin',
                'department_admin',
                'lecturer',
                'teaching_assistant',
                'student',
            ]);

            $table->string('account_status')
                ->default('active');

            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['university_id']);

            $table->dropColumn([
                'university_id',
                'department_id',
                'role',
                'account_status',
                'last_login_at',
            ]);
        });
    }
};