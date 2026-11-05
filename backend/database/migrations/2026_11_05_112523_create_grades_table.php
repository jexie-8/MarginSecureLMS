<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submission_id')
                ->unique()
                ->constrained('submissions')
                ->restrictOnDelete();

            $table->foreignId('graded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->decimal('score', 8, 2);
            $table->text('feedback')->nullable();

            $table->foreignId('published_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
