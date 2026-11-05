<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assignment_id')
                ->constrained('assignments')
                ->restrictOnDelete();

            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->unsignedInteger('attempt_number');
            $table->text('content')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->unique([
                'assignment_id',
                'student_id',
                'attempt_number',
            ], 'submissions_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
