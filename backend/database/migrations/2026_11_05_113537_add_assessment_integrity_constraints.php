<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE assignments
            ADD CONSTRAINT assignments_max_score_positive
            CHECK (max_score > 0)
        ");

        DB::statement("
            ALTER TABLE submissions
            ADD CONSTRAINT submissions_attempt_positive
            CHECK (attempt_number >= 1)
        ");

        DB::statement("
            ALTER TABLE grades
            ADD CONSTRAINT grades_score_nonnegative
            CHECK (score >= 0)
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE grades
            DROP CONSTRAINT IF EXISTS grades_score_nonnegative
        ");

        DB::statement("
            ALTER TABLE submissions
            DROP CONSTRAINT IF EXISTS submissions_attempt_positive
        ");

        DB::statement("
            ALTER TABLE assignments
            DROP CONSTRAINT IF EXISTS assignments_max_score_positive
        ");
    }
};
