<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000);
            $table->decimal('previous_score', 10, 2);
            $table->decimal('new_score', 10, 2);
            $table->text('previous_feedback')->nullable();
            $table->text('new_feedback')->nullable();
            $table->jsonb('previous_rubric_scores')->nullable();
            $table->jsonb('new_rubric_scores')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_revisions');
    }
};
