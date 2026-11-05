
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check for existing cross-university associations.
        $invalid = DB::table('program_department as pd')
            ->join('programs as p', 'p.id', '=', 'pd.program_id')
            ->join('departments as d', 'd.id', '=', 'pd.department_id')
            ->whereColumn('p.university_id', '!=', 'd.university_id')
            ->exists();

        if ($invalid) {
            throw new RuntimeException(
                'Cross-university program-department links exist. '
                . 'Correct them before running this migration.'
            );
        }

        // Required for the composite foreign key.
        Schema::table('departments', function (Blueprint $table) {
            $table->unique(
                ['id', 'university_id'],
                'departments_id_university_unique'
            );
        });

        // Add university scope to existing junction records.
        Schema::table('program_department', function (Blueprint $table) {
            $table->unsignedBigInteger('university_id')->nullable();
        });

        DB::statement('
            UPDATE program_department AS pd
            SET university_id = p.university_id
            FROM programs AS p
            WHERE pd.program_id = p.id
        ');

        DB::statement('
            ALTER TABLE program_department
            ALTER COLUMN university_id SET NOT NULL
        ');

        // Both sides must belong to the same university.
        Schema::table('program_department', function (Blueprint $table) {
            $table->foreign(
                ['program_id', 'university_id'],
                'pd_program_university_fk'
            )
                ->references(['id', 'university_id'])
                ->on('programs')
                ->cascadeOnDelete();

            $table->foreign(
                ['department_id', 'university_id'],
                'pd_department_university_fk'
            )
                ->references(['id', 'university_id'])
                ->on('departments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('program_department', function (Blueprint $table) {
            $table->dropForeign('pd_program_university_fk');
            $table->dropForeign('pd_department_university_fk');
            $table->dropColumn('university_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique('departments_id_university_unique');
        });
    }
};
