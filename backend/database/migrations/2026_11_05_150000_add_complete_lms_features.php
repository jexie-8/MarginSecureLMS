<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->timestampTz('mfa_confirmed_at')->nullable();
        });
        Schema::table('assignments', fn (Blueprint $table) => $table->json('rubric')->nullable());
        Schema::table('grades', fn (Blueprint $table) => $table->json('rubric_scores')->nullable());

        Schema::create('course_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['course_id', 'published_at']);
        });

        Schema::create('submission_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->timestampsTz();
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['course_id', 'published_at']);
        });

        Schema::create('lms_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->timestampTz('held_at');
            $table->timestampsTz();
        });
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['attendance_session_id', 'student_id']);
        });

        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('visibility', 20)->default('course');
            $table->timestampsTz();
            $table->index(['course_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('lms_notifications');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('submission_files');
        Schema::dropIfExists('course_materials');
        Schema::table('grades', fn (Blueprint $table) => $table->dropColumn('rubric_scores'));
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn('rubric'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['avatar_path', 'mfa_secret', 'mfa_recovery_codes', 'mfa_confirmed_at']));
    }
};
