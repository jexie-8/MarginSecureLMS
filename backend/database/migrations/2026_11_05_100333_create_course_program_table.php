
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->unique(
                ['id', 'university_id'],
                'courses_id_university_unique'
            );
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->unique(
                ['id', 'university_id'],
                'programs_id_university_unique'
            );
        });

        Schema::create('course_program', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('program_id');
            $table->timestamps();

            $table->foreign('university_id')
                ->references('id')
                ->on('universities')
                ->restrictOnDelete();

            $table->foreign(['course_id', 'university_id'])
                ->references(['id', 'university_id'])
                ->on('courses')
                ->cascadeOnDelete();

            $table->foreign(['program_id', 'university_id'])
                ->references(['id', 'university_id'])
                ->on('programs')
                ->cascadeOnDelete();

            $table->unique(['course_id', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_program');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique('courses_id_university_unique');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique('programs_id_university_unique');
        });
    }
};

