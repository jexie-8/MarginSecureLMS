<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('university_id')
                ->constrained('universities')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('code');

            $table->timestamps();

            $table->unique(['university_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
